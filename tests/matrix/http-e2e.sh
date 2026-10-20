#!/usr/bin/env bash
# Sourced by container-run.sh INSIDE a matrix container, after Apache is up and $JAR holds an admin login.
# Drives the plugin's user-facing backup flow over REAL HTTP as a logged-in admin: REST nonce enforcement,
# start / 409 / progress / browser-kick / cancel / lock / delete, authenticated download (Range, checksums,
# hostile rows) and the Backup Now / History pages. Run for each format, with and without Action Scheduler.
#
# Uses: URL, JAR, CHECK_STATUS, wps/wpc (container-run.sh). Prints PASS/FAIL lines.

RB="$URL/index.php?rest_route=/ovb/v1"
NONCE=""
LOCAL_DIR=""

e_ok() { echo "PASS $1"; }
e_bad() { echo "FAIL $1${2:+: $2}"; CHECK_STATUS=1; }
e_check() { if [ "$2" = 1 ]; then e_ok "$1"; else e_bad "$1" "${3:-}"; fi; }

# jg <key>...: print a value of the JSON on stdin (booleans as true/false, arrays as JSON).
jg() {
	php -r '$v = json_decode( stream_get_contents( STDIN ), true ); foreach ( array_slice( $argv, 1 ) as $k ) { $v = is_array( $v ) && isset( $v[ $k ] ) ? $v[ $k ] : null; } echo is_bool( $v ) ? ( $v ? "true" : "false" ) : ( is_array( $v ) ? json_encode( $v ) : (string) $v );' -- "$@"
}

# api <METHOD> <route> [json body] [extra curl args]
api() {
	local method=$1 route=$2 body=${3:-}
	shift 2; [ $# -gt 0 ] && shift
	curl -s -b "$JAR" -X "$method" -H "X-WP-Nonce: $NONCE" ${body:+-H "Content-Type: application/json" --data "$body"} "$@" "$RB$route"
}
api_code() {
	local method=$1 route=$2 body=${3:-}
	shift 2; [ $# -gt 0 ] && shift
	curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -X "$method" -H "X-WP-Nonce: $NONCE" ${body:+-H "Content-Type: application/json" --data "$body"} "$@" "$RB$route"
}

# Poll progress until finished (or timeout). Sets E_STATUSES, E_MONO (1 = percent never decreased), E_LAST (last JSON).
e_poll() {
	local id=$1 deadline=$((SECONDS + ${2:-240})) last=-1 pc st
	E_STATUSES=""; E_MONO=1; E_LAST=""
	while [ "$SECONDS" -lt "$deadline" ]; do
		E_LAST=$(api GET "/backups/$id/progress")
		st=$(jg status <<<"$E_LAST"); pc=$(jg percent <<<"$E_LAST")
		case " $E_STATUSES " in *" $st "*) ;; *) E_STATUSES="$E_STATUSES $st" ;; esac
		if [[ "$pc" =~ ^[0-9]+$ ]]; then [ "$pc" -lt "$last" ] && E_MONO=0; last=$pc; fi
		[ "$(jg finished <<<"$E_LAST")" = true ] && return 0
		sleep 1
	done
	return 1
}

e_php() { wpc eval "$1" 2>/dev/null; }

