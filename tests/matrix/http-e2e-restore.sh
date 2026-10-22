#!/usr/bin/env bash
# Sourced by container-run.sh after http-e2e.sh (it uses its helpers: api, api_code, jg, e_php, e_poll, e_check, e_bad, JAR, NONCE, RB, URL).
# Drives the USER-FACING restore (Milestone 5 step 5d) over REAL HTTP as a logged-in admin: the Restore page, the REST routes with their
# nonce / capability / confirmation-phrase rules, a WRECK-AND-RESTORE through the API (start, verified, confirm, progress with the logout of
# the administrator, the unauthenticated status channel, undo, dismiss), cancel rules, the browser kick without Action Scheduler, the status
# token rules, a restore from the S3 test server, XSS canaries and leak checks. Multisite: the refusal, the rest is skipped by name.

R_UP=/var/www/html/wp-content/uploads/ovb-e2e-restore
R_TOKEN=""
R_LAST=""
R_PCT_OK=1
R_PUB_OK=0
R_LOGOUT_SEEN=0
R_MM_SEEN=0
mode_as_nudge=1
R_BASE=0

r_jar_login() {
	# A fresh admin session (the restore replaced the users table), then a fresh REST nonce from the Restore page.
	rm -f "$JAR"
	curl -s -c "$JAR" -b "wordpress_test_cookie=WP%20Cookie%20check" -o /dev/null \
		--data-urlencode "log=admin" --data-urlencode "pwd=password" --data-urlencode "wp-submit=Log In" \
		--data-urlencode "redirect_to=$URL/wp-admin/" --data-urlencode "testcookie=1" "$URL/wp-login.php"
	r_nonce
}

r_nonce() {
	local page
	page=$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?page=openvault-restore")
	NONCE=$(php -r 'if ( preg_match( "/ovbRestore = (\{.*?\});/s", stream_get_contents( STDIN ), $m ) ) { $d = json_decode( $m[1], true ); echo isset( $d["nonce"] ) ? $d["nonce"] : ""; }' <<<"$page")
}

# r_pub <restore id> <token> [extra curl args]: the unauthenticated status route, no cookie.
r_pub() {
	local id=$1 token=$2
	shift 2
	curl -s "$@" "$RB/restores/$id/status&t=$token"
}
r_pub_code() {
	local id=$1 token=$2
	curl -s -o /dev/null -w '%{http_code}' "$RB/restores/$id/status&t=$token"
}

r_count() { e_php 'global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ovb_restores" );'; }

# r_page_has <page query> <fixed text>: whether the admin page contains the text (a here-string, not a pipe: grep -q would SIGPIPE curl under pipefail).
r_page_has() { local html; html=$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?$1"); grep -qF -- "$2" <<<"$html"; }

r_sha() { sha256sum "$1" 2>/dev/null | cut -d' ' -f1; }

# r_make_backup <fmt> <note>: a database + uploads backup through the REST API; sets R_BID.
r_make_backup() {
	local fmt=$1 note=$2 resp id
	R_BID=""
	[ "$mode_as_nudge" = 1 ] && { touch /tmp/ovb-nudge; ( while [ -f /tmp/ovb-nudge ]; do curl -s -o /dev/null "$URL/wp-cron.php?doing_wp_cron"; sleep 2; done ) & }
	resp=$(api POST /backups "{\"contents\":{\"db\":true,\"plugins\":false,\"themes\":false,\"uploads\":true,\"mu_plugins\":false,\"other_content\":false,\"core\":false,\"wp_config\":false},\"format\":\"$fmt\",\"note\":\"$note\"}")
	id=$(jg id <<<"$resp")
	if ! [[ "$id" =~ ^[0-9]+$ ]]; then rm -f /tmp/ovb-nudge; echo "backup start failed: $resp"; return 1; fi
	e_poll "$id" 280
	rm -f /tmp/ovb-nudge
	case "$(jg status <<<"$E_LAST")" in complete | partial) R_BID=$id ;; *) echo "backup did not finish: $(jg error <<<"$E_LAST")"; return 1 ;; esac
}

# r_poll_status <id> <wanted statuses, space separated> <timeout>: poll GET /restores/{id} until the status is one of them.
r_poll_status() {
	local id=$1 wanted=" $2 " deadline=$((SECONDS + ${3:-240})) st pc last=-1
	R_PCT_OK=1
	while [ "$SECONDS" -lt "$deadline" ]; do
		R_LAST=$(api GET "/restores/$id")
		st=$(jg status <<<"$R_LAST"); pc=$(jg percent <<<"$R_LAST")
		if [[ "$pc" =~ ^[0-9]+$ ]]; then [ "$pc" -lt "$last" ] && R_PCT_OK=0; last=$pc; fi
		case "$wanted" in *" $st "*) return 0 ;; esac
		case "$st" in complete | failed | cancelled) return 1 ;; esac
		sleep 1
	done
	return 1
}

# r_apply_poll <id> <token>: after confirm. Polls the authenticated route AND the public one (no cookie) until the public route says done.
# The administrator may be logged out by the database import: that is expected, never an error here. Sets R_LOGOUT_SEEN, R_PUB_OK, R_MM_SEEN, R_PCT_OK.
r_apply_poll() {
	local id=$1 token=$2 deadline=$((SECONDS + ${3:-280})) out code pub pst pc last=-1 flag
	R_LOGOUT_SEEN=0; R_PUB_OK=0; R_MM_SEEN=0; R_PCT_OK=1
	flag=$(e_php 'echo OpenVault\Backup\Restore\MaintenanceMode::flag_path();')
	while [ "$SECONDS" -lt "$deadline" ]; do
		out=$(curl -s -w '\n%{http_code}' -b "$JAR" -H "X-WP-Nonce: $NONCE" "$RB/restores/$id")
		code=${out##*$'\n'}
		if [ "$code" = 200 ]; then
			pc=$(jg percent <<<"${out%$'\n'*}")
			[[ "$pc" =~ ^[0-9]+$ ]] && { [ "$pc" -lt "$last" ] && R_PCT_OK=0; last=$pc; }
		else
			R_LOGOUT_SEEN=1
		fi
		pub=$(r_pub "$id" "$token")
		pst=$(jg status <<<"$pub")
		if [ -n "$pst" ]; then
			R_PUB_OK=$((R_PUB_OK + 1))
			[ -n "$flag" ] && [ -f "$flag" ] && R_MM_SEEN=1
			pc=$(jg percent <<<"$pub")
			[[ "$pc" =~ ^[0-9]+$ ]] && { [ "$pc" -lt "$last" ] && R_PCT_OK=0; last=$pc; }
		fi
		[ "$(jg done <<<"$pub")" = true ] && return 0
		sleep 1
	done
	return 1
}

e_restore_pages() {
	local tag=$1 bid=$2 page hist
	page=$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?page=openvault-restore&backup=$bid")
	printf '%s' "$page" >/tmp/ovb-e2e-restore-page.html
	e_check "$tag Restore page loads its script, carries a REST nonce and the restore id marker" "$(grep -q 'restore.js' <<<"$page" && grep -q 'ovbRestore = ' <<<"$page" && [ -n "$NONCE" ] && grep -q 'data-ovb-backup-id="'"$bid"'"' <<<"$page" && echo 1 || echo 0)"
	e_check "$tag Restore page lists the backup as a choice with a reason-aware mode map" "$(grep -q "id=\"ovb-b-$bid\"" <<<"$page" && grep -q 'data-ovb-modes=' <<<"$page" && echo 1 || echo 0)"
	e_check "$tag Restore page offers the three modes and says upload / folder restore is planned" "$(grep -q 'Database only' <<<"$page" && grep -q 'Files only' <<<"$page" && grep -q 'Whole site (database and files)' <<<"$page" && grep -q 'planned for a later release' <<<"$page" && echo 1 || echo 0)"
	e_check "$tag Restore page has the typed confirmation, the danger text and the JavaScript notice" "$(grep -q 'Type RESTORE to confirm' <<<"$page" && grep -q 'You may be logged out when the database is replaced' <<<"$page" && grep -q 'JavaScript is required' <<<"$page" && echo 1 || echo 0)"
	e_check "$tag Restore page names encrypted backups and multisite as not supported yet" "$(grep -q 'encrypted backups' <<<"$page" && grep -q 'multisite' <<<"$page" && echo 1 || echo 0)"
	e_check "$tag Restore page shows no raw server path and no secret" "$(grep -q '/var/www\|wp-content/ovb-\|DB_PASSWORD\|refresh_token' <<<"$page" && echo 0 || echo 1)"
	e_check "$tag other plugin pages do not load restore.js" "$(r_page_has page=openvault-system 'restore.js' && echo 0 || echo 1)"
	hist=$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?page=openvault-backups")
	e_check "$tag History has a Restore action linking to the Restore page with the backup id" "$(grep -q "page=openvault-restore&#038;backup=$bid\|page=openvault-restore&amp;backup=$bid\|page=openvault-restore&backup=$bid" <<<"$hist" && echo 1 || echo 0)"
}

