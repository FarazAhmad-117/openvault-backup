import { describe, it, expect } from 'vitest';
import { softwareApplication, faqPage, howTo, breadcrumbs } from '../src/lib/jsonld';
import { features } from '../src/data/features';
import { PLUGIN_VERSION } from '../src/config/site';

const roundTrips = (x: unknown) => expect(JSON.parse(JSON.stringify(x))).toEqual(x);

describe('softwareApplication', () => {
  const s: any = softwareApplication('https://x.test');
  it('is serialisable with a context', () => {
    roundTrips(s);
    expect(s['@context']).toBe('https://schema.org');
    expect(s['@type']).toBe('SoftwareApplication');
  });
  it('has the real facts', () => {
    expect(s.applicationCategory).toBe('UtilitiesApplication');
    expect(s.operatingSystem).toBe('WordPress');
    expect(s.offers.price).toBe('0');
    expect(s.downloadUrl.endsWith('/openvault-backup.zip')).toBe(true);
    expect(s.softwareVersion).toBe(PLUGIN_VERSION);
    expect(s.url).toBe('https://x.test/');
  });
  it('lists only available features', () => {
    const expected = features.filter((f) => f.status === 'available').map((f) => f.title);
    if (expected.length === 0) expect('featureList' in s).toBe(false);
    else expect(s.featureList).toEqual(expected);
  });
  it('never lists planned or in-progress features', () => {
    const list: string[] = s.featureList ?? [];
    for (const f of features.filter((f) => f.status !== 'available')) {
      expect(list).not.toContain(f.title);
    }
  });
  it('has no rating or review claims', () => {
    expect(s.aggregateRating).toBeUndefined();
    expect(s.review).toBeUndefined();
  });
});

describe('faqPage', () => {
  const f: any = faqPage([{ q: 'Q1?', a: 'A1' }, { q: 'Q2?', a: 'A2' }]);
  it('maps items to questions', () => {
    roundTrips(f);
    expect(f['@context']).toBe('https://schema.org');
    expect(f['@type']).toBe('FAQPage');
    expect(f.mainEntity).toHaveLength(2);
    expect(f.mainEntity[0]['@type']).toBe('Question');
    expect(f.mainEntity[0].name).toBe('Q1?');
    expect(f.mainEntity[1].acceptedAnswer['@type']).toBe('Answer');
    expect(f.mainEntity[1].acceptedAnswer.text).toBe('A2');
  });
});

describe('howTo', () => {
  const h: any = howTo('Install', [{ name: 'a', text: 'ta' }, { name: 'b', text: 'tb' }, { name: 'c', text: 'tc' }]);
  it('numbers steps from 1', () => {
    roundTrips(h);
    expect(h['@context']).toBe('https://schema.org');
    expect(h['@type']).toBe('HowTo');
    expect(h.name).toBe('Install');
    expect(h.step.map((s: any) => s.position)).toEqual([1, 2, 3]);
    expect(h.step[0]['@type']).toBe('HowToStep');
    expect(h.step[2].text).toBe('tc');
  });
});

describe('breadcrumbs', () => {
  const b: any = breadcrumbs('https://x.test', [{ name: 'Home', path: '/' }, { name: 'Features', path: '/features' }]);
  it('builds absolute positioned items', () => {
    roundTrips(b);
    expect(b['@context']).toBe('https://schema.org');
    expect(b['@type']).toBe('BreadcrumbList');
    expect(b.itemListElement.map((i: any) => i.position)).toEqual([1, 2]);
    expect(b.itemListElement[0]['@type']).toBe('ListItem');
    expect(b.itemListElement[1].item).toBe('https://x.test/features/');
    expect(b.itemListElement[0].item).toBe('https://x.test/');
  });
});