e_flow() {
	local fmt=$1 mode=$2 tag="[$1/$2]" body id resp code
	local contents='{"db":true,"plugins":true,"themes":true,"uploads":true,"mu_plugins":false,"other_content":false,"core":false,"wp_config":false}'

	LOCAL_DIR=/var/www/html/wp-content/$(wps option get ovb_local_dir)

	if [ "$mode" = kick ]; then
		# No WP-Cron, no Action Scheduler loopback: only the progress polling can move the job.
		mkdir -p /var/www/html/wp-content/mu-plugins
		cat >/var/www/html/wp-content/mu-plugins/ovb-e2e-kick.php <<'PHP'
<?php
if ( ! defined( 'DISABLE_WP_CRON' ) ) { define( 'DISABLE_WP_CRON', true ); }
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	return ( false !== strpos( $url, 'wp-cron.php' ) || false !== strpos( $url, 'as_async_request_queue_runner' ) ) ? new WP_Error( 'ovb_e2e_blocked', 'blocked' ) : $pre;
}, 10, 3 );
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
add_filter( 'ovb_kick_after_seconds', function () { return 2; } );
PHP
		chown -R www-data:www-data /var/www/html/wp-content/mu-plugins
	fi

	# 1. Pages and the REST nonce.
	body=$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?page=openvault-backup-now")
	NONCE=$(php -r 'if ( preg_match( "/ovbBackupNow = (\{.*?\});/s", stream_get_contents( STDIN ), $m ) ) { $d = json_decode( $m[1], true ); echo isset( $d["nonce"] ) ? $d["nonce"] : ""; }' <<<"$body")
	e_check "$tag Backup Now page carries a REST nonce" "$([ -n "$NONCE" ] && echo 1 || echo 0)"
	e_check "$tag Backup Now page loads its script only there" "$(grep -q 'backup-now.js' <<<"$body" && echo 1 || echo 0)"
	body2=$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?page=openvault-system")
	e_check "$tag assets are not loaded on other plugin pages" "$(grep -q 'backup-now.js\|history.js' <<<"$body2" && echo 0 || echo 1)"

	# Only formats the server supports are offered (and unavailable ones are disabled).
	local want ids
	ids="zip zip-store tar.gz"
	for f in $ids; do
		want=$(e_php 'echo ( new OpenVault\Backup\Archive\CapabilityDetector() )->is_available( "'"$f"'" ) ? "1" : "0";')
		if [ "$want" = 1 ]; then
			e_check "$tag Backup Now offers available format $f" "$(grep -q "id=\"ovb-f-$f\"" <<<"$body" && ! grep -q "id=\"ovb-f-$f\"[^>]*disabled" <<<"$body" && echo 1 || echo 0)"
		else
			e_check "$tag Backup Now disables unavailable format $f" "$(grep -q "id=\"ovb-f-$f\"[^>]*disabled" <<<"$body" && echo 1 || echo 0)"
		fi
	done
	e_check "$tag Backup Now does not offer tar.bz2 (later phase)" "$(grep -q 'ovb-f-tar.bz2' <<<"$body" && echo 0 || echo 1)"

	# 2. Nonce and capability enforcement on REST.
	code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -X POST -H 'Content-Type: application/json' --data "{\"format\":\"$fmt\"}" "$RB/backups")
	e_check "$tag POST /backups without a nonce is rejected (got $code)" "$([ "$code" = 401 ] || [ "$code" = 403 ] && echo 1 || echo 0)"
	code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -X POST -H 'X-WP-Nonce: 0123456789' -H 'Content-Type: application/json' --data "{\"format\":\"$fmt\"}" "$RB/backups")
	e_check "$tag POST /backups with a bad nonce is rejected 403 (got $code)" "$([ "$code" = 403 ] && echo 1 || echo 0)"
	code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' --data "{\"format\":\"$fmt\"}" "$RB/backups")
	e_check "$tag anonymous POST /backups is rejected (got $code)" "$([ "$code" = 401 ] || [ "$code" = 403 ] && echo 1 || echo 0)"
	code=$(curl -s -o /dev/null -w '%{http_code}' "$RB/backups")
	e_check "$tag anonymous GET /backups is rejected (got $code)" "$([ "$code" = 401 ] || [ "$code" = 403 ] && echo 1 || echo 0)"
	code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$RB/backups/1/progress")
	e_check "$tag GET progress without a nonce is rejected (got $code)" "$([ "$code" = 401 ] || [ "$code" = 403 ] && echo 1 || echo 0)"
	e_check "$tag a backup was not created by the rejected requests" "$([ "$(e_php 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ovb_backups WHERE status IN (\"queued\",\"running\")" );')" = 0 ] && echo 1 || echo 0)"

	# 3. Argument validation.
	code=$(api_code POST /backups '{"format":"tar.bz2"}')
	e_check "$tag tar.bz2 is refused (got $code)" "$([ "$code" = 400 ] && echo 1 || echo 0)"
	code=$(api_code POST /backups '{"format":"7z"}')
	e_check "$tag unknown format is refused (got $code)" "$([ "$code" = 400 ] && echo 1 || echo 0)"
	code=$(api_code POST /backups '{"contents":{"evil":true}}')
	e_check "$tag unknown contents flag is refused (got $code)" "$([ "$code" = 400 ] && echo 1 || echo 0)"
	code=$(api_code POST /backups '{"contents":{"db":false}}')
	e_check "$tag empty contents selection is refused (got $code)" "$([ "$code" = 400 ] && echo 1 || echo 0)"
	code=$(api_code POST /backups "{\"note\":\"$(printf 'x%.0s' $(seq 1 600))\"}")
	e_check "$tag over-long note is refused (got $code)" "$([ "$code" = 400 ] && echo 1 || echo 0)"
	code=$(api_code GET "/backups&per_page=0")
	e_check "$tag per_page=0 is refused (got $code)" "$([ "$code" = 400 ] && echo 1 || echo 0)"
	code=$(api_code GET /backups/999999/progress)
	e_check "$tag progress of an unknown backup is 404 (got $code)" "$([ "$code" = 404 ] && echo 1 || echo 0)"
	code=$(api_code GET "/backups/1%20OR%201=1/progress")
	e_check "$tag non numeric id never matches a route (got $code)" "$([ "$code" = 404 ] && echo 1 || echo 0)"

	# 4. Start, 409 on a second start, progress.
	[ "$mode" = as ] && { touch /tmp/ovb-nudge; ( while [ -f /tmp/ovb-nudge ]; do curl -s -o /dev/null "$URL/wp-cron.php?doing_wp_cron"; sleep 2; done ) & }
	resp=$(api POST /backups "{\"contents\":$contents,\"format\":\"$fmt\",\"note\":\"e2e $fmt $mode\"}")
	id=$(jg id <<<"$resp")
	e_check "$tag POST /backups starts a backup (id=$id, status $(jg status <<<"$resp"))" "$([[ "$id" =~ ^[0-9]+$ ]] && echo 1 || echo 0)" "$resp"
	if ! [[ "$id" =~ ^[0-9]+$ ]]; then rm -f /tmp/ovb-nudge; return; fi
	code=$(api_code POST /backups "{\"format\":\"$fmt\"}")
	e_check "$tag a second start while one is running returns 409 (got $code)" "$([ "$code" = 409 ] && echo 1 || echo 0)"
	code=$(api_code DELETE "/backups/$id")
	e_check "$tag a running backup cannot be deleted (got $code)" "$([ "$code" = 409 ] && echo 1 || echo 0)"

	e_poll "$id" 280
	rm -f /tmp/ovb-nudge
	e_check "$tag backup finished before the timeout (statuses:$E_STATUSES)" "$([ "$(jg finished <<<"$E_LAST")" = true ] && echo 1 || echo 0)" "$(jg error <<<"$E_LAST")"
	e_check "$tag final status is complete or partial" "$(case "$(jg status <<<"$E_LAST")" in complete|partial) echo 1 ;; *) echo 0 ;; esac)" "$E_LAST"
	e_check "$tag percent never decreased" "$E_MONO"
	e_check "$tag final percent is 100" "$([ "$(jg percent <<<"$E_LAST")" = 100 ] && echo 1 || echo 0)"
	e_check "$tag summary lists parts" "$([ "$(php -r '$d=json_decode($argv[1],true); echo count($d["parts"]);' -- "$E_LAST")" -ge 3 ] && echo 1 || echo 0)" "$E_LAST"
	e_check "$tag summary has a total size" "$([ "$(jg total_size <<<"$E_LAST")" -gt 0 ] 2>/dev/null && echo 1 || echo 0)"
	e_check "$tag response has no server path" "$(grep -q '/var/www' <<<"$E_LAST" && echo 0 || echo 1)"

	e_downloads "$tag" "$id" "$fmt"
	e_pages "$tag" "$id"
	e_hostile "$tag" "$id"
	e_listing "$tag"
	e_lock_delete "$tag" "$id"
	e_cancel "$tag" "$fmt"

	if [ "$mode" = kick ]; then
		rm -f /var/www/html/wp-content/mu-plugins/ovb-e2e-kick.php
	fi
}

