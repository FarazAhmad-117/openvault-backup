// Every competitor statement on the site lives here, each with the official page it came from and the date we read it.
// Rules: only facts we fetched and read from an official vendor or WordPress.org page; neutral wording; no prices;
// no subjective judgement. If a topic cannot be verified, leave it out: the pages print "Not verified".
export type Topic = 'destinations' | 'scheduling' | 'restore' | 'pricing-model' | 'credentials';

export interface Fact { topic: Topic; claim: string; source: string; checkedOn: string }

export interface Competitor {
  slug: 'updraftplus' | 'backwpup' | 'duplicator';
  name: string;
  officialUrl: string;
  wporgUrl: string;
  summary: string;
  facts: Fact[];
  checkedOn: string;
}

export const CHECKED_ON = '2026-10-15';
export const TRADEMARK_NOTICE = 'UpdraftPlus, BackWPup and Duplicator are trademarks of their respective owners; OpenVault is not affiliated with them.';
export const topicLabels: Record<Topic, string> = {
  destinations: 'Storage destinations',
  scheduling: 'Scheduling',
  restore: 'Restore',
  'pricing-model': 'Free and paid plans',
  credentials: 'Credentials and cloud access',
};
export const topicOrder: Topic[] = ['destinations', 'scheduling', 'restore', 'pricing-model', 'credentials'];

const UP = 'https://wordpress.org/plugins/updraftplus/';
const BW = 'https://wordpress.org/plugins/backwpup/';
const DU = 'https://wordpress.org/plugins/duplicator/';
const f = (topic: Topic, claim: string, source: string): Fact => ({ topic, claim, source, checkedOn: CHECKED_ON });

export const competitors: Competitor[] = [
  {
    slug: 'updraftplus',
    name: 'UpdraftPlus',
    officialUrl: 'https://teamupdraft.com/updraftplus/',
    wporgUrl: UP,
    summary: 'The UpdraftPlus page on WordPress.org describes it as a plugin to "backup, restore and migrate" WordPress websites.',
    checkedOn: CHECKED_ON,
    facts: [
      f('destinations', 'The free version on WordPress.org lists backing up to "Dropbox, Google Drive, Amazon S3 (or compatible), Rackspace Cloud, FTP, DreamObjects, Openstack Swift or email".', UP),
      f('scheduling', 'The WordPress.org page says you can back up manually or "schedule to run every 2, 4, 8 or 12 hours, daily, weekly, monthly or fortnightly".', UP),
      f('restore', 'The WordPress.org page says you can "restore your WordPress website in just a few clicks".', UP),
      f('pricing-model', 'The WordPress.org page says a paid Premium version exists, and lists database encryption, incremental backups and automatic backups before updates among its extras.', UP),
      f('credentials', 'A notice on the WordPress.org page says new connections to Google Drive "can only access backups created by UpdraftPlus directly".', UP),
    ],
  },
  {
    slug: 'backwpup',
    name: 'BackWPup',
    officialUrl: 'https://backwpup.com/',
    wporgUrl: BW,
    summary: 'The BackWPup page on WordPress.org describes it as "a complete WordPress backup and restore solution".',
    checkedOn: CHECKED_ON,
    facts: [
      f('destinations', 'The WordPress.org page says the free version supports "Website Server (local), Dropbox, FTP, Microsoft Azure, Amazon S3 and compatible storage, SugarSync, and Rackspace Cloud Files".', BW),
      f('destinations', 'The same page says BackWPup Pro "adds Google Drive, Microsoft OneDrive, Amazon Glacier, and HiDrive".', BW),
      f('scheduling', 'The WordPress.org page says you can "Set automatic backups to run hourly, daily, weekly, or monthly".', BW),
      f('restore', 'The WordPress.org page says that in the free version "you can restore backups you upload manually", while BackWPup Pro "lets you restore directly from any storage destination, including the cloud".', BW),
      f('pricing-model', 'The WordPress.org page lists a standalone restore application, which can recover a site "even when WordPress itself is unavailable", among the features of BackWPup Pro.', BW),
    ],
  },
  {
    slug: 'duplicator',
    name: 'Duplicator',
    officialUrl: 'https://duplicator.com/',
    wporgUrl: DU,
    summary: 'The Duplicator page on WordPress.org describes it as "a simple way to move WordPress sites, create reliable backups, or clone a site for staging".',
    checkedOn: CHECKED_ON,
    facts: [
      f('destinations', 'The WordPress.org page says "The free plugin stores backups locally and on Duplicator Cloud."', DU),
      f('destinations', 'The same page says Duplicator Pro "adds Dropbox, Google Drive, OneDrive, Amazon S3, FTP/SFTP and any S3-compatible provider".', DU),
      f('scheduling', 'The WordPress.org page says Duplicator Pro "comes with scheduled backups, cloud storage integrations, multisite support, staging sites and more".', DU),
      f('restore', 'The WordPress.org page says you can "Restore any backup with a single click from your WordPress dashboard".', DU),
      f('pricing-model', 'The WordPress.org page describes a free plugin and a paid Duplicator Pro that adds scheduled backups, cloud storage integrations, multisite support and staging sites.', DU),
    ],
  },
];

export const factsFor = (c: Competitor, topic: Topic) => c.facts.filter((x) => x.topic === topic);
