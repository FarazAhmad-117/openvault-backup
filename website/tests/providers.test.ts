import { describe, it, expect } from 'vitest';
import { providers } from '../src/data/providers';
import { guideHref, isDocsGuide } from '../src/lib/guides';

const allText = (p: (typeof providers)[number]) =>
  [p.name, p.endpointPattern, p.regionNote, ...p.needs, ...p.distinct, ...p.faq.flatMap((f) => [f.q, f.a])].join('\n');

describe('providers', () => {
  it('has unique slugs', () => {
    expect(new Set(providers.map((p) => p.slug)).size).toBe(providers.length);
  });
  it('covers the five providers', () => {
    expect(providers.map((p) => p.slug).sort()).toEqual(['aws-s3', 'backblaze-b2', 'cloudflare-r2', 'digitalocean-spaces', 'wasabi']);
  });
  it('has an https docs URL and https endpoint pattern', () => {
    for (const p of providers) {
      expect(p.docsUrl, p.slug).toMatch(/^https:\/\/\S+$/);
      expect(p.endpointPattern, p.slug).toMatch(/^https:\/\/\S+$/);
    }
  });
  it('has 2-3 distinct points and 2 FAQs each', () => {
    for (const p of providers) {
      expect(p.distinct.length, p.slug).toBeGreaterThanOrEqual(2);
      expect(p.distinct.length, p.slug).toBeLessThanOrEqual(3);
      expect(p.faq.length, p.slug).toBe(2);
    }
  });
  it('contains no prices, free allowances, egress or comparison claims', () => {
    for (const p of providers) {
      const t = allText(p);
      expect(t, p.slug).not.toMatch(/[$€£]/);
      expect(t, p.slug).not.toMatch(/cheaper|free tier|per GB|egress/i);
    }
  });
  it('every guideId resolves to the provider page itself', () => {
    for (const p of providers.filter((x) => x.guideId)) {
      expect(isDocsGuide(p.guideId!), p.slug).toBe(false);
      expect(guideHref(p.guideId!), p.slug).toBe(`/s3-r2/${p.slug}/`);
    }
  });
});