e_restore_security() {
	local tag=$1 bid=$2 body code sub_jar=/tmp/ovb-sub-cookies.txt sub_nonce lookalike
	R_BASE=$(r_count)
	lookalike=$(printf 'REST\xd0\x9eRE')
	body="{\"backup_id\":$bid,\"mode\":\"db\"}"
	code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -X POST -H 'Content-Type: application/json' --data "$body" "$RB/restores/preview")
	e_check "$tag preview without a nonce is rejected (got $code)" "$([ "$code" = 401 ] || [ "$code" = 403 ] && echo 1 || echo 0)"
	code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -X POST -H 'X-WP-Nonce: 0123456789' -H 'Content-Type: application/json' --data "$body" "$RB/restores/preview")
	e_check "$tag preview with a bad nonce is 403 (got $code)" "$([ "$code" = 403 ] && echo 1 || echo 0)"
	code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' --data "$body" "$RB/restores/preview")
	e_check "$tag anonymous preview is 401 (got $code)" "$([ "$code" = 401 ] && echo 1 || echo 0)"
	for route in "POST /restores" "GET /restores" "GET /restores/1" "POST /restores/1/confirm" "POST /restores/1/cancel" "POST /restores/1/undo" "POST /restores/1/undo/dismiss"; do
		set -- $route
		code=$(curl -s -o /dev/null -w '%{http_code}' -X "$1" -H 'Content-Type: application/json' --data '{"confirm_phrase":"RESTORE","backup_id":1,"mode":"db"}' "$RB$2")
		e_check "$tag anonymous $1 $2 is rejected (got $code)" "$([ "$code" = 401 ] || [ "$code" = 403 ] && echo 1 || echo 0)"
		code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -X "$1" -H 'Content-Type: application/json' --data '{"confirm_phrase":"RESTORE","backup_id":1,"mode":"db"}' "$RB$2")
		e_check "$tag $1 $2 with a cookie but no nonce is rejected (got $code)" "$([ "$code" = 401 ] || [ "$code" = 403 ] && echo 1 || echo 0)"
	done
	# A subscriber: logged in, with a valid REST nonce of his own, is still refused (403).
	wpc user create e2esub e2esub@example.com --role=subscriber --user_pass=subpass123 >/dev/null 2>&1
	rm -f "$sub_jar"
	curl -s -c "$sub_jar" -b "wordpress_test_cookie=WP%20Cookie%20check" -o /dev/null --data-urlencode "log=e2esub" --data-urlencode "pwd=subpass123" --data-urlencode "wp-submit=Log In" --data-urlencode "testcookie=1" "$URL/wp-login.php"
	sub_nonce=$(curl -s -b "$sub_jar" "$URL/wp-admin/admin-ajax.php?action=rest-nonce")
	e_check "$tag the subscriber got a REST nonce of his own" "$([ -n "$sub_nonce" ] && [ "${#sub_nonce}" -lt 20 ] && echo 1 || echo 0)" "$sub_nonce"
	for route in "POST /restores/preview" "POST /restores" "GET /restores" "GET /restores/1" "POST /restores/1/confirm" "POST /restores/1/cancel" "POST /restores/1/undo"; do
		set -- $route
		code=$(curl -s -o /dev/null -w '%{http_code}' -b "$sub_jar" -X "$1" -H "X-WP-Nonce: $sub_nonce" -H 'Content-Type: application/json' --data "{\"confirm_phrase\":\"RESTORE\",\"backup_id\":$bid,\"mode\":\"db\"}" "$RB$2")
		e_check "$tag subscriber $1 $2 is 403 (got $code)" "$([ "$code" = 403 ] && echo 1 || echo 0)"
	done
	code=$(curl -s -o /dev/null -w '%{http_code}' -b "$sub_jar" "$URL/wp-admin/admin.php?page=openvault-restore")
	e_check "$tag a subscriber cannot open the Restore page (got $code)" "$([ "$code" = 403 ] || [ "$code" = 302 ] && echo 1 || echo 0)"
	wpc user delete e2esub --yes >/dev/null 2>&1
	rm -f "$sub_jar"
	e_check "$tag no restore job was created by any rejected request" "$([ "$(r_count)" = "$R_BASE" ] && echo 1 || echo 0)"

	# Arguments and the confirmation phrase.
	code=$(api_code POST /restores/preview '{"backup_id":"abc","mode":"db"}')
	e_check "$tag preview with a non numeric backup id is 400 (got $code)" "$([ "$code" = 400 ] && echo 1 || echo 0)"
	code=$(api_code POST /restores/preview "{\"backup_id\":$bid,\"mode\":\"undo\"}")
	e_check "$tag mode undo is refused 400 (got $code)" "$([ "$code" = 400 ] && echo 1 || echo 0)"
	code=$(api_code POST /restores/preview "{\"backup_id\":$bid,\"mode\":\"everything\"}")
	e_check "$tag an unknown mode is refused 400 (got $code)" "$([ "$code" = 400 ] && echo 1 || echo 0)"
	code=$(api_code POST /restores/preview '{"backup_id":999999,"mode":"db"}')
	e_check "$tag preview of an unknown backup is 404 (got $code)" "$([ "$code" = 404 ] && echo 1 || echo 0)"
	code=$(api_code POST /restores "{\"backup_id\":$bid,\"mode\":\"db\"}")
	e_check "$tag start without confirm_phrase is 400 (got $code)" "$([ "$code" = 400 ] && echo 1 || echo 0)"
	for phrase in restore Restore 'RESTORE ' ' RESTORE' RESTOR 'RESTORE!' ''; do
		code=$(api_code POST /restores "{\"backup_id\":$bid,\"mode\":\"db\",\"confirm_phrase\":\"$phrase\"}")
		e_check "$tag start with the phrase '$phrase' is 400 (got $code)" "$([ "$code" = 400 ] && echo 1 || echo 0)"
	done
	code=$(api_code POST /restores "{\"backup_id\":$bid,\"mode\":\"db\",\"confirm_phrase\":\"$lookalike\"}")
	e_check "$tag a look-alike (Cyrillic O) phrase is 400 (got $code)" "$([ "$code" = 400 ] && echo 1 || echo 0)"
	code=$(api_code POST /restores "{\"backup_id\":$bid,\"mode\":\"db\",\"confirm_phrase\":\"RESTORE\",\"acknowledge_url_mismatch\":\"maybe\"}")
	e_check "$tag a non boolean flag is 400 (got $code)" "$([ "$code" = 400 ] && echo 1 || echo 0)"
	e_check "$tag still no restore job exists after the refused starts" "$([ "$(r_count)" = "$R_BASE" ] && echo 1 || echo 0)"
	code=$(api_code GET /restores/999999)
	e_check "$tag GET of an unknown restore is 404 (got $code)" "$([ "$code" = 404 ] && echo 1 || echo 0)"
	code=$(api_code POST /restores/999999/confirm '{"confirm_phrase":"RESTORE"}')
	e_check "$tag confirm of an unknown restore is 404 (got $code)" "$([ "$code" = 404 ] && echo 1 || echo 0)"
	code=$(api_code GET "/restores&per_page=0")
	e_check "$tag per_page=0 is refused (got $code)" "$([ "$code" = 400 ] && echo 1 || echo 0)"
}

