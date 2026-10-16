# 00 – Overview

## Vision
A completely free backup plugin where every feature is available to everyone, and the user's data and tokens only ever travel between their own site and their own storage accounts.

## Core principles
- **Bring your own credentials** – no relay servers, no shared OAuth app.
- **Works on cheap shared hosting** – every long task is resumable and chunked (30s timeouts, 128 MB memory).
- **Restore must be trustworthy** – checksums, safety snapshot before restore, the plugin protects itself during restore.
- **Disaster-proof** – a manifest.json next to every backup lets a fresh install rediscover backups.
- **Small footprint** – no full Google/AWS SDKs; lightweight HTTP calls via `wp_remote_*`.
- **Honest UI** – only show formats/features the server actually supports, with explanations.

## Competitive position
| Plugin | Gap we fill |
|---|---|
| UpdraftPlus | Drive auth via their relay; many features paid |
| BackWPup / WPvivid / Duplicator | Paid tiers for destinations, scheduling, or restore features |
| **OpenVault** | Everything free, own credentials, transparent |

## Open decisions (defaults assumed in this plan)
| # | Decision | Assumed default | Status |
|---|---|---|---|
| D1 | Plugin name / slug (no trademark at start of slug) | `openvault-backup` | Confirmed |
| D2 | Minimum PHP | 7.4 | Confirmed |
| D3 | Admin UI tech | Classic PHP pages + small vanilla JS (React optional later) | Confirmed |
| D4 | Standalone restorer in MVP? | No, Phase 3 | Confirmed |
| D5 | Cloudinary support | Dropped (free raw-file size limit too small) | Confirmed |
| D6 | Minimum WordPress | 6.9 (was 6.0) | Changed |

**Note on D6:** the bundled Action Scheduler 4.2.0 declares `Requires at least: 6.9`, so the plugin minimum was raised from 6.0 to 6.9. The Docker matrix (`tests/matrix`) also runs WP 6.4 and 6.8 as informational profiles (minimum lowered in a copy of the zip). **Evidence (2026-10-15):** WP 6.8 passes all 57 checks; WP 6.4 fails because Action Scheduler 4.2.0 calls `wp_is_serving_rest_request()`, which exists only from WP 6.5. So the real floor is between 6.5 and 6.8; 6.9 is the conservative, officially declared choice. To lower it, add 6.5/6.6/6.7 profiles first.
