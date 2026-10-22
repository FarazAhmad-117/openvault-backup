# Folder Structure

Files that are still plan stubs say so in their docblock (phase, purpose, responsibilities); implemented files keep that docblock as their spec. Restore (Milestone 5): the engine core (plan, preflight, fetch, verify, runner), the database restore (safety snapshot, maintenance mode, self-preservation, statement whitelist, streamed importer; step 5b) and the file restore with its rollback journal and undo (`ExtractionPolicy`, `SafeWriter`, `FileRestorer`, `UndoJournal`, `UndoStore`, `FileUndoer`, `Archive/EntryWalker`; step 5c) are implemented in `includes/Restore/`; the user-facing restore (REST routes `Rest/RestoreRestController`, the Restore page with `assets/admin/js/restore.js`, progress, status token, History action, banner; step 5d) sits on top; RemoteIndexer and UploadReceiver are still stubs. Read docs/plan/ in numeric order.

```
openvault-backup/
|-- assets
|   |-- admin
|   |   |-- css
|   |   |   `-- admin.css
|   |   |-- js
|   |   |   |-- backup-now.js
|   |   |   |-- destinations.js
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
|   |   |-- ArchiveReader.php
|   |   |-- EntryWalker.php
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
|   |   |-- RestoresRepository.php
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
|   |   |   |-- EndpointValidator.php
|   |   |   |-- MultipartUploader.php
|   |   |   |-- S3Client.php
|   |   |   |-- S3Destination.php
|   |   |   |-- S3Presets.php
|   |   |   `-- SigV4Signer.php
|   |   |-- Sftp
|   |   |   `-- SftpDestination.php
|   |   |-- DestinationException.php
|   |   |-- DestinationInterface.php
|   |   |-- DestinationPresenter.php
|   |   |-- DestinationRegistry.php
|   |   |-- DestinationSettings.php
|   |   |-- RemotePath.php
|   |   |-- ResumableDownloadInterface.php
|   |   |-- TestResult.php
|   |   `-- UploadProgress.php
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
|   |   |   |-- UploadPlan.php
|   |   |   `-- UploadStep.php
|   |   |-- Budget.php
|   |   |-- JobContext.php
|   |   |-- JobLock.php
|   |   |-- JobRunner.php
|   |   |-- JobState.php
|   |   |-- LockStore.php
|   |   |-- OptionsLockStore.php
|   |   |-- Progress.php
|   |   |-- StepResult.php
|   |   `-- UploadSummary.php
|   |-- Notifications
|   |   `-- Notifier.php
|   |-- Backups
|   |   `-- BackupFiles.php
|   |-- Rest
|   |   |-- DestinationsRestController.php
|   |   `-- RestController.php
|   |-- Restore
|   |   |-- Steps
|   |   |   |-- AbstractRestoreStep.php
|   |   |   |-- DbRestoreStep.php
|   |   |   |-- FetchStep.php
|   |   |   |-- FileRestoreStep.php
|   |   |   |-- FileUndoStep.php
|   |   |   |-- FinishStep.php
|   |   |   |-- PreflightStep.php
|   |   |   |-- SnapshotStep.php
|   |   |   `-- VerifyStep.php
|   |   |-- DatabaseImporter.php
|   |   |-- DestinationFactory.php
|   |   |-- DumpRejectedException.php
|   |   |-- DumpValidator.php
|   |   |-- ExtractionPolicy.php
|   |   |-- FileRestoreException.php
|   |   |-- FileRestorer.php
|   |   |-- FileUndoer.php
|   |   |-- FsProbe.php
|   |   |-- ImportException.php
|   |   |-- MaintenanceMode.php
|   |   |-- PlanLoader.php
|   |   |-- RemoteIndexer.php
|   |   |-- RestoreManager.php
|   |   |-- RestoreMeta.php
|   |   |-- RestorePlan.php
|   |   |-- RestorePreflight.php
|   |   |-- RestoreRunner.php
|   |   |-- RestoreWorkDir.php
|   |   |-- SafeWriter.php
|   |   |-- SafetySnapshot.php
|   |   |-- SelfPreserver.php
|   |   |-- SqlExecutor.php
|   |   |-- UndoJournal.php
|   |   |-- UndoStore.php
|   |   |-- UploadReceiver.php
|   |   `-- WpdbExecutor.php
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