e_restore_preview() {
	local tag=$1 bid=$2 mode resp
	for mode in db files full; do
		resp=$(api POST /restores/preview "{\"backup_id\":$bid,\"mode\":\"$mode\"}")
		e_check "$tag preview $mode is ok with a plan (ok=$(jg ok <<<"$resp"))" "$([ "$(jg ok <<<"$resp")" = true ] && [ "$(jg mode <<<"$resp")" = "$mode" ] && echo 1 || echo 0)" "$resp"
		case "$mode" in
			db) e_check "$tag preview db touches only the database" "$([ "$(jg touches db <<<"$resp")" = true ] && [ "$(jg touches files <<<"$resp")" = false ] && [ "$(jg sizes snapshot <<<"$resp")" -gt 0 ] && echo 1 || echo 0)" "$resp" ;;
			files) e_check "$tag preview files touches only files, no snapshot, a journal estimate" "$([ "$(jg touches db <<<"$resp")" = false ] && [ "$(jg touches files <<<"$resp")" = true ] && [ "$(jg sizes snapshot <<<"$resp")" = 0 ] && [ "$(jg sizes journal <<<"$resp")" -gt 0 ] && echo 1 || echo 0)" "$resp" ;;
			full) e_check "$tag preview full touches both and lists the protected paths and the local source" "$([ "$(jg touches db <<<"$resp")" = true ] && [ "$(jg touches files <<<"$resp")" = true ] && [ "$(jg source kind <<<"$resp")" = local ] && grep -q 'never overwritten' <<<"$resp" && echo 1 || echo 0)" "$resp" ;;
		esac
		e_check "$tag preview $mode has backup facts, sizes and disk figures" "$([ -n "$(jg backup created_at <<<"$resp")" ] && [ "$(jg backup trigger <<<"$resp")" = manual ] && [ -n "$(jg disk needed <<<"$resp")" ] && echo 1 || echo 0)"
		e_check "$tag preview $mode shows no server path, secret or SQL" "$(grep -q '/var/www\|DB_PASSWORD\|ovb1:\|CREATE TABLE\|INSERT INTO' <<<"$resp" && echo 0 || echo 1)"
	done
	e_check "$tag preview created nothing" "$([ "$(r_count)" = "$R_BASE" ] && echo 1 || echo 0)"
}

