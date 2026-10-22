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
	local tag=$1 fmt=$2 resp id uuid code hold=/var/www/html/wp-content/mu-plugins/ovb-e2e-zhold.php
	mkdir -p /var/www/html/wp-content/mu-plugins
	cat >"$hold" <<'PHP'
<?php
if ( ! defined( 'DISABLE_WP_CRON' ) ) { define( 'DISABLE_WP_CRON', true ); }
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	return ( false !== strpos( $url, 'wp-cron.php' ) || false !== strpos( $url, 'as_async_request_queue_runner' ) ) ? new WP_Error( 'ovb_e2e_blocked', 'blocked' ) : $pre;
}, 10, 3 );
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
add_filter( 'ovb_kick_after_seconds', function () { return 3600; }, 99 );
PHP
	chown www-data:www-data "$hold"
	resp=$(api POST /backups "{\"contents\":{\"db\":true,\"uploads\":true,\"plugins\":true,\"themes\":true,\"other_content\":true,\"mu_plugins\":true,\"core\":true},\"format\":\"$fmt\"}")
	id=$(jg id <<<"$resp")
	if ! [[ "$id" =~ ^[0-9]+$ ]]; then e_bad "$tag second backup could be started for the cancel test" "$resp"; rm -f "$hold"; return; fi
	uuid=$(e_php 'echo ( new OpenVault\Backup\Database\BackupsRepository() )->find( '"$id"' )["uuid"];')
	# Deterministic: the job is held queued (no cron, no loopback, no browser kick) and gets a work folder with
	# content, so cancel always meets an unfinished job and has something to clean.
	e_php '$d = OpenVault\Backup\Support\Paths::work_dir( "'"$uuid"'" ); wp_mkdir_p( $d ); file_put_contents( $d . "/db.sql", "x" );' >/dev/null
	chown -R www-data:www-data "$LOCAL_DIR/tmp/$uuid" 2>/dev/null
	e_check "$tag the job is still unfinished and has a work folder before cancel" "$([ "$(jg finished <<<"$(api GET "/backups/$id/progress")")" = false ] && [ -d "$LOCAL_DIR/tmp/$uuid" ] && echo 1 || echo 0)"
	resp=$(api POST "/backups/$id/cancel")
	e_check "$tag cancel returns the backup (HTTP 200 payload with an id)" "$([ "$(jg id <<<"$resp")" = "$id" ] && echo 1 || echo 0)" "$resp"
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
	rm -f "$hold"
}

