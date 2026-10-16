# Guide: Real server cron for reliable schedules (outline)
Why: WP-Cron only runs when someone visits the site.
Steps: add `define('DISABLE_WP_CRON', true);` to wp-config → in hosting panel (cPanel/hPanel) add cron every 5 minutes:
`wget -q -O - https://example.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1`
Alternative: external free cron services.