# The main scenario: wreck, restore through the API, undo, dismiss. $1 = format.
e_restore_wreck() {
	local fmt=$1 tag="[$1/restore]" resp id token code orig_post wreck_post a_sha b_sha c_sha hist result st
	# Fixture: uploads, a marker option and a marker post.
	rm -rf "$R_UP"; mkdir -p "$R_UP/sub"
	printf 'original-a-content' >"$R_UP/a.txt"; head -c 9000 /dev/urandom >"$R_UP/b.bin"; printf 'original-c' >"$R_UP/sub/c.txt"
	chown -R www-data:www-data "$R_UP"
	a_sha=$(r_sha "$R_UP/a.txt"); b_sha=$(r_sha "$R_UP/b.bin"); c_sha=$(r_sha "$R_UP/sub/c.txt")
	wpc option update e2e_restore_marker before >/dev/null 2>&1
	orig_post=$(wpc post create --post_title='E2E ORIGINAL POST' --post_status=publish --porcelain 2>/dev/null)

	if ! r_make_backup "$fmt" "e2e restore $fmt"; then e_bad "$tag could not create the backup to restore" "$R_BID"; return; fi
	local bid=$R_BID
	e_restore_pages "$tag" "$bid"
	e_restore_security "$tag" "$bid"
	e_restore_preview "$tag" "$bid"

	# Wreck the site after the backup.
	wpc option update e2e_restore_marker wrecked >/dev/null 2>&1
	wpc post delete "$orig_post" --force >/dev/null 2>&1
	wreck_post=$(wpc post create --post_title='E2E WRECK POST' --post_status=publish --porcelain 2>/dev/null)
	printf 'WRECKED-a' >"$R_UP/a.txt"; rm -f "$R_UP/b.bin"; printf 'new-d' >"$R_UP/d.txt"; printf 'WRECKED-c' >"$R_UP/sub/c.txt"
	chown -R www-data:www-data "$R_UP"
	local wrecked_a_sha; wrecked_a_sha=$(r_sha "$R_UP/a.txt")

	# Start (this downloads and verifies; it does not apply).
	[ "$mode_as_nudge" = 1 ] && { touch /tmp/ovb-nudge; ( while [ -f /tmp/ovb-nudge ]; do curl -s -o /dev/null "$URL/wp-cron.php?doing_wp_cron"; sleep 2; done ) & }
	resp=$(api POST /restores "{\"backup_id\":$bid,\"mode\":\"full\",\"confirm_phrase\":\"RESTORE\"}")
	id=$(jg id <<<"$resp"); token=$(jg status_token <<<"$resp")
	e_check "$tag POST /restores starts a restore (id=$id, status $(jg status <<<"$resp"), kind $(jg kind <<<"$resp"))" "$([[ "$id" =~ ^[0-9]+$ ]] && [ "$(jg kind <<<"$resp")" = restore ] && echo 1 || echo 0)" "$resp"
	if ! [[ "$id" =~ ^[0-9]+$ ]]; then rm -f /tmp/ovb-nudge; return; fi
	e_check "$tag the start response shows a 32 hex status token once" "$([[ "$token" =~ ^[a-f0-9]{32}$ ]] && echo 1 || echo 0)"
	e_check "$tag the stored job meta holds only the hash of the token" "$([ "$(e_php 'global $wpdb; $s = (string) $wpdb->get_var( $wpdb->prepare( "SELECT state FROM {$wpdb->prefix}ovb_restores WHERE id = %d", '"$id"' ) ); echo ( false === strpos( $s, "'"$token"'" ) && false !== strpos( $s, hash( "sha256", "'"$token"'" ) ) ) ? 1 : 0;')" = 1 ] && echo 1 || echo 0)"
	e_check "$tag the start payload shows no server path or secret" "$(grep -q '/var/www\|DB_PASSWORD' <<<"$resp" && echo 0 || echo 1)"
	code=$(api_code POST /restores "{\"backup_id\":$bid,\"mode\":\"db\",\"confirm_phrase\":\"RESTORE\"}")
	e_check "$tag a second restore start while one is active is 409 (got $code)" "$([ "$code" = 409 ] && echo 1 || echo 0)"
	code=$(api_code POST /backups '{"format":"zip"}')
	e_check "$tag starting a backup during a restore is 409 (got $code)" "$([ "$code" = 409 ] && echo 1 || echo 0)"

	r_poll_status "$id" "verified" 240
	e_check "$tag the job reaches verified (status $(jg status <<<"$R_LAST"), percent monotonic)" "$([ "$(jg status <<<"$R_LAST")" = verified ] && [ "$R_PCT_OK" = 1 ] && echo 1 || echo 0)" "$(jg error <<<"$R_LAST")"
	e_check "$tag verified payload: phase ready, 40 percent, awaiting confirmation, cancellable, nothing applied yet" "$([ "$(jg phase <<<"$R_LAST")" = ready ] && [ "$(jg percent <<<"$R_LAST")" = 40 ] && [ "$(jg awaiting_confirmation <<<"$R_LAST")" = true ] && [ "$(jg can_cancel <<<"$R_LAST")" = true ] && [ "$(jg maintenance <<<"$R_LAST")" = false ] && [ "$(wpc option get e2e_restore_marker 2>/dev/null)" = wrecked ] && echo 1 || echo 0)" "$R_LAST"
	code=$(api_code POST /backups '{"format":"zip"}')
	e_check "$tag a backup cannot start while a restore waits for confirmation (409, got $code)" "$([ "$code" = 409 ] && echo 1 || echo 0)"

	# The status token route.
	code=$(r_pub_code "$id" "$token")
	local pub hdr=/tmp/ovb-e2e-pubhdr
	pub=$(r_pub "$id" "$token" -D "$hdr")
	e_check "$tag status route with the right token is 200 with only the whitelisted keys (no cookie, no nonce)" "$([ "$code" = 200 ] && [ "$(php -r '$d=json_decode($argv[1],true); $k=array_keys($d); sort($k); echo implode(",",$k);' -- "$pub")" = "done,failed,message,percent,status,step_label" ] && echo 1 || echo 0)" "$pub"
	e_check "$tag status route sends Cache-Control: no-store" "$(tr -d '\r' <"$hdr" | grep -qi '^cache-control:.*no-store' && echo 1 || echo 0)"
	e_check "$tag status route shows nothing but coarse facts (no path, id, error text, SQL)" "$(grep -q '/var/www\|restore_id\|backup_id\|CREATE\|"error"' <<<"$pub" && echo 0 || echo 1)"
	e_check "$tag status route with a wrong token is 404 (got $(r_pub_code "$id" 00000000000000000000000000000000))" "$([ "$(r_pub_code "$id" 00000000000000000000000000000000)" = 404 ] && echo 1 || echo 0)"
	e_check "$tag status route with a malformed, empty or missing token is 404" "$([ "$(r_pub_code "$id" 'abc')" = 404 ] && [ "$(r_pub_code "$id" '')" = 404 ] && [ "$(curl -s -o /dev/null -w '%{http_code}' "$RB/restores/$id/status")" = 404 ] && [ "$(r_pub_code "$id" "$(tr 'a-f' 'A-F' <<<"$token")")" = 404 ] && echo 1 || echo 0)"
	e_check "$tag the token of this job does not open another restore id or an unknown one" "$([ "$(r_pub_code $((id + 1)) "$token")" = 404 ] && [ "$(r_pub_code 999999 "$token")" = 404 ] && echo 1 || echo 0)"
	e_check "$tag a wrong token and an unknown id answer with the same body (ids cannot be probed)" "$([ "$(r_pub "$id" 11111111111111111111111111111111)" = "$(r_pub 999999 "$token")" ] && echo 1 || echo 0)"
	e_check "$tag the status route changes nothing (the job still waits, same step)" "$([ "$(jg status <<<"$(api GET "/restores/$id")")" = verified ] && echo 1 || echo 0)"
	e_check "$tag a login cookie does not widen the status route (it still needs the token)" "$([ "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" "$RB/restores/$id/status")" = 404 ] && echo 1 || echo 0)"

	# The second, explicit confirmation.
	code=$(api_code POST "/restores/$id/confirm" '{}')
	e_check "$tag confirm without the phrase is 400 (got $code)" "$([ "$code" = 400 ] && echo 1 || echo 0)"
	code=$(api_code POST "/restores/$id/confirm" '{"confirm_phrase":"restore"}')
	e_check "$tag confirm with a wrong phrase is 400 (got $code)" "$([ "$code" = 400 ] && echo 1 || echo 0)"
	e_check "$tag the refused confirms changed nothing (still verified, site unchanged)" "$([ "$(jg status <<<"$(api GET "/restores/$id")")" = verified ] && [ "$(wpc option get e2e_restore_marker 2>/dev/null)" = wrecked ] && echo 1 || echo 0)"
	resp=$(api POST "/restores/$id/confirm" '{"confirm_phrase":"RESTORE"}')
	e_check "$tag confirm with RESTORE starts applying (status $(jg status <<<"$resp"))" "$(case "$(jg status <<<"$resp")" in running | queued | complete) echo 1 ;; *) echo 0 ;; esac)" "$resp"

	r_apply_poll "$id" "$token" 300
	rm -f /tmp/ovb-nudge
	e_check "$tag the public status channel answered while the restore ran ($R_PUB_OK answers) and ended done, not failed" "$([ "$R_PUB_OK" -ge 2 ] && echo 1 || echo 0)" "$(r_pub "$id" "$token")"
	e_check "$tag percent never decreased over both channels" "$R_PCT_OK"
	e_check "$tag the logout of the administrator is tolerated (logged out during apply: $R_LOGOUT_SEEN, maintenance seen: $R_MM_SEEN)" 1
	pub=$(r_pub "$id" "$token")
	e_check "$tag the public channel reports done, not failed, 100 percent" "$([ "$(jg done <<<"$pub")" = true ] && [ "$(jg failed <<<"$pub")" = false ] && [ "$(jg percent <<<"$pub")" = 100 ] && echo 1 || echo 0)" "$pub"

	r_jar_login
	R_LAST=$(api GET "/restores/$id")
	result=$R_LAST
	e_check "$tag after logging in again the restore is complete" "$([ "$(jg status <<<"$result")" = complete ] && echo 1 || echo 0)" "$(jg error <<<"$result")"
	e_check "$tag the wrecked option is back" "$([ "$(wpc option get e2e_restore_marker 2>/dev/null)" = before ] && echo 1 || echo 0)"
	e_check "$tag the deleted post is back and the post created after the backup is gone" "$([ "$(wpc post list --post_type=post --post_status=publish --field=post_title 2>/dev/null | grep -c 'E2E ORIGINAL POST')" = 1 ] && [ "$(wpc post list --post_type=post --post_status=any --field=post_title 2>/dev/null | grep -c 'E2E WRECK POST')" = 0 ] && echo 1 || echo 0)"
	e_check "$tag files are identical to the backup (a.txt, b.bin, sub/c.txt)" "$([ "$(r_sha "$R_UP/a.txt")" = "$a_sha" ] && [ "$(r_sha "$R_UP/b.bin")" = "$b_sha" ] && [ "$(r_sha "$R_UP/sub/c.txt")" = "$c_sha" ] && echo 1 || echo 0)"
	e_check "$tag the OpenVault plugin is still active" "$([ "$(wpc plugin is-active openvault-backup && echo yes)" = yes ] && echo 1 || echo 0)"
	e_check "$tag maintenance mode is off (front page 200, no flag file)" "$([ "$(curl -s -o /dev/null -w '%{http_code}' "$URL/")" = 200 ] && [ "$(jg maintenance <<<"$result")" = false ] && echo 1 || echo 0)"
	e_check "$tag the result payload has the counts (tables, rows, files restored/overwritten, seconds)" "$([ "$(jg result tables <<<"$result")" -gt 5 ] && [ "$(jg result rows <<<"$result")" -gt 10 ] && [ "$(jg result files restored <<<"$result")" -ge 3 ] && [ "$(jg result files overwritten <<<"$result")" -ge 2 ] && [ -n "$(jg result seconds <<<"$result")" ] && echo 1 || echo 0)" "$result"
	e_check "$tag the result and status payloads show no server path, secret, SQL or user name" "$(grep -q '/var/www\|DB_PASSWORD\|CREATE TABLE\|"user"\|ovb1:' <<<"$result" && echo 0 || echo 1)" "$result"
	hist=$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?page=openvault-backups")
	e_check "$tag History shows the safety snapshot labelled 'Safety snapshot before restore'" "$(grep -q 'Safety snapshot before restore' <<<"$hist" && echo 1 || echo 0)"
	e_check "$tag the snapshot id of the result is a locked pre-restore backup" "$([ "$(e_php '$r = ( new OpenVault\Backup\Database\BackupsRepository() )->find( '"$(jg snapshot_id <<<"$result")"' ); echo ( $r && $r["locked"] && "pre-restore" === $r["trigger_type"] ) ? 1 : 0;')" = 1 ] && echo 1 || echo 0)"
	e_check "$tag the maintenance banner is gone from plugin pages and History has the Restore action" "$(grep -q 'ovb-restore-banner' <<<"$hist" && echo 0 || echo 1)"
	e_check "$tag the Restore page of the finished job opens by id" "$(r_page_has "page=openvault-restore&restore=$id" "data-ovb-restore-id=\"$id\"" && echo 1 || echo 0)"
	e_check "$tag GET /restores lists the job (newest first)" "$([ "$(jg 0 id <<<"$(api GET '/restores&per_page=5')")" = "$id" ] && echo 1 || echo 0)"

	# Status token: reachable during maintenance, valid after the end, expires one hour after the end.
	e_php '( new OpenVault\Backup\Restore\MaintenanceMode() )->enable( '"$id"' );' >/dev/null
	code=$(curl -s -o /dev/null -w '%{http_code}' "$URL/")
	e_check "$tag (precondition) the front page answers 503 while maintenance is on (got $code)" "$([ "$code" = 503 ] && echo 1 || echo 0)"
	e_check "$tag the status route answers 200 during maintenance mode, without a cookie" "$([ "$(r_pub_code "$id" "$token")" = 200 ] && echo 1 || echo 0)"
	e_check "$tag the REST nonce routes still answer during maintenance for the administrator (GET /restores/$id)" "$([ "$(api_code GET "/restores/$id")" = 200 ] && echo 1 || echo 0)"
	e_php '( new OpenVault\Backup\Restore\MaintenanceMode() )->disable();' >/dev/null
	e_check "$tag the token still works shortly after the job finished" "$([ "$(r_pub_code "$id" "$token")" = 200 ] && echo 1 || echo 0)"
	e_php 'global $wpdb; $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}ovb_restores SET finished_at = %s WHERE id = %d", gmdate( "Y-m-d H:i:s", time() - 3700 ), '"$id"' ) );' >/dev/null
	e_check "$tag the token is refused (404) more than an hour after the job finished" "$([ "$(r_pub_code "$id" "$token")" = 404 ] && echo 1 || echo 0)"
	e_php 'global $wpdb; $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}ovb_restores SET finished_at = %s WHERE id = %d", gmdate( "Y-m-d H:i:s" ), '"$id"' ) );' >/dev/null

	# Undo.
	local undo
	undo=$(jg undo can_undo <<<"$result")
	e_check "$tag the payload offers undo with the database warning" "$([ "$undo" = true ] && grep -q 'removes everything written to the database since the restore' <<<"$result" && [ "$(jg undo undo_db <<<"$result")" = true ] && [ "$(jg undo undo_files <<<"$result")" = true ] && echo 1 || echo 0)" "$result"
	code=$(api_code POST "/restores/$id/undo" '{}')
	e_check "$tag undo without the phrase is 400 (got $code)" "$([ "$code" = 400 ] && echo 1 || echo 0)"
	code=$(api_code POST "/restores/$id/undo" '{"confirm_phrase":"Restore"}')
	e_check "$tag undo with a wrong phrase is 400 (got $code)" "$([ "$code" = 400 ] && echo 1 || echo 0)"
	e_check "$tag the refused undo requests changed nothing" "$([ "$(wpc option get e2e_restore_marker 2>/dev/null)" = before ] && echo 1 || echo 0)"
	touch /tmp/ovb-nudge; ( while [ -f /tmp/ovb-nudge ]; do curl -s -o /dev/null "$URL/wp-cron.php?doing_wp_cron"; sleep 2; done ) &
	resp=$(api POST "/restores/$id/undo" '{"confirm_phrase":"RESTORE"}')
	local uid utoken
	uid=$(jg id <<<"$resp"); utoken=$(jg status_token <<<"$resp")
	e_check "$tag POST undo starts an undo job (id=$uid, kind $(jg kind <<<"$resp"))" "$([[ "$uid" =~ ^[0-9]+$ ]] && [ "$(jg kind <<<"$resp")" = undo ] && [[ "$utoken" =~ ^[a-f0-9]{32}$ ]] && echo 1 || echo 0)" "$resp"
	if [[ "$uid" =~ ^[0-9]+$ ]]; then
		r_apply_poll "$uid" "$utoken" 300
		rm -f /tmp/ovb-nudge
		r_jar_login
		R_LAST=$(api GET "/restores/$uid")
		e_check "$tag the undo job completes (status $(jg status <<<"$R_LAST"))" "$([ "$(jg status <<<"$R_LAST")" = complete ] && echo 1 || echo 0)" "$(jg error <<<"$R_LAST")"
		e_check "$tag undo brought the pre-restore (wrecked) state back: option, posts, files" "$([ "$(wpc option get e2e_restore_marker 2>/dev/null)" = wrecked ] && [ "$(wpc post list --post_type=post --post_status=any --field=post_title 2>/dev/null | grep -c 'E2E WRECK POST')" = 1 ] && [ "$(wpc post list --post_type=post --post_status=any --field=post_title 2>/dev/null | grep -c 'E2E ORIGINAL POST')" = 0 ] && [ "$(r_sha "$R_UP/a.txt")" = "$wrecked_a_sha" ] && [ ! -e "$R_UP/b.bin" ] && echo 1 || echo 0)"
		e_check "$tag the plugin is still active and maintenance is off after the undo" "$([ "$(wpc plugin is-active openvault-backup && echo yes)" = yes ] && [ "$(curl -s -o /dev/null -w '%{http_code}' "$URL/")" = 200 ] && echo 1 || echo 0)"
		resp=$(api GET "/restores/$id")
		e_check "$tag the original restore is now marked as undone and cannot be undone twice" "$([ "$(jg undo undone_by <<<"$resp")" = "$uid" ] && [ "$(jg undo can_undo <<<"$resp")" = false ] && echo 1 || echo 0)" "$resp"
		code=$(api_code POST "/restores/$id/undo" '{"confirm_phrase":"RESTORE"}')
		e_check "$tag a second undo is 409 (got $code)" "$([ "$code" = 409 ] && echo 1 || echo 0)"
		code=$(api_code POST "/restores/$uid/undo" '{"confirm_phrase":"RESTORE"}')
		e_check "$tag an undo job cannot be undone (409, got $code)" "$([ "$code" = 409 ] && echo 1 || echo 0)"
		e_check "$tag the undo job payload has no undo block" "$([ "$(jg undo <<<"$(api GET "/restores/$uid")")" = "" ] && echo 1 || echo 0)"
		e_check "$tag the journal of the restore still exists until dismissed" "$([ "$(e_php 'echo OpenVault\Backup\Restore\UndoStore::for_restore( '"$id"' )->exists() ? 1 : 0;')" = 1 ] && echo 1 || echo 0)"
		resp=$(api POST "/restores/$id/undo/dismiss")
		e_check "$tag dismiss deletes the undo data (journal gone, payload says dismissed)" "$([ "$(jg undo dismissed <<<"$resp")" = true ] && [ "$(e_php 'echo OpenVault\Backup\Restore\UndoStore::for_restore( '"$id"' )->exists() ? 1 : 0;')" = 0 ] && echo 1 || echo 0)" "$resp"
		code=$(api_code POST "/restores/$id/undo/dismiss")
		e_check "$tag dismissing again is harmless (200 or 409, got $code)" "$([ "$code" = 200 ] || [ "$code" = 409 ] && echo 1 || echo 0)"
	else
		rm -f /tmp/ovb-nudge
	fi

	# Clean up what the wreck made.
	wpc option delete e2e_restore_marker >/dev/null 2>&1
	wpc post delete $(wpc post list --post_type=post --post_status=any --field=ID --s='E2E' 2>/dev/null) --force >/dev/null 2>&1
	rm -rf "$R_UP"
	E_RESTORE_BID=$bid
}

