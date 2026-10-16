import { describe, it, expect } from 'vitest';
import { downloadUrl, absoluteUrl } from '../src/config/site';

describe('site config', () => {
  it('builds a stable download URL from the repo', () => {
    expect(downloadUrl('openvault/openvault-backup')).toBe(
      'https://github.com/openvault/openvault-backup/releases/latest/download/openvault-backup.zip');
  });
  it('builds absolute URLs without double slashes', () => {
    expect(absoluteUrl('https://x.test/', '/a/')).toBe('https://x.test/a/');
  });
});
