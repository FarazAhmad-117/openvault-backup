export const SITE_NAME = 'OpenVault Backup';
export const TAGLINE = 'Free WordPress backups to your own cloud. No middleman, no paid tiers.';
export const PLUGIN_VERSION = '0.1.0'; // keep equal to openvault-backup.php (checked by tests/version.test.ts)
export const PLACEHOLDER_SITE_URL = 'https://example.invalid';
export const siteUrl = () => process.env.SITE_URL || import.meta.env?.SITE || PLACEHOLDER_SITE_URL;
export const isConfiguredSite = () => !siteUrl().includes('example.invalid');
export const repo = () => process.env.PUBLIC_GITHUB_REPO || import.meta.env?.PUBLIC_GITHUB_REPO || 'openvault/openvault-backup';
export const downloadUrl = (r: string = repo()) => `https://github.com/${r}/releases/latest/download/openvault-backup.zip`;
export const devDownloadUrl = (r: string = repo()) => `https://github.com/${r}/releases/download/dev/openvault-backup-dev.zip`;
export const repoUrl = (r: string = repo()) => `https://github.com/${r}`;
export const absoluteUrl = (base: string, path: string) => base.replace(/\/+$/, '') + '/' + path.replace(/^\/+/, '');