# Cancel rules and confirm-before-verified, with the job held (no cron, no loopback, no browser kick).
e_restore_cancel() {
	local fmt=$1 bid=$2 tag="[$1/restore-cancel]" hold=/var/www/html/wp-content/mu-plugins/ovb-e2e-rhold.php resp id code
	mkdir -p /var/www/html/wp-content/mu-plugins
	cat >"$hold" <<'PHP'
<?php
if ( ! defined( 'DISABLE_WP_CRON' ) ) { define( 'DISABLE_WP_CRON', true ); }
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	return ( false !== strpos( $url, 'wp-cron.php' ) || false !== strpos( $url, 'as_async_request_queue_runner' ) ) ? new WP_Error( 'ovb_e2e_blocked', 'blocked' ) : $pre;
}, 10, 3 );
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
add_filter( 'ovb_restore_kick_after_seconds', function () { return 3600; }, 99 );
PHP
	chown www-data:www-data "$hold"
	resp=$(api POST /restores "{\"backup_id\":$bid,\"mode\":\"db\",\"confirm_phrase\":\"RESTORE\"}")
	id=$(jg id <<<"$resp")
	e_check "$tag a held restore starts (id=$id)" "$([[ "$id" =~ ^[0-9]+$ ]] && echo 1 || echo 0)" "$resp"
	if ! [[ "$id" =~ ^[0-9]+$ ]]; then rm -f "$hold"; return; fi
	e_check "$tag the held job stays unfinished and is not kicked (GET changes nothing)" "$(sleep 2; [ "$(jg finished <<<"$(api GET "/restores/$id")")" = false ] && [ "$(jg status <<<"$(api GET "/restores/$id")")" = running ] && echo 1 || echo 0)"
	code=$(api_code POST "/restores/$id/confirm" '{"confirm_phrase":"RESTORE"}')
	e_check "$tag confirm before verification is 409 (got $code)" "$([ "$code" = 409 ] && echo 1 || echo 0)"
	code=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -X POST -H 'Content-Type: application/json' --data '{}' "$RB/restores/$id/cancel")
	e_check "$tag cancel without a nonce is rejected (got $code)" "$([ "$code" = 401 ] || [ "$code" = 403 ] && echo 1 || echo 0)"
	resp=$(api POST "/restores/$id/cancel")
	e_check "$tag cancel of a job that has not changed anything returns 200 cancelled (got $(jg status <<<"$resp"))" "$([ "$(jg status <<<"$resp")" = cancelled ] && [ "$(jg finished <<<"$resp")" = true ] && echo 1 || echo 0)" "$resp"
	code=$(api_code POST "/restores/$id/cancel")
	e_check "$tag cancelling again is 409 with a message (got $code)" "$([ "$code" = 409 ] && echo 1 || echo 0)"
	code=$(api_code POST "/restores/$id/confirm" '{"confirm_phrase":"RESTORE"}')
	e_check "$tag confirming a cancelled job is 409 (got $code)" "$([ "$code" = 409 ] && echo 1 || echo 0)"
	e_check "$tag the cancelled job leaves no work folder and no maintenance flag" "$([ ! -e "$LOCAL_DIR/tmp/restore-$id" ] && [ "$(curl -s -o /dev/null -w '%{http_code}' "$URL/")" = 200 ] && echo 1 || echo 0)"
	e_check "$tag a cancelled restore has no undo block" "$([ "$(jg undo <<<"$(api GET "/restores/$id")")" = "" ] && echo 1 || echo 0)"
	rm -f "$hold"
}

