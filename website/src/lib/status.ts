export type Status = 'available' | 'in-progress' | 'planned';
export const label = (s: Status) => ({ available: 'Available', 'in-progress': 'In progress', planned: 'Planned' }[s]);
export const countByStatus = (items: { status: Status }[]) =>
  items.reduce((acc, i) => ({ ...acc, [i.status]: (acc[i.status] || 0) + 1 }),
    { available: 0, 'in-progress': 0, planned: 0 } as Record<Status, number>);
