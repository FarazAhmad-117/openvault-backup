# 05 – Archive Formats

`CapabilityDetector` decides what appears in the UI. Each option shows a short "best for" text.

| Format | Requirement | Pros | Cons | Phase |
|---|---|---|---|---|
| ZIP (deflate) | ZipArchive | Universal, single-file extraction | Slightly larger than tar.gz | MVP (default) |
| ZIP (store) | ZipArchive | Fastest, near-zero CPU; ideal for media | No compression | MVP |
| TAR.GZ | zlib only (own streaming writer, PharData is not used) | Smaller, streams well, a big file can span several runs | No random access | MVP |
| TAR.BZ2 | PharData + bz2 | Smaller still | Slow | Phase 2 |
| 7z / XZ / Zstd | CLI binaries via exec | Best ratio | Rarely available on shared hosts | Maybe, only if detected |

## Split archives
User-set part size (`ovb_settings['part_size_mb']`, default 250 MB, never below 10 MB, never above 4096 MB). Each part is a valid archive on its own: `db.part01.<ext>` holds `db/db.sql`, then `files.part01.<ext>`, `files.part02.<ext>` ... A part can exceed the limit by less than one file, and a file that alone is as big as the limit gets a part of its own. The load that counts is the real compressed size for TAR.GZ and the uncompressed bytes added so far for ZIP (an estimate, ZIP compresses when it is closed). What was built is described in `04-backup-engine.md` ("Implemented in Milestone 3 step 3c").

ZIP limit: one file is read and compressed in one go (libzip closes atomically), so a single file of 1 GB or more cannot be split over several runs; use TAR.GZ for such sites. TAR.GZ cannot be read by PharData when it was written over several runs (several gzip members); `tar`, `gzip`, 7-Zip and `gzopen()` read it, and the restore uses `gzopen()`.

## Encryption (Phase 2)
AES-256 (libsodium secretstream preferred). Password not stored; manifest records `encrypted: true`. Clear warning: lost password = unrecoverable backup.

## Manifest (stored next to every backup, never encrypted except sensitive fields)
uuid, plugin version, site URL, WP/PHP versions, table prefix, contents, format, parts + checksums, created_at, encrypted flag.
