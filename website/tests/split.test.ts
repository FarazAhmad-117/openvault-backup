import { describe, it, expect } from 'vitest';
import { splitTitle, headTier } from '../src/lib/split';

describe('splitTitle', () => {
  it('keeps short titles on one line', () => expect(splitTitle('Features')).toEqual(['Features']));
  it('never drops or reorders words', () => {
    for (const t of ['Download OpenVault Backup', 'Back up WordPress to S3, Cloudflare R2, Backblaze B2 or Wasabi', 'Free WordPress backup plugin: how OpenVault compares', 'What changed, and when']) {
      expect(splitTitle(t).join(' ')).toBe(t);
    }
  });
  it('balances long titles into at most four lines', () => {
    const l = splitTitle('Back up WordPress to S3, Cloudflare R2, Backblaze B2 or Wasabi');
    expect(l.length).toBeLessThanOrEqual(4);
    expect(l.length).toBeGreaterThan(1);
  });
  it('picks a smaller tier for longer lines', () => {
    expect(headTier(['Features'])).toBe('xl');
    expect(headTier(['From your site to'])).toBe('lg');
    expect(headTier(['Back up WordPress to S3,'])).toBe('md');
  });
});

describe('splitTitle balance', () => {
  it('does not leave one tiny line before a huge one', () => {
    const l = splitTitle('Free WordPress backup plugin: how OpenVault compares');
    const lens = l.map((x) => x.length);
    expect(Math.max(...lens) - Math.min(...lens)).toBeLessThan(12);
  });
});
