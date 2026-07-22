// Bakes a sports TV guide into data/sports-listings.json at build/deploy time.
//
// Source: iptv-org/epg (MIT), a community-maintained grabber that reads the
// published EPG straight from broadcasters — DStv/SuperSport for Africa and
// Sky for the UK & Ireland. It is the only permanently free source of that
// data; every commercial sports API puts broadcaster listings behind a paid
// plan. The grabber is a Node project of its own, cloned into .cache/epg and
// run here rather than added to this repo's dependencies.
//
// Why build-time and not from WordPress: the grab clones ~150MB, installs its
// own dependency tree and takes minutes — nothing a page request can do. The
// plugin just reads the baked JSON and drops anything already finished, the
// same pattern as the IMDb watchlist sync.
//
//   npm run sync-listings              # ~3 days of listings
//   EPG_DAYS=5 npm run sync-listings   # more days
//   EPG_CACHE=/tmp/epg npm run sync-listings

import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import process from 'node:process';

const ROOT = process.cwd();
const CACHE = resolve(ROOT, process.env.EPG_CACHE || '.cache');
const REPO = join(CACHE, 'epg');
const CHANNELS = join(CACHE, 'sports.channels.xml');
const GUIDE = join(CACHE, 'guide.xml');
const OUT = join(ROOT, 'data', 'sports-listings.json');
const DAYS = Number(process.env.EPG_DAYS || 3);

// Which of the grabber's channel lists to mine. DStv publishes a separate list
// per market; the three biggest English-language ones carry the whole
// SuperSport line-up between them.
const SOURCES = [
  'sites/dstv.com/dstv.com_za.channels.xml',
  'sites/dstv.com/dstv.com_ng.channels.xml',
  'sites/dstv.com/dstv.com_ke.channels.xml',
  'sites/sky.com/sky.com.channels.xml',
];

// Only sports channels — the grabber's lists are whole platform line-ups.
const SPORTS_CHANNEL = /supersport|skysports|beinsport|eurosport/i;

// Channel ids carry an ISO country suffix ("SuperSportCricket.za@SD"), which is
// where the broadcast country comes from.
const COUNTRIES = {
  za: 'South Africa', ng: 'Nigeria', ke: 'Kenya', gh: 'Ghana',
  uk: 'United Kingdom', ie: 'Ireland', qa: 'Qatar', fr: 'France',
};

// Sport name (from the guide's own <category>, or failing that the channel id
// and programme title) => the portal's sporting code, so a listing lands under
// the right "Sport Type" filter. Order matters: first match wins.
const SPORT_CODES = [
  [/cricket|\bt20\b|test match|the hundred/i, 'Cricket'],
  [/rugby|six nations|united rugby/i, 'Rugby'],
  [/golf|open championship|\bpga\b|ryder cup/i, 'Golf'],
  [/tennis|wimbledon|\batp\b|\bwta\b/i, 'Tennis'],
  [/motorsport|formula 1|formula one|\bf1\b|motogp|nascar|grand prix|racing/i, 'Motorsport'],
  [/american football|\bnfl\b|super bowl/i, 'American Football'],
  [/basketball|\bnba\b/i, 'Basketball'],
  [/\bufc\b|boxing|\bmma\b|wrestling|fighting/i, 'MMA'],
  [/football|soccer|premier league|la liga|serie a|bundesliga|champions league|\bpsl\b/i, 'Soccer'],
];

// Repeats and filler. A sports channel's day is mostly highlight reels of the
// same fixture on a loop, which is noise in a "what's on" row — the portal
// wants the live event, not the sixth airing of its highlights.
const FILLER = /\bHL\b|highlights|\bmag\b|magazine|classic|rewind|\brepeat\b|preview show/i;

// "Team A vs Team B" in any of the spellings broadcasters use.
const FIXTURE = /\s+(?:vs?\.?|v)\s+/i;
// A series episode marker, e.g. "S26/E9 of 12".
const EPISODE = /^S\d+\/E\d+/i;
// Trailing words that qualify a fixture rather than name one.
const QUALIFIER = /^(women|men|ladies|mixed|final|semi-final|day \d+|round \d+|part \d+)$/i;
// A candidate that names nothing on its own: a lone number ("16/17"), or a
// single short word ("Iasi"). Both come from descriptions that put the useful
// text somewhere other than where the fixture normally sits.
const USELESS = /^(\d+([\/.-]\d+)*|\S{1,6})$/;

