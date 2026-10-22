=== OpenVault Backup ===
Contributors: (your-wporg-username)
Tags: backup, restore, s3, cloudflare r2, local backup
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later

Free, open-source backups for WordPress. Pre-alpha: backups to your server, S3-compatible storage or a folder, and restore from your backup history with undo.

== Description ==

OpenVault Backup is a free, open-source (GPLv2 or later) backup plugin with no paid tiers. You connect your own storage credentials (your own S3 keys) and nothing passes through a server of ours.

**Pre-alpha 0.3.0: read this first.** This release creates backups of your database and files, keeps a copy on your server, can also send them to S3-compatible storage or another folder, and can restore a backup of this site from your backup history, with a safety snapshot first and an undo afterwards. It cannot run schedules, delete old backups automatically, send backups to Google Drive, encrypt archives, restore an uploaded archive or move a site to another domain. Try restore on a copy of your site first. S3 uploads were verified live only against SeaweedFS; Amazon S3, Cloudflare R2, Backblaze B2, Wasabi and DigitalOcean Spaces follow each provider's documented endpoint pattern and are covered by unit tests, not by real calls, so please report problems. Do not rely on this release as your only backup.

**What works in 0.3.0**

* Back up now: database and files, as ZIP (deflate), ZIP (store) or TAR.GZ. Only the formats your server supports are offered.
* Resumable backups in small slices, built for shared hosting limits, split into parts, with a manifest.json written next to every backup.
* A Destinations page to add, test, edit and remove S3-compatible storage (presets for Amazon S3, Cloudflare R2, Backblaze B2, Wasabi, DigitalOcean Spaces and a custom endpoint) and local folders.
* A "where to send it" choice on Back up now. A copy always stays on this server.
* A Backups page to download (authenticated), lock, delete or cancel backups.
* **Restore from history (new):** pick a backup of this site (from the local copy or any destination), choose Database only, Files only or Whole site, preview what will change, and type RESTORE. The backup is downloaded and checksum-verified first, then you confirm a second time with "Apply restore now".
* **Safety:** a safety snapshot (a real, locked database backup) is taken before the database is replaced and a rollback journal keeps every overwritten file. Visitors see a 503 maintenance page while admins keep access.
* **Undo restore (new):** the database comes back from the snapshot and files come back from the journal. Undoing the database also discards everything written since the restore.
* Secret keys are encrypted at rest and never shown or logged again. A System Info page shows what your server can do.

**Restore limits**

* Only backups of this site made by this plugin can be restored, from your history. Restoring an uploaded archive or a folder on the server is not built.
* No migration. A different table prefix is refused. A different site URL needs an explicit acknowledgement, and the restored data keeps the old URLs (search and replace is planned).
* Multisite networks are refused. Encrypted archives do not exist yet.
* Existing files are overwritten and files that are not in the backup are not deleted. wp-config.php is never restored.
* The plugin's own folder, its backup folder and server config files are never overwritten, and the plugin's own settings and credentials survive a database restore.
* Very large sites restore slowly (a tar.gz part is re-read for every slice).

**Not built yet**

Scheduled backups, retention (nothing is deleted automatically, locally or remotely), Google Drive, archive encryption, restore from an uploaded archive, migration, email notifications, settings and logs pages. See the roadmap at https://openvault.farazahmad.dev/roadmap/.

**Privacy.** The plugin has no analytics and makes no external calls except to the S3-compatible endpoints you configure, when you test a destination, send a backup there or restore from it.

== Installation ==

1. In your WordPress admin go to Plugins, Add New Plugin, Upload Plugin.
2. Choose the openvault-backup zip and click Install Now, then Activate Plugin.
3. Open OpenVault Backup in the admin menu and check System Info.
4. Open Back up now, choose what to include and a format, and start the backup.
5. When it finishes, open Backups to download it. Keep the download somewhere other than your server.
6. To restore, open Restore, choose a backup and a mode, and read the preview. Try it on a copy of your site first.

