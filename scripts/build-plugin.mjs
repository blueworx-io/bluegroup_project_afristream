// Stages the deployable plugin folder at dist/afristream-portal/ — exactly
// what gets zipped into afristream-portal.zip (the deployment artifact).
// Only runtime files are included; repo tooling (tests, preview, CI) is not.

import { cpSync, mkdirSync, rmSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import process from 'node:process';

const ROOT = process.cwd();
const SLUG = 'afristream-portal';
const OUT = join(ROOT, 'dist', SLUG);

const INCLUDE = ['afristream-portal.php', 'assets', 'includes', 'data'];

rmSync(join(ROOT, 'dist'), { recursive: true, force: true });
mkdirSync(OUT, { recursive: true });

for (const item of INCLUDE) {
  const src = join(ROOT, item);
  if (!existsSync(src)) {
    console.error(`Missing required plugin file/folder: ${item}`);
    process.exit(1);
  }
  cpSync(src, join(OUT, item), { recursive: true });
}

console.log(`Plugin staged at dist/${SLUG}/ — zip this folder as ${SLUG}.zip to deploy.`);
