set -eu
wps() { php -d disable_functions= -d allow_url_fopen=1 -d memory_limit=512M /usr/local/bin/wp-cli.phar --allow-root --path=/var/www/html "$@"; }
for i in $(seq 1 60); do php -d disable_functions= -r 'mysqli_report(MYSQLI_REPORT_OFF); $c=@mysqli_connect("db","root","root","wp"); exit($c?0:1);' 2>/dev/null && break; sleep 2; done
wps core download --force --quiet
wps config create --dbname=wp --dbuser=root --dbpass=root --dbhost=db --skip-check --force --quiet --extra-php <<'PHP'
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_AUTO_UPDATE_CORE', false );
define( 'DISALLOW_FILE_EDIT', true );
PHP
wps core install --url=http://example.test --title="Example Site" --admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email --quiet
mkdir -p /var/www/html/wp-content/mu-plugins
cat > /var/www/html/wp-content/mu-plugins/clean.php <<'PHP'
<?php
add_filter( 'pre_site_transient_update_core', function () { return (object) array( 'last_checked' => time(), 'updates' => array(), 'version_checked' => get_bloginfo( 'version' ) ); } );
add_filter( 'pre_site_transient_update_plugins', function () { return (object) array( 'last_checked' => time(), 'response' => array(), 'translations' => array(), 'no_update' => array() ); } );
add_filter( 'pre_site_transient_update_themes', function () { return (object) array( 'last_checked' => time(), 'response' => array(), 'translations' => array(), 'no_update' => array() ); } );
add_action( 'admin_init', function () { remove_action( 'admin_notices', 'update_nag', 3 ); remove_action( 'network_admin_notices', 'update_nag', 3 ); } );
add_filter( 'admin_footer_text', '__return_empty_string' );
add_filter( 'update_footer', '__return_empty_string', 99 );
add_filter( 'wp_fatal_error_handler_enabled', '__return_false' );
// Show only the screens that exist in a release (Backups, Restore, Destinations, System Info): hide the unreleased ones (see website/README.md, Honesty rules for visuals).
add_action( 'admin_menu', function () { foreach ( array( 'openvault-schedules', 'openvault-settings', 'openvault-logs' ) as $s ) { remove_submenu_page( 'openvault', $s ); } }, 999 );
PHP
wps plugin install /zips/openvault-backup-0.3.0.zip --activate
wps option update blogdescription "Just another example"
wps rewrite structure '/%postname%/' --hard --quiet || true
wps post delete 1 2 --force || true
for t in "Hello, world" "Spring menu" "Notes from the studio" "Launch day" "A short guide to our process" "Behind the scenes"; do wps post create --post_title="$t" --post_status=publish --post_content="Example content for $t. Lorem ipsum dolor sit amet, consectetur adipiscing elit." --quiet; done
for t in "About" "Contact" "Services"; do wps post create --post_type=page --post_title="$t" --post_status=publish --post_content="Example page." --quiet; done
for i in 1 2 3 4; do convert -size 1600x1000 gradient:'#445566-#aa8866' /tmp/img$i.jpg 2>/dev/null || php -r '$im=imagecreatetruecolor(1600,1000);imagefill($im,0,0,imagecolorallocate($im,60+'$i'*30,80,120));imagejpeg($im,"/tmp/img'$i'.jpg");' 2>/dev/null || head -c 600000 /dev/urandom > /tmp/img$i.jpg; wps media import /tmp/img$i.jpg --quiet || true; done
wps user update admin --display_name=admin --nickname=admin --first_name= --last_name= --quiet
chown -R www-data:www-data /var/www/html
# pretty permalinks need AllowOverride + a hand-written .htaccess in this image (WP-CLI cannot write it here)
printf "<Directory /var/www/html>
AllowOverride All
</Directory>
" > /etc/apache2/conf-enabled/z.conf
printf "RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %%{REQUEST_FILENAME} !-f
RewriteCond %%{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
" > /var/www/html/.htaccess
# dummy media so a backup has a believable size
mkdir -p /var/www/html/wp-content/uploads/2026/10
for i in $(seq 1 8); do head -c $((900000 + i*270000)) /dev/urandom > /var/www/html/wp-content/uploads/2026/10/studio-photo-$i.jpg; done
for i in $(seq 1 10); do head -c $((5000000 + i*400000)) /dev/urandom > /var/www/html/wp-content/uploads/2026/10/event-gallery-$i.jpg; done
chown -R www-data:www-data /var/www/html
echo DONE
