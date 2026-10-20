// What OpenVault is DESIGNED to do, per comparison topic. Only design facts from the repo docs.
// Each entry carries registry ids so the page shows the real status badge. Nothing here is Available.
import type { Topic } from './competitors';

export interface DesignPoint { text: string; badges: readonly (readonly [string, string])[] }

export const openvaultDesign: Record<Topic, DesignPoint> = {
  destinations: {
    text: 'Designed to back up to your own Google Drive, to S3-compatible storage (AWS S3, Cloudflare R2, Backblaze B2, Wasabi, DigitalOcean Spaces) or to a protected local folder. Every destination is free.',
    badges: [['Google Drive', 'destination-gdrive'], ['S3-compatible', 'destination-s3'], ['Local', 'destination-local']],
  },
  scheduling: {
    text: 'Designed to run scheduled backups from hourly to monthly through Action Scheduler, in resumable chunks sized for shared hosting.',
    badges: [['Scheduled backups', 'backup-scheduled']],
  },
  restore: {
    text: 'Designed to restore from history (full, database-only or files-only) and to take a safety snapshot first, so a restore can be undone. A manifest.json next to each backup is meant to let a fresh install rediscover it.',
    badges: [['Restore from history', 'restore-in-place'], ['Restore from upload', 'restore-upload']],
  },
  'pricing-model': {
    text: 'Released under the GPLv2 or later. The design has no paid tiers: every feature is free.',
    badges: [],
  },
  credentials: {
    text: 'Designed so you connect your own Google OAuth app or your own S3 keys, with no relay server in the middle. The Google Drive scope is drive.file only.',
    badges: [['Google Drive', 'destination-gdrive'], ['S3-compatible', 'destination-s3']],
  },
};

export const fitCompetitor: Record<string, string[]> = {
  updraftplus: [
    'You need backups that work today. UpdraftPlus is a released plugin on WordPress.org; OpenVault cannot restore or send backups off-site yet.',
    'You want the destinations its WordPress.org page lists for the free version, available now.',
  ],
  backwpup: [
    'You need backups that work today. BackWPup is a released plugin; OpenVault cannot restore or send backups off-site yet.',
    'You want local, Dropbox, FTP or S3-compatible destinations in a free plugin now, as its WordPress.org page lists.',
  ],
  duplicator: [
    'You need to move a site to a new host or domain now. Duplicator is a released plugin that lists migration; OpenVault migration is planned for Phase 3.',
    'You want one-click restore available today.',
  ],
};