# A backup started over REST with a Local destination selected: the progress payload reports the destination, History shows it
# (as text), the remote folder holds byte-identical files, and bad destination ids are refused.
e_destination() {
	local fmt=$1 tag="[$1/destination]" dest_dir=/var/www/html/wp-content/ovb-e2e-dest dest_id resp id code html folder f same=1 n=0 contents
	contents='{"db":true,"plugins":false,"themes":false,"uploads":false,"mu_plugins":false,"other_content":false,"core":false,"wp_config":false}'
	LOCAL_DIR=/var/www/html/wp-content/$(wps option get ovb_local_dir)
	dest_id=$(e_php 'echo ( new OpenVault\Backup\Database\DestinationsRepository() )->create( "local", "E2E folder", array( "path" => "'"$dest_dir"'" ), array() );')
	e_check "$tag a Local destination row was created (id=$dest_id)" "$([[ "$dest_id" =~ ^[1-9][0-9]*$ ]] && echo 1 || echo 0)"
	[[ "$dest_id" =~ ^[1-9][0-9]*$ ]] || return

	code=$(api_code POST /backups "{\"format\":\"$fmt\",\"destinations\":[2147000000]}")
	e_check "$tag a destination id that does not exist is refused (got $code)" "$([ "$code" = 400 ] && echo 1 || echo 0)"
	code=$(api_code POST /backups "{\"format\":\"$fmt\",\"destinations\":\"all\"}")
	e_check "$tag destinations must be a list (got $code)" "$([ "$code" = 400 ] && echo 1 || echo 0)"
	code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' --data "{\"destinations\":[$dest_id]}" "$RB/backups")
	e_check "$tag anonymous start with a destination is rejected (got $code)" "$([ "$code" = 401 ] || [ "$code" = 403 ] && echo 1 || echo 0)"

	touch /tmp/ovb-nudge; ( while [ -f /tmp/ovb-nudge ]; do curl -s -o /dev/null "$URL/wp-cron.php?doing_wp_cron"; sleep 2; done ) &
	resp=$(api POST /backups "{\"contents\":$contents,\"format\":\"$fmt\",\"destinations\":[$dest_id],\"note\":\"e2e destination\"}")
	id=$(jg id <<<"$resp")
	e_check "$tag POST /backups with destinations starts a backup (id=$id)" "$([[ "$id" =~ ^[0-9]+$ ]] && echo 1 || echo 0)" "$resp"
	if ! [[ "$id" =~ ^[0-9]+$ ]]; then rm -f /tmp/ovb-nudge; return; fi
	e_poll "$id" 240
	rm -f /tmp/ovb-nudge
	e_check "$tag backup with a destination finished and is complete (statuses:$E_STATUSES)" "$([ "$(jg status <<<"$E_LAST")" = complete ] && echo 1 || echo 0)" "$(jg error <<<"$E_LAST")"
	e_check "$tag the progress payload reports the destination as done with all files" "$(php -r '$d=json_decode($argv[1],true); $x=$d["destinations"]; echo ( count($x)===1 && $x[0]["id"]==(int)$argv[2] && $x[0]["status"]==="done" && $x[0]["label"]==="E2E folder" && $x[0]["files_done"]===$x[0]["files_total"] && $x[0]["files_total"]>=2 ) ? 1 : 0;' -- "$E_LAST" "$dest_id")" "$E_LAST"
	e_check "$tag the progress payload shows no server path" "$(grep -q '/var/www\|ovb-e2e-dest' <<<"$E_LAST" && echo 0 || echo 1)"

	folder=$(e_php '$r = ( new OpenVault\Backup\Database\BackupsRepository() )->find( '"$id"' ); echo OpenVault\Backup\Support\Paths::build_backup_folder_name( $r["created_at"], $r["uuid"] );')
	e_check "$tag the remote folder has a manifest.json" "$([ -f "$dest_dir"/OpenVault-Backups/*/"$folder"/manifest.json ] && echo 1 || echo 0)"
	for f in "$LOCAL_DIR/backups/$folder"/*.zip "$LOCAL_DIR/backups/$folder"/*.tar.gz "$LOCAL_DIR/backups/$folder"/manifest.json; do
		[ -f "$f" ] || continue
		n=$((n + 1))
		cmp -s "$f" "$dest_dir"/OpenVault-Backups/*/"$folder"/"$(basename "$f")" || same=0
	done
	e_check "$tag $n remote files are byte identical to the local ones" "$([ "$same" = 1 ] && [ "$n" -ge 2 ] && echo 1 || echo 0)"
	e_check "$tag the remote folder is guarded and has no .part leftovers" "$([ -f "$dest_dir/.htaccess" ] && [ -z "$(find "$dest_dir" -name '*.part')" ] && echo 1 || echo 0)"

	html=$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?page=openvault-backups")
	e_check "$tag History shows the destination status as text" "$(grep -q 'E2E folder: Uploaded' <<<"$html" && echo 1 || echo 0)"
	e_check "$tag History does not show the destination folder path" "$(grep -q 'ovb-e2e-dest' <<<"$html" && echo 0 || echo 1)"

	code=$(api_code DELETE "/backups/$id")
	e_check "$tag the backup can be deleted (got $code); the remote copy is not touched yet (retention comes later)" "$([ "$code" = 200 ] && [ -f "$dest_dir"/OpenVault-Backups/*/"$folder"/manifest.json ] && echo 1 || echo 0)"
	e_php '( new OpenVault\Backup\Database\DestinationsRepository() )->delete( '"$dest_id"' );' >/dev/null
	rm -rf "$dest_dir"
}

