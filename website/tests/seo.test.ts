import { describe, it, expect } from 'vitest';
import { buildTitle, truncate, canonical, shouldNoindex } from '../src/lib/seo';

describe('buildTitle', () => {
  it('builds a short home title containing the site name', () => {
    const t = buildTitle();
    expect(t.length).toBeGreaterThan(0);
    expect(t.length).toBeLessThanOrEqual(70);
    expect(t).toContain('OpenVault Backup');
  });
  it('builds a page title', () => {
    expect(buildTitle('Features')).toBe('Features | OpenVault Backup');
  });
});

describe('truncate', () => {
  it('leaves short strings alone and collapses whitespace', () => {
    expect(truncate('short text')).toBe('short text');
    expect(truncate('a   b\n c')).toBe('a b c');
  });
  it('never exceeds max and never cuts mid-word', () => {
    const words = Array.from({ length: 80 }, (_, i) => `word${i}`);
    const text = words.join(' ');
    const out = truncate(text);
    expect(out.length).toBeLessThanOrEqual(160);
    expect(out.endsWith('…')).toBe(true);
    const lastWord = out.slice(0, -1).split(' ').pop()!;
    expect(words).toContain(lastWord);
  });
  it('honours a custom max', () => {
    expect(truncate('one two three four five', 12).length).toBeLessThanOrEqual(12);
  });
});

describe('canonical', () => {
  it('adds one trailing slash', () => {
    expect(canonical('https://x.test', '/features')).toBe('https://x.test/features/');
  });
  it('strips query and hash', () => {
    expect(canonical('https://x.test/', '/a/?q=1#h')).toBe('https://x.test/a/');
  });
  it('handles the root', () => {
    expect(canonical('https://x.test', '/')).toBe('https://x.test/');
  });
});

describe('shouldNoindex', () => {
  it('forces noindex when the site is not configured', () => {
    expect(shouldNoindex(false, false)).toBe(true);
    expect(shouldNoindex(true, false)).toBe(true);
  });
  it('honours the prop when configured', () => {
    expect(shouldNoindex(false, true)).toBe(false);
    expect(shouldNoindex(true, true)).toBe(true);
  });
});
