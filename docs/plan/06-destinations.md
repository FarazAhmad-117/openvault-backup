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
