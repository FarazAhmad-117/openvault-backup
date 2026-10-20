# Folder Structure

Every PHP file is a plan stub: open it to see its phase (MVP / Phase 2 / Phase 3), purpose and responsibilities. Read docs/plan/ in numeric order.

```
openvault-backup/
|-- assets
|   |-- admin
|   |   |-- css
|   |   |   `-- admin.css
|   |   |-- js
|   |   |   |-- backup-now.js
|   |   |   `-- history.js
|   |   `-- src
|   |-- images
|   |   `-- guides
|   `-- README.md
|-- docs
|   |-- guides
|   |   |-- backblaze-b2.md
|   |   |-- cloudflare-r2.md
|   |   |-- dropbox.md
|   |   |-- google-drive-setup.md
|   |   |-- onedrive.md
|   |   |-- s3-compatible-setup.md
|   |   `-- server-cron.md
|   `-- plan
|       |-- 00-overview.md
|       |-- 01-scope-and-roadmap.md
|       |-- 02-architecture.md
|       |-- 03-database-schema.md
|       |-- 04-backup-engine.md
|       |-- 05-archive-formats.md
|       |-- 06-destinations.md
|       |-- 07-google-drive-oauth.md
|       |-- 08-restore.md
|       |-- 09-security.md
|       |-- 10-admin-ui.md
|       `-- 11-testing-and-release.md
|-- includes
|   |-- Admin
|   |   |-- Assets.php
|   |   |-- BackupRowPresenter.php
|   |   |-- BackupsListTable.php
|   |   |-- Pages
|   |   |   |-- BackupNowPage.php
|   |   |   |-- DashboardPage.php
|   |   |   |-- DestinationsPage.php
|   |   |   |-- GuidesPage.php
|   |   |   |-- HistoryPage.php
|   |   |   |-- LogsPage.php
|   |   |   |-- RestorePage.php
|   |   |   |-- SchedulesPage.php
|   |   |   |-- SettingsPage.php
|   |   |   `-- SystemInfoPage.php
|   |   `-- Menu.php
|   |-- Archive
|   |   |-- ArchiveException.php
|   |   |-- ArchiverInterface.php
|   |   |-- CapabilityDetector.php
|   |   |-- Encryptor.php
|   |   |-- Splitter.php
|   |   |-- TarBz2Archiver.php
|   |   |-- TarGzArchiver.php
|   |   |-- TarWriter.php
|   |   `-- ZipArchiver.php
|   |-- Cli
|   |   `-- Commands.php
|   |-- Core
|   |   |-- Activator.php
|   |   |-- Capabilities.php
|   |   |-- Deactivator.php
|   |   |-- Installer.php
|   |   `-- Plugin.php
|   |-- Database
|   |   |-- BackupFilesRepository.php
|   |   |-- BackupsRepository.php
|   |   |-- DestinationsRepository.php
|   |   |-- LogsRepository.php
|   |   |-- SchedulesRepository.php
|   |   `-- Util.php
|   |-- Destinations
|   |   |-- Dropbox
|   |   |   `-- DropboxDestination.php
|   |   |-- GoogleDrive
|   |   |   |-- GoogleDriveDestination.php
|   |   |   |-- GoogleOAuth.php
|   |   |   `-- ResumableUploader.php
|   |   |-- Local
|   |   |   `-- LocalDestination.php
|   |   |-- OneDrive
|   |   |   `-- OneDriveDestination.php
|   |   |-- S3
|   |   |   |-- MultipartUploader.php
|   |   |   |-- S3Destination.php
|   |   |   `-- SigV4Signer.php
|   |   |-- Sftp
|   |   |   `-- SftpDestination.php
|   |   |-- DestinationInterface.php
|   |   `-- DestinationRegistry.php
|   |-- Engine
|   |   |-- Dump
|   |   |   |-- BatchSizer.php
|   |   |   |-- DumpException.php
|   |   |   |-- ExclusionRules.php
|   |   |   |-- SqlDumpWriter.php
|   |   |   |-- SqlValueFormatter.php
|   |   |   |-- TableInspector.php
|   |   |   `-- TableList.php
|   |   |-- Scan
|   |   |   |-- AppendFile.php
|   |   |   |-- ContentSelector.php
|   |   |   |-- EntryName.php
|   |   |   |-- LineReader.php
|   |   |   `-- PathFilter.php
|   |   |-- Steps
|   |   |   |-- ArchiveStep.php
|   |   |   |-- CleanupStep.php
|   |   |   |-- DatabaseExportStep.php
|   |   |   |-- EncryptStep.php
|   |   |   |-- FileScanStep.php
|   |   |   |-- PreflightChecks.php
|   |   |   |-- PreflightStep.php
|   |   |   |-- RetentionStep.php
|   |   |   |-- StepInterface.php
|   |   |   `-- UploadStep.php
|   |   |-- Budget.php
|   |   |-- JobContext.php
|   |   |-- JobLock.php
|   |   |-- JobRunner.php
|   |   |-- JobState.php
|   |   |-- LockStore.php
|   |   |-- OptionsLockStore.php
|   |   |-- Progress.php
|   |   `-- StepResult.php
|   |-- Notifications
|   |   `-- Notifier.php
|   |-- Backups
|   |   `-- BackupFiles.php
|   |-- Rest
|   |   `-- RestController.php
|   |-- Restore
|   |   |-- DatabaseImporter.php
|   |   |-- FileRestorer.php
|   |   |-- MaintenanceMode.php
|   |   |-- RemoteIndexer.php
|   |   |-- RestoreManager.php
|   |   |-- SafetySnapshot.php
|   |   |-- SelfPreserver.php
|   |   `-- UploadReceiver.php
|   |-- Scheduling
|   |   |-- RetentionPolicy.php
|   |   `-- Scheduler.php
|   |-- Security
|   |   |-- CredentialVault.php
|   |   |-- DownloadController.php
|   |   `-- DownloadHelper.php
|   `-- Support
|       |-- Logger.php
|       |-- Manifest.php
|       |-- Paths.php
|       `-- SystemInfo.php
|-- languages
|-- lib
|   `-- README.md
|-- standalone-restore
|   `-- restore.php
|-- tests
|   |-- integration
|   |-- unit
|   `-- README.md
|-- .gitignore
|-- CHANGELOG.md
|-- CONTRIBUTING.md
|-- LICENSE
|-- README.md
|-- openvault-backup.php
|-- readme.txt
`-- uninstall.php
```
