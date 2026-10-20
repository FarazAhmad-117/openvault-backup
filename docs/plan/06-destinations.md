# 06 – Storage Destinations

## Interface (every provider)
`testConnection()`, `upload()` (chunked & resumable), `download()` (streamed), `delete()`, `list()` (for re-indexing), `quota()` (if supported).

## Providers
| Provider | Free offering* | Auth | Phase |
|---|---|---|---|
| Google Drive | 15 GB | User's own OAuth app, scope `drive.file` | MVP |
| S3-compatible: Cloudflare R2 | ~10 GB, no egress fees | Access key / secret | MVP |
| S3-compatible: Backblaze B2 | ~10 GB | App key | MVP |
| S3-compatible: AWS S3, Wasabi, DO Spaces, IDrive e2, MinIO | Varies / trial / self-hosted | Access key | MVP |
| Local | Free | – | MVP |
| Dropbox | 2 GB | User's own app (PKCE) | Phase 3 |
| OneDrive | 5 GB | User's own Azure app | Phase 3 |
| FTP / SFTP | Own server | Password / key | Phase 3 |
| Cloudinary | Free credits | – | **Dropped** (raw file size limit too small) |

*Free tiers change: UI links to each provider's pricing page instead of hard-coding numbers.

## S3 presets
Each preset pre-fills endpoint format, region rules, and path-style vs virtual-host addressing. A "Custom S3-compatible" option takes any endpoint.

## Remote folder layout
```
OpenVault-Backups/<site-host>/<YYYY-MM-DD_HHMMSS>_<uuid8>/
   manifest.json
   db.part01.zip
   files.part01.zip ...
```

## Implemented in Milestone 4 step 4a (framework, Local destination, upload step)

**Contract** (`Destinations\DestinationInterface`, resumable by design): `id()`, `label()`, `test_connection(): TestResult`, `begin_upload( remote_path, size, options ): array` (opaque JSON-safe session; `options['sha256']` is the digest the stored file must match), `upload_chunk( session, local_file, Budget ): UploadProgress` (sends as much as the budget allows, at least one chunk, reads the file in bounded chunks), `abort_upload( session )`, `download( remote_ref, sink )` (streamed; the sink returns false to stop), `delete( remote_ref )` (file or backup folder), `list( prefix, limit )` (lazy, for re-indexing), `quota(): ?array{free,total}`, `remote_root()`. A session may hold an upload id or session URL, never a token, key or password. Value objects: `TestResult` (ok, scrubbed message, details), `UploadProgress` (new session, bytes sent by this call, done, remote_ref), `DestinationException` (`retryable()`: transient errors back off, permanent ones fail the file; `reason()`: transient, permanent, needs_reconnect, unknown_type; the message is scrubbed of tokens). Providers are told nothing about the engine and the engine nothing about providers.

**Registry** (`Destinations\DestinationRegistry`): `register( type, factory )` (a callable `( settings, credentials, row )` or a class name built with `( settings, credentials )`), `types()`, `has()`, `create( row )`. `create()` is the only place credentials are decrypted (`DestinationsRepository::get_credentials()`); unreadable credentials or a row with status `needs_reconnect` raise `DestinationException::needs_reconnect()`, an unknown type raises reason `unknown_type`. The filter `ovb_register_destinations` receives the type => factory map and may add, replace or remove providers (invalid entries are dropped). `local` is built in; `s3` was added in step 4b (see below); `gdrive` comes later.

**Remote layout** (`Destinations\RemotePath`, pure): `OpenVault-Backups/<site-host>/<YYYY-MM-DD_HHMMSS>_<uuid8>/<file>`. The host is the host and port of `site_url()`, lower case, only `a-z 0-9 . -` (port as `-8888`), at most 100 characters, `site` when nothing remains. A file name must be one segment of `A-Za-z0-9._-` (no leading dot, no `..`), the folder must match the backup folder format. `is_safe()` checks any reference a destination receives: relative, forward slashes, no empty, `.` or `..` segment, no control character, no drive letter.

**Local destination** (`Destinations\Local\LocalDestination`, settings `path`): an empty path means the plugin's own protected backups folder (`Paths::backups_dir()`): the backup is already there, so `upload_chunk()` returns done at once for a file that lies inside the base (bytes sent 0, `remote_ref` = the file's path relative to the base, nothing is copied and no `OpenVault-Backups` tree is created). A custom path must be absolute, without `.`, `..` or empty segments (also checked on the deepest existing ancestor resolved with `realpath()`, so a symlink in the path is seen before anything is created), creatable, writable, and not the WordPress folder itself, `wp-admin`, `wp-includes` or the plugin's tmp folder (compared on the given and the resolved path, case-insensitive for drive paths). Inside the WordPress folder it gets guard files (`Paths::ensure_protected_dir`); outside it needs none (preferred). Every remote path is validated and no part of it below the base may be a symlink (never followed for upload, download, delete or list). Messages never contain absolute paths.

