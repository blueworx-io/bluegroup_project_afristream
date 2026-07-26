// Runs the PHP unit suite (tests/php) from npm.
//
// Skips with a clear message when php is not on PATH: the shared CI workflow
// lives in bluegroup_core_foundation and this repo cannot guarantee a PHP
// binary there. A missing interpreter must not read as a passing suite, so the
// skip is loud, and locally — where PHP is present — the tests always run.

import { spawnSync } from 'node:child_process';
import process from 'node:process';

const probe = spawnSync('php', ['-v'], { stdio: 'ignore' });

if (probe.error) {
  console.log('PHP tests: SKIPPED — no `php` on PATH. Run them locally with `php tests/php/run.php`.');
  process.exit(0);
}

const run = spawnSync('php', ['tests/php/run.php'], { stdio: 'inherit' });
process.exit(run.status === null ? 1 : run.status);