# Parts of a finished backup: "id|name|size|sha|kind|url" lines.
e_parts() { php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d["parts"] as $p){echo $p["id"],"|",$p["name"],"|",$p["size"],"|",$p["sha256"],"|",$p["kind"],"|",$p["download_url"],"\n";}' <<<"$1"; }

e_downloads() {
	local tag=$1 id=$2 fmt=$3 line fid name size sha kind url got_sha hdr work="/tmp/ovb-e2e-dl" db_part="" files_part=""
	rm -rf "$work"; mkdir -p "$work"
	while IFS='|' read -r fid name size sha kind url; do
		[ -z "$fid" ] && continue
		hdr="$work/h.$fid"
		code=$(curl -s -b "$JAR" -D "$hdr" -o "$work/$name" -w '%{http_code}' "$url")
		e_check "$tag download of $name returns 200 (got $code)" "$([ "$code" = 200 ] && echo 1 || echo 0)"
		e_check "$tag $name Content-Length matches" "$([ "$(tr -d '\r' <"$hdr" | grep -i '^content-length:' | tail -1 | awk '{print $2}')" = "$size" ] && [ "$(wc -c <"$work/$name" | tr -d ' ')" = "$size" ] && echo 1 || echo 0)"
		got_sha=$(sha256sum "$work/$name" | cut -d' ' -f1)
		e_check "$tag $name sha256 equals the recorded checksum" "$([ "$got_sha" = "$sha" ] && echo 1 || echo 0)" "$got_sha vs $sha"
		e_check "$tag $name is sent as an attachment with nosniff and no cache" "$(tr -d '\r' <"$hdr" | grep -qi '^content-disposition: attachment' && tr -d '\r' <"$hdr" | grep -qi '^x-content-type-options: nosniff' && tr -d '\r' <"$hdr" | grep -qi '^cache-control:.*no-cache' && echo 1 || echo 0)"
		[ "$kind" = db ] && db_part=$name
		[ "$kind" = files ] && [ -z "$files_part" ] && files_part=$name
		[ "$kind" = files ] && FILE_ID=$fid && FILE_URL=$url && FILE_SIZE=$size
	done < <(e_parts "$E_LAST")

	e_check "$tag recorded checksum agrees with the database row" "$([ "$(e_php 'global $wpdb; echo $wpdb->get_var( $wpdb->prepare( "SELECT checksum_sha256 FROM {$wpdb->prefix}ovb_backup_files WHERE id = %d", '"${FILE_ID:-0}"' ) );')" = "$(sha256sum "$work/$files_part" | cut -d' ' -f1)" ] && echo 1 || echo 0)"

	# Nonce, login, ids.
	code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$(sed 's/_wpnonce=[a-f0-9]*/_wpnonce=deadbeef00/' <<<"$FILE_URL")")
	e_check "$tag download with a bad nonce is 403 (got $code)" "$([ "$code" = 403 ] && echo 1 || echo 0)"
	code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$(sed 's/&_wpnonce=[a-f0-9]*//' <<<"$FILE_URL")")
	e_check "$tag download without a nonce is 403 (got $code)" "$([ "$code" = 403 ] && echo 1 || echo 0)"
	code=$(curl -s -o /dev/null -w '%{http_code}' "$FILE_URL")
	e_check "$tag anonymous download is 403 (got $code)" "$([ "$code" = 403 ] && echo 1 || echo 0)"
	code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$(sed "s/backup=$id/backup=$((id + 1000))/" <<<"$FILE_URL")")
	e_check "$tag a file requested under another backup id is 404 (got $code)" "$([ "$code" = 404 ] && echo 1 || echo 0)"
	body=$(curl -s -b "$JAR" "$FILE_URL&path=../../../wp-config.php&file_name=../../wp-config.php&dir=/etc" -o "$work/trav.bin"; sha256sum "$work/trav.bin" | cut -d' ' -f1)
	e_check "$tag extra path parameters are ignored (same bytes served)" "$([ "$body" = "$(sha256sum "$work/$files_part" | cut -d' ' -f1)" ] && echo 1 || echo 0)"
	code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$URL/wp-admin/admin-post.php?action=ovb_download&backup=$id&file=../../wp-config.php&_wpnonce=abc")
	e_check "$tag file=../../wp-config.php is refused (got $code)" "$([ "$code" = 403 ] || [ "$code" = 404 ] && echo 1 || echo 0)"
	e_check "$tag the local backup folder is not web readable" "$([ "$(curl -s -o /dev/null -w '%{http_code}' "$URL/wp-content/$(wps option get ovb_local_dir)/backups/")" != 200 ] && echo 1 || echo 0)"

	# Range.
	curl -s -b "$JAR" -D "$work/rh" -H 'Range: bytes=0-99' -o "$work/r.bin" "$FILE_URL"
	e_check "$tag Range bytes=0-99 answers 206 with exactly 100 bytes" "$(head -1 "$work/rh" | grep -q ' 206' && [ "$(wc -c <"$work/r.bin" | tr -d ' ')" = 100 ] && cmp -s "$work/r.bin" <(head -c 100 "$work/$files_part") && echo 1 || echo 0)" "$(head -1 "$work/rh")"
	e_check "$tag Content-Range header is correct" "$(tr -d '\r' <"$work/rh" | grep -qi "^content-range: bytes 0-99/$FILE_SIZE\$" && echo 1 || echo 0)"
	curl -s -b "$JAR" -D "$work/rh2" -H 'Range: bytes=-50' -o "$work/r2.bin" "$FILE_URL"
	e_check "$tag suffix Range bytes=-50 returns the last 50 bytes" "$(cmp -s "$work/r2.bin" <(tail -c 50 "$work/$files_part") && echo 1 || echo 0)"
	code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -H "Range: bytes=$((FILE_SIZE + 10))-" "$FILE_URL")
	e_check "$tag Range beyond the end answers 416 (got $code)" "$([ "$code" = 416 ] && echo 1 || echo 0)"
	code=$(curl -s -o "$work/m.bin" -w '%{http_code}' -b "$JAR" -H "Range: bytes=0-9,20-29" "$FILE_URL")
	e_check "$tag multiple ranges are ignored (full file, got $code)" "$([ "$code" = 200 ] && [ "$(wc -c <"$work/m.bin" | tr -d ' ')" = "$FILE_SIZE" ] && echo 1 || echo 0)"

	# Archive contents.
	rm -rf "$work/x"; mkdir -p "$work/x"
	if [ -n "$db_part" ]; then
		if [ "$fmt" = tar.gz ]; then tar -xzf "$work/$db_part" -C "$work/x" 2>/dev/null; else unzip -q -o "$work/$db_part" -d "$work/x" 2>/dev/null; fi
		e_check "$tag db part contains db/db.sql" "$([ -s "$work/x/db/db.sql" ] && grep -q 'CREATE TABLE' "$work/x/db/db.sql" && echo 1 || echo 0)"
		e_check "$tag dump leaves out the plugin's own tables" "$(grep -q 'CREATE TABLE `[a-z0-9_]*ovb_backups`' "$work/x/db/db.sql" && echo 0 || echo 1)"
	else
		e_bad "$tag db part present"
	fi
	if [ "$fmt" = tar.gz ]; then tar -tzf "$work/$files_part" >"$work/list.txt" 2>/dev/null; else unzip -Z1 "$work/$files_part" >"$work/list.txt" 2>/dev/null; fi
	e_check "$tag files part lists plugin files" "$(grep -q 'plugins/' "$work/list.txt" && echo 1 || echo 0)"
	e_check "$tag no unsafe entry names" "$(grep -qE '(^|/)\.\.(/|$)|^/' "$work/list.txt" && echo 0 || echo 1)"
	e_check "$tag the plugin's own backup storage folder (ovb-*) is never archived" "$(grep -q 'ovb-' "$work/list.txt" && echo 0 || echo 1)"
}

