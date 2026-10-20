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
    a: 'Many storage services copy the Amazon S3 request format, so one client can talk to all of them. OpenVault Backup is designed to use a single request signer for AWS S3, Cloudflare R2, Backblaze B2, Wasabi, DigitalOcean Spaces and any custom endpoint.',
  },
  {
    tag: 's3', q: 'Do I need an AWS account to use S3-compatible storage?',
    a: 'Only if you choose AWS S3. For the other providers you use an account with that provider, create a bucket there, and give the plugin the endpoint and access keys it issues.',
  },
  {
    tag: 's3', q: 'Does the plugin use the AWS SDK?',
    a: 'No. It is designed to sign requests itself with Signature Version 4 and send them through the WordPress HTTP API. It also uploads a manifest.json with each backup, and large archives use multipart uploads.',
  },
  {
    q: 'Which archive formats will it support?',
    a: 'The first release is planned to offer ZIP (deflate, the default), ZIP (store, fastest, good for media) and TAR.GZ. TAR.BZ2 is planned for a later phase. The interface only offers formats your server actually supports, with a short explanation of each.',
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
    a: 'WordPress 6.9 or newer and PHP 7.4 or newer. The WordPress floor comes from the bundled Action Scheduler 4.2.0, which declares 6.9 as its minimum.',
  },
  {
    q: 'What happens to my backups if I uninstall the plugin?',
    a: 'The plugin never deletes backups stored at your remote destinations when you uninstall it. They stay in your own storage account, and each one has a manifest.json next to it so a fresh install can find it again.',
  },
  {
    q: 'Will it work on shared hosting?',
    a: 'It is designed for it. Every long task is built to be resumable in small batches, sized for roughly 30 second limits and 128 MB of memory. The pre-alpha 0.1.0 is tested in Docker profiles, including low-memory and restricted ones, but it has not yet been proven on real shared hosts.',
  },
  {
    q: 'What can the pre-alpha do today?',
    a: 'Version 0.1.0 creates backups of your database and files (ZIP or TAR.GZ, split into parts), keeps them in a protected folder on your own server, and lets you download, lock or delete them. It cannot restore, run schedules or send backups to Google Drive or S3. Backups on the same server do not protect you if you lose the server.',
  },
  {
    q: 'When will restore and Google Drive work?',
    a: 'Not yet, and there are no dates. Restore is the next half of the core loop, and Google Drive and S3 destinations are planned after it. The roadmap shows each milestone and where every feature stands.',
  },
];

export const featuredFaqs = faqs.filter((f) => f.featured);

/** FAQ entries about one topic (e.g. 'gdrive'), in file order. */
export const faqsByTag = (tag: string) => faqs.filter((f) => f.tag === tag);
