// php -l over every PHP file in the plugin. Same skip-when-absent rule as the
// PHP test runner: a missing interpreter is reported, never silently passed.

import { spawnSync } from 'node:child_process';
import { readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import process from 'node:process';

const probe = spawnSync('php', ['-v'], { stdio: 'ignore' });
if (probe.error) {
  console.log('PHP lint: SKIPPED — no `php` on PATH.');
  process.exit(0);
}

const SKIP = new Set(['node_modules', 'dist', '.git', '.cache', 'playwright-report', 'test-results']);

function phpFiles(dir) {
  const out = [];
  for (const entry of readdirSync(dir)) {
    if (SKIP.has(entry)) continue;
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) out.push(...phpFiles(full));
    else if (entry.endsWith('.php')) out.push(full);
  }
  return out;
}

let failed = 0;
for (const file of phpFiles(process.cwd())) {
  const res = spawnSync('php', ['-l', file], { encoding: 'utf8' });
  if (res.status !== 0) {
    failed++;
    console.error(res.stdout.trim() || res.stderr.trim());
  }
}

console.log(failed ? `PHP lint: ${failed} file(s) with syntax errors` : 'PHP lint: clean');
process.exit(failed ? 1 : 0);
