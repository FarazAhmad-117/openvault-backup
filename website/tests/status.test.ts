import { describe, it, expect } from 'vitest';
import { features } from '../src/data/features';
import { countByStatus, label } from '../src/lib/status';

describe('feature registry', () => {
  it('only uses known statuses', () => {
    for (const f of features) expect(['available', 'in-progress', 'planned']).toContain(f.status);
  });
  it('has unique ids', () => {
    expect(new Set(features.map((f) => f.id)).size).toBe(features.length);
  });
  it('labels statuses for humans', () => {
    expect(label('in-progress')).toBe('In progress');
  });
  it('counts by status', () => {
    expect(countByStatus(features).available).toBeGreaterThanOrEqual(0);
  });
  it('does not mark restore or any backup feature available before a release says so', () => {
    const backupish = features.filter((f) => /backup|restore|schedule|destination/i.test(f.id));
    expect(backupish.every((f) => f.status !== 'available' || f.since)).toBe(true);
  });
});
