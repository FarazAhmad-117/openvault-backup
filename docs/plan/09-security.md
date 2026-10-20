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

## Implemented in Milestone 2 (CredentialVault)
- **Cipher**: libsodium `sodium_crypto_secretbox` (XSalsa20-Poly1305), random 24-byte nonce per message.
- **Envelope**: `ovb1:` + base64( nonce . ciphertext ). The prefix carries the version; unknown versions fail with `unsupported_version`. Arrays are stored as JSON inside the envelope, never PHP `serialize()`.
- **Key source**: `OVB_ENCRYPTION_KEY` if defined and non-empty, otherwise `wp_salt( 'auth' )` + `wp_salt( 'secure_auth' )`. The 32-byte key is `sodium_crypto_generichash` over `'ovb-vault-v1|' . material`, zeroed after use. `CredentialVault::key_source()` reports `constant` or `salts` for System Info.
- **Failure behaviour**: if the key changes (salts rotated, constant added/removed) decryption fails safely. `decrypt()` throws `VaultException` with a reason (`invalid_format`, `wrong_key`, `unsupported_version`, `unavailable`); `try_decrypt()` returns `[ ok, value, reason ]` for UI code. It never returns garbage and never fatals. On `wrong_key` the UI should tell the user to reconnect the destination. Wrong key and tampered data are deliberately indistinguishable. Messages never contain plaintext, ciphertext or key material.
- **Also built**: `Engine\JobState` (immutable, JSON-only, cursor capped at 64 KB) and `Support\Manifest` (untrusted-input validation: generator, schema, UUID v4, SHA-256, part-name traversal checks, 10,000 parts / 5 MB limits).

## Implemented in Milestone 3 step 3d (DownloadController)
- **Guarantees**: logged-in + `manage_options` (network admin on multisite) + nonce `ovb_download_<file id>`, otherwise 403 with a one-line body. Anonymous requests also get 403 (`admin_post_nopriv_`).
- **Path from the database only**: the file row (destination 0, upload status `done`) and the backup row (status complete/partial) give uuid, creation time and file name; `Backups\BackupFiles::resolve_file()` builds the path under `<local dir>/backups/<folder>` and accepts it only if the name is a plain name, the file is a regular file and not a symlink, and its `realpath` is inside the real backup folder, which is inside the real backups folder. A poisoned row (`../../wp-config.php`, a symlink) gets 404 (tested over HTTP). Request parameters never name a path.
- **Response**: `Content-Disposition: attachment` with a sanitised name, `Content-Length`, `X-Content-Type-Options: nosniff`, no-cache headers, `Accept-Ranges: bytes`, one single `Range` (206; unsatisfiable 416; malformed or multiple ranges are ignored and the whole file is sent), output buffers dropped, 1 MB chunks with `flush()`, session lock released, time limit lifted, stops on client abort. The file is never read into memory.
- **Delete** (`DELETE /backups/{id}`) refuses locked and running backups and removes only a folder whose name matches `<date>_<time>_<uuid8>` directly inside the backups folder (no symlinks followed).
