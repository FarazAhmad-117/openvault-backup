# 02 – Architecture

## Namespaces (PSR-4, `includes/` → `OpenVault\Backup\`)
| Layer | Folder | Role |
|---|---|---|
| Core | `Core/` | Bootstrap, activation, migrations, capabilities |
| Data | `Database/` | Repositories for custom tables |
| Engine | `Engine/` | Resumable step-based job runner |
| Archive | `Archive/` | Format implementations + capability detection |
| Storage | `Destinations/` | One class per provider behind `DestinationInterface` |
| Restore | `Restore/` | Restore orchestration and self-protection |
| Scheduling | `Scheduling/` | Action Scheduler wrapper, retention |
| Security | `Security/` | Credential encryption, authenticated downloads |
| UI | `Admin/`, `Rest/`, `assets/` | Pages, REST endpoints, JS/CSS |
| Support | `Support/` | Logger, system info, manifest |

## Request/flow overview
```
Admin UI / Schedule ──► Rest\RestController / Scheduling\Scheduler
                               │
                               ▼
                       Engine\JobRunner ──► Steps (Preflight → DB export → File scan
                               │              → Archive → [Encrypt] → Upload
                               │              → Retention → Cleanup)
                               ▼
                 Database\* repositories   Destinations\* providers
```

## Extensibility hooks (planned)
- `ovb_register_destinations` – add third-party providers
- `ovb_backup_exclusions` – filter exclusion list
- `ovb_before_backup`, `ovb_after_backup`, `ovb_before_restore`, `ovb_after_restore`

## Dependencies
| Dependency | Why | Location |
|---|---|---|
| Action Scheduler | Reliable queued/recurring jobs | `lib/action-scheduler/` |
| phpseclib (Phase 3) | SFTP | via Composer, prefixed with Strauss/PHP-Scoper to avoid conflicts |
| No Google/AWS SDK | Size; use own HTTP + SigV4 | — |
