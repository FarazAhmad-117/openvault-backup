#!/usr/bin/env bash
# Runs INSIDE a matrix container (see run.sh). Installs WordPress, installs the plugin
# from the built zip, runs the checks, then exercises it over real HTTP through Apache.
#
# Env: WP_VERSION (latest|x.y), VARIANT (single|multisite|restricted|lowmem), DB_HOST, ZIP
set -uo pipefail

WP_VERSION=${WP_VERSION:-latest}
VARIANT=${VARIANT:-single}
export DB_HOST=${DB_HOST:-db}
ZIP=${ZIP:-/src/dist/openvault-backup.zip}
URL=http://localhost
NETWORK=""
[ "$VARIANT" = multisite ] && NETWORK="--network"

# Setup runs unrestricted (WP-CLI needs shell functions and URL access); checks run under the profile's php.ini.
wps() { php -d disable_functions= -d allow_url_fopen=1 -d memory_limit=512M /usr/local/bin/wp-cli.phar --allow-root --path=/var/www/html "$@"; }
wpc() { php /usr/local/bin/wp-cli.phar --allow-root --path=/var/www/html "$@"; }

step() { echo "--- $*"; }
fail() { echo "CONTAINER FAIL: $*"; exit 1; }

step "wait for database"
for i in $(seq 1 60); do
	php -d disable_functions= -d display_errors=0 -r 'mysqli_report(MYSQLI_REPORT_OFF); $c=@mysqli_connect(getenv("DB_HOST"),"root","root","wp"); exit($c?0:1);' 2>/dev/null && break
	sleep 2
	[ "$i" = 60 ] && fail "database not reachable"
done

step "download WordPress ($WP_VERSION)"
if [ "$WP_VERSION" = latest ]; then
	wps core download --force --quiet || fail "core download"
else
	wps core download --version="$WP_VERSION" --force --quiet || fail "core download $WP_VERSION"
fi

wps config create --dbname=wp --dbuser=root --dbpass=root --dbhost="$DB_HOST" --skip-check --force --quiet \
	--extra-php <<'PHP' || fail "config create"
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
PHP

step "install WordPress ($VARIANT)"
if [ "$VARIANT" = multisite ]; then
	wps core multisite-install --url="$URL" --title="OVB Matrix" --admin_user=admin --admin_password=password \
		--admin_email=admin@example.com --skip-email --quiet || fail "multisite install"
else
	wps core install --url="$URL" --title="OVB Matrix" --admin_user=admin --admin_password=password \
		--admin_email=admin@example.com --skip-email --quiet || fail "core install"
fi
wps rewrite structure '/%postname%/' --hard --quiet 2>/dev/null || true

step "WordPress $(wps core version) on PHP $(php -r 'echo PHP_VERSION;')"

step "install plugin from the release zip"
[ -f "$ZIP" ] || fail "zip not found at $ZIP (run bin/build-zip.sh)"
if [ -n "${LOWER_MIN_WP:-}" ]; then
	# Informational run below the supported minimum: lower the minimum in a copy so the plugin loads and we see what breaks.
	echo "(informational: minimum WordPress lowered to $LOWER_MIN_WP in a copy of the zip)"
	rm -rf /tmp/patched && mkdir /tmp/patched && unzip -q "$ZIP" -d /tmp/patched
	sed -i "s/Requires at least:.*/Requires at least: $LOWER_MIN_WP/; s/'OVB_MIN_WP', '[0-9.]*'/'OVB_MIN_WP', '$LOWER_MIN_WP'/" /tmp/patched/openvault-backup/openvault-backup.php
	rm -rf /var/www/html/wp-content/plugins/openvault-backup
	cp -r /tmp/patched/openvault-backup /var/www/html/wp-content/plugins/openvault-backup
	wps plugin activate openvault-backup || fail "plugin activate (lowered minimum)"
elif [ "$VARIANT" = multisite ]; then
	wps plugin install "$ZIP" --activate-network || fail "plugin install (network)"
else
	wps plugin install "$ZIP" --activate || fail "plugin install"
fi
chown -R www-data:www-data /var/www/html

step "in-WordPress checks (php.ini profile: $VARIANT)"
wpc eval-file /src/tests/matrix/check-plugin.php
CHECK_STATUS=$?

