=== OpenVault Backup ===
Contributors: (your-wporg-username)
Tags: backup, restore, google drive, s3, scheduled backup
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later

Free, open-source backups for WordPress. Pre-alpha: local backups only, no restore yet.

== Description ==

OpenVault Backup is a free, open-source (GPLv2 or later) backup plugin with no paid tiers. It is designed so that you connect your own storage credentials (your own Google OAuth app, your own S3 keys) and nothing passes through a server of ours.

**Pre-alpha 0.1.0: read this first.** This release creates local backups of your database and files and lets you download them. It cannot restore a backup, run schedules or send backups to Google Drive, S3-compatible storage or any other remote destination yet. Backups are stored on the same server as your site, so they do not protect you if you lose the server. Do not rely on this release as your only backup.

**What works in 0.1.0**

* Back up now: database and files, as ZIP (deflate), ZIP (store) or TAR.GZ. Only the formats your server supports are offered.
* Resumable backups in small slices, built for shared hosting limits, split into parts, with a manifest.json written next to every backup.
* A Backups page to download (authenticated), lock, delete or cancel backups.
* A System Info page that shows what your server can do.
* Encrypted storage for credentials the plugin will need later.

**Not built yet**

Restore, scheduled backups, retention, Google Drive, S3-compatible destinations (S3, R2, B2, Wasabi, Spaces), archive encryption, email notifications, settings and logs pages. See the roadmap at https://openvault.farazahmad.dev/roadmap/.

**Privacy.** The plugin makes no external calls and has no analytics. It only contacts destinations you configure, and no destination exists yet.

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

In a protected folder on your own server. Because they live on the same server as your site, copy them somewhere else yourself. Google Drive and S3-compatible destinations are planned, not built.

= Can I use it as my only backup? =

No. It is pre-alpha. Keep your current backup solution running and do not delete existing backups because of this plugin.

== Changelog ==

= 0.1.0 =
* First public pre-alpha: Back up now (database and files, ZIP or TAR.GZ, resumable, split into parts, manifest.json), Backups page with authenticated download, lock, delete and cancel, System Info, encrypted credential vault.
* Not included yet: restore, schedules, retention, remote destinations, archive encryption, email notifications, settings and logs pages.
