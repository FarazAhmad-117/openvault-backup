// Single source for FAQ copy. Plain text only (it also feeds FAQPage JSON-LD).
// Nothing here may claim a feature works today unless its status in features.ts is 'available'.
export interface Faq { q: string; a: string; featured?: boolean; tag?: string }

export const faqs: Faq[] = [
  {
    tag: 'gdrive', q: 'Why do I need my own Google app?',
    a: 'So your tokens and data only ever travel between your site and your own Google account. There is no relay server in the middle, and nobody else holds your credentials.',
    featured: true,
  },
  {
    tag: 'gdrive', q: 'Why did Google Drive stop working after 7 days?',
    a: "A Google OAuth app left in 'Testing' status loses its refresh token after 7 days. Publish the app to fix this; the setup guide explains how.",
    featured: true,
  },
  {
    tag: 'gdrive', q: 'Does Google Drive access cover my whole Drive?',
    a: 'No. The design uses only the drive.file scope, which limits the plugin to files and folders it creates itself. It is not meant to read the rest of your Drive.',
  },
  {
    tag: 's3', q: 'What does S3-compatible mean?',
    a: 'Many storage services copy the Amazon S3 request format, so one client can talk to all of them. OpenVault Backup uses a single request signer for AWS S3, Cloudflare R2, Backblaze B2, Wasabi, DigitalOcean Spaces and any custom endpoint.',
  },
  {
    tag: 's3', q: 'Do I need an AWS account to use S3-compatible storage?',
    a: 'Only if you choose AWS S3. For the other providers you use an account with that provider, create a bucket there, and give the plugin the endpoint and access keys it issues.',
  },
  {
    tag: 's3', q: 'Does the plugin use the AWS SDK?',
    a: 'No. It signs requests itself with Signature Version 4 and sends them through the WordPress HTTP API. It also uploads a manifest.json with each backup, and large archives use multipart uploads that resume after an interruption.',
  },
  {
    q: 'Which archive formats will it support?',
    a: 'The plugin offers ZIP (deflate, the default), ZIP (store, fastest, good for media) and TAR.GZ. TAR.BZ2 is planned for a later phase. The interface only offers formats your server actually supports.',
  },
  {
    q: 'Does it phone home?',
    a: 'No. The plugin only contacts the destinations you configure. It includes no analytics.',
    featured: true,
  },
  {
    q: 'Is it really free?',
    a: 'Yes. OpenVault Backup is released under the GPLv2 or later. There are no paid tiers, and all features are free.',
    featured: true,
  },
  {
    q: 'Which WordPress and PHP versions does it need?',
    a: 'WordPress 6.5 or newer and PHP 7.4 or newer. It is tested on WordPress 6.5 through the latest release; 6.4 is not supported because the bundled Action Scheduler needs a function added in 6.5.',
  },
  {
    q: 'What happens to my backups if I uninstall the plugin?',
    a: 'The plugin never deletes backups stored at your remote destinations when you uninstall it. They stay in your own storage account, and each one has a manifest.json next to it so a fresh install can find it again.',
  },
  {
    q: 'Will it work on shared hosting?',
    a: 'It is designed for it. Every long task is built to be resumable in small batches, sized for roughly 30 second limits and 128 MB of memory. The pre-alpha 0.3.0 is tested in Docker profiles, including low-memory and restricted ones, but it has not yet been proven on real shared hosts.',
  },
  {
    q: 'What can the pre-alpha do today?',
    a: 'Version 0.3.0 creates backups of your database and files (ZIP or TAR.GZ, split into parts), keeps them in a protected folder on your own server, can also send a copy to S3-compatible storage or another folder, lets you download, lock or delete them, and restores a backup of this site from your history with a safety snapshot and Undo. It cannot restore an uploaded archive, migrate to another domain, run schedules, delete old backups automatically, send backups to Google Drive or encrypt archives. S3 was verified live only against SeaweedFS. A backup that stays only on your server does not protect you if you lose the server.',
  },
  {
    q: 'Is restore safe?',
    a: 'It is built to be careful, but it is pre-alpha and a restore replaces your live database and files, so try it on a copy of your site first. Before the database is replaced the plugin takes a safety snapshot, a rollback journal keeps every file it overwrites, and Undo puts both back. Undoing the database also discards anything written since the restore. It never overwrites wp-config.php, the plugin itself or server config files.',
    featured: true,
  },
  {
    q: 'How does restore work?',
    a: 'Choose a backup of this site (from the local copy or any destination) and Database only, Files only or Whole site. A preview shows sizes, warnings and what is never overwritten. You type RESTORE, the backup is downloaded and its checksum verified, then you confirm again with Apply restore now. Visitors see a maintenance page meanwhile.',
  },
  {
    q: 'Can I undo a restore?',
    a: 'Yes. Undo restores the database from the safety snapshot and the files from the rollback journal. Dismiss undo data deletes them when you are happy with the result.',
  },
  {
    q: 'Can I restore an uploaded archive or move to a new domain?',
    a: 'Not yet. Only backups in your history can be restored, a different table prefix is refused, and a backup from a different site address needs an acknowledgement and keeps its old URLs. Upload restore and migration with safe search and replace are planned, with no dates.',
  },
  {
    q: 'When will schedules and Google Drive work?',
    a: 'Not yet, and there are no dates. Schedules and retention are next, and Google Drive is planned after them. S3-compatible storage and restore already work in pre-alpha. The roadmap shows each milestone and where every feature stands.',
  },
];

export const featuredFaqs = faqs.filter((f) => f.featured);

/** FAQ entries about one topic (e.g. 'gdrive'), in file order. */
export const faqsByTag = (tag: string) => faqs.filter((f) => f.tag === tag);