step "deactivate / reactivate keeps data"
BEFORE=$(wps option get ovb_local_dir)
wps plugin deactivate openvault-backup $NETWORK --quiet || fail "deactivate"
wps plugin activate openvault-backup $NETWORK --quiet || fail "reactivate"
AFTER=$(wps option get ovb_local_dir)
TABLES=$(wps eval 'global $wpdb; echo count( $wpdb->get_col( "SHOW TABLES LIKE \"%ovb\\_%\"" ) );' 2>/dev/null)
if [ "$BEFORE" = "$AFTER" ]; then echo "PASS local dir stable across reactivation"; else echo "FAIL local dir changed ($BEFORE -> $AFTER)"; CHECK_STATUS=1; fi
if [ "${TABLES:-0}" -ge 5 ]; then echo "PASS tables kept after deactivation ($TABLES)"; else echo "FAIL tables missing after deactivation ($TABLES)"; CHECK_STATUS=1; fi
chown -R www-data:www-data /var/www/html

step "HTTP through Apache"
apache2-foreground >/tmp/apache.log 2>&1 &
for i in $(seq 1 30); do curl -s -o /dev/null "$URL/" && break; sleep 1; done

code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
if [ "$(code "$URL/")" = 200 ]; then echo "PASS front page 200"; else echo "FAIL front page"; CHECK_STATUS=1; fi
if [ "$(code "$URL/wp-login.php")" = 200 ]; then echo "PASS login page 200"; else echo "FAIL login page"; CHECK_STATUS=1; fi

JAR=/tmp/cookies.txt
rm -f "$JAR"
curl -s -c "$JAR" -b "wordpress_test_cookie=WP%20Cookie%20check" -o /dev/null \
	--data-urlencode "log=admin" --data-urlencode "pwd=password" --data-urlencode "wp-submit=Log In" \
	--data-urlencode "redirect_to=$URL/wp-admin/" --data-urlencode "testcookie=1" "$URL/wp-login.php"

for page in "openvault:Dashboard" "openvault-backup-now:Backup Now" "openvault-schedules:Schedules" "openvault-backups:History" \
	"openvault-restore:Restore" "openvault-destinations:Destinations" "openvault-guides:Setup Guides" \
	"openvault-settings:Settings" "openvault-system:System Info" "openvault-logs:Logs"; do
	slug=${page%%:*}; title=${page#*:}
	body=$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?page=$slug")
	# Here-strings, not `echo | grep -q`: with pipefail an early-exiting grep makes echo die of SIGPIPE on big pages.
	if grep -q "<h1>$title</h1>" <<<"$body" && ! grep -qi "fatal error" <<<"$body"; then
		echo "PASS admin page $slug (HTTP, logged in)"
	else
		echo "FAIL admin page $slug"; CHECK_STATUS=1
	fi
done

body=$(curl -s -b "$JAR" "$URL/wp-admin/admin.php?page=openvault-system")
if grep -q "ovb-copy-report" <<<"$body"; then echo "PASS system info copy button served"; else echo "FAIL system info copy button"; CHECK_STATUS=1; fi
if grep -q "5 of 5 present" <<<"$body"; then echo "PASS system info shows 5 of 5 tables over HTTP"; else echo "FAIL system info tables over HTTP"; CHECK_STATUS=1; fi

# Logged-out visitors must not reach the pages.
anon=$(code "$URL/wp-admin/admin.php?page=openvault-system")
if [ "$anon" = 302 ]; then echo "PASS anonymous request is redirected to login"; else echo "FAIL anonymous access returned $anon"; CHECK_STATUS=1; fi

step "PHP problems attributable to the plugin"
LOG=/var/www/html/wp-content/debug.log
if [ -f "$LOG" ] && grep -E "PHP (Warning|Notice|Deprecated|Fatal error|Parse error)" "$LOG" | grep -q "openvault-backup"; then
	echo "FAIL plugin triggered PHP diagnostics:"
	grep -E "PHP (Warning|Notice|Deprecated|Fatal error|Parse error)" "$LOG" | grep "openvault-backup" | head -10
	CHECK_STATUS=1
else
	echo "PASS no PHP warnings/notices/deprecations from the plugin"
fi
if grep -Ei "PHP (Warning|Fatal)" /tmp/apache.log 2>/dev/null | grep -q "openvault-backup"; then
	echo "FAIL Apache error log mentions the plugin"; CHECK_STATUS=1
fi

exit "$CHECK_STATUS"