function run(cmd, args, cwd) {
  const options = { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] };
  // npm and npx are .cmd shims on Windows, and since Node 22 those can only be
  // spawned through a shell — which means quoting the arguments ourselves,
  // because a shell invocation gets one command line rather than an argv.
  if (process.platform === 'win32' && /^(npm|npx)$/.test(cmd)) {
    const quoted = args.map((arg) => (/[\s"]/.test(arg) ? `"${arg.replace(/"/g, '\\"')}"` : arg));
    return execFileSync(`${cmd}.cmd`, quoted, { ...options, shell: true });
  }
  return execFileSync(cmd, args, options);
}

/** Clone the grabber on first run, refresh it on later ones. */
function ensureGrabber() {
  mkdirSync(CACHE, { recursive: true });
  if (!existsSync(REPO)) {
    console.log('Cloning iptv-org/epg (one-off, ~150MB)…');
    run('git', ['clone', '--depth', '1', '-b', 'master', 'https://github.com/iptv-org/epg.git', REPO], CACHE);
  } else {
    console.log('Refreshing iptv-org/epg…');
    try {
      run('git', ['pull', '--ff-only'], REPO);
    } catch {
      console.warn('  pull failed — carrying on with the existing checkout');
    }
  }
  if (!existsSync(join(REPO, 'node_modules'))) {
    console.log('Installing the grabber\'s dependencies…');
    run('npm', ['install', '--no-audit', '--no-fund'], REPO);
  }
  // The grab reads a reference dataset (countries, timezones) that the
  // grabber's own postinstall fetches. Fetch it explicitly rather than trust a
  // hook that silently no-ops when npm skips lifecycle scripts.
  if (!existsSync(join(REPO, 'temp', 'data', 'timezones.json'))) {
    console.log('Loading the grabber\'s reference data…');
    run('npm', ['run', 'api:load'], REPO);
  }
}

/** XML attribute reader that tolerates single or double quotes. */
function attr(tag, name) {
  const match = tag.match(new RegExp(`${name}=["']([^"']*)["']`));
  return match ? match[1] : '';
}

const decode = (s) => s
  .replace(/&apos;/g, "'").replace(/&quot;/g, '"')
  .replace(/&lt;/g, '<').replace(/&gt;/g, '>')
  .replace(/&amp;/g, '&').trim();

/**
 * "SuperSportCricket.za@SD" => { name: 'SuperSport Cricket', country: 'South
 * Africa' }. Falls back to an empty country for a suffix we don't know.
 */
function channelMeta(id) {
  const [base, region] = id.split('@')[0].split('.');
  const name = base
    .replace(/([a-z])([A-Z0-9])/g, '$1 $2')
    // Brand names the camel-case split gets wrong.
    .replace(/^Super Sport/, 'SuperSport')
    .replace(/^Sky Sports/, 'Sky Sports')
    .replace(/^Bein Sports?/i, 'beIN Sports')
    .replace(/^Euro Sport/, 'Eurosport');
  return { name, country: COUNTRIES[String(region).toLowerCase()] || '' };
}

/**
 * Narrow the grabber's platform-wide channel lists down to sports channels and
 * write the combined list the grab reads.
 */
function writeChannelList() {
  // Keyed by channel name, not id: Sky publishes a .uk and a .ie feed of every
  // Sky Sports channel with identical programming, so grabbing both would
  // double the run time and put every listing on screen twice. Ireland is kept
  // only where there is no UK equivalent.
  const chosen = new Map();

  for (const source of SOURCES) {
    const path = join(REPO, source);
    if (!existsSync(path)) {
      console.warn(`  skipping missing channel list: ${source}`);
      continue;
    }
    for (const match of readFileSync(path, 'utf8').matchAll(/<channel[^>]*>[^<]*<\/channel>/g)) {
      const tag = match[0];
      const id = attr(tag, 'xmltv_id');
      if (!id || !SPORTS_CHANNEL.test(id)) continue;

      const { name, country } = channelMeta(id);
      const existing = chosen.get(name);
      if (existing && !(existing.country === 'Ireland' && country !== 'Ireland')) continue;
      chosen.set(name, { country, line: `  ${tag.trim()}` });
    }
  }

  if (!chosen.size) throw new Error('No sports channels matched — the upstream channel lists may have moved.');
  const lines = [...chosen.values()].map((c) => c.line);
  writeFileSync(CHANNELS, `<?xml version="1.0" encoding="UTF-8"?>\n<channels>\n${lines.join('\n')}\n</channels>\n`);
  console.log(`Selected ${lines.length} sports channels.`);
}

/** id => { name, country } for every channel in the written list. */
function readChannelMeta() {
  const meta = new Map();
  if (!existsSync(CHANNELS)) throw new Error(`No channel list at ${CHANNELS}.`);
  for (const match of readFileSync(CHANNELS, 'utf8').matchAll(/<channel[^>]*>[^<]*<\/channel>/g)) {
    const id = attr(match[0], 'xmltv_id');
    if (id) meta.set(id, channelMeta(id));
  }
  return meta;
}

/** "20260722183000 +0200" => ISO 8601. */
function xmltvTime(value) {
  const match = String(value).match(/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})\s*([+-]\d{4})?$/);
  if (!match) return '';
  const [, y, mo, d, h, mi, s, tz] = match;
  const offset = tz ? `${tz.slice(0, 3)}:${tz.slice(3)}` : '+00:00';
  return `${y}-${mo}-${d}T${h}:${mi}:${s}${offset}`;
}

/**
 * The guide carries its own <category> tags ("Cricket", "All Sport"), which
 * beat guessing from the title. "All Sport" is a catch-all every SuperSport
 * programme carries, so it is never the answer on its own.
 */
function sportFor(categories, channelId, title) {
  for (const haystack of [...categories.filter((c) => !/^all sport$/i.test(c)), channelId, title]) {
    for (const [pattern, code] of SPORT_CODES) {
      if (pattern.test(haystack)) return code;
    }
  }
  return 'Sport';
}

/**
 * Broadcaster titles are shorthand ("Int CRI '26: WI v NZL 5th ODI") while the
 * description spells the fixture out ("'ODI Series - West Indies vs New
 * Zealand 5th ODI'. LIVE From Kensington Oval"). Prefer the readable one, and
 * split off the competition where the description names it.
 *
 * Returns { comp, fx } with comp empty when there is nothing better than the
 * channel name.
 */
function fixtureOf(title, desc) {
  const quoted = (desc.match(/^'([^']+)'/) || [])[1] || '';
  const colonTail = title.split(/\s*:\s*/).pop().trim();

  for (const source of [quoted, colonTail, title]) {
    // A studio series' description is just its episode marker ("S26/E9 of 12")
    // or a bare fragment ("16/17"), neither of which says what is on — the
    // title does better.
    if (!source || EPISODE.test(source) || USELESS.test(source)) continue;

    const parts = source.split(/\s+-\s+/).map((p) => p.trim()).filter(Boolean);
    const tail = parts.length > 1 ? parts[parts.length - 1] : source;

    // "ODI Series - West Indies vs New Zealand" => comp "ODI Series", fx the rest.
    if (parts.length > 1 && FIXTURE.test(tail)) {
      return { comp: parts.slice(0, -1).join(' · '), fx: tail };
    }
    if (FIXTURE.test(source)) return { comp: '', fx: source };
    // "The Hundred - Women" leaves "Women", which is a qualifier, not a fixture.
    if (!QUALIFIER.test(tail)) return { comp: '', fx: source.replace(/^live\s+/i, '').trim() };
  }
  return { comp: '', fx: title };
}

/** Parse the grabbed XMLTV into the portal's sport-row shape. */
function parseGuide(meta) {
  const xml = readFileSync(GUIDE, 'utf8');
  const listings = [];
  const seen = new Map();
  const cutoff = Date.now();
  let filler = 0;

  for (const match of xml.matchAll(/<programme\b([^>]*)>([\s\S]*?)<\/programme>/g)) {
    const [, attrs, body] = match;
    const tag = `<x ${attrs}>`;
    const channel = meta.get(attr(tag, 'channel'));
    if (!channel) continue;

    const titleMatch = body.match(/<title[^>]*>([\s\S]*?)<\/title>/);
    const title = titleMatch ? decode(titleMatch[1]) : '';
    if (!title) continue;

    const descMatch = body.match(/<desc[^>]*>([\s\S]*?)<\/desc>/);
    const desc = descMatch ? decode(descMatch[1]) : '';

    // Keep anything the broadcaster flags as LIVE; drop the highlight loops.
    const isLive = /\bLIVE\b/.test(desc);
    if (!isLive && FILLER.test(`${title} ${desc}`)) {
      filler++;
      continue;
    }

    const start = xmltvTime(attr(tag, 'start'));
    const stop = xmltvTime(attr(tag, 'stop'));
    if (!start) continue;
    // Anything already finished is dead weight in a baked file.
    if (stop && Date.parse(stop) < cutoff) continue;

    // The same fixture is often listed back to back on one channel, and Sky
    // publishes a separate .uk and .ie feed of each channel carrying identical
    // programming — both collapse here, because the key is the channel's name
    // and slot rather than its feed id. Where a guide predates the .uk/.ie
    // preference applied when the channel list is built, the UK row still wins
    // rather than labelling Sky Sports as an Irish channel.
    const key = `${channel.name}|${title}|${start}`;
    const previous = seen.get(key);
    if (previous !== undefined) {
      if (channel.country !== 'Ireland' && listings[previous].chCountry === 'Ireland') {
        listings[previous].chCountry = channel.country;
        listings[previous].country = channel.country;
      }
      continue;
    }
    seen.set(key, listings.length);

    const categories = [...body.matchAll(/<category[^>]*>([\s\S]*?)<\/category>/g)].map((c) => decode(c[1]));
    const { comp, fx } = fixtureOf(title, desc);

    listings.push({
      comp: comp || channel.name,
      code: sportFor(categories, attr(tag, 'channel'), title),
      country: channel.country,
      fx,
      title,
      iso: start,
      endIso: stop,
      ch: channel.name,
      chCountry: channel.country,
    });
  }

  listings.sort((a, b) => a.iso.localeCompare(b.iso));
  console.log(`Dropped ${filler} highlight/filler programmes.`);
  return listings;
}

// EPG_SKIP_GRAB=1 re-parses the guide already in the cache — the grab is the
// slow part, so this is how you iterate on the parsing without re-fetching.
if (process.env.EPG_SKIP_GRAB === '1') {
  if (!existsSync(GUIDE)) {
    console.error(`EPG_SKIP_GRAB=1 but no guide at ${GUIDE} — run the sync once first.`);
    process.exit(1);
  }
  console.log('Skipping the grab; re-parsing the cached guide.');
} else {
  ensureGrabber();
  writeChannelList();
  console.log(`Grabbing ${DAYS} days of listings — this takes a few minutes…`);
  // Called through tsx directly rather than `npm run grab`: npm's argument
  // forwarding swallows these flags on Windows and the grab then hangs.
  run('npx', ['tsx', 'scripts/commands/epg/grab.ts', '--channels', CHANNELS, '--days', String(DAYS), '--output', GUIDE], REPO);
}

// Read back from the channel list actually used, so a skipped grab still maps
// channel ids onto names and countries.
const meta = readChannelMeta();

const listings = parseGuide(meta);
if (!listings.length) {
  console.error('Grabbed guide contained no usable sports listings — leaving data/sports-listings.json untouched.');
  process.exit(1);
}

writeFileSync(OUT, `${JSON.stringify({
  generated: new Date().toISOString(),
  source: 'iptv-org/epg',
  days: DAYS,
  listings,
}, null, 2)}\n`);

const channels = new Set(listings.map((l) => l.ch));
console.log(`Wrote ${listings.length} listings across ${channels.size} channels to data/sports-listings.json`);