e_pages() {
	local tag=$1 id=$2 html
	html=$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?page=openvault-backups")
	e_check "$tag History page lists the backup (status text)" "$(grep -q 'ovb-status--complete\|ovb-status--partial' <<<"$html" && grep -q '>Complete' <<<"$html" && echo 1 || echo 0)"
	e_check "$tag History page has download links with nonces" "$([ "$(grep -o 'admin-post.php?action=ovb_download[^"]*_wpnonce=[a-f0-9]*' <<<"$html" | wc -l)" -ge 3 ] && echo 1 || echo 0)"
	e_check "$tag History page shows no raw server paths" "$(grep -q '/var/www\|wp-content/ovb-' <<<"$html" && echo 0 || echo 1)"
	e_check "$tag History page shows no secrets" "$(grep -qi 'secret\|DB_PASSWORD\|refresh_token' <<<"$html" && echo 0 || echo 1)"
	e_check "$tag History page has the lock, delete and log actions" "$(grep -q 'data-ovb-action="lock"' <<<"$html" && grep -q 'data-ovb-action="delete"' <<<"$html" && grep -q 'page=openvault-logs' <<<"$html" && echo 1 || echo 0)"
	e_check "$tag History page loads history.js" "$(grep -q 'history.js' <<<"$html" && echo 1 || echo 0)"
}

