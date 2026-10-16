# Folder Structure

Every PHP file is a plan stub: open it to see its phase (MVP / Phase 2 / Phase 3), purpose and responsibilities. Read docs/plan/ in numeric order.

```
openvault-backup/
|-- assets
|   |-- admin
|   |   |-- css
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
|   |   |-- ArchiverInterface.php
|   |   |-- CapabilityDetector.php
|   |   |-- Encryptor.php
|   |   |-- Splitter.php
|   |   |-- TarBz2Archiver.php
|   |   |-- TarGzArchiver.php
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
|   |   `-- SchedulesRepository.php
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
|   |   |-- Steps
|   |   |   |-- ArchiveStep.php
|   |   |   |-- CleanupStep.php
|   |   |   |-- DatabaseExportStep.php
|   |   |   |-- EncryptStep.php
|   |   |   |-- FileScanStep.php
|   |   |   |-- PreflightStep.php
|   |   |   |-- RetentionStep.php
|   |   |   |-- StepInterface.php
|   |   |   `-- UploadStep.php
|   |   |-- JobRunner.php
|   |   `-- JobState.php
|   |-- Notifications
|   |   `-- Notifier.php
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
|   |   `-- DownloadController.php
|   `-- Support
|       |-- Logger.php
|       |-- Manifest.php
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
