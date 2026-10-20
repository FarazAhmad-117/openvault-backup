=== OpenVault Backup ===
Contributors: (your-wporg-username)
Tags: backup, restore, google drive, s3, scheduled backup
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later

Free, open-source backups for WordPress. Pre-alpha: backups to S3-compatible storage or a local folder, no restore yet.

== Description ==

OpenVault Backup is a free, open-source (GPLv2 or later) backup plugin with no paid tiers. You connect your own storage credentials (your own S3 keys, later your own Google OAuth app) and nothing passes through a server of ours.

**Pre-alpha 0.2.0: read this first.** This release creates backups of your database and files, keeps a copy on your server and can also send them to S3-compatible storage or another folder. It cannot restore a backup, run schedules, delete old backups automatically, send backups to Google Drive or encrypt archives yet. A downloaded or off-site backup can only be extracted by hand. S3 uploads were verified live only against SeaweedFS; Amazon S3, Cloudflare R2, Backblaze B2, Wasabi and DigitalOcean Spaces follow each provider's documented endpoint pattern and are covered by unit tests, not by real calls, so please report problems. Do not rely on this release as your only backup.

**What works in 0.2.0**

* Back up now: database and files, as ZIP (deflate), ZIP (store) or TAR.GZ. Only the formats your server supports are offered.
* Resumable backups in small slices, built for shared hosting limits, split into parts, with a manifest.json written next to every backup.
* A Destinations page to add, test, edit and remove S3-compatible storage (presets for Amazon S3, Cloudflare R2, Backblaze B2, Wasabi, DigitalOcean Spaces and a custom endpoint) and local folders.
* A "where to send it" choice on Back up now. A copy always stays on this server.
* Large uploads go in parts and resume after an interruption. A backup finishes as partial when a destination fails, and the History page shows the result per destination.
* A Backups page to download (authenticated), lock, delete or cancel backups.
* A System Info page that shows what your server can do.
* Secret keys are encrypted at rest and never shown or logged again.

**Not built yet**

Restore, scheduled backups, retention (nothing is deleted automatically, locally or remotely), Google Drive, archive encryption, email notifications, settings and logs pages. See the roadmap at https://openvault.farazahmad.dev/roadmap/.

**Privacy.** The plugin has no analytics and makes no external calls except to the S3-compatible endpoints you configure, when you test a destination or send a backup there.

== Installation ==

1. In your WordPress admin go to Plugins, Add New Plugin, Upload Plugin.
2. Choose the openvault-backup zip and click Install Now, then Activate Plugin.
3. Open OpenVault Backup in the admin menu and check System Info.
4. Open Back up now, choose what to include and a format, and start the backup.
5. When it finishes, open Backups to download it. Keep the download somewhere other than your server.

== Frequently Asked Questions ==

= Can I restore a site with this plugin? =

Not yet. Restore is not built. A downloaded backup can only be extracted by hand.

= Where are my backups stored? =

Always in a protected folder on your own server. If you choose a destination, a copy is also sent there. Nothing is deleted automatically, so remove old backups yourself.

= Which storage services work? =

S3-compatible storage (Amazon S3, Cloudflare R2, Backblaze B2, Wasabi, DigitalOcean Spaces or a custom endpoint) and a local folder. Only SeaweedFS was tested against a real server; the others use the documented endpoint patterns. Google Drive is planned, not built.

= Are my keys safe? =

Secret keys are encrypted at rest with a key derived from your WordPress salts, or from OVB_ENCRYPTION_KEY if you define it. They are never shown again and never logged. If you change your salts you will have to enter the keys again.

= Are my backups encrypted? =

Not yet. Archives are sent over HTTPS but are not encrypted themselves. Archive encryption is planned.

= Can I use it as my only backup? =

No. It is pre-alpha. Keep your current backup solution running and do not delete existing backups because of this plugin.

== Changelog ==

= 0.2.0 =
* Destinations page and REST API: S3-compatible storage (Amazon S3, R2, B2, Wasabi, Spaces, custom endpoint) and local folders, with write-only secret keys and an unsafe-endpoint guard.
* Back up now can send a copy to chosen destinations; resumable multipart uploads; per-destination results in History; partial status when a destination fails.
* Includes everything from the unreleased 0.1.0: Back up now (database and files, ZIP or TAR.GZ, resumable, split into parts, manifest.json), Backups page with authenticated download, lock, delete and cancel, System Info, encrypted credential vault.
* Not included yet: restore, schedules, retention, Google Drive, archive encryption, email notifications, settings and logs pages. S3 was verified live only against SeaweedFS.
