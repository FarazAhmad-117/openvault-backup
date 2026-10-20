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
| D6 | Minimum WordPress | 6.5 (was 6.0, briefly 6.9) | Changed: 6.5 |

**Note on D6:** the minimum is **6.5**, the lowest version the Docker matrix passes. Action Scheduler 4.2.0 calls `wp_is_serving_rest_request()` (added in WP 6.5), which sets the floor; the plugin's own `%i` placeholder in `$wpdb->prepare` needs 6.2. Action Scheduler's header says `Requires at least: 6.9`, but that is metadata of its standalone plugin; the bundled library works on 6.5 to 6.8. **Evidence:** WP 6.5, 6.6, 6.7, 6.8, 6.9 and 7.1.3 each pass 741/741 matrix checks; WP 6.4 fails 42 checks (`wp_is_serving_rest_request()` is undefined) and stays as the informational `wp64-info` profile.
