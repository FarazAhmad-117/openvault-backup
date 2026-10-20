// EDIT THIS FILE when a feature ships. It is the single source of truth for feature status on the site.
// `since` = the plugin version that first shipped the feature. Never mark a feature
// 'available' unless it genuinely works in a released build.
import type { Status } from '../lib/status';

export interface Feature {
  id: string;
  title: string;
  summary: string;
  status: Status;
  since?: string;
  phase: 'MVP' | 'Phase 2' | 'Phase 3';
}

export const features: Feature[] = [
  { id: 'admin-and-system-info', title: 'Admin dashboard shell and System Info', summary: 'See exactly what your server can do before you back up.', status: 'available', since: '0.2.0', phase: 'MVP' },
  { id: 'backup-manual', title: 'One-click local backups', summary: 'Database and files, as ZIP or TAR.GZ, split into parts and resumable. Always saved in a protected folder on your own server, with an optional copy sent to the destinations you choose.', status: 'available', since: '0.2.0', phase: 'MVP' },
  { id: 'backup-manage', title: 'Download, lock and delete backups', summary: 'A backup history with per-destination results, authenticated downloads, a lock against deletion, and cancel for a running backup.', status: 'available', since: '0.2.0', phase: 'MVP' },
  { id: 'backup-scheduled', title: 'Scheduled backups', summary: 'Hourly to monthly schedules powered by Action Scheduler.', status: 'planned', phase: 'MVP' },
  { id: 'destination-gdrive', title: 'Google Drive (your own OAuth app)', summary: 'drive.file scope only. No relay server.', status: 'planned', phase: 'MVP' },
  { id: 'destination-s3', title: 'S3-compatible storage', summary: 'AWS S3, Cloudflare R2, Backblaze B2, Wasabi, DigitalOcean Spaces or a custom endpoint, with resumable multipart uploads. Verified live against one S3-compatible server (SeaweedFS); the others follow documented endpoint patterns.', status: 'available', since: '0.2.0', phase: 'MVP' },
  { id: 'destination-local', title: 'Local storage', summary: 'A protected folder on your own server, plus an optional extra copy sent to another folder you choose.', status: 'available', since: '0.2.0', phase: 'MVP' },
  { id: 'backup-retention', title: 'Retention rules', summary: 'Keep the last N backups or the last X days, with locked backups kept.', status: 'planned', phase: 'MVP' },
  { id: 'restore-in-place', title: 'Restore from history', summary: 'Full, database-only or files-only, with a safety snapshot first.', status: 'planned', phase: 'MVP' },
  { id: 'restore-upload', title: 'Restore from an uploaded archive', summary: 'Chunked upload and remote re-indexing via manifest.json.', status: 'planned', phase: 'Phase 2' },
  { id: 'encryption', title: 'AES-256 encryption', summary: 'Encrypt archives before they leave your server.', status: 'planned', phase: 'Phase 2' },
  { id: 'destination-dropbox-onedrive-sftp', title: 'Dropbox, OneDrive and SFTP', summary: 'More places to keep your backups.', status: 'planned', phase: 'Phase 3' },
  { id: 'migration', title: 'Migration with safe search and replace', summary: 'Move to a new host or domain.', status: 'planned', phase: 'Phase 3' },
];
