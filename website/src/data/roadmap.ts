// Release milestones from docs/plan/01-scope-and-roadmap.md. No dates on purpose: do not invent any.
export interface Milestone {
  id: string;
  name: string;
  channel: string;
  summary: string;
  exit: string[];
  phases: string;
}

export const milestones: Milestone[] = [
  {
    id: 'alpha',
    name: 'Alpha',
    channel: 'GitHub',
    summary: 'The core loop works end to end. Backup, with local and S3-compatible destinations, and restore from history with undo both work in 0.3.0. It is still pre-alpha, so try restore on a copy of your site.',
    exit: ['Backup and restore round trip works on 3 test hosts'],
    phases: 'Core of the MVP',
  },
  {
    id: 'beta',
    name: 'Beta',
    channel: 'GitHub',
    summary: 'Feature complete for the first release, open to outside testers.',
    exit: ['MVP complete', 'Plugin Check clean', 'Public testers'],
    phases: 'MVP',
  },
  {
    id: '1-0',
    name: '1.0',
    channel: 'WordPress.org',
    summary: 'The stable release.',
    exit: ['Phase 2 done', 'Documentation complete', 'No known data-loss bugs'],
    phases: 'MVP and Phase 2',
  },
];
