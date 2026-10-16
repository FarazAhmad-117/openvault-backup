# 09 – Security

- **Capability**: `manage_options` (network admin on multisite) for every page, REST route, and AJAX action.
- **Nonces** on all forms and REST calls.
- **Credentials** encrypted at rest (CredentialVault, key from WP salts or `OVB_ENCRYPTION_KEY` constant in wp-config). Secrets never re-displayed in UI (show "••••" + replace button).
- **Local backups**: random folder name, `.htaccess` deny + `index.php`; outside webroot if possible. Warn Nginx users (`.htaccess` ignored).
- **Downloads** streamed through DownloadController only.
- **OAuth state** validation; redirect URI fixed to admin URL.
- **Uploaded archives** for restore: validate it's our manifest, size limits, path traversal checks during extraction (reject `../`).
- **Logs** must never contain tokens or passwords.
- **Standalone restorer**: password protected, random filename, self-delete.