# A poisoned database row (path traversal, symlink) must never serve a file outside the backup folder.
e_hostile() {
	local tag=$1 id=$2 folder dir html url code
	folder=$(e_php '$r = ( new OpenVault\Backup\Database\BackupsRepository() )->find( '"$id"' ); echo OpenVault\Backup\Support\Paths::build_backup_folder_name( $r["created_at"], $r["uuid"] );')
	dir="$LOCAL_DIR/backups/$folder"
	ln -s /var/www/html/wp-config.php "$dir/evil.zip"
	e_php 'global $wpdb; $t = $wpdb->prefix . "ovb_backup_files"; $now = gmdate( "Y-m-d H:i:s" ); foreach ( array( "../../../wp-config.php", "evil.zip", "nope.zip" ) as $n ) { $wpdb->insert( $t, array( "backup_id" => '"$id"', "destination_id" => 0, "part_number" => 9, "file_name" => $n, "size" => 1, "checksum_sha256" => "", "upload_status" => "done", "created_at" => $now, "updated_at" => $now ) ); }' >/dev/null
	html=$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?page=openvault-backups")
	for n in '../../../wp-config.php' 'evil.zip' 'nope.zip'; do
		url=$(php -r 'preg_match_all( "#<a href=\"([^\"]+ovb_download[^\"]+)\">([^<]+)</a>#", file_get_contents( "php://stdin" ), $m, PREG_SET_ORDER ); foreach ( $m as $x ) { if ( html_entity_decode( $x[2] ) === $argv[1] ) { echo html_entity_decode( $x[1] ); break; } }' -- "$n" <<<"$html")
		if [ -z "$url" ]; then e_bad "$tag poisoned row $n has a download link in History"; continue; fi
		code=$(curl -s -o /tmp/ovb-e2e-poison.bin -w '%{http_code}' -b "$JAR" "$url")
		e_check "$tag poisoned row '$n' is refused 404 (got $code)" "$([ "$code" = 404 ] && ! grep -q 'DB_PASSWORD' /tmp/ovb-e2e-poison.bin && echo 1 || echo 0)"
	done
	e_php 'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->prefix}ovb_backup_files WHERE part_number = 9" );' >/dev/null
	rm -f "$dir/evil.zip" /tmp/ovb-e2e-poison.bin
}