== Frequently Asked Questions ==

= How does restore work? =

Open Restore, choose a backup of this site (from the local copy or any destination) and a mode: Database only, Files only or Whole site. A preview shows sizes, disk need, warnings and what is never overwritten. Type RESTORE to start. The backup is downloaded and its checksum verified, then you confirm again with "Apply restore now". Before the database is replaced the plugin takes a safety snapshot, and a rollback journal keeps every file it overwrites. Restore is pre-alpha: try it on a copy of your site first.

= Can I undo a restore? =

Yes. Undo restores the database from the safety snapshot and the files from the journal. Undoing the database also discards everything written since the restore, such as new orders or comments. "Dismiss undo data" deletes the snapshot and journal when you are happy.

= What is never overwritten? =

wp-config.php, server config files, the plugin's own folder and its backup folder. The plugin's own settings, locks and credentials survive a database restore. Files that are not in the backup are not deleted.

= Can I restore on another domain? =

No. Migration is not built. A different table prefix is refused. A backup from a different site URL needs an explicit acknowledgement, and the restored data keeps the old URLs. Search and replace is planned for a later phase.

= Will it log me out? =

It can. When the users table is replaced your session may end. Progress then continues on a read-only status link.

= Can I restore an uploaded archive? =

Not yet. Only backups in your history can be restored. A downloaded backup can still be extracted by hand.

= Where are my backups stored? =

Always in a protected folder on your own server. If you choose a destination, a copy is also sent there. Nothing is deleted automatically, so remove old backups yourself.

= Which storage services work? =

S3-compatible storage (Amazon S3, Cloudflare R2, Backblaze B2, Wasabi, DigitalOcean Spaces or a custom endpoint) and a local folder. Only SeaweedFS was tested against a real server; the others use the documented endpoint patterns. Google Drive is planned, not built.

= Are my keys safe? =

Secret keys are encrypted at rest with a key derived from your WordPress salts, or from OVB_ENCRYPTION_KEY if you define it. They are never shown again and never logged. If you change your salts you will have to enter the keys again.

= Are my backups encrypted? =

Not yet. Archives are sent over HTTPS but are not encrypted themselves. Archive encryption is planned.

= Can I use it as my only backup? =

No. It is pre-alpha. Keep your current backup solution running and do not delete existing backups because of this plugin. Try restore on a copy of your site first.

== Changelog ==

= 0.3.0 =
* Restore from history: Restore page and REST API. Choose a backup of this site (local copy or any destination), Database only, Files only or Whole site, preview, type RESTORE, then confirm "Apply restore now". The backup is downloaded and checksum-verified first.
* Safety snapshot (a real, locked database backup) before the database is replaced, a rollback journal for overwritten files, a 503 maintenance page for visitors, strict checking of the database dump, safe extraction (no ../, no symlinks, protected paths).
* Undo restore and Dismiss undo data. History has a Restore action and a banner shows while a restore runs.
* Limits: no restore from an uploaded archive or a server folder, no migration (different prefix refused, different site URL needs an acknowledgement and keeps old URLs), multisite refused, no encrypted archives, slow on very large sites, wp-config.php never restored.
* Still not built: schedules, retention, Google Drive, archive encryption, email notifications, settings and logs pages. Try restore on a copy of your site first.

= 0.2.0 =
* Destinations page and REST API: S3-compatible storage (Amazon S3, R2, B2, Wasabi, Spaces, custom endpoint) and local folders, with write-only secret keys and an unsafe-endpoint guard.
* Back up now can send a copy to chosen destinations; resumable multipart uploads; per-destination results in History; partial status when a destination fails.
* Includes everything from the unreleased 0.1.0: Back up now (database and files, ZIP or TAR.GZ, resumable, split into parts, manifest.json), Backups page with authenticated download, lock, delete and cancel, System Info, encrypted credential vault.
* Not included yet: restore, schedules, retention, Google Drive, archive encryption, email notifications, settings and logs pages. S3 was verified live only against SeaweedFS.