# Destinations over real HTTP (step 4c): REST CRUD with the REST nonce against the real S3 server of the matrix, a real backup to
# the created destination, the Destinations and Back up now pages, and proof that the secrets never appear anywhere.
e_destinations() {
	local fmt=$1 tag="[$1/destinations-rest]" E_RESP=/tmp/ovb-e2e-responses.txt
	local ep=${OVB_S3_ENDPOINT:-} acc=${OVB_S3_ACCESS:-} sec=${OVB_S3_SECRET:-} wrong="E2eWrongSecret$RANDOM$RANDOM" bucket="ovb-e2e-$(date +%s)"
	local page nb hist code resp id id2 id3 bid folder f n same sjson creds_ok creds_bad before_count remote body hold=/var/www/html/wp-content/mu-plugins/ovb-e2e-zhold.php
	local rdir=/var/www/html/wp-content/ovb-e2e-rest-local lines
	: >"$E_RESP"

	dapi() { local out; out=$(api "$@"); printf '%s\n' "$out" >>"$E_RESP"; printf '%s' "$out"; }
	dcode() {
		local method=$1 route=$2 payload=${3:-} status
		status=$(curl -s -o /tmp/ovb-e2e-last.json -w '%{http_code}' -b "$JAR" -X "$method" -H "X-WP-Nonce: $NONCE" ${payload:+-H "Content-Type: application/json" --data "$payload"} "$RB$route")
		cat /tmp/ovb-e2e-last.json >>"$E_RESP"; echo >>"$E_RESP"
		printf '%s' "$status"
	}
	dcount() { e_php 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ovb_destinations" );'; }
	denvelope() { e_php 'global $wpdb; $raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT credentials FROM {$wpdb->prefix}ovb_destinations WHERE id = %d", '"$1"' ) ); echo ( 0 === strpos( $raw, "ovb1:" ) && false === strpos( $raw, getenv( "OVB_S3_SECRET" ) ) && false === strpos( $raw, getenv( "OVB_S3_ACCESS" ) ) && false === strpos( $raw, "'"$wrong"'" ) ) ? 1 : 0;'; }
	has() { grep -q -- "$1" <<<"$2"; }
	# Settings of the test destination with one key changed: sjson_with <key> <json value>.
	sjson_with() { php -r '$d = json_decode( $argv[1], true ); $d[ $argv[2] ] = json_decode( $argv[3], true ); echo json_encode( $d );' -- "$sjson" "$1" "$2"; }

	e_check "$tag the matrix S3 server is configured (OVB_S3_ENDPOINT/ACCESS/SECRET)" "$([ -n "$ep" ] && [ -n "$acc" ] && [ -n "$sec" ] && echo 1 || echo 0)"
	[ -n "$ep" ] && [ -n "$acc" ] && [ -n "$sec" ] || return
	creds_ok="{\"access_key\":\"$acc\",\"secret_key\":\"$sec\"}"
	creds_bad="{\"access_key\":\"$acc\",\"secret_key\":\"$wrong\"}"
	sjson="{\"preset\":\"custom\",\"endpoint\":\"$ep\",\"addressing\":\"path\",\"allow_insecure_http\":true,\"allow_private_endpoint\":true,\"bucket\":\"$bucket\",\"prefix\":\"e2e-prefix\"}"
	LOCAL_DIR=/var/www/html/wp-content/$(wps option get ovb_local_dir)
	e_check "$tag the harness created a bucket on the S3 server" "$([ "$(wpc eval-file /src/tests/matrix/e2e-s3.php bucket-create "$bucket" 2>/dev/null | tail -1)" = 200 ] && echo 1 || echo 0)"

	# 1. The pages before any destination exists.
	page=$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?page=openvault-destinations"); printf '%s\n' "$page" >>"$E_RESP"
	NONCE=$(php -r 'if ( preg_match( "/ovbDestinations = (\{.*?\});/s", stream_get_contents( STDIN ), $m ) ) { $d = json_decode( $m[1], true ); echo isset( $d["nonce"] ) ? $d["nonce"] : ""; }' <<<"$page")
	e_check "$tag Destinations page carries a REST nonce and loads destinations.js" "$([ -n "$NONCE" ] && has 'destinations.js' "$page" && echo 1 || echo 0)"
	e_check "$tag Destinations page does not load the other page scripts" "$(has 'backup-now.js\|history.js' "$page" && echo 0 || echo 1)"
	n=1; for f in aws r2 b2 wasabi spaces custom; do has "<option value=\"$f\"" "$page" || n=0; done
	e_check "$tag the S3 form offers all six provider presets" "$n"
	e_check "$tag inline help links to the provider documentation" "$(has 'docs.aws.amazon.com' "$page" && has 'developers.cloudflare.com' "$page" && has 'Provider documentation' "$page" && has 'rel="noopener noreferrer"' "$page" && echo 1 || echo 0)"
	e_check "$tag the page says archives are not encrypted yet and explains the private-network risk" "$(has 'not encrypted yet' "$page" && has 'reach internal systems' "$page" && echo 1 || echo 0)"
	e_check "$tag the secret key field is a password field, autocomplete off, never pre-filled" "$(has 'type="password"[^>]*autocomplete="off"[^>]*value=""' "$page" && echo 1 || echo 0)"
	e_check "$tag the page shows the empty state, no Google Drive option and a JavaScript notice" "$(has 'No destinations yet' "$page" && ! grep -qi 'google' <<<"$page" && has 'JavaScript is required' "$page" && echo 1 || echo 0)"
	nb=$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?page=openvault-backup-now"); printf '%s\n' "$nb" >>"$E_RESP"
	e_check "$tag Back up now says a local copy always stays and hints at the Destinations page when none is set up" "$(has 'always stays in the local backup folder' "$nb" && has 'No other destination is set up' "$nb" && has 'page=openvault-destinations' "$nb" && echo 1 || echo 0)"

	# 2. Nonce and capability.
	code=$(curl -s -o /dev/null -w '%{http_code}' "$RB/destinations")
	e_check "$tag anonymous GET /destinations is rejected (got $code)" "$([ "$code" = 401 ] || [ "$code" = 403 ] && echo 1 || echo 0)"
	code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$RB/destinations")
	e_check "$tag GET /destinations without a nonce is rejected (got $code)" "$([ "$code" = 401 ] || [ "$code" = 403 ] && echo 1 || echo 0)"
	code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -X POST -H 'Content-Type: application/json' --data "{\"type\":\"s3\",\"label\":\"x\",\"settings\":$sjson,\"credentials\":$creds_ok}" "$RB/destinations")
	e_check "$tag POST /destinations without a nonce is rejected (got $code)" "$([ "$code" = 401 ] || [ "$code" = 403 ] && echo 1 || echo 0)"
	code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -X POST -H 'X-WP-Nonce: 0123456789' -H 'Content-Type: application/json' --data "{\"type\":\"s3\",\"label\":\"x\",\"settings\":$sjson,\"credentials\":$creds_ok}" "$RB/destinations")
	e_check "$tag POST /destinations with a bad nonce is 403 (got $code)" "$([ "$code" = 403 ] && echo 1 || echo 0)"
	code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' --data "{\"type\":\"s3\",\"label\":\"x\",\"settings\":$sjson,\"credentials\":$creds_ok}" "$RB/destinations")
	e_check "$tag anonymous POST /destinations is rejected (got $code)" "$([ "$code" = 401 ] || [ "$code" = 403 ] && echo 1 || echo 0)"
	code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -X DELETE "$RB/destinations/1")
	e_check "$tag DELETE /destinations/1 without a nonce is rejected (got $code)" "$([ "$code" = 401 ] || [ "$code" = 403 ] && echo 1 || echo 0)"
	e_check "$tag the rejected requests stored nothing" "$([ "$(dcount)" = 0 ] && echo 1 || echo 0)"

	# 3. Hostile payloads: all 400 and nothing stored.
	before_count=$(dcount)
	n=1; lines=""
	for body in \
		"{\"type\":\"s3\",\"label\":\"x\",\"settings\":$(sjson_with evil 1),\"credentials\":$creds_ok}" \
		"{\"type\":\"s3\",\"label\":\"x\",\"settings\":$(sjson_with bucket '"B'"'"'; DROP TABLE wp_options;--"'),\"credentials\":$creds_ok}" \
		"{\"type\":\"s3\",\"label\":\"x\",\"settings\":$(sjson_with prefix '"../../etc"'),\"credentials\":$creds_ok}" \
		"{\"type\":\"s3\",\"label\":\"x\",\"settings\":$(sjson_with prefix '"/abs"'),\"credentials\":$creds_ok}" \
		"{\"type\":\"s3\",\"label\":\"x\",\"settings\":$(sjson_with endpoint '"http://169.254.169.254/"'),\"credentials\":$creds_ok}" \
		"{\"type\":\"s3\",\"label\":\"x\",\"settings\":$(sjson_with endpoint '"http://2130706433/"'),\"credentials\":$creds_ok}" \
		"{\"type\":\"s3\",\"label\":\"x\",\"settings\":$sjson}" \
		"{\"type\":\"s3\",\"label\":\"x\",\"settings\":$sjson,\"credentials\":{\"access_key\":\"$acc\",\"secret_key\":\"$wrong\",\"role\":\"admin\"}}" \
		"{\"type\":\"s3\",\"label\":\"\",\"settings\":$sjson,\"credentials\":$creds_bad}" \
		"{\"type\":\"s3\",\"label\":\"$(printf 'x%.0s' $(seq 1 101))\",\"settings\":$sjson,\"credentials\":$creds_bad}" \
		"{\"type\":\"gdrive\",\"label\":\"x\",\"settings\":{}}" \
		"{\"type\":\"s3\",\"label\":\"x\",\"settings\":\"text\",\"credentials\":$creds_bad}" \
		"{\"type\":\"s3\",\"label\":\"x\",\"settings\":[1,2],\"credentials\":$creds_bad}" \
		"{\"label\":\"x\",\"settings\":$sjson,\"credentials\":$creds_bad}" \
		"{\"type\":\"local\",\"label\":\"x\",\"settings\":{\"path\":\"/etc\"}}" \
		"{\"type\":\"local\",\"label\":\"x\",\"settings\":{\"path\":\"/var/www/html/wp-content/../../etc\"}}" \
		"{\"type\":\"local\",\"label\":\"x\",\"settings\":{\"path\":\"/var/www/html/wp-admin\"}}" \
		"{\"type\":\"local\",\"label\":\"x\",\"settings\":{\"path\":\"/var/www/html\"}}" \
		"{\"type\":\"local\",\"label\":\"x\",\"settings\":{\"path\":\"../relative\"}}" \
		"{\"type\":\"local\",\"label\":\"x\",\"settings\":{\"path\":\"\"},\"credentials\":$creds_bad}"; do
		code=$(dcode POST /destinations "$body")
		lines="$lines $code"
		[ "$code" = 400 ] || n=0
	done
	e_check "$tag 20 hostile create payloads (unknown keys, bad bucket, traversal and absolute prefix, SSRF endpoints, missing or extra credentials, bad label, unknown type, /etc, ../, wp-admin, the WordPress root) are all 400 (got$lines)" "$n"
	e_check "$tag nothing was stored by the hostile payloads" "$([ "$(dcount)" = "$before_count" ] && echo 1 || echo 0)"

	# 4. Create with a wrong secret, test, then replace the keys, test again.
	resp=$(dapi POST /destinations "{\"type\":\"s3\",\"label\":\"E2E S3\",\"settings\":$sjson,\"credentials\":$creds_bad}")
	id=$(jg id <<<"$resp")
	e_check "$tag POST /destinations creates an s3 destination (id=$id) and the response has no credentials" "$([[ "$id" =~ ^[1-9][0-9]*$ ]] && ! has '"credentials"' "$resp" && ! has "$wrong" "$resp" && ! has "$acc" "$resp" && [ "$(jg has_credentials <<<"$resp")" = true ] && [ "$(jg status <<<"$resp")" = new ] && echo 1 || echo 0)" "$resp"
	[[ "$id" =~ ^[1-9][0-9]*$ ]] || return
	e_check "$tag the raw credentials column is an ovb1: envelope with neither key inside" "$(denvelope "$id")"
	resp=$(dapi GET /destinations)
	e_check "$tag GET /destinations shows the destination without credentials" "$([ "$(php -r '$d=json_decode($argv[1],true); echo ( is_array($d) && count($d)===1 && $d[0]["label"]==="E2E S3" && $d[0]["preset"]==="custom" && !array_key_exists("credentials",$d[0]) && strpos($argv[1],"ovb1:")===false ) ? 1 : 0;' -- "$resp")" = 1 ] && ! has "$wrong" "$resp" && ! has "$acc" "$resp" && echo 1 || echo 0)" "$resp"
	resp=$(dapi POST "/destinations/$id/test")
	e_check "$tag a wrong secret fails the test with a message that does not contain the secret (status $(jg destination status <<<"$resp"))" "$([ "$(jg ok <<<"$resp")" = false ] && [ -n "$(jg message <<<"$resp")" ] && [ "$(jg destination status <<<"$resp")" = error ] && ! has "$wrong" "$resp" && ! has "$sec" "$resp" && echo 1 || echo 0)" "$resp"
	resp=$(dapi POST "/destinations/$id" "{\"credentials\":$creds_ok}")
	e_check "$tag replacing the keys resets the status to new and returns no secret" "$([ "$(jg status <<<"$resp")" = new ] && ! has "$sec" "$resp" && [ "$(denvelope "$id")" = 1 ] && echo 1 || echo 0)" "$resp"
	resp=$(dapi POST "/destinations/$id/test")
	e_check "$tag the right secret passes the test and the status becomes connected" "$([ "$(jg ok <<<"$resp")" = true ] && [ "$(jg destination status <<<"$resp")" = connected ] && echo 1 || echo 0)" "$resp"
	resp=$(dapi POST "/destinations/$id" '{"label":"E2E S3 renamed"}')
	e_check "$tag updating only the label keeps the credentials" "$([ "$(jg label <<<"$resp")" = "E2E S3 renamed" ] && [ "$(jg has_credentials <<<"$resp")" = true ] && echo 1 || echo 0)" "$resp"
	resp=$(dapi POST "/destinations/$id/test")
	e_check "$tag the test still passes after the label-only update" "$([ "$(jg ok <<<"$resp")" = true ] && echo 1 || echo 0)" "$resp"
	code=$(dcode POST "/destinations/$id" "{\"settings\":$(sjson_with endpoint '"http://169.254.169.254/"')}")
	e_check "$tag updating the settings with the metadata address is 400 (got $code)" "$([ "$code" = 400 ] && echo 1 || echo 0)"
	resp=$(dapi POST "/destinations/$id/test")
	e_check "$tag the refused update changed nothing (test still ok)" "$([ "$(jg ok <<<"$resp")" = true ] && echo 1 || echo 0)" "$resp"
	code=$(dcode POST /destinations/999999/test)
	e_check "$tag testing an unknown destination is 404 (got $code)" "$([ "$code" = 404 ] && echo 1 || echo 0)"

	# 5. The pages with a destination.
	page=$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?page=openvault-destinations"); printf '%s\n' "$page" >>"$E_RESP"
	e_check "$tag the Destinations page lists the card with its status as text and the details line" "$(has 'E2E S3 renamed' "$page" && has 'Connected' "$page" && has "Bucket $bucket" "$page" && has 'Test connection' "$page" && echo 1 || echo 0)"
	nb=$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?page=openvault-backup-now"); printf '%s\n' "$nb" >>"$E_RESP"
	e_check "$tag Back up now lists the destination as an enabled checkbox with its status" "$(has "data-ovb-destination=\"$id\"" "$nb" && ! has "data-ovb-destination=\"$id\"[^>]*disabled" "$nb" && has 'E2E S3 renamed' "$nb" && echo 1 || echo 0)"

	# 6. A real backup to it. The job is held queued first, so deleting the destination meets a backup that uses it.
	mkdir -p /var/www/html/wp-content/mu-plugins
	cat >"$hold" <<'PHP'