e_listing() {
	local tag=$1 r
	r=$(api GET "/backups&per_page=1&page=1" "" -D /tmp/ovb-e2e-h)
	e_check "$tag GET /backups paginates (1 item)" "$([ "$(php -r 'echo count(json_decode($argv[1],true));' -- "$r")" = 1 ] && echo 1 || echo 0)" "$r"
	e_check "$tag GET /backups sends X-WP-Total" "$(tr -d '\r' </tmp/ovb-e2e-h | grep -qi '^x-wp-total: [1-9]' && echo 1 || echo 0)"
	r=$(api GET "/backups&per_page=1&page=999")
	e_check "$tag a page past the end is empty" "$([ "$r" = "[]" ] && echo 1 || echo 0)" "$r"
}

e_lock_delete() {
	local tag=$1 id=$2 code folder
	folder=$(e_php '$r = ( new OpenVault\Backup\Database\BackupsRepository() )->find( '"$id"' ); echo OpenVault\Backup\Support\Paths::build_backup_folder_name( $r["created_at"], $r["uuid"] );')
	e_check "$tag backup folder exists on disk before delete" "$([ -d "$LOCAL_DIR/backups/$folder" ] && echo 1 || echo 0)"
	code=$(api_code POST "/backups/$id/lock" '{"locked":true}')
	e_check "$tag lock returns 200 (got $code)" "$([ "$code" = 200 ] && echo 1 || echo 0)"
	code=$(api_code DELETE "/backups/$id")
	e_check "$tag DELETE refuses a locked backup (got $code)" "$([ "$code" = 409 ] && [ -d "$LOCAL_DIR/backups/$folder" ] && echo 1 || echo 0)"
	code=$(api_code POST "/backups/$id/note" '{"note":"renamed note"}')
	e_check "$tag note update returns 200 (got $code)" "$([ "$code" = 200 ] && echo 1 || echo 0)"
	code=$(api_code POST "/backups/$id/lock" '{"locked":false}')
	e_check "$tag unlock returns 200 (got $code)" "$([ "$code" = 200 ] && echo 1 || echo 0)"
	code=$(api_code DELETE "/backups/$id")
	e_check "$tag DELETE removes an unlocked backup (got $code)" "$([ "$code" = 200 ] && echo 1 || echo 0)"
	e_check "$tag backup folder is gone from disk" "$([ ! -e "$LOCAL_DIR/backups/$folder" ] && echo 1 || echo 0)"
	e_check "$tag backup and file rows are gone" "$([ "$(e_php 'global $wpdb; echo (int) $wpdb->get_var( "SELECT (SELECT COUNT(*) FROM {$wpdb->prefix}ovb_backups WHERE id = '"$id"') + (SELECT COUNT(*) FROM {$wpdb->prefix}ovb_backup_files WHERE backup_id = '"$id"')" );')" = 0 ] && echo 1 || echo 0)"
	e_check "$tag the backups folder itself and its guard files survive" "$([ -d "$LOCAL_DIR/backups" ] && [ -f "$LOCAL_DIR/backups/index.php" ] && echo 1 || echo 0)"
	code=$(api_code DELETE "/backups/$id")
	e_check "$tag deleting it again is 404 (got $code)" "$([ "$code" = 404 ] && echo 1 || echo 0)"
}

