import { features } from '../data/features';
import type { Status } from './status';

/** Look up a feature's status in the registry. Throws on unknown ids so typos fail the build. */
export const statusOf = (id: string): Status => {
  const f = features.find((x) => x.id === id);
  if (!f) throw new Error(`Unknown feature id: ${id}`);
  return f.status;
};
