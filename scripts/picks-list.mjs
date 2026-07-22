// The Editor Picks ID-list format, and the rules for growing it.
//
// Kept apart from bake-picks.mjs deliberately: these are pure functions over the
// list with no I/O, no network and no entry-point guard, so the test suite can
// import them directly.

// "<tt-id> <imdb-rating>" per line, rating optional, # comments ignored.
// Mirrors afristream_portal_editor_ids() in the plugin.
export function parseEntries(raw) {
  const out = new Map();
  for (const line of String(raw).split(/[\r\n,]+/)) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) continue;
    const m = trimmed.match(/(tt\d+)(?:\D+(\d+(?:\.\d+)?))?/);
    if (!m || out.has(m[1])) continue;
    // Guard the rating: a stray number on the line (a year pasted alongside the
    // ID, say) must not masquerade as a 0-10 score.
    const rating = m[2] !== undefined && Number(m[2]) >= 0 && Number(m[2]) <= 10 ? Number(m[2]) : null;
    out.set(m[1], rating);
  }
  return [...out.entries()].map(([id, rating]) => ({ id, rating }));
}

// Merge a fresh scrape into the list already on disk, additively: nothing is
// ever dropped, existing order is preserved, ratings are refreshed where the
// scrape has a newer one, and titles the scrape has not seen before are appended
// in the order it found them.
//
// Additive is the whole point. IMDb caps a public watchlist view at 250 rows, so
// a scrape sees a window onto the list rather than all of it — replacing the file
// with that window silently discards everything outside it. Accumulating means
// the list can grow past the cap and a bad scrape can only ever be a no-op.
// Removing a title is therefore a deliberate edit to the file, not a side effect
// of a sync.
export function mergeEntries(existing, scraped) {
  const merged = new Map(existing.map((e) => [e.id, e.rating]));
  let added = 0;
  let updated = 0;
  for (const { id, rating } of scraped) {
    const known = merged.has(id);
    if (!known) added += 1;
    // A null rating from the scrape never overwrites a rating we already have —
    // IMDb hides the score on some rows, and that is not the same as it changing.
    const next = rating === null || rating === undefined ? (known ? merged.get(id) : null) : Number(rating);
    if (known && next !== merged.get(id)) updated += 1;
    merged.set(id, next);
  }
  return { entries: [...merged.entries()].map(([id, rating]) => ({ id, rating })), added, updated };
}