<?php
if ( ! defined( 'DISABLE_WP_CRON' ) ) { define( 'DISABLE_WP_CRON', true ); }
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	return ( false !== strpos( $url, 'wp-cron.php' ) || false !== strpos( $url, 'as_async_request_queue_runner' ) ) ? new WP_Error( 'ovb_e2e_blocked', 'blocked' ) : $pre;
}, 10, 3 );
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
add_filter( 'ovb_kick_after_seconds', function () { return 3600; }, 99 );
PHP
	chown www-data:www-data "$hold"
	resp=$(dapi POST /backups "{\"contents\":{\"db\":true,\"plugins\":false,\"themes\":false,\"uploads\":false,\"mu_plugins\":false,\"other_content\":false,\"core\":false,\"wp_config\":false},\"format\":\"$fmt\",\"destinations\":[$id],\"note\":\"e2e destinations rest\"}")
	bid=$(jg id <<<"$resp")
	e_check "$tag a backup with the destination selected is started (id=$bid)" "$([[ "$bid" =~ ^[1-9][0-9]*$ ]] && echo 1 || echo 0)" "$resp"
	if ! [[ "$bid" =~ ^[1-9][0-9]*$ ]]; then rm -f "$hold"; return; fi
	code=$(dcode DELETE "/destinations/$id")
	e_check "$tag deleting the destination while a backup that uses it is queued or running is 409 (got $code)" "$([ "$code" = 409 ] && [ "$(dcount)" = 1 ] && echo 1 || echo 0)"
	rm -f "$hold"
	touch /tmp/ovb-nudge; ( while [ -f /tmp/ovb-nudge ]; do curl -s -o /dev/null "$URL/wp-cron.php?doing_wp_cron"; sleep 2; done ) &
	e_poll "$bid" 240
	rm -f /tmp/ovb-nudge
	printf '%s\n' "$E_LAST" >>"$E_RESP"
	e_check "$tag the backup finished complete (statuses:$E_STATUSES)" "$([ "$(jg status <<<"$E_LAST")" = complete ] && echo 1 || echo 0)" "$(jg error <<<"$E_LAST")"
	e_check "$tag the progress payload reports the destination by label as done with all files" "$(php -r '$d=json_decode($argv[1],true); $x=$d["destinations"]; echo ( count($x)===1 && $x[0]["id"]==(int)$argv[2] && $x[0]["status"]==="done" && $x[0]["label"]==="E2E S3 renamed" && $x[0]["files_done"]===$x[0]["files_total"] && $x[0]["files_total"]>=2 ) ? 1 : 0;' -- "$E_LAST" "$id")" "$E_LAST"
	folder=$(e_php '$r = ( new OpenVault\Backup\Database\BackupsRepository() )->find( '"$bid"' ); echo OpenVault\Backup\Support\Paths::build_backup_folder_name( $r["created_at"], $r["uuid"] );')
	remote=$(wpc eval-file /src/tests/matrix/e2e-s3.php list "$bucket" e2e-prefix/ 2>/dev/null)
	e_check "$tag the objects exist remotely below the prefix, including manifest.json" "$(has "e2e-prefix/OpenVault-Backups/.*/$folder/manifest.json" "$remote" && echo 1 || echo 0)" "$remote"
	same=1; n=0
	for f in "$LOCAL_DIR/backups/$folder"/*.zip "$LOCAL_DIR/backups/$folder"/*.tar.gz "$LOCAL_DIR/backups/$folder"/manifest.json; do
		[ -f "$f" ] || continue
		n=$((n + 1))
		has "^$(stat -c %s "$f") e2e-prefix/OpenVault-Backups/.*/$folder/$(basename "$f")\$" "$remote" || same=0
	done
	e_check "$tag $n remote objects have exactly the size of the local files" "$([ "$same" = 1 ] && [ "$n" -ge 2 ] && echo 1 || echo 0)" "$remote"
	hist=$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?page=openvault-backups"); printf '%s\n' "$hist" >>"$E_RESP"
	e_check "$tag History shows the destination LABEL and its status as text" "$(has 'E2E S3 renamed: Uploaded' "$hist" && echo 1 || echo 0)"

	# 7. needs_reconnect: unreadable credentials give a clear answer, the pages offer the fix, new keys repair it.
	resp=$(dapi POST /destinations "{\"type\":\"s3\",\"label\":\"E2E reconnect\",\"settings\":$sjson,\"credentials\":$creds_ok}")
	id2=$(jg id <<<"$resp")
	e_php 'global $wpdb; $wpdb->update( $wpdb->prefix . "ovb_destinations", array( "credentials" => "ovb1:garbage-envelope" ), array( "id" => '"${id2:-0}"' ) );' >/dev/null
	resp=$(dapi POST "/destinations/$id2/test")
	e_check "$tag unreadable credentials: ok=false, needs_reconnect and an actionable message" "$([ "$(jg ok <<<"$resp")" = false ] && [ "$(jg destination status <<<"$resp")" = needs_reconnect ] && has 'Enter the keys again' "$resp" && echo 1 || echo 0)" "$resp"
	page=$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?page=openvault-destinations"); printf '%s\n' "$page" >>"$E_RESP"
	nb=$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?page=openvault-backup-now"); printf '%s\n' "$nb" >>"$E_RESP"
	e_check "$tag the card says the saved credentials can no longer be read and offers 'Enter keys again'" "$(has 'can no longer be read' "$page" && has 'Enter keys again' "$page" && has 'Needs reconnect' "$page" && echo 1 || echo 0)"
	e_check "$tag Back up now disables a needs_reconnect destination and says why" "$(has "data-ovb-destination=\"$id2\"[^>]*disabled" "$nb" && has 'Enter the keys again on the Destinations page' "$nb" && echo 1 || echo 0)"
	code=$(dcode POST /backups "{\"format\":\"$fmt\",\"destinations\":[$id2]}")
	e_check "$tag starting a backup with a needs_reconnect destination is refused 400 (got $code)" "$([ "$code" = 400 ] && echo 1 || echo 0)"
	resp=$(dapi POST "/destinations/$id2" "{\"credentials\":$creds_ok}")
	e_check "$tag entering the keys again repairs it (status new)" "$([ "$(jg status <<<"$resp")" = new ] && [ "$(denvelope "$id2")" = 1 ] && echo 1 || echo 0)" "$resp"
	resp=$(dapi POST "/destinations/$id2/test")
	e_check "$tag and the repaired destination connects" "$([ "$(jg ok <<<"$resp")" = true ] && echo 1 || echo 0)" "$resp"
	code=$(dcode DELETE "/destinations/$id2")
	e_check "$tag the second destination is removed (got $code)" "$([ "$code" = 200 ] && echo 1 || echo 0)"

	# 8. Local destination over REST.
	resp=$(dapi POST /destinations "{\"type\":\"local\",\"label\":\"E2E local\",\"settings\":{\"path\":\"$rdir\"}}")
	id3=$(jg id <<<"$resp")
	e_check "$tag a local destination with a custom folder is created (id=$id3)" "$([[ "$id3" =~ ^[1-9][0-9]*$ ]] && [ "$(jg has_credentials <<<"$resp")" = false ] && echo 1 || echo 0)" "$resp"
	if [[ "$id3" =~ ^[1-9][0-9]*$ ]]; then
		resp=$(dapi POST "/destinations/$id3/test")
		e_check "$tag the local folder test passes" "$([ "$(jg ok <<<"$resp")" = true ] && [ -d "$rdir" ] && echo 1 || echo 0)" "$resp"
		code=$(dcode POST "/destinations/$id3" '{"settings":{"path":"/var/www/html/wp-includes"}}')
		e_check "$tag changing a local folder to wp-includes is 400 (got $code)" "$([ "$code" = 400 ] && echo 1 || echo 0)"
		code=$(dcode POST "/destinations/$id3" "{\"credentials\":$creds_ok}")
		e_check "$tag a local destination does not accept credentials (got $code)" "$([ "$code" = 400 ] && echo 1 || echo 0)"
		code=$(dcode DELETE "/destinations/$id3")
		e_check "$tag the local destination is removed (got $code)" "$([ "$code" = 200 ] && echo 1 || echo 0)"
	fi
	rm -rf "$rdir"

	# 9. Delete after the backup is complete: the row is gone, the remote objects stay.
	code=$(dcode DELETE "/destinations/$id")
	e_check "$tag DELETE after the backup completed is 200 (got $code)" "$([ "$code" = 200 ] && echo 1 || echo 0)"
	e_check "$tag the destination row (and with it the credentials) is gone" "$([ "$(dcount)" = 0 ] && echo 1 || echo 0)"
	resp=$(dapi GET /destinations)
	e_check "$tag GET /destinations is empty again" "$([ "$resp" = "[]" ] && echo 1 || echo 0)" "$resp"
	code=$(dcode DELETE "/destinations/$id")
	e_check "$tag deleting it again is 404 (got $code)" "$([ "$code" = 404 ] && echo 1 || echo 0)"
	remote=$(wpc eval-file /src/tests/matrix/e2e-s3.php list "$bucket" e2e-prefix/ 2>/dev/null)
	e_check "$tag removing a destination does not delete the remote objects" "$(has 'manifest.json' "$remote" && echo 1 || echo 0)"
	hist=$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?page=openvault-backups"); printf '%s\n' "$hist" >>"$E_RESP"
	e_check "$tag History still lists the upload with a fallback name once the destination is gone" "$(has "Destination $id: Uploaded" "$hist" && echo 1 || echo 0)"
	code=$(api_code DELETE "/backups/$bid")
	e_check "$tag the backup can be deleted (got $code)" "$([ "$code" = 200 ] && echo 1 || echo 0)"

	# 10. The secrets are nowhere: not in any response or page body, the PHP and Apache logs, or the plugin log table.
	n=1; for f in "$sec" "$wrong"; do grep -qF -- "$f" "$E_RESP" && n=0; done
	e_check "$tag neither secret appears in any REST response or page body seen in this test" "$n"
	n=1; for f in "$sec" "$wrong"; do grep -qF -- "$f" /var/www/html/wp-content/debug.log /tmp/apache.log 2>/dev/null && n=0; done
	e_check "$tag neither secret appears in debug.log or the Apache log" "$n"
	e_check "$tag neither secret appears in the plugin log table" "$([ "$(e_php 'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ovb_logs WHERE message LIKE %s OR context LIKE %s OR message LIKE %s OR context LIKE %s", "%" . getenv( "OVB_S3_SECRET" ) . "%", "%" . getenv( "OVB_S3_SECRET" ) . "%", "%'"$wrong"'%", "%'"$wrong"'%" ) );')" = 0 ] && echo 1 || echo 0)"

	wpc eval-file /src/tests/matrix/e2e-s3.php bucket-delete "$bucket" >/dev/null 2>&1
	rm -f "$E_RESP" /tmp/ovb-e2e-last.json
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
for fmt in zip tar.gz; do
	if [ "$(e_php 'echo ( new OpenVault\Backup\Archive\CapabilityDetector() )->is_available( "'"$fmt"'" ) ? "1" : "0";')" = 1 ]; then
		e_destination "$fmt"
		e_destinations "$fmt"
		break
	fi
