import { describe, it, expect } from 'vitest';
import { guideHref, isDocsGuide, guideTitle, guideDescription } from '../src/lib/guides';

describe('guideHref', () => {
  it('maps each guide to exactly one canonical URL', () => {
    expect(guideHref('google-drive-setup')).toBe('/google-drive/setup-guide/');
    expect(guideHref('cloudflare-r2')).toBe('/s3-r2/cloudflare-r2/');
    expect(guideHref('backblaze-b2')).toBe('/s3-r2/backblaze-b2/');
    expect(guideHref('s3-compatible-setup')).toBe('/s3-r2/s3-compatible-setup/');
    expect(guideHref('dropbox')).toBe('/docs/dropbox/');
    expect(guideHref('onedrive')).toBe('/docs/onedrive/');
    expect(guideHref('server-cron')).toBe('/docs/server-cron/');
  });
  it('only generates /docs/ pages for guides without another home', () => {
    expect(['google-drive-setup', 'cloudflare-r2', 'backblaze-b2', 's3-compatible-setup'].some(isDocsGuide)).toBe(false);
    expect(['dropbox', 'onedrive', 'server-cron'].every(isDocsGuide)).toBe(true);
  });
});

describe('guideTitle', () => {
  it('strips "Guide: " and "(outline)"', () => {
    expect(guideTitle('# Guide: Backblaze B2 (outline)\ntext')).toBe('Backblaze B2');
  });
  it('strips "(Phase 3, outline)"', () => {
    expect(guideTitle('# Guide: Dropbox (Phase 3, outline)\ntext')).toBe('Dropbox');
  });
  it('keeps a title without suffix and falls back when there is no heading', () => {
    expect(guideTitle('# Plain title\n')).toBe('Plain title');
    expect(guideTitle('no heading here')).toBe('Setup guide');
  });
  it('handles CRLF line endings', () => {
    expect(guideTitle('# Guide: X (outline)\r\nbody')).toBe('X');
  });
});

describe('guideDescription', () => {
  it('uses the first non-heading line, stripped of markdown', () => {
    expect(guideDescription('# T\n\n1. Go to **Google** `Console` [docs](http://x)\nmore')).toBe('Go to Google Console docs');
  });
  it('keeps underscores inside identifiers', () => {
    expect(guideDescription('# T\nAPI permission offline_access and _emphasis_ here')).toBe('API permission offline_access and emphasis here');
  });
  it('truncates to 155 chars or fewer', () => {
    const d = guideDescription('# T\n' + 'word '.repeat(80));
    expect(d.length).toBeLessThanOrEqual(155);
    expect(d.endsWith('…')).toBe(true);
  });
  it('falls back when there is no body', () => {
    expect(guideDescription('# Only a heading\n')).toMatch(/outline/i);
  });
});
