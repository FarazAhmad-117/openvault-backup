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
  { id: 'admin-and-system-info', title: 'Admin dashboard shell and System Info', summary: 'See exactly what your server can do before you back up.', status: 'in-progress', phase: 'MVP' }, // flip to 'available' + since: '0.1.0' when the first release is published
  { id: 'backup-manual', title: 'One-click backups', summary: 'Database, plugins, themes, uploads and more, in resumable chunks.', status: 'in-progress', phase: 'MVP' },
  { id: 'backup-scheduled', title: 'Scheduled backups', summary: 'Hourly to monthly schedules powered by Action Scheduler.', status: 'planned', phase: 'MVP' },
  { id: 'destination-gdrive', title: 'Google Drive (your own OAuth app)', summary: 'drive.file scope only. No relay server.', status: 'planned', phase: 'MVP' },
  { id: 'destination-s3', title: 'S3-compatible storage', summary: 'AWS S3, Cloudflare R2, Backblaze B2, Wasabi, DigitalOcean Spaces.', status: 'planned', phase: 'MVP' },
  { id: 'destination-local', title: 'Local storage', summary: 'A protected folder on your own server.', status: 'planned', phase: 'MVP' },
  { id: 'restore-in-place', title: 'Restore from history', summary: 'Full, database-only or files-only, with a safety snapshot first.', status: 'planned', phase: 'MVP' },
  { id: 'restore-upload', title: 'Restore from an uploaded archive', summary: 'Chunked upload and remote re-indexing via manifest.json.', status: 'planned', phase: 'Phase 2' },
  { id: 'encryption', title: 'AES-256 encryption', summary: 'Encrypt archives before they leave your server.', status: 'planned', phase: 'Phase 2' },
  { id: 'destination-dropbox-onedrive-sftp', title: 'Dropbox, OneDrive and SFTP', summary: 'More places to keep your backups.', status: 'planned', phase: 'Phase 3' },
  { id: 'migration', title: 'Migration with safe search and replace', summary: 'Move to a new host or domain.', status: 'planned', phase: 'Phase 3' },
];