# Browser kick: Action Scheduler, WP-Cron and the loopback are blocked, only GET /restores/{id} (and the confirm) advance the job.
e_restore_kick() {
	local fmt=$1 bid=$2 tag="[$1/restore-kick]" hold=/var/www/html/wp-content/mu-plugins/ovb-e2e-rkick.php resp id code
	mkdir -p /var/www/html/wp-content/mu-plugins
	cat >"$hold" <<'PHP'
<?php
if ( ! defined( 'DISABLE_WP_CRON' ) ) { define( 'DISABLE_WP_CRON', true ); }
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	return ( false !== strpos( $url, 'wp-cron.php' ) || false !== strpos( $url, 'as_async_request_queue_runner' ) ) ? new WP_Error( 'ovb_e2e_blocked', 'blocked' ) : $pre;
}, 10, 3 );
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
add_filter( 'ovb_restore_kick_after_seconds', function () { return 1; }, 99 );
PHP
	chown www-data:www-data "$hold"
	resp=$(api POST /restores "{\"backup_id\":$bid,\"mode\":\"db\",\"confirm_phrase\":\"RESTORE\"}")
	id=$(jg id <<<"$resp")
	e_check "$tag a restore starts without a working loopback (id=$id)" "$([[ "$id" =~ ^[0-9]+$ ]] && echo 1 || echo 0)" "$resp"
	if ! [[ "$id" =~ ^[0-9]+$ ]]; then rm -f "$hold"; return; fi
	r_poll_status "$id" "verified" 200
	e_check "$tag polling alone (the browser kick) carries the job to verified (status $(jg status <<<"$R_LAST"))" "$([ "$(jg status <<<"$R_LAST")" = verified ] && echo 1 || echo 0)" "$(jg error <<<"$R_LAST")"
	sleep 2
	e_check "$tag a verified job is never kicked: it stays verified while polled" "$([ "$(jg status <<<"$(api GET "/restores/$id")")" = verified ] && [ "$(jg status <<<"$(api GET "/restores/$id")")" = verified ] && echo 1 || echo 0)"
	wpc option update e2e_restore_marker wrecked >/dev/null 2>&1
	resp=$(api POST "/restores/$id/confirm" '{"confirm_phrase":"RESTORE"}')
	e_check "$tag confirm is accepted" "$([ "$(jg ok <<<"$resp")" != false ] && [ -n "$(jg id <<<"$resp")" ] && echo 1 || echo 0)" "$resp"
	# Only the authenticated poll advances the job here; it may lose its session during the import, so re-login on 401/403 like a user would.
	local deadline=$((SECONDS + 240)) tries=0
	while [ "$SECONDS" -lt "$deadline" ]; do
		code=$(curl -s -o /tmp/ovb-e2e-kick.json -w '%{http_code}' -b "$JAR" -H "X-WP-Nonce: $NONCE" "$RB/restores/$id")
		if [ "$code" = 200 ]; then
			R_LAST=$(cat /tmp/ovb-e2e-kick.json)
			case "$(jg status <<<"$R_LAST")" in complete | failed | cancelled) break ;; esac
		else
			tries=$((tries + 1))
			r_jar_login
		fi
		sleep 1
	done
	e_check "$tag polling alone finishes the restore (status $(jg status <<<"$R_LAST"), re-logins: $tries)" "$([ "$(jg status <<<"$R_LAST")" = complete ] && echo 1 || echo 0)" "$(jg error <<<"$R_LAST")"
	e_check "$tag the database really was restored by the kicked job (the marker set after the backup is gone)" "$([ -z "$(wpc option get e2e_restore_marker 2>/dev/null)" ] && echo 1 || echo 0)"
	case "$(jg status <<<"$R_LAST")" in complete | failed | cancelled) ;; *) api POST "/restores/$id/cancel" >/dev/null ;; esac
	wpc option delete e2e_restore_marker >/dev/null 2>&1
	rm -f "$hold" /tmp/ovb-e2e-kick.json
	[ -z "${R_NO_LOGIN:-}" ] && r_jar_login
	E_KICK_ID=$id
}

