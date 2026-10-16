import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { PLUGIN_VERSION } from '../src/config/site';

describe('plugin version', () => {
  it('matches the Version header in openvault-backup.php', () => {
    const php = readFileSync(new URL('../../openvault-backup.php', import.meta.url), 'utf8');
    const m = php.match(/^\s*\*\s*Version:\s*(\d+\.\d+\.\d+)\s*$/m);
    expect(m).not.toBeNull();
    expect(PLUGIN_VERSION).toBe(m![1]);
  });
});