done

# Maintenance mode of a database restore over REAL HTTP: the flag file is written the way the restore does it.
e_maintenance() {
	local tag="[maintenance]" hdr=/tmp/ovb-mm-hdr
	e_php '( new OpenVault\Backup\Restore\MaintenanceMode() )->enable( 99 );'
	code=$(curl -s -D "$hdr" -o /tmp/ovb-mm-body -w "%{http_code}" "$URL/")
	e_check "$tag the front page answers 503 while the flag is on" "$([ "$code" = 503 ] && echo 1 || echo 0)" "$code"
	e_check "$tag with Retry-After: 120 and a branded page" "$(grep -qi '^retry-after: 120' "$hdr" && grep -q 'OpenVault Backup' /tmp/ovb-mm-body && echo 1 || echo 0)"
	code=$(curl -s -o /dev/null -w '%{http_code}' "$URL/?p=1&x=/wp-json/ovb/v1/")
	e_check "$tag a path in a query value does not open the site" "$([ "$code" = 503 ] && echo 1 || echo 0)" "$code"
	code=$(curl -s -o /dev/null -w '%{http_code}' "$URL/index.php?rest_route=/wp/v2/posts")
	e_check "$tag other REST routes are blocked" "$([ "$code" = 503 ] && echo 1 || echo 0)" "$code"
	code=$(curl -s -o /dev/null -w '%{http_code}' "$URL/index.php?rest_route=/ovb/v1/backups")
	e_check "$tag the plugin REST namespace still answers (401 without a login, not 503)" "$([ "$code" = 401 ] && echo 1 || echo 0)" "$code"
	code=$(curl -s -o /dev/null -w '%{http_code}' "$URL/wp-login.php")
	e_check "$tag the login page still works" "$([ "$code" = 200 ] && echo 1 || echo 0)" "$code"
	code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$URL/wp-admin/admin.php?page=openvault-system")
	e_check "$tag an administrator still reaches the admin area" "$([ "$code" = 200 ] && echo 1 || echo 0)" "$code"
	code=$(curl -s -o /dev/null -w '%{http_code}' "$URL/wp-admin/admin-ajax.php?action=nopriv_other")
	e_check "$tag other ajax actions of anonymous visitors are blocked" "$([ "$code" = 503 ] && echo 1 || echo 0)" "$code"
	code=$(curl -s -o /dev/null -w '%{http_code}' "$URL/wp-cron.php?doing_wp_cron")
	e_check "$tag wp-cron.php runs (only the queue runner event)" "$([ "$code" = 200 ] && echo 1 || echo 0)" "$code"
	e_php '( new OpenVault\Backup\Restore\MaintenanceMode() )->disable();'
	code=$(curl -s -o /dev/null -w '%{http_code}' "$URL/")
	e_check "$tag the front page is back after the flag is removed" "$([ "$code" = 200 ] && echo 1 || echo 0)" "$code"
	e_php '$p = OpenVault\Backup\Restore\MaintenanceMode::flag_path(); file_put_contents( $p, json_encode( array( "restore_id" => 99, "started" => time() - 4000, "expires" => time() - 2000 ) ) );'
	code=$(curl -s -o /dev/null -w '%{http_code}' "$URL/")
	e_check "$tag an expired flag fails open" "$([ "$code" = 200 ] && echo 1 || echo 0)" "$code"
	e_php '( new OpenVault\Backup\Restore\MaintenanceMode() )->disable();'
	rm -f "$hdr" /tmp/ovb-mm-body
}
step "Maintenance mode over HTTP"
e_maintenance
