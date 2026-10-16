import { describe, it, expect } from 'vitest';
import { competitors } from '../src/data/competitors';

const banned = ['$', '€', '£', 'cheaper', 'worse', 'bloat', 'slow', 'best', 'better than'];

describe('competitor facts', () => {
  const all = competitors.flatMap((c) => c.facts.map((x) => ({ c: c.slug, ...x })));
  it('has three competitors with unique slugs', () => {
    expect(competitors.length).toBe(3);
    expect(new Set(competitors.map((c) => c.slug)).size).toBe(competitors.length);
  });
  it('every fact has an https source and a valid date', () => {
    expect(all.length).toBeGreaterThan(0);
    for (const x of all) {
      expect(x.source, x.claim).toMatch(/^https:\/\//);
      expect(x.checkedOn, x.claim).toMatch(/^\d{4}-\d{2}-\d{2}$/);
      expect(Number.isNaN(Date.parse(x.checkedOn)), x.claim).toBe(false);
    }
  });
  it('no fact, summary or name uses banned wording', () => {
    for (const c of competitors) {
      for (const text of [c.summary, ...c.facts.map((x) => x.claim)]) {
        for (const w of banned) expect(text.toLowerCase(), `${c.slug}: "${w}"`).not.toContain(w);
      }
    }
  });
});