**Session (Local)**: `{ "v": 1, "p": "<remote path>", "o": <offset>, "s": <size>, "h": "<sha256 or empty>" }`. The copy goes into `<name>.part` in 1 MB chunks (`fopen`/`fread`/`fwrite`), the budget is checked after each chunk, and the session returned after each call carries the new offset. A resumed call cuts the partial file back to the saved offset (or restarts at 0 when it is gone or shorter). On completion the size and (up to 256 MB) the sha256 are verified and the file is renamed; a wrong copy is removed and raises a retryable exception. The source must still have the size recorded when the upload began. `delete()` removes a file or folder only below the base and prunes empty parent folders up to (never including) the base; `list()` skips `.part` files, guard files and symlinks; `quota()` uses `disk_free_space()`; `test_connection()` writes, reads back and deletes a probe file.

**Upload step**: see 04-backup-engine.md ("Implemented in Milestone 4 step 4a"). Not built yet: Google Drive, remote retention (the Destinations page and REST CRUD came in 4c).

## Implemented in Milestone 4 step 4b (S3-compatible destination)

**Files** (`Destinations\S3\`): `SigV4Signer` (pure; the secret never leaves the class and is hidden from dumps), `S3Client` (HTTP, XML, error mapping; injectable transport), `EndpointValidator` (pure SSRF guard), `S3Presets` (provider table, settings validation, key building), `MultipartUploader`, `S3Destination`. Type slug `s3`, label "S3-compatible storage", registered as a built-in. No AWS SDK.

**Settings** (JSON in `ovb_destinations.settings`): `preset` (aws, r2, b2, wasabi, spaces, custom), `region` or `account_id` (r2) or `endpoint` (custom), `addressing` (`path`, `virtual` or empty for the preset default), `bucket`, `prefix`, `storage_class` (STANDARD, STANDARD_IA, ONEZONE_IA, INTELLIGENT_TIERING, GLACIER_IR), `part_size_mb` (5 to 64, default 8), `allow_insecure_http`, `allow_private_endpoint`. Credentials (vault only): `access_key`, `secret_key`.

**Presets** (patterns from each provider's own documentation, see `website/src/data/providers.ts`): aws `s3.<region>.amazonaws.com` (virtual-hosted default), r2 `<account_id>.r2.cloudflarestorage.com` (region `auto`, path-style, 32 hex account id), b2 `s3.<region>.backblazeb2.com` (e.g. `us-west-004`), wasabi `s3.<region>.wasabisys.com`, spaces `<region>.digitaloceanspaces.com` (signed with `us-east-1`, which DigitalOcean documents as validation only), custom (endpoint required, region default `us-east-1`, path-style). Region and account id are checked by a regex per preset, so a preset can never build a host outside its provider's domain. A dotted bucket or an IP endpoint always falls back to path-style. A private host (MinIO, SeaweedFS, Ceph) needs `allow_private_endpoint`; plain http additionally needs `allow_insecure_http`.

**Keys and references**: the remote reference is the same relative path as for Local (`OpenVault-Backups/<site-host>/<folder>/<file>`); the object key is `<prefix>/<reference>`. Every reference must start with `OpenVault-Backups/`, pass `RemotePath::is_safe()`, and the key is at most 1024 bytes, so nothing else in the bucket can be read, listed or deleted through the plugin.

**Upload**: a file up to one part is a single PUT; a bigger one uses multipart. Part size: the configured value (default 8 MiB) clamped to 5 to 64 MiB, at most about 25% of `memory_limit` (a part is read into one string because the body of `wp_remote_request` is a string, so one part costs roughly its own size in memory), raised in whole MiB when the file would need more than 10,000 parts; a file that does not fit in 10,000 parts of 64 MiB is refused when the upload starts. The last part may be smaller. `upload_chunk()` sends whole parts until the budget cannot fit another (time left under max(5 s, 1.5 x the last part's duration)), but always at least one. Request timeouts come from the remaining budget (5 to 25 s).

**Session** (stored by the upload step after every call, a few hundred bytes, far below the 64 KB column): `{ v, path, size, part_size, upload_id, next_part, offset, restarts }`. No ETags and no part list. When all parts are sent the server's own list (ListParts, paginated 1000 per page) is the truth: a part the server lacks or holds with a wrong size sends the upload back to the first such part (a limited number of times); stray parts beyond the file are ignored; then CompleteMultipartUpload and a HEAD whose size must equal the local size (otherwise a permanent error naming both sizes). A vanished upload (NoSuchUpload, for example removed by a lifecycle rule) is accepted when the object already exists with the right size, otherwise started again (at most twice). A crash after any part simply re-sends from the stored `next_part`; sending a part twice is harmless.

**Integrity**: `Content-MD5` on every PUT and part. For a single PUT the returned ETag is compared with the local md5 only when it looks like an md5 and the server reports no server-side encryption other than AES256 (multipart and SSE-KMS ETags are not md5s, so they are never compared). Payloads are signed with their real SHA-256 (cheap for 8 MiB parts); `UNSIGNED-PAYLOAD` exists in the signer but the client never uses it.

**Errors** (S3Client): retryable = network failure, 5xx, 429, SlowDown, RequestTimeout, InternalError, ServiceUnavailable, throttling codes, BadDigest and similar damage in transit. Permanent with an actionable message = SignatureDoesNotMatch (check keys, region, endpoint), InvalidAccessKeyId, AccessDenied, NoSuchBucket (with the bucket name), AuthorizationHeaderMalformed (shows the region the server asks for, only when it is a plain token), redirects (301/307: wrong region or endpoint, never followed), RequestTimeTooSkewed ("The clock of this server differs from the storage server's clock by more than 15 minutes"). Messages are fixed texts plus a sanitised code and status: server error text is never copied (it can echo keys), and secrets, the Authorization header, signatures and signed URLs are never in a message. A 200 response to CompleteMultipartUpload that contains `<Error>` is an error. A HEAD on the bucket that fails with a bare 400/403 is repeated as a one-key listing to learn the real reason.

**test_connection()**: HEAD bucket, then PUT, ranged GET and DELETE of `<prefix>/.ovb-probe-<random>`; the probe is removed even when the read-back fails. `download()` uses 8 MiB ranged GETs (3 attempts per range for transient errors). `delete()` of a backup folder deletes the objects below it (a DELETE on a bare prefix fails on some servers, so the key itself is only deleted when nothing lies below it). `list()` is lazy (ListObjectsV2, continuation tokens). `quota()` is null. `abort_stale_uploads( older_than )` aborts multipart uploads below `OpenVault-Backups/` older than the given age; an upload whose start time the server does not report (SeaweedFS omits it) is never aborted. It is a utility and not wired to anything yet.

**Tested**: unit (SigV4 against the AWS documentation vectors, endpoint and preset validation with many hostile inputs, client error mapping and XXE payloads, uploader and destination against an in-memory S3 that verifies every signature) and in the Docker matrix against a real S3 server (single and multipart upload with simulated crashes, ListParts past 1000 parts, wrong secret, wrong bucket, a full backup through the JobRunner, a partial result with a wrong-secret destination). MinIO's Docker images can no longer be pulled, so the matrix uses SeaweedFS (`chrislusf/seaweedfs`, alias `s3srv`).

**Not built yet**: Google Drive, remote retention, wiring of `abort_stale_uploads()`.

## Implemented in Milestone 4 step 4c (user-facing destinations)
- **Routes and page**: REST CRUD under `ovb/v1/destinations` (`Rest\DestinationsRestController`), the Destinations page, the destination picker on Back up now and destination labels in History: see 10-admin-ui.md ("Implemented in Milestone 4 step 4c"). Secrets are write-only: see 09-security.md.
- **Pure helpers**: `Destinations\DestinationSettings` (validation of what the browser sends, per type; s3 reuses `S3Presets::resolve()` and `EndpointValidator`, local reuses `LocalDestination::validate()`), `Destinations\DestinationPresenter` (list items without credentials, status text, details line, whether a destination can be selected for a backup). `DestinationsRepository::mark_untested()` resets the status after the settings of a destination change.
- **Choices**: only types with a validator can be created from the browser (`local`, `s3`); `part_size_mb` is not editable in the UI (default 8 MiB); the storage class select is shown for Amazon S3 only; a destination that last failed its test, or needs reconnect, cannot be selected for a backup until it is fixed and tested; "new" (never tested) can, so a user is not forced to test first.
- **Not built yet**: Google Drive, Dropbox, OneDrive and SFTP destinations, remote retention, scheduled backups choosing destinations, importing existing remote backups.
