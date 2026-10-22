// Real screenshots of the real plugin admin (v0.3.0 build) on a throwaway demo site ("Example Site").
// How they are captured and regenerated: website/README.md ("Product imagery"). Alts describe what the screenshot actually shows.
import form from '../assets/product/screen-form.png';
import progress from '../assets/product/screen-progress.png';
import done from '../assets/product/screen-done.png';
import destinations from '../assets/product/screen-destinations.png';
import history from '../assets/product/screen-history.png';
import system from '../assets/product/screen-system.png';
import upload from '../assets/product/screen-upload.png';
import phoneForm from '../assets/product/phone-form.png';
import cropWhat from '../assets/product/crop-what.png';
import cropFormat from '../assets/product/crop-format.png';
import cropWhere from '../assets/product/crop-where.png';
import cropDestination from '../assets/product/crop-destination.png';
import cropHistory from '../assets/product/crop-history.png';
import restoreChoose from '../assets/product/screen-restore-choose.png';
import restorePreview from '../assets/product/screen-restore-preview.png';
import restoreReady from '../assets/product/crop-restore-ready.png';
import restoreResult from '../assets/product/screen-restore-result.png';

export const shots = {
  form: { src: form, alt: 'The Backup Now screen of OpenVault Backup in WordPress: checkboxes for Database, Plugins, Themes, Uploads, Must-use plugins and Other wp-content files, with WordPress core files and wp-config.php off by default.', caption: 'Backup Now' },
  progress: { src: progress, alt: 'Backup progress in the plugin: "Creating the archive", a progress bar at 40 percent, elapsed time and an estimate, and a Cancel backup button.', caption: 'A running backup' },
  done: { src: done, alt: 'The finished backup: size 97.8 MB in 2 archive parts, download links for manifest.json, db.part01.zip and files.part01.zip, and the line "Example bucket: Uploaded".', caption: 'Backup complete, with download links' },
  destinations: { src: destinations, alt: 'The Destinations screen: a destination called "Example bucket", a custom S3-compatible endpoint, marked Connected with the message that a test file was written, read back and removed.', caption: 'Destinations' },
  history: { src: history, alt: 'The History screen: a table of backups with date, status, size, contents, parts and note, one marked Locked, and the download links of one backup opened.', caption: 'History' },
  system: { src: system, alt: 'The System Info screen listing the OpenVault, WordPress and server details the plugin checks before a backup.', caption: 'System Info' },
  upload: { src: upload, alt: 'The WordPress Add Plugins screen with the upload form: choose the .zip file and press Install Now.', caption: 'Plugins, Add Plugin, Upload Plugin' },
  phoneForm: { src: phoneForm, alt: 'The Backup Now screen on a phone: the same options in one column.', caption: 'Backup Now on a phone' },
  cropWhat: { src: cropWhat, alt: 'Zoomed crop of the Backup Now options: Database, Plugins, Themes, Uploads, Must-use plugins and Other wp-content files ticked, WordPress core files and wp-config.php off.', caption: 'What to back up' },
  cropFormat: { src: cropFormat, alt: 'Zoomed crop of the archive format choice: ZIP (compressed), ZIP (store, no compression) and TAR.GZ.', caption: 'Archive format' },
  cropWhere: { src: cropWhere, alt: 'Zoomed crop of "Where to send it": a copy always stays on this server, with Example bucket offered as an extra destination.', caption: 'Where to send it' },
  cropDestination: { src: cropDestination, alt: 'Zoomed crop of the Example bucket destination card, marked Connected, with Test connection, Edit and Remove buttons.', caption: 'A connected destination' },
  cropHistory: { src: cropHistory, alt: 'Zoomed crop of the History table with four backups, one locked, one with the note "before the theme update".', caption: 'Backup history' },
  restoreChoose: { src: restoreChoose, alt: 'The Restore screen: two backups of the site listed with date, size, contents and notes, one selected, and the choices Database only, Files only and Whole site, with Whole site selected above the button "Preview what will happen".', caption: 'Restore: choose a backup' },
  restorePreview: { src: restorePreview, alt: 'The restore preview: what will happen (database replaced, files restored, a safety snapshot first, a rollback journal, disk space), what is never overwritten, and a red box "This replaces your live site" with the word RESTORE typed to confirm.', caption: 'Restore: preview and typed confirmation' },
  restoreReady: { src: restoreReady, alt: 'The "Ready to apply" box: the backup is downloaded and verified, nothing has changed yet, a second field asks to type RESTORE again, and a button reads "Apply restore now".', caption: 'Restore: ready to apply' },
  restoreResult: { src: restoreResult, alt: 'The restore result: 16 database tables and 461 files restored, files of the plugin itself skipped, the safety snapshot kept in History, and an Undo section with a typed confirmation and a Dismiss undo data link.', caption: 'Restore: result and Undo' },
} as const;
