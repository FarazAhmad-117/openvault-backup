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

## Implemented in Milestone 4 step 4b (S3 endpoint validation, SSRF)
- **Endpoint rules** (`Destinations\S3\EndpointValidator`, pure): https is required. http only when `allow_insecure_http` is on AND the host is private (loopback, private ranges, `.local` and similar suffixes, or a plain name without dots such as a Docker service). A private host at all needs `allow_private_endpoint`. A public host may only use ports 443, 80 and 8080. No user info, query, fragment or path (a bare `/` is fine), no whitespace, control or backslash characters; host labels are ASCII letters, digits and `-` (IDN in punycode form).
- **IP spellings**: only a clean dotted quad or a bracketed IPv6 address. Decimal (`2130706433`), hex (`0x7f.1`), octal and short forms (`127.1`) are refused outright.
- **Never allowed, whatever the flags**: link-local and cloud metadata addresses (169.254.0.0/16, fe80::/10, fd00:ec2::254, IPv4-mapped forms of these), `metadata.google.internal` and similar names, the unspecified addresses (0.0.0.0/8, ::) and the broadcast address.
- **No global switch**: WordPress's URL safety (`reject_unsafe_urls`) stays on for every request; it is turned off per request only when the validated `allow_private_endpoint` setting is true. Redirects are never followed (`redirection => 0`), so a public endpoint cannot bounce the server to an internal address. Hosts are judged by their form, not by DNS; a public name that resolves to a private address is still caught by WordPress's own check (the setting is off by default).
- **Presets build hosts from validated pieces** (a regex per provider for the region or account id), so a preset can never point outside its provider's domain; a preset ignores a foreign `endpoint` setting.
- **Keys**: every reference must start with `OpenVault-Backups/` and pass `RemotePath::is_safe()`; the prefix setting is limited to letters, digits, `.`, `_`, `-` and `/` without `.` or `..` parts. Nothing outside the plugin's folder in the bucket is reachable.
- **Secrets**: the secret key lives only in the signer (hidden from `var_dump` and `print_r`). Error messages are fixed texts with a sanitised S3 code; server error bodies are never copied, XML is parsed with DOCTYPE and entity declarations refused, `LIBXML_NONET` set and (PHP below 8) entity loading disabled.

## Implemented in Milestone 4 step 4c (secrets are write-only)
- **No re-display**: the Destinations form never renders the access key or the secret key, not even in a `value` attribute. Editing a destination shows that keys are saved and offers to replace them; leaving the fields empty keeps the stored ones. `GET /destinations` is built by `DestinationPresenter` from a whitelist and the repository never selects the credentials column for normal reads, so a response cannot carry them (it only says `has_credentials`).
- **Server-side only**: credentials are decrypted in `DestinationRegistry::create()` for a test or an upload and nowhere else. Write path: `DestinationSettings::validate_credentials()` (shape and length) then `CredentialVault` encryption (`ovb1:` envelope). If encryption is unavailable nothing is saved.
- **No leaks**: validators never put a submitted value in a message; test failures pass through `DestinationsRepository::scrub_error()` before they are stored or returned; the logger records only ids and types for destination changes. The matrix greps REST responses, page HTML, `debug.log`, the Apache log and the plugin log table for the real and a wrong secret.
- **Every route**: capability (`manage_options`, `manage_network_options` on multisite) and the REST nonce; anonymous 401, no nonce 401, bad nonce 403, subscriber 403.
- **Unsafe input is refused, not trimmed away**: unknown keys, endpoints that fail the SSRF guard (also on update, where a refused update changes nothing), `..` or absolute prefixes, local folders that are the WordPress root, wp-admin, wp-includes, the tmp folder, relative or contain `..`.
- **Removal** deletes the row and its credentials. Remote files are never deleted by removing a destination, and removal is refused (409) while a queued or running backup uses it.