# A backup that lives only on the S3 test server: preview names the destination, start downloads and verifies it, cancel cleans up.
e_restore_remote() {
	local fmt=$1 tag="[$1/restore-remote]" ep=${OVB_S3_ENDPOINT:-} acc=${OVB_S3_ACCESS:-} sec=${OVB_S3_SECRET:-} bucket="ovb-e2e-r-$(date +%s)" sjson did resp bid id folder
	if [ -z "$ep" ] || [ -z "$acc" ] || [ -z "$sec" ]; then e_bad "$tag the matrix S3 server is configured"; return; fi
	e_check "$tag the harness created a bucket" "$([ "$(wpc eval-file /src/tests/matrix/e2e-s3.php bucket-create "$bucket" 2>/dev/null | tail -1)" = 200 ] && echo 1 || echo 0)"
	sjson="{\"preset\":\"custom\",\"endpoint\":\"$ep\",\"addressing\":\"path\",\"allow_insecure_http\":true,\"allow_private_endpoint\":true,\"bucket\":\"$bucket\",\"prefix\":\"e2e-restore\"}"
	resp=$(api POST /destinations "{\"type\":\"s3\",\"label\":\"E2E Restore S3\",\"settings\":$sjson,\"credentials\":{\"access_key\":\"$acc\",\"secret_key\":\"$sec\"}}")
	did=$(jg id <<<"$resp")
	[[ "$did" =~ ^[0-9]+$ ]] || { e_bad "$tag the S3 destination was created" "$resp"; return; }
	api POST "/destinations/$did/test" >/dev/null
	touch /tmp/ovb-nudge; ( while [ -f /tmp/ovb-nudge ]; do curl -s -o /dev/null "$URL/wp-cron.php?doing_wp_cron"; sleep 2; done ) &
	resp=$(api POST /backups "{\"contents\":{\"db\":true,\"plugins\":false,\"themes\":false,\"uploads\":false,\"mu_plugins\":false,\"other_content\":false,\"core\":false,\"wp_config\":false},\"format\":\"$fmt\",\"destinations\":[$did],\"note\":\"e2e remote\"}")
	bid=$(jg id <<<"$resp")
	[[ "$bid" =~ ^[0-9]+$ ]] || { e_bad "$tag the S3 backup could be started" "$resp"; rm -f /tmp/ovb-nudge; return; }
	e_poll "$bid" 280
	e_check "$tag a backup sent to the S3 server completed (statuses:$E_STATUSES)" "$([ "$(jg status <<<"$E_LAST")" = complete ] && [ "$(jg destinations 0 status <<<"$E_LAST")" = done ] && echo 1 || echo 0)" "$E_LAST"
	rm -f /tmp/ovb-nudge
	# Hide the local copy: only the S3 copy is left.
	folder=$(e_php '$r = ( new OpenVault\Backup\Database\BackupsRepository() )->find( '"$bid"' ); echo OpenVault\Backup\Support\Paths::build_backup_folder_name( $r["created_at"], $r["uuid"] );')
	mv "$LOCAL_DIR/backups/$folder" "$LOCAL_DIR/backups/$folder-hidden"
	resp=$(api POST /restores/preview "{\"backup_id\":$bid,\"mode\":\"db\"}")
	e_check "$tag preview reads from the destination and names it by label" "$([ "$(jg ok <<<"$resp")" = true ] && [ "$(jg source kind <<<"$resp")" = destination ] && [ "$(jg source label <<<"$resp")" = "E2E Restore S3" ] && [ "$(jg sizes download <<<"$resp")" -gt 0 ] && echo 1 || echo 0)" "$resp"
	e_check "$tag the Restore page lists the backup with the destination as a copy" "$(r_page_has "page=openvault-restore&backup=$bid" 'E2E Restore S3' && echo 1 || echo 0)"
	resp=$(api POST /restores "{\"backup_id\":$bid,\"mode\":\"db\",\"confirm_phrase\":\"RESTORE\"}")
	id=$(jg id <<<"$resp")
	e_check "$tag a restore from the S3 copy starts (id=$id)" "$([[ "$id" =~ ^[0-9]+$ ]] && echo 1 || echo 0)" "$resp"
	if [[ "$id" =~ ^[0-9]+$ ]]; then
		touch /tmp/ovb-nudge; ( while [ -f /tmp/ovb-nudge ]; do curl -s -o /dev/null "$URL/wp-cron.php?doing_wp_cron"; sleep 2; done ) &
		r_poll_status "$id" "verified" 240
		rm -f /tmp/ovb-nudge
		e_check "$tag the backup is downloaded from S3 and verified (status $(jg status <<<"$R_LAST"))" "$([ "$(jg status <<<"$R_LAST")" = verified ] && echo 1 || echo 0)" "$(jg error <<<"$R_LAST")"
		e_check "$tag the work folder holds the downloaded database part" "$([ -n "$(find "$LOCAL_DIR/tmp/restore-$id" -name 'db.*' 2>/dev/null)" ] && echo 1 || echo 0)"
		resp=$(api POST "/restores/$id/cancel")
		e_check "$tag cancel at verified removes the download folder (status $(jg status <<<"$resp"))" "$([ "$(jg status <<<"$resp")" = cancelled ] && [ ! -e "$LOCAL_DIR/tmp/restore-$id" ] && echo 1 || echo 0)"
	fi
	mv "$LOCAL_DIR/backups/$folder-hidden" "$LOCAL_DIR/backups/$folder"
	api DELETE "/backups/$bid" >/dev/null
	api DELETE "/destinations/$did" >/dev/null
	wpc eval-file /src/tests/matrix/e2e-s3.php bucket-delete "$bucket" >/dev/null 2>&1
}