e_cancel() {
	local tag=$1 fmt=$2 resp id uuid code
	resp=$(api POST /backups "{\"contents\":{\"db\":true,\"uploads\":true,\"plugins\":true,\"themes\":true,\"other_content\":true,\"mu_plugins\":true,\"core\":true},\"format\":\"$fmt\"}")
	id=$(jg id <<<"$resp")
	if ! [[ "$id" =~ ^[0-9]+$ ]]; then e_bad "$tag second backup could be started for the cancel test" "$resp"; return; fi
	uuid=$(e_php 'echo ( new OpenVault\Backup\Database\BackupsRepository() )->find( '"$id"' )["uuid"];')
	api GET "/backups/$id/progress" >/dev/null
	resp=$(api POST "/backups/$id/cancel")
	e_check "$tag cancel ends the backup as failed (got $(jg status <<<"$resp"))" "$([ "$(jg status <<<"$resp")" = failed ] && echo 1 || echo 0)" "$resp"
	e_check "$tag cancel message is 'Cancelled by user'" "$([ "$(jg error <<<"$resp")" = "Cancelled by user" ] && echo 1 || echo 0)" "$(jg error <<<"$resp")"
	e_check "$tag the cancelled backup's work folder is cleaned" "$([ ! -e "$LOCAL_DIR/tmp/$uuid" ] && echo 1 || echo 0)"
	code=$(api_code POST "/backups/$id/cancel")
	e_check "$tag cancelling a finished backup is 409 (got $code)" "$([ "$code" = 409 ] && echo 1 || echo 0)"
	sleep 3
	resp=$(api GET "/backups/$id/progress")
	e_check "$tag a cancelled backup stays failed" "$([ "$(jg status <<<"$resp")" = failed ] && echo 1 || echo 0)"
	code=$(api_code DELETE "/backups/$id")
	e_check "$tag a cancelled backup can be deleted (got $code)" "$([ "$code" = 200 ] && echo 1 || echo 0)"
}

step "REST, download and pages over HTTP (admin session)"
for fmt in zip tar.gz; do
	if [ "$(e_php 'echo ( new OpenVault\Backup\Archive\CapabilityDetector() )->is_available( "'"$fmt"'" ) ? "1" : "0";')" != 1 ]; then
		echo "SKIP e2e for $fmt (not available on this server)"
		continue
	fi
	e_flow "$fmt" as
	e_flow "$fmt" kick
done
