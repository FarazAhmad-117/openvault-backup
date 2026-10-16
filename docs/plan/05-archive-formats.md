# 05 – Archive Formats

`CapabilityDetector` decides what appears in the UI. Each option shows a short "best for" text.

| Format | Requirement | Pros | Cons | Phase |
|---|---|---|---|---|
| ZIP (deflate) | ZipArchive | Universal, single-file extraction | Slightly larger than tar.gz | MVP (default) |
| ZIP (store) | ZipArchive | Fastest, near-zero CPU; ideal for media | No compression | MVP |
| TAR.GZ | PharData + zlib | Smaller, streams well | No random access | MVP |
| TAR.BZ2 | PharData + bz2 | Smaller still | Slow | Phase 2 |
| 7z / XZ / Zstd | CLI binaries via exec | Best ratio | Rarely available on shared hosts | Maybe, only if detected |

## Split archives
User-set part size (default 250 MB). Each part is a valid archive on its own.

## Encryption (Phase 2)
AES-256 (libsodium secretstream preferred). Password not stored; manifest records `encrypted: true`. Clear warning: lost password = unrecoverable backup.

## Manifest (stored next to every backup, never encrypted except sensitive fields)
uuid, plugin version, site URL, WP/PHP versions, table prefix, contents, format, parts + checksums, created_at, encrypted flag.
