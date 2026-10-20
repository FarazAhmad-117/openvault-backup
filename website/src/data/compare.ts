// What OpenVault is DESIGNED to do, per comparison topic. Only design facts from the repo docs.
// Each entry carries registry ids so the page shows the real status badge. Only what a badge says Available works today.
import type { Topic } from './competitors';

export interface DesignPoint { text: string; badges: readonly (readonly [string, string])[] }

export const openvaultDesign: Record<Topic, DesignPoint> = {
  destinations: {
    text: 'Works today in pre-alpha: S3-compatible storage (presets for AWS S3, Cloudflare R2, Backblaze B2, Wasabi, DigitalOcean Spaces, verified live against one S3-compatible server only) and a local folder. Designed but not built: your own Google Drive. Every destination is free.',
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
    text: 'You connect your own S3 keys today, with no relay server in the middle; they are encrypted at rest and never shown again. Designed but not built: your own Google OAuth app, with the drive.file scope only.',
    badges: [['Google Drive', 'destination-gdrive'], ['S3-compatible', 'destination-s3']],
  },
};

export const fitCompetitor: Record<string, string[]> = {
  updraftplus: [
    'You need backups that work today. UpdraftPlus is a released plugin on WordPress.org; OpenVault cannot restore, run schedules or send backups to Google Drive yet.',
    'You want the destinations its WordPress.org page lists for the free version, available now.',
  ],
  backwpup: [
    'You need backups that work today. BackWPup is a released plugin; OpenVault cannot restore, run schedules or send backups to Google Drive yet.',
    'You want local, Dropbox, FTP or S3-compatible destinations in a free plugin now, as its WordPress.org page lists.',
  ],
  duplicator: [
    'You need to move a site to a new host or domain now. Duplicator is a released plugin that lists migration; OpenVault migration is planned for Phase 3.',
    'You want one-click restore available today.',
  ],
};
