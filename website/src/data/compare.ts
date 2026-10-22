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
    text: 'Works today in pre-alpha: restore from history (whole site, database only or files only) with a safety snapshot first and an Undo. Pre-alpha, so try it on a copy first. Designed but not built: restoring an uploaded archive and rediscovering backups from manifest.json on a fresh install.',
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
    'You need backups that work today. UpdraftPlus is a released plugin on WordPress.org; OpenVault cannot run schedules, restore an uploaded archive or send backups to Google Drive yet.',
    'You want the destinations its WordPress.org page lists for the free version, available now.',
  ],
  backwpup: [
    'You need backups that work today. BackWPup is a released plugin; OpenVault cannot run schedules, restore an uploaded archive or send backups to Google Drive yet.',
    'You want local, Dropbox, FTP or S3-compatible destinations in a free plugin now, as its WordPress.org page lists.',
  ],
  duplicator: [
    'You need to move a site to a new host or domain now. Duplicator is a released plugin that lists migration; OpenVault migration is planned for Phase 3.',
    'You want a released, widely used restore available today; OpenVault restore is new, pre-alpha and limited to backups in its own history.',
  ],
};