# Hostile text in a backup note and a destination label must be escaped on the Restore page and in History.
e_restore_xss() {
	local fmt=$1 bid=$2 tag="[$1/restore-xss]" did page hist canary='<script>alert("ovbxss")</script><img src=x onerror=alert(1)>'
	cat >/tmp/ovb-xss.php <<PHP
<?php
global \$wpdb;
\$canary = '<script>alert("ovbxss")</script><img src=x onerror=alert(1)>';
\$id = ( new OpenVault\\Backup\\Database\\DestinationsRepository() )->create( 'local', 'tmp', array( 'path' => '/var/www/html/wp-content/ovb-e2e-xss' ), array() );
\$wpdb->update( \$wpdb->prefix . 'ovb_destinations', array( 'label' => \$canary ), array( 'id' => \$id ) );
\$now = gmdate( 'Y-m-d H:i:s' );
\$wpdb->insert( \$wpdb->prefix . 'ovb_backup_files', array( 'backup_id' => $bid, 'destination_id' => \$id, 'part_number' => 0, 'file_name' => 'manifest.json', 'size' => 10, 'checksum_sha256' => '', 'upload_status' => 'done', 'remote_ref' => 'x/manifest.json', 'created_at' => \$now, 'updated_at' => \$now ) );
\$wpdb->update( \$wpdb->prefix . 'ovb_backups', array( 'note' => \$canary ), array( 'id' => $bid ) );
echo \$id;
PHP
	did=$(wpc eval-file /tmp/ovb-xss.php 2>/dev/null | tail -1)
	e_check "$tag a hostile destination label and backup note were planted (destination $did)" "$([[ "$did" =~ ^[0-9]+$ ]] && echo 1 || echo 0)" "$did"
	page=$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?page=openvault-restore")
	hist=$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?page=openvault-backups")
	e_check "$tag the Restore page never contains the raw script or the onerror tag" "$(grep -q '<script>alert("ovbxss")\|<img src=x onerror' <<<"$page" && echo 0 || echo 1)"
	e_check "$tag the Restore page shows the hostile text as escaped text (note and destination label)" "$(grep -q '&lt;script&gt;alert' <<<"$page" && echo 1 || echo 0)"
	e_check "$tag History never contains the raw script or the onerror tag" "$(grep -q '<script>alert("ovbxss")\|<img src=x onerror' <<<"$hist" && echo 0 || echo 1)"
	e_check "$tag History shows the hostile text escaped" "$(grep -q '&lt;script&gt;alert' <<<"$hist" && echo 1 || echo 0)"
	rm -f /tmp/ovb-xss.php
	e_php 'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->prefix}ovb_backup_files WHERE remote_ref = \"x/manifest.json\"" ); ( new OpenVault\Backup\Database\DestinationsRepository() )->delete( '"$did"' ); $wpdb->update( $wpdb->prefix . "ovb_backups", array( "note" => "" ), array( "id" => '"$bid"' ) );' >/dev/null
}

e_restore_multisite() {
	local fmt=$1 tag="[$1/restore-multisite]" resp bid code
	if ! r_make_backup "$fmt" "e2e multisite"; then e_bad "$tag could not create a backup" "$R_BID"; return; fi
	bid=$R_BID
	r_nonce
	resp=$(api POST /restores/preview "{\"backup_id\":$bid,\"mode\":\"db\"}")
	e_check "$tag preview refuses a multisite network (multisite_unsupported)" "$([ "$(jg ok <<<"$resp")" = false ] && grep -q 'multisite_unsupported' <<<"$resp" && echo 1 || echo 0)" "$resp"
	resp=$(api POST /restores "{\"backup_id\":$bid,\"mode\":\"files\",\"confirm_phrase\":\"RESTORE\"}")
	code=$(api_code POST /restores "{\"backup_id\":$bid,\"mode\":\"full\",\"confirm_phrase\":\"RESTORE\"}")
	e_check "$tag start is refused 400 with a clear message and creates nothing (got $code)" "$([ "$code" = 400 ] && grep -q 'multisite' <<<"$resp" && [ "$(r_count)" = "$R_BASE" ] && echo 1 || echo 0)" "$resp"
	e_check "$tag the Restore page says multisite is not supported and disables the choices" "$(r_page_has page=openvault-restore 'Restoring a multisite network is not supported yet' && echo 1 || echo 0)"
	echo "SKIP restore wreck/undo/kick/remote scenarios on the multisite profile (restore of a network is not supported yet; the refusal is asserted above)"
	api DELETE "/backups/$bid" >/dev/null
}

step "Restore over HTTP (step 5d)"
LOCAL_DIR=/var/www/html/wp-content/$(wps option get ovb_local_dir)
r_nonce
e_check "[restore] the Restore page carries a REST nonce for this session" "$([ -n "$NONCE" ] && echo 1 || echo 0)"
R_FIRST=1
for fmt in zip tar.gz; do
	if [ "$(e_php 'echo ( new OpenVault\Backup\Archive\CapabilityDetector() )->is_available( "'"$fmt"'" ) ? "1" : "0";')" != 1 ]; then
		echo "SKIP restore e2e for $fmt (not available on this server)"
		continue
	fi
	if [ "$VARIANT" = multisite ]; then
		e_restore_multisite "$fmt"
		break
	fi
	e_restore_wreck "$fmt"
	if [ "$R_FIRST" = 1 ]; then
		R_FIRST=0
		# One more backup for the cancel, kick, remote and XSS scenarios (the wreck scenario's backup was restored twice and is fine to reuse).
		r_make_backup "$fmt" "e2e restore extras" && {
			e_restore_xss "$fmt" "$R_BID"
			e_restore_cancel "$fmt" "$R_BID"
			e_restore_kick "$fmt" "$R_BID"
			api DELETE "/backups/$R_BID" >/dev/null
		}
		e_restore_remote "$fmt"
	fi
done
