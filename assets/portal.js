/* AfriStream Customer Portal — vanilla JS port of the Claude Design handoff
   (AfriStream Portal v2.dc.html). Renders into any element carrying the
   data-afristream-portal attribute; state lives per-mount and every state
   change re-renders the whole portal. */

(function () {
  'use strict';

  // ---------------------------------------------------------------- helpers

  const HUES = { Action: 25, Drama: 320, Crime: 265, Comedy: 80, Family: 210, Docs: 155, Kids: 55, News: 235, Ent: 290, Sport: 145, Movies: 300 };
  const hue = (g) => HUES[g] ?? 250;
  const bg = (g) => {
    const h = hue(g);
    return `linear-gradient(165deg, oklch(0.52 0.13 ${h}) 0%, oklch(0.27 0.09 ${(h + 45) % 360}) 100%)`;
  };
  const mk = (t, genre, platform, meta) => ({ t, genre, platform, meta, initial: t[0], bg: bg(genre) });

  const esc = (s) => String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

  // Subscriptions are annual, and commission is paid on every renewal, so a
  // year's income is every cohort still subscribed — not just that year's new
  // sign-ups. Sign up the same number of people five years running and the
  // fifth year pays five times the first.
  //
  // Minor units throughout (pence, cents) so five years of arithmetic cannot
  // drift by a penny. Returns the year-by-year series the graph draws, plus
  // the totals beside it.
  //
  // `recurring` false means commission on the first payment only, so a cohort
  // pays in its own year and never again. `recurringDays` caps how many
  // renewals a cohort is paid for, in whole years.
  const projectEarnings = (commissionMinor, perYear, years, recurring = true, recurringDays = null) => {
    const maxPayments = recurring && recurringDays ? Math.max(0, Math.floor(recurringDays / 365)) : null;
    const yearly = [];
    for (let y = 0; y < years; y++) {
      let payers = 0;
      for (let cohort = 0; cohort <= y; cohort++) {
        if (!recurring) {
          if (cohort === y) payers += perYear;
          continue;
        }
        if (null !== maxPayments && y - cohort >= maxPayments) continue;
        payers += perYear;
      }
      yearly.push(payers * commissionMinor);
    }
    return {
      yearly,
      first: yearly[0] || 0,
      last: yearly[years - 1] || 0,
      total: yearly.reduce((a, b) => a + b, 0)
    };
  };

  // How many years the projection runs for. Long enough to show what recurring
  // commission compounds into over a decade of renewals.
  const AFF_YEARS = 10;

  // Where a customer pays by bank transfer. Shown on the Account tab only
  // when the plugin settings say so, since not every install takes payment
  // this way.
  const BANK_DETAILS = [
    { label: 'Bank Name', value: 'Standard Bank' },
    { label: 'Branch Code', value: '317' },
    { label: 'Acc Name', value: 'MR LUKE MCFARLAND' },
    { label: 'Acc Number', value: '28 089 075 3' },
  ];

  // The two things an affiliate actually sends people to buy. They are only a
  // fallback: WordPress passes the checkout URLs saved in the plugin settings,
  // so changing a price point there moves these links with it. The price ids are
  // SureCart's and the bracket escaping is deliberate — these are pasted from
  // the store's own buy links and must survive verbatim.
  const AFF_BUY_LINKS = [
    { key: 'subscription', label: 'AfriStream Subscription', note: 'For users that have their own device', url: 'https://afristream.io/checkout/?line_items%5B0%5D%5Bprice_id%5D=e204f70c-35dc-498c-b2b4-e850e6d84ac8&line_items%5B0%5D%5Bquantity%5D=1' },
    { key: 'subscription-setup', label: 'AfriStream Subscription & Setup', note: 'For users that need us to buy a device for them', url: 'https://afristream.io/checkout/?line_items%5B0%5D%5Bprice_id%5D=8b2a7b7a-cf23-4f96-97f6-47acfe925412&line_items%5B0%5D%5Bquantity%5D=1' },
  ];

  // A buy link earns nothing unless it carries the affiliate's code, so the
  // referral URL's own query is lifted off and appended. Read from the URL
  // rather than hardcoded as "ref=" because the parameter is SureCart's to
  // name — if the store renames it, the referral link changes with it and
  // these follow, instead of quietly attributing to nobody.
  // The plugin version on the end of a checkout link, so an update is never
  // hidden behind a cached page. Stable between page loads — an affiliate's
  // copied link keeps working — and left alone if the URL already carries one.
  const bustUrl = (url, version) => {
    if (!version || /[?&]v=/.test(url)) return url;
    return url + (url.indexOf('?') === -1 ? '?' : '&') + 'v=' + encodeURIComponent(version);
  };

  const referralQuery = (referralUrl) => String(referralUrl || '').split('#')[0].split('?')[1] || '';
  const withReferral = (url, referralUrl) => {
    const query = referralQuery(referralUrl);
    if (!query) return url;
    return url + (url.indexOf('?') === -1 ? '?' : '&') + query;
  };

  // The link itself is a wall of percent-encoded price ids — unreadable, and
  // nothing an affiliate can check at a glance. What they need to see is that
  // it points at AfriStream's checkout and carries their code, so the price
  // ids collapse to an ellipsis and the code stays visible. The copy button
  // still puts the full, exact URL on the clipboard.
  const friendlyBuyUrl = (url, referralUrl) => {
    const base = String(url).split('?')[0].replace(/^https?:\/\//, '');
    const query = referralQuery(referralUrl);
    return base + '?…' + (query ? '&' + query : '');
  };

  // "£45 / month" for a plain monthly or annual price; a multi-month interval
  // (a quarterly price, say) is a lie as "/ month", so it gets spelled out —
  // "every 3 months" — instead.
  const planIntervalLabel = (plan) => {
    const count = Math.max(1, Number(plan.interval_count) || 1);
    const unit = 'year' === plan.interval ? 'year' : 'month';
    return count > 1 ? `every ${count} ${unit}s` : `/ ${unit}`;
  };

  // The currencies an affiliate can read their earnings in. Rands first,
  // because most of them are in South Africa; named rather than coded because
  // "Rands" is what people say and ZAR is what accountants say.
  const AFF_CURRENCIES = [
    { code: 'zar', label: 'Rands' },
    { code: 'usd', label: 'Dollars' },
    { code: 'gbp', label: 'Pounds' },
    { code: 'eur', label: 'Euros' }
  ];

  const yr = (meta) => {
    const m = String(meta).match(/((?:19|20)\d\d)/);
    return m ? +m[1] : 0;
  };
  const decadeOf = (year) => (year ? `${Math.floor(year / 10) * 10}s` : '');

  // ------------------------------------------------------------------- data

  const ACCOUNTS = [
    { label: 'Profile 1', user: 'BabyBlue123', pass: '8FSWPDHMBn' },
    { label: 'Profile 2', user: 'BabyBlue-TV', pass: 'kR2mWQZv7p' }
  ];

  const MOVIES = [
    mk('Iron Vows', 'Action', 'Netflix', '2026 · 1h 58m'),
    mk('The Long Rains', 'Drama', 'Showmax', '2026 · 2h 11m'),
    mk('Jozi Nights', 'Crime', 'Netflix', '2025 · 1h 47m'),
    mk('Blackout Protocol', 'Action', 'Prime Video', '2026 · 2h 04m'),
    mk('Safari Blue', 'Family', 'Disney+', '2025 · 1h 36m'),
    mk('Stand-Up Lagos', 'Comedy', 'Prime Video', '2026 · 1h 12m'),
    mk('The Salt Coast', 'Drama', 'Apple TV+', '2026 · 1h 52m'),
    mk('Midnight Taxi', 'Crime', 'Showmax', '2025 · 1h 41m')
  ];
  const SERIES = [
    mk('Harbour House', 'Drama', 'Showmax', 'S3 · 8 episodes'),
    mk('The Syndicate', 'Crime', 'Netflix', 'S2 · 10 episodes'),
    mk('Dust & Gold', 'Drama', 'Prime Video', 'Limited · 6 episodes'),
    mk('Camps Bay Med', 'Drama', 'Showmax', 'S5 · 12 episodes'),
    mk('The Fixer', 'Crime', 'Netflix', 'S1 · 8 episodes'),
    mk('Once Upon a Braai', 'Comedy', 'Prime Video', 'S2 · 10 episodes'),
    mk('Little Explorers', 'Kids', 'Disney+', 'S4 · 20 episodes'),
    mk('Deep Water', 'Docs', 'Apple TV+', 'Docuseries · 5 parts')
  ];
  const NEW_WEEK = [
    mk('Kagiso', 'Drama', 'Netflix', 'Added Friday'),
    mk('Bush Pilots', 'Docs', 'Disney+', 'Added Thursday'),
    mk('The Salt Coast', 'Drama', 'Apple TV+', 'Added Wednesday'),
    mk('Vula!', 'Comedy', 'Showmax', 'Added Tuesday'),
    mk('Night Market', 'Crime', 'Prime Video', 'Added Monday'),
    mk('Tide Riders', 'Family', 'Disney+', 'Added Monday')
  ];
  const SPORT = [
    { comp: 'FIFA World Cup', code: 'Soccer', country: 'International', fx: 'Semi-final build-up', time: 'LIVE now', ch: 'FOX Sports', chCountry: 'United States', live: true },
    { comp: 'Premier League', code: 'Soccer', country: 'England', fx: 'Arsenal vs Spurs', time: 'Today · 21:00', ch: 'Sky Sports PL', chCountry: 'United Kingdom', live: false },
    { comp: 'ICC World Cup', code: 'Cricket', country: 'International', fx: 'Super Eight · Match Day', time: 'Today · 14:30', ch: 'Sky Sports Cricket', chCountry: 'United Kingdom', live: false },
    { comp: 'PGA Tour', code: 'Golf', country: 'United States', fx: 'The Open · Round 2', time: 'Fri · 13:00', ch: 'Sky Sports Golf', chCountry: 'United Kingdom', live: false },
    { comp: 'URC Rugby', code: 'Rugby', country: 'International', fx: 'Final · Build-up', time: 'Sat · 18:00', ch: 'SuperSport', chCountry: 'South Africa', live: false },
    { comp: 'Formula 1', code: 'Motorsport', country: 'International', fx: 'British GP · Qualifying', time: 'Sat · 15:00', ch: 'Sky Sports F1', chCountry: 'United Kingdom', live: false },
    { comp: 'UFC', code: 'MMA', country: 'International', fx: 'Fight Night Prelims', time: 'Sun · 02:00', ch: 'ESPN+', chCountry: 'United States', live: false },
    { comp: 'NBA', code: 'Basketball', country: 'United States', fx: 'Summer League opener', time: 'Sun · 22:00', ch: 'ESPN', chCountry: 'United States', live: false }
  ];
  // A stable index into a list of `len` for the current calendar day: the same
  // pick all day, a different one tomorrow. Seeded from the local date so it
  // turns over at the viewer's midnight, and hashed (rather than day-of-year
  // modulo len) so consecutive days don't just walk the list in order.
  function dailyIndex(len) {
    if (len <= 0) return 0;
    const d = new Date();
    const seed = d.getFullYear() * 10000 + (d.getMonth() + 1) * 100 + d.getDate();
    let h = seed;
    h = (h ^ (h >>> 15)) * 2246822507;
    h = (h ^ (h >>> 13)) * 3266489909;
    h = (h ^ (h >>> 16)) >>> 0;
    return h % len;
  }

  // Star rating parsed from a "★ 8.4" platform badge; -1 when unrated.
  const ratingOf = (x) => { const m = String(x && x.platform).match(/([\d.]+)/); return m ? +m[1] : -1; };

  // Collections are live queries over the fetched TMDB catalog — no manual title
  // lists. Each `match(item)` runs at render time over the flat INDEX (movies +
  // series only); the card shows the real match count and the detail drawer shows
  // the actual matching posters. `sort: 'rating'` orders a collection by rating.
  const COLLECTIONS = [
    { name: 'Weekend Binge', desc: 'Bingeable series — pick one and settle in.', h: 300, sort: 'rating', match: (x) => x.type === 'Series' },
    // Crime and mystery only. Thriller and Documentary used to be in here too,
    // which is how nature docs (My Octopus Teacher, Penguin Town) and any
    // generic thriller ended up filed under true crime.
    { name: 'True Crime Deep Dive', desc: 'Crime and mystery — the cases that keep you guessing.', h: 265, sort: 'rating', match: (x) => /^(Crime|Mystery)$/.test(x.genre) },
    { name: 'Family Movie Night', desc: 'Safe for the whole couch.', h: 210, match: (x) => x.type === 'Movies' && /^(Family|Kids|Animation)$/.test(x.genre) },
    // Was "Big Match Build-Up — documentaries to line up before kick-off", but
    // nothing in the catalog marks a documentary as sport, so it was really
    // just every documentary. Named for what it actually is.
    { name: 'Documentary Corner', desc: 'Real stories, real people — the pick of the docs.', h: 150, sort: 'rating', match: (x) => /^(Documentary|Docs)$/.test(x.genre) },
    // 7.5 pulled in a third of the catalog, which is not "what the critics
    // loved" — 8.0 keeps the row genuinely selective.
    { name: 'Award Season Catch-Up', desc: 'Everything the critics loved, in one row.', h: 25, sort: 'rating', match: (x) => ratingOf(x) >= 8 }
  ].map((c) => ({ ...c, bg: `linear-gradient(135deg, oklch(0.42 0.12 ${c.h}), oklch(0.24 0.09 ${(c.h + 50) % 360}))` }));

  // Editor Picks filters: a fixed, mutually exclusive set of content types, and
  // the IMDb rating bands offered alongside them. Both are filtered down to
  // the options that actually have picks behind them before rendering.
  const EDITOR_TYPES = ['Movies', 'Series', 'Documentaries'];
  // Each step is the floor of a one-point band, not a minimum: picking 8 shows
  // 8.0–8.9 and nothing higher. The top step stays open-ended so a perfect 10
  // is not stranded outside every band.
  const EDITOR_RATING_STEPS = [6, 7, 8, 9];
  // Sub-categories, offered alphabetically. Deliberately a small, coarse set:
  // six shelves people actually browse by, not the twenty-odd genres TMDB
  // returns.
  const EDITOR_CATEGORIES = ['Action', 'Adventure', 'Animation', 'Comedy', 'Romance', 'Thriller'];
  // Every pick lands on exactly one shelf, so nothing falls out of the filter.
  // TMDB's genre list is wider than the six, and a title only carries its first
  // genre here, so the rest are folded into their nearest neighbour: crime and
  // horror sit with Thriller, the speculative genres with Adventure, war and
  // westerns with Action. Drama is the awkward one — it is the largest genre
  // and none of the six is a drama shelf, so it goes to Thriller, the closest
  // in tone. The card itself keeps showing the real genre; this mapping only
  // decides which pill a title answers to.
  const EDITOR_CATEGORY_MAP = {
    'Action': 'Action',
    'Action & Adventure': 'Action',
    'War': 'Action',
    'War & Politics': 'Action',
    'Western': 'Action',
    'Adventure': 'Adventure',
    'Fantasy': 'Adventure',
    'Science Fiction': 'Adventure',
    'Sci-Fi & Fantasy': 'Adventure',
    'Family': 'Adventure',
    'Animation': 'Animation',
    'Kids': 'Animation',
    'Comedy': 'Comedy',
    'Reality': 'Comedy',
    'Romance': 'Romance',
    'Soap': 'Romance',
    'Thriller': 'Thriller',
    'Crime': 'Thriller',
    'Mystery': 'Thriller',
    'Horror': 'Thriller',
    'Drama': 'Thriller',
  };
  // Anything unmapped — documentaries, history, music, and the bare "Film" /
  // "Movies" placeholders a failed TMDB lookup leaves behind — goes to
  // Adventure rather than being dropped from every shelf.
  const EDITOR_CATEGORY_DEFAULT = 'Adventure';
  const editorCategoryOf = (p) => EDITOR_CATEGORY_MAP[String((p && p.genre) || '').trim()] || EDITOR_CATEGORY_DEFAULT;
  const EDITOR_RATING_TOP = Math.max(...EDITOR_RATING_STEPS);
  const inRatingBand = (rating, floor) => {
    const r = Number(rating);
    if (!floor) return true;
    if (!isFinite(r)) return false;
    return floor === EDITOR_RATING_TOP ? r >= floor : r >= floor && r < floor + 1;
  };
  const ratingBandLabel = (floor) =>
    (floor === EDITOR_RATING_TOP ? `★ ${floor}+` : `★ ${floor}–${floor}.9`);

  // Ceiling for a What-to-Watch row once a single category is selected. Large
  // enough to feel like the full catalog, bounded so one row can't render the
  // entire index into a horizontal scroller.
  const EXPANDED_ROW_MAX = 40;

  const EDITOR_FALLBACK = [
    { t: 'The Colour of Home', genre: 'Drama', platform: '★ 8.4', rating: 8.4, meta: '2024', type: 'Movies', country: 'South Africa', rank: 1 },
    { t: 'Harmattan', genre: 'Thriller', platform: '★ 8.1', rating: 8.1, meta: 'TV · 2023', type: 'Series', country: 'Nigeria', rank: 2 },
    { t: 'Salt & Silver', genre: 'Documentary', platform: '★ 7.9', rating: 7.9, meta: '2022', type: 'Movies', country: 'Kenya', rank: 3 },
    { t: 'The Long Dry', genre: 'Drama', platform: '★ 7.7', rating: 7.7, meta: '2021', type: 'Movies', country: 'South Africa', rank: 4 },
    { t: 'Northern Lights', genre: 'Family', platform: '★ 7.5', rating: 7.5, meta: 'TV · 2020', type: 'Series', country: 'United Kingdom', rank: 5 },
  ].map((p) => ({ ...p, initial: p.t[0], bg: bg(p.genre) }));

  const TIPS_DATA = [
    { tag: 'Setup', h: 250, title: 'Setup Tips', items: ['Use a stable WiFi connection before starting.', 'Make sure the FireStick is fully signed into an Amazon account.', 'Complete all FireStick updates before installing apps.', 'Keep the remote nearby during every install step.'] },
    { tag: 'Permissions', h: 290, title: 'FireStick Permission Tips', items: ['Developer permissions may need to be enabled before downloads work.', 'Allow install permissions when prompted.', 'If an app will not install, check FireStick permission settings first.', 'Restart the FireStick if permissions do not apply immediately.'] },
    { tag: 'Downloads', h: 210, title: 'App Download Tips', items: ['Enter download codes carefully.', 'Double-check codes before pressing download.', 'Wait for each download to fully complete before moving on.', 'Do not exit the app during installation.'] },
    { tag: 'Login', h: 320, title: 'Login Tips', items: ['Usernames and passwords are case-sensitive.', 'Copy login details exactly as provided.', 'Avoid adding spaces before or after the username/password.', 'Save login details somewhere safe.'] },
    { tag: 'Devices', h: 160, title: 'Device Tips', items: ['One license may only allow one active connection at a time.', 'Android devices may support the same login.', 'Apple devices may need a separate player app.', 'Test one device fully before setting up extra devices.'] },
    { tag: 'Support', h: 60, title: 'Support Tips', items: ['Take screenshots of errors.', 'Confirm which device is being used before troubleshooting.', 'Confirm the app name before giving setup support.', 'Ask whether the issue is install, login, or connection related.'] }
  ];

  // ------------------------------------------------------------- apps data
  // Controlled vocabularies for data/apps.json. Any value outside these lists
  // is dropped at load time so one bad row can't break the grid; the schema
  // test in tests/portal.spec.js is what actually catches the mistake.
  const APP_DEVICES = [
    { key: 'smart-tv', label: 'Smart TV' },
    { key: 'consoles', label: 'Consoles' },
    { key: 'sticks', label: 'Sticks & Boxes' },
    { key: 'tablets', label: 'Tablets' },
    { key: 'phones', label: 'Phones' }
  ];
  // Movies, Series and Sport only. Live TV and Documentaries were dropped
  // deliberately — the directory is for on-demand films, series and sport, not
  // for live channels or news. Anything still tagged with a retired value is
  // discarded by prepApp() below.
  const APP_CONTENT = ['Movies', 'Series', 'Sport'];

  // Card tint per content type, reusing the portal's existing hues so app tiles
  // sit in the same palette as the poster cards instead of all defaulting to one
  // colour. Falls back to bg()'s own default for anything unmapped.
  const APP_CONTENT_HUE = { Movies: 'Movies', Series: 'Drama', Sport: 'Sport' };

  // Generic install steps per device class. An app only carries an `install`
  // entry where its real steps differ from these.
  const APP_INSTALL_DEFAULTS = {
    'smart-tv': "Open your TV's app store and search for the app by name.",
    'consoles': 'Open the PlayStation Store or the Microsoft Store on your console and search for the app.',
    'sticks': "Search your device's app store — the Amazon Appstore on a Fire TV Stick, the Channel Store on Roku.",
    'tablets': 'Install from the App Store on iPad, or Google Play on an Android tablet.',
    'phones': 'Install from the App Store on iPhone, or Google Play on Android.'
  };

  const APP_DEVICE_LABEL = (key) => (APP_DEVICES.find((d) => d.key === key) || {}).label || key;

  // Normalise one entry from data/apps.json into the shape the card and the
  // detail drawer expect. `t`, `initial` and `bg` mirror the poster-card
  // contract so the shared drawer chrome renders an app unchanged.
  const prepApp = (a) => ({
    id: String(a.id),
    name: String(a.name),
    t: String(a.name),
    initial: String(a.name)[0],
    bg: bg(APP_CONTENT_HUE[(Array.isArray(a.content) ? a.content : [])[0]] || ''),
    detailKind: 'app',
    cost: a.cost === 'free-tier' ? 'free-tier' : 'free',
    blurb: String(a.blurb || ''),
    availability: String(a.availability || ''),
    // Defence in depth: esc() stops attribute breakout and the schema test
    // asserts ^https://, but that guarantee lives in a different file — keep
    // the front end safe on its own even if the data ever slips past the test.
    url: /^https:\/\//.test(String(a.url || '')) ? String(a.url) : '',
    install: a.install && typeof a.install === 'object' ? a.install : {},
    content: (Array.isArray(a.content) ? a.content : []).filter((c) => APP_CONTENT.includes(c)),
    devices: (Array.isArray(a.devices) ? a.devices : []).filter((d) => APP_DEVICES.some((x) => x.key === d))
  });

  // Troubleshooting accordion. `body` is trusted static HTML (rendered as-is,
  // not escaped) — keep it authored here, never from user input. Mirrors the
  // [troubleshooting_guide] shortcode in includes/shortcodes.php.
  const TROUBLESHOOTING = [
    { badge: 'Note', title: 'Before You Start', body: '<ul><li>Do not uninstall your app unless instructed</li><li>Enter your username and password exactly correct or the app will require a re-connection (Step 3).</li><li>Username and Password are case sensitive</li><li>DNS updates can take some time to propagate</li></ul>' },
    { badge: 'Step 1', title: 'Restart the App', body: '<ol><li>Close the app completely and reopen it.</li><li>If channels/content still do not load, continue to the next step.</li></ol>' },
    { badge: 'Step 2', title: 'Clear Cache &amp; App Data', body: '<h4>Firestick / Android TV</h4><ol><li>Go to Settings</li><li>Open Applications</li><li>Select Manage Installed Applications</li><li>Select your streaming app</li><li>Choose: <strong>Clear Cache</strong> then <strong>Clear Data</strong></li><li>Re-open the app</li><li>Enter your login details again</li></ol>' },
    { badge: 'Step 3', title: 'Reconnect the Playlist / Server', body: '<p>If the app opens but shows no channels/content:</p><ol><li>Open the app menu</li><li>Select <strong>Edit Playlist</strong> or <strong>Update Playlist</strong></li><li>Re-enter your login details carefully</li><li>Save changes</li><li>Press Connect</li><li>Wait 10&ndash;15 seconds for content to load</li></ol>' },
    { badge: 'Step 4', title: 'Playlist Not Working?', body: '<p>This usually means the old DNS/server is cached. Try:</p><ul><li>Rebooting the device</li><li>Clearing app cache again</li><li>Reconnecting the playlist</li><li>Waiting 15&ndash;30 minutes for DNS propagation</li></ul>' },
    { badge: 'Step 6', title: 'Try an Alternative App', body: '<p>If the current app still does not connect after following all previous steps, try installing an alternative supported app.</p><br><h4>Install Alternative App</h4><ol><li>Open the Downloader app</li><li>Enter one of the provided codes:<ul><li><code>569138</code></li><li><code>6573365</code></li><li><code>617725</code></li><li><code>9469460</code></li></ul></li><li>Download and install the app</li><li>Open the new app</li><li>Enter your existing login details</li><li>Allow a few seconds for playlists/content to sync</li></ol><br><h4>If It Still Does Not Work</h4><ul><li>Restart your device</li><li>Retry the login carefully</li><li>Wait for DNS propagation to complete</li><li>Try another listed app if available</li></ul>' },
    { badge: 'Info', title: 'Important Notes', body: '<ul><li>Your username/password stay the same</li><li>Most issues are caused by cached DNS or outdated playlist data</li><li>Full restoration may take some time while apps are updated</li></ul>' }
  ];

  // Flat, deduped search index across every content source. Movies/series/
  // newWeek come from the live data (API or built-in); sport, live TV and
  // collections are always the curated lists.
  function buildIndex(data) {
    const seen = new Set();
    const index = [];
    const src = [
      ...data.movies.map((x) => ({ ...x, type: x.type || 'Movies' })),
      ...data.series.map((x) => ({ ...x, type: x.type || 'Series' })),
      ...data.newWeek.map((x) => ({ ...x, type: x.type || (/episode/i.test(x.meta) ? 'Series' : 'Movies') })),
      ...data.sport.map((s) => ({ t: s.fx, genre: s.code || 'Sport', platform: s.ch, meta: `${s.comp} · ${s.time}`, type: 'Sport', country: s.country || '', initial: s.fx[0], bg: bg('Sport') })),
      ...COLLECTIONS.map((c) => ({ t: c.name, genre: 'Collection', platform: 'AfriStream', meta: 'Collection', type: 'Collection', initial: c.name[0], bg: c.bg })),
      ...(data.catalog || []).map((x) => ({ ...x, type: x.type || 'Movies' })),
    ];
    // Only the deep catalog carries origin country; the trending/newWeek copies
    // of the same title don't. Map title→country from the catalog so a title
    // deduped to its trending copy still matches its Country facet.
    const countryByTitle = new Map();
    for (const x of (data.catalog || [])) {
      if (x.country && !countryByTitle.has(x.t)) countryByTitle.set(x.t, x.country);
    }
    for (const x of src) {
      if (!seen.has(x.t)) {
        seen.add(x.t);
        const year = yr(x.meta);
        index.push({ ...x, year, country: x.country || countryByTitle.get(x.t) || '', decade: decadeOf(year) });
      }
    }
    return index;
  }

  // ---------------------------------------------------------- style helpers

  const navBtn = (a) => `flex:none;border:none;background:none;cursor:pointer;font-family:inherit;font-weight:${a ? 800 : 600};font-size:14px;letter-spacing:.01em;padding:20px 2px 16px;color:${a ? '#fff' : 'rgba(255,255,255,.58)'};border-bottom:3px solid ${a ? '#CD2DF5' : 'transparent'};white-space:nowrap;transition:color .15s,border-color .15s`;
  const subBtn = (a) => `flex:none;cursor:pointer;font-family:inherit;font-size:12.5px;font-weight:700;padding:8px 15px;border-radius:999px;white-space:nowrap;transition:background .15s,color .15s;background:${a ? '#65009F' : '#fff'};color:${a ? '#fff' : 'rgba(11,21,51,.62)'};border:1px solid ${a ? '#65009F' : 'rgba(11,21,51,.12)'}`;
  const badgeStyle = (b) => `display:inline-flex;align-items:center;flex:none;background:${b === 'Note' ? '#65009F' : '#65009F'};color:#fff;font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;white-space:nowrap`;
  const tagStyle = (h) => `align-self:flex-start;position:relative;font-size:10.5px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:4px 10px;border-radius:999px;background:oklch(0.95 0.03 ${h});color:oklch(0.42 0.13 ${h})`;
  const copyBtnStyle = 'flex:none;background:#65009F;color:#fff;border:none;border-radius:13px;padding:13px 24px;font-family:inherit;font-weight:700;font-size:13.5px;cursor:pointer;min-width:98px;box-shadow:0 8px 18px -10px rgba(101,0,159,.7)';

  // Official TMDB short logo (themoviedb.org/about/logos-attribution), inlined
  // so attribution works offline; gradient id namespaced to avoid collisions.
  const TMDB_LOGO = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 273.42 35.52" role="img" aria-label="TMDB" style="height:11px;width:auto;display:block"><defs><linearGradient id="asTmdbGrad" y1="17.76" x2="273.42" y2="17.76" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#90cea1"/><stop offset="0.56" stop-color="#3cbec9"/><stop offset="1" stop-color="#00b3e5"/></linearGradient></defs><path fill="url(#asTmdbGrad)" d="M191.85,35.37h63.9A17.67,17.67,0,0,0,273.42,17.7h0A17.67,17.67,0,0,0,255.75,0h-63.9A17.67,17.67,0,0,0,174.18,17.7h0A17.67,17.67,0,0,0,191.85,35.37ZM10.1,35.42h7.8V6.92H28V0H0v6.9H10.1Zm28.1,0H46V8.25h.1L55.05,35.4h6L70.3,8.25h.1V35.4h7.8V0H66.45l-8.2,23.1h-.1L50,0H38.2ZM89.14.12h11.7a33.56,33.56,0,0,1,8.08,1,18.52,18.52,0,0,1,6.67,3.08,15.09,15.09,0,0,1,4.53,5.52,18.5,18.5,0,0,1,1.67,8.25,16.91,16.91,0,0,1-1.62,7.58,16.3,16.3,0,0,1-4.38,5.5,19.24,19.24,0,0,1-6.35,3.37,24.53,24.53,0,0,1-7.55,1.15H89.14Zm7.8,28.2h4a21.66,21.66,0,0,0,5-.55A10.58,10.58,0,0,0,110,26a8.73,8.73,0,0,0,2.68-3.35,11.9,11.9,0,0,0,1-5.08,9.87,9.87,0,0,0-1-4.52,9.17,9.17,0,0,0-2.63-3.18A11.61,11.61,0,0,0,106.22,8a17.06,17.06,0,0,0-4.68-.63h-4.6ZM133.09.12h13.2a32.87,32.87,0,0,1,4.63.33,12.66,12.66,0,0,1,4.17,1.3,7.94,7.94,0,0,1,3,2.72,8.34,8.34,0,0,1,1.15,4.65,7.48,7.48,0,0,1-1.67,5,9.13,9.13,0,0,1-4.43,2.82V17a10.28,10.28,0,0,1,3.18,1,8.51,8.51,0,0,1,2.45,1.85,7.79,7.79,0,0,1,1.57,2.62,9.16,9.16,0,0,1,.55,3.2,8.52,8.52,0,0,1-1.2,4.68,9.32,9.32,0,0,1-3.1,3A13.38,13.38,0,0,1,152.32,35a22.5,22.5,0,0,1-4.73.5h-14.5Zm7.8,14.15h5.65a7.65,7.65,0,0,0,1.78-.2,4.78,4.78,0,0,0,1.57-.65,3.43,3.43,0,0,0,1.13-1.2,3.63,3.63,0,0,0,.42-1.8A3.3,3.3,0,0,0,151,8.6a3.42,3.42,0,0,0-1.23-1.13A6.07,6.07,0,0,0,148,6.9a9.9,9.9,0,0,0-1.85-.18h-5.3Zm0,14.65h7a8.27,8.27,0,0,0,1.83-.2,4.67,4.67,0,0,0,1.67-.7,3.93,3.93,0,0,0,1.23-1.3,3.8,3.8,0,0,0,.47-1.95,3.16,3.16,0,0,0-.62-2,4,4,0,0,0-1.58-1.18,8.23,8.23,0,0,0-2-.55,15.12,15.12,0,0,0-2.05-.15h-5.9Z"/></svg>';

  // ---------------------------------------------------------- markup pieces

  const posterArt = (m) => `
    <div style="width:100%;aspect-ratio:2/3;border-radius:14px;background:${m.bg};position:relative;overflow:hidden;display:flex;align-items:flex-end;padding:11px;box-shadow:0 10px 24px -18px rgba(11,21,51,.5)">
      ${m.poster
        ? `<img src="${esc(m.poster)}" alt="" loading="lazy" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover"><div style="position:absolute;inset:0;background:linear-gradient(180deg,rgba(5,9,24,0) 42%,rgba(5,9,24,.82))"></div>`
        : `<div style="position:absolute;top:-26px;right:-10px;font-size:120px;font-weight:800;color:rgba(255,255,255,.13);line-height:1;user-select:none">${esc(m.initial)}</div>`}
      <div style="position:absolute;top:9px;left:9px;background:rgba(5,9,24,.55);color:#fff;font-size:10px;font-weight:700;padding:3px 8px;border-radius:999px">${esc(m.platform)}</div>
      <div style="position:relative;color:#fff;font-weight:700;font-size:13.5px;line-height:1.25;text-shadow:0 1px 8px rgba(0,0,0,.4)">${esc(m.t)}</div>
    </div>`;

  const posterMeta = (m) => `
    <div style="margin-top:7px;font-size:11.5px;color:rgba(11,21,51,.55);display:flex;gap:6px;flex-wrap:wrap"><span style="font-weight:700;color:rgba(11,21,51,.7)">${esc(m.genre)}</span><span>·</span><span>${esc(m.meta)}</span></div>`;

  // Shared TMDB attribution block — rendered under any section backed by live
  // TMDB data (watch catalog, editor picks). Byte-identical for both callers.
  const tmdbAttribution = () => `
  <p style="margin:22px 2px 0;display:flex;align-items:center;flex-wrap:wrap;gap:6px 8px;font-size:11px;color:rgba(11,21,51,.45)">
    <a href="https://www.themoviedb.org" target="_blank" rel="noopener noreferrer" aria-label="TMDB" style="display:inline-flex;flex:none">${TMDB_LOGO}</a>
    <span>Listings and artwork from TMDB. This product uses the TMDB API but is not endorsed or certified by TMDB.</span>
  </p>`;

  // ------------------------------------------------------------------ mount

  function createPortal(root) {
    const props = {
      defaultTab: root.getAttribute('data-default-tab') || 'profile',
      showSport: !/^(false|0|no)$/i.test(root.getAttribute('data-show-sport') || 'true'),
      endpoint: root.getAttribute('data-endpoint') || '',
      editorEndpoint: root.getAttribute('data-editor-endpoint') || '',
      detailEndpoint: root.getAttribute('data-detail-endpoint') || '',
      credentialsEndpoint: root.getAttribute('data-credentials-endpoint') || '',
      affiliateEndpoint: root.getAttribute('data-affiliate-endpoint') || '',
      restNonce: root.getAttribute('data-rest-nonce') || '',
      // The two checkout URLs from the plugin settings. Empty means none has
      // been saved yet, in which case the built-in links stand in rather than
      // leaving an affiliate with a dead buy link.
      buyUrl: root.getAttribute('data-buy-url') || '',
      buySetupUrl: root.getAttribute('data-buy-setup-url') || '',
      // Off unless WordPress says otherwise: publishing bank details is a
      // deliberate choice, not something a fresh install should do by itself.
      showBank: /^(true|1|yes)$/i.test(root.getAttribute('data-show-bank') || ''),
      // Stamped onto the buy links so a cached checkout page is never what a
      // customer lands on. Empty outside WordPress, where there is nothing to
      // version against.
      version: root.getAttribute('data-portal-version') || '',
      appsUrl: root.getAttribute('data-apps-url') || '',
      // Where the "Home" link in the header points. WordPress fills this from
      // home_url(); the header hides the link rather than guessing when it is
      // missing, so a portal embedded somewhere unexpected never offers a way
      // out that goes nowhere.
      homeUrl: root.getAttribute('data-home-url') || ''
    };
    // Profile credentials. Without a credentials endpoint (e.g. the generic
    // local preview) the built-in demo ACCOUNTS are shown. With one (the
    // WordPress shortcode), they're fetched for the logged-in user: 'loading'
    // until the fetch resolves, then 'ready' (real profiles), 'empty' (no
    // license assigned) or 'error' (fetch failed).
    let accounts = ACCOUNTS.map((a) => ({ ...a }));
    let credState = props.credentialsEndpoint ? 'loading' : 'demo';
    // Affiliate payload, and whether the tab exists at all. 'off' covers every
    // negative: no endpoint, not an affiliate, SureCart unreachable. There is
    // deliberately no 'error' state — a failed lookup must look exactly like
    // not being an affiliate, or the tab becomes a way to probe for one.
    let affiliate = null;
    let affiliateState = props.affiliateEndpoint ? 'loading' : 'off';
    // Live catalog data — starts as the built-in curated lists, replaced
    // per-array by whatever the watch endpoint returns (TMDB catalog and/or
    // ESPN sport fixtures).
    const data = { movies: MOVIES, series: SERIES, newWeek: NEW_WEEK, sport: SPORT, catalog: [], editorPicks: EDITOR_FALLBACK };
    let INDEX = buildIndex(data);
    let dataSource = 'built-in';
    let editorSource = 'built-in';
    const FILTER_DEFAULTS = { type: 'All Types', genre: 'All Genres', country: 'All Countries', decade: 'All Decades', sort: 'Recommended' };

    const state = {
      // 'tips' and 'help' are hidden from the nav but still valid entry points,
      // so a host page that already pins one of them keeps working.
      section: ['profile', 'setup', 'watch', 'apps', 'editor', 'download', 'affiliate', 'tips', 'help'].includes(props.defaultTab) ? props.defaultTab : 'profile',
      subWatch: 'All',
      guideOpen: 0,
      // '' until the user picks a device on the Setup tab. setupScreen indexes
      // that device's three screens; setupDone is the finished card, which is
      // a stage rather than a fourth screen because it is reachable from the
      // last screen only.
      setupDevice: '',
      setupScreen: 0,
      setupDone: false,
      setupHelpOpen: false,
      // '' until the user picks a platform on the Download tab, which follows
      // the same pick-then-steps shape as Setup.
      downloadPlatform: '',
      affPlan: '',
      affPerYear: 5,
      affValue: 15,
      affCurrency: '',
      accIdx: 0,
      copied: '',
      query: '',
      genre: 'All Genres',
      type: 'All Types',
      country: 'All Countries',
      decade: 'All Decades',
      sort: 'Recommended',
      appsContent: 'All',
      editorTag: 'All',
      editorCategory: 'All',
      editorRating: 0,
      filtersOpen: false,
      detail: null
    };
    let copyTimer = null;

    const setState = (patch) => {
      Object.assign(state, patch);
      render();
    };

    function facetPool(exclude) {
      const q = state.query.trim().toLowerCase();
      return INDEX.filter((x) =>
        (!q || x.t.toLowerCase().includes(q)) &&
        (exclude === 'type' || state.type === 'All Types' || x.type === state.type) &&
        (exclude === 'genre' || state.genre === 'All Genres' || x.genre === state.genre) &&
        (exclude === 'country' || state.country === 'All Countries' || x.country === state.country) &&
        (exclude === 'decade' || state.decade === 'All Decades' || x.decade === state.decade)
      );
    }
    const uniq = (list, key) => [...new Set(list.map((x) => x[key]))];

    function copy(text, key) {
      try {
        navigator.clipboard.writeText(text);
      } catch (e) {
        /* clipboard unavailable (e.g. insecure context) — still show feedback */
      }
      clearTimeout(copyTimer);
      copyTimer = setTimeout(() => setState({ copied: '' }), 1600);
      setState({ copied: key });
    }

    // Minor units in, formatted money out. AfriStream sells globally, so the
    // currency comes from the plan rather than being assumed — and when none
    // is known at all, the number is formatted plain rather than inventing a
    // currency symbol nobody asked for.
    //
    // Not every currency stores minor units the same way: JPY and KRW have no
    // decimal places, so their "minor unit" is the same as the major one and
    // dividing by 100 would render the value at 1/100th of its true size. The
    // formatter's own resolvedOptions() is the source of truth for how many
    // decimal places a currency uses, so the divisor is derived from it
    // rather than assumed.
    const money = (minor, currency) => {
      const cur = currency ? String(currency).toUpperCase() : '';
      const opts = cur
        ? { style: 'currency', currency: cur }
        : { minimumFractionDigits: 2, maximumFractionDigits: 2 };
      let formatter;
      try {
        formatter = new Intl.NumberFormat(undefined, opts);
      } catch (e) {
        return ((Number(minor) || 0) / 100).toFixed(2);
      }
      const digits = formatter.resolvedOptions().minimumFractionDigits;
      const value = (Number(minor) || 0) / Math.pow(10, digits);
      return formatter.format(value);
    };

    // The same money, shortened — "£9.9K" rather than "£9,900.00". Ten labels
    // sitting along a line have no room for the full thing, and the exact
    // figures are in the tiles above it anyway.
    const moneyShort = (minor, currency) => {
      const cur = currency ? String(currency).toUpperCase() : '';
      try {
        const opts = { notation: 'compact', maximumFractionDigits: 1 };
        if (cur) {
          opts.style = 'currency';
          opts.currency = cur;
        }
        const formatter = new Intl.NumberFormat(undefined, opts);
        const digits = cur ? new Intl.NumberFormat(undefined, { style: 'currency', currency: cur }).resolvedOptions().minimumFractionDigits : 2;
        return formatter.format((Number(minor) || 0) / Math.pow(10, digits));
      } catch (e) {
        return money(minor, currency);
      }
    };

    // 30 rather than 30.0, 12.5 kept as 12.5.
    const trimNum = (n) => String(Math.round(Number(n) * 100) / 100);

    // The rate in a sentence, because "30%" on its own does not tell an
    // affiliate the part that matters — that it keeps paying.
    function rateSentence() {
      const c = (affiliate && affiliate.commission) || {};
      const cur = (affiliate && affiliate.currency) || '';
      let lead;
      if (c.percent) lead = `You earn ${trimNum(c.percent)}% of`;
      else if (c.amount) lead = `You earn ${money(c.amount, cur)} on`;
      else return 'Your commission rate is set in SureCart — open your dashboard to see it.';

      if (!c.recurring) return `${lead} the first payment each customer makes.`;
      if (c.recurring_days) return `${lead} every payment, for the first ${Math.round(c.recurring_days / 30)} months of each subscription.`;
      return `${lead} every payment, for as long as they stay subscribed.`;
    }

    // What one payment on a given sale value earns this affiliate. A percentage
    // structure wins over a fixed amount when SureCart somehow returns both.
    const commissionPerPayment = (amountMinor) => {
      const c = (affiliate && affiliate.commission) || {};
      if (c.percent) return Math.round((amountMinor * c.percent) / 100);
      if (c.amount) return Math.round(c.amount);
      return 0;
    };

    // ------------------------------------------------------------ sections

    // Payment details for anyone paying by transfer. Rendered as its own card
    // under the profile one rather than inside it, so hiding it takes nothing
    // else with it.
    const busted = (url) => bustUrl(url, props.version);

    function bankCard() {
      if (!props.showBank) return '';
      const plain = BANK_DETAILS.map((d) => d.label + ': ' + d.value).join('\n');
      return `
  <div data-testid="account-bank" style="background:#fff;border:1px solid rgba(11,21,51,.08);border-radius:20px;padding:clamp(20px,3.5vw,28px);margin-top:20px;box-shadow:0 1px 2px rgba(11,21,51,.04)">
    <h2 style="margin:0 0 4px;font-size:17px;font-weight:800;letter-spacing:-0.01em">How to pay</h2>
    <p style="margin:0 0 18px;font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.58);max-width:620px">Please make payment to the account below, and use your name as the reference so we can match it to your subscription.</p>
    <dl style="margin:0 0 16px;display:grid;grid-template-columns:auto 1fr;gap:10px 18px;align-items:baseline">
      ${BANK_DETAILS.map((d) => `
      <dt style="margin:0;font-size:13px;font-weight:700;color:rgba(11,21,51,.72)">${esc(d.label)}</dt>
      <dd data-testid="bank-${esc(d.label.toLowerCase().replace(/[^a-z]+/g, '-'))}" style="margin:0;font-family:ui-monospace,Menlo,monospace;font-size:14.5px;overflow-wrap:anywhere">${esc(d.value)}</dd>`).join('')}
    </dl>
    <button class="as-hover-primary" style="${copyBtnStyle}" data-act="copy-bank" data-val="${esc(plain)}">${state.copied === 'bank' ? 'Copied!' : 'Copy details'}</button>
  </div>`;
    }
    function profileSection() {
      const acc = accounts[state.accIdx] || accounts[0];
      const multi = accounts.length > 1;

      // Card body varies by credentials state.
      let body;
      if (credState === 'loading') {
        body = `<div style="padding:clamp(20px,3.5vw,30px);font-size:13.5px;color:rgba(11,21,51,.6)">Loading your profile…</div>`;
      } else if (credState === 'empty') {
        body = `<div style="padding:clamp(20px,3.5vw,30px);display:flex;flex-direction:column;gap:10px">
          <div style="font-size:14.5px;font-weight:700">No profile assigned yet</div>
          <div style="font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.7)">There's no active AfriStream profile on your account. If you've just subscribed this can take a short while — otherwise email <a href="mailto:support@afristream.io">support@afristream.io</a> and we'll sort it out.</div>
        </div>`;
      } else if (credState === 'error' || !acc) {
        body = `<div style="padding:clamp(20px,3.5vw,30px);display:flex;flex-direction:column;gap:10px">
          <div style="font-size:14.5px;font-weight:700">Couldn't load your profile</div>
          <div style="font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.7)">Please refresh the page. If it keeps happening, email <a href="mailto:support@afristream.io">support@afristream.io</a>.</div>
        </div>`;
      } else {
        body = `<div style="padding:clamp(20px,3.5vw,30px);display:flex;flex-direction:column;gap:22px">
      <div data-testid="account-scope-notice" style="background:#FFF7E6;border:1px solid rgba(180,120,0,.22);border-radius:13px;padding:14px 17px;font-size:13px;line-height:1.6;color:rgba(11,21,51,.78)">The following usernames and passwords are to be used in conjunction with your Apps used via AfriStream. They do not provide any access to the Free Streaming Apps provided.</div>
      <div data-testid="account-connections-notice" style="background:#FFF7E6;border:1px solid rgba(180,120,0,.22);border-radius:13px;padding:14px 17px;font-size:13px;line-height:1.6;color:rgba(11,21,51,.78)"><strong>One connection, one screen at a time.</strong> A single connection can be used anywhere, but only on one device at once — do not leave the TV running while you watch on your phone. If you have two or more connections they must be used in the same household, on the same internet connection; the only exception is one on home WiFi and one on mobile data. Connections used across two different networks are removed automatically.</div>
      <div>
        <div style="font-size:13.5px;font-weight:700;margin-bottom:8px">Active Username</div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <div style="flex:1 1 240px;min-width:0;background:#F4F5F9;border:1px solid rgba(11,21,51,.1);border-radius:13px;padding:14px 16px;font-family:ui-monospace,Menlo,monospace;font-size:14.5px;overflow-wrap:anywhere">${esc(acc.user)}</div>
          <button class="as-hover-primary" style="${copyBtnStyle}" data-act="copy-user">${state.copied === 'user' ? 'Copied!' : 'Copy'}</button>
        </div>
      </div>
      <div>
        <div style="font-size:13.5px;font-weight:700;margin-bottom:8px">Password</div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <div style="flex:1 1 240px;min-width:0;background:#F4F5F9;border:1px solid rgba(11,21,51,.1);border-radius:13px;padding:14px 16px;font-family:ui-monospace,Menlo,monospace;font-size:14.5px;overflow-wrap:anywhere">${esc(acc.pass)}</div>
          <button class="as-hover-primary" style="${copyBtnStyle}" data-act="copy-pass">${state.copied === 'pass' ? 'Copied!' : 'Copy'}</button>
        </div>
      </div>
      <div style="background:#F7E9FF;border:1px solid rgba(101,0,159,.18);border-radius:13px;padding:14px 17px;font-size:13px;line-height:1.6;color:rgba(11,21,51,.72)">${multi ? 'Each profile works on one device at a time. Switch between your profiles using the tabs above. ' : 'Your profile works on one device at a time. '}Need an extra profile for another screen? Email <a href="mailto:support@afristream.io">support@afristream.io</a>.</div>
    </div>`;
      }

      const showTabs = multi && (credState === 'ready' || credState === 'demo');
      const pill = acc ? `${esc(acc.label)}${multi ? ' of ' + accounts.length : ''}` : '';

      return `
<section data-screen-label="App Profile">
  ${showTabs ? `<div style="display:flex;gap:8px;flex-wrap:wrap;margin:2px 0 18px">
    ${accounts.map((a, i) => `<button style="${subBtn(i === state.accIdx)}" data-act="acct" data-val="${i}">${esc(a.label)}</button>`).join('')}
  </div>` : ''}
  <div style="background:#fff;border:1px solid rgba(11,21,51,.08);border-radius:20px;overflow:hidden;box-shadow:0 1px 2px rgba(11,21,51,.04),0 16px 40px -30px rgba(11,21,51,.35)">
    <div style="background:linear-gradient(115deg,#65009F 20%,#CD2DF5 108%);padding:clamp(22px,3.5vw,32px);color:#fff;display:flex;flex-wrap:wrap;gap:12px 24px;align-items:flex-end;justify-content:space-between">
      <div style="min-width:240px;flex:1 1 300px">
        <h1 class="as-on-dark" style="margin:0 0 6px;font-size:clamp(21px,3vw,27px);font-weight:800;letter-spacing:-0.015em;color:#fff">Your AfriStream App Profile Details</h1>
        <p class="as-on-dark" style="margin:0;font-size:13.5px;line-height:1.55;color:rgba(255,255,255,.78);max-width:560px">These will be used when accessing any AfriStream platforms or content. They cannot be edited.</p>
      </div>
      ${pill ? `<div style="font-size:12px;font-weight:700;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22);padding:6px 14px;border-radius:999px;flex:none">${pill}</div>` : ''}
    </div>
    ${body}
  </div>
  ${bankCard()}
</section>`;
    }

    // Built in one place so the rendered link and the one the copy button puts
    // on the clipboard can never drift apart.
    function affiliateBuyLinks() {
      const referral = (affiliate && affiliate.referral_url) || '';
      const configured = { subscription: props.buyUrl, 'subscription-setup': props.buySetupUrl };
      return AFF_BUY_LINKS.map((b) => {
        const url = busted(configured[b.key] || b.url);
        return {
          key: b.key,
          label: b.label,
          note: b.note,
          url: withReferral(url, referral),
          display: friendlyBuyUrl(url, referral),
        };
      });
    }

    function affiliateSection() {
      const aff = affiliate || {};
      const portalUrl = aff.portal_url || 'https://afristream.surecart.com/affiliates/';
      const referral = aff.referral_url || '';
      const buyLinks = affiliateBuyLinks();
      const commission = aff.commission || {};
      // Neither a percentage nor a fixed amount — the affiliate is on the
      // store default and no default rate has been set (percent and amount
      // are both null). The rate card already sends them to SureCart to see
      // it; the calculator has nothing to multiply, so it says so rather than
      // rendering a wall of £0.00.
      const rateKnown = null != commission.percent || null != commission.amount;

      // Subscriptions are sold by the year, so an annual price is the only one
      // the projection can honestly model. Anything else in the store stays out
      // of the picker rather than being quietly counted as a year.
      const plans = (Array.isArray(aff.plans) ? aff.plans : []).filter((p) => 'year' === p.interval);
      const plan = plans.find((p) => p.id === state.affPlan) || plans[0] || null;
      const currency = plan ? plan.currency : (aff.currency || '');
      // Clamped for the maths only — the input keeps rendering exactly what was
      // typed, or clearing the box to type "10" would snap it back to 1.
      // Floored at one, with no ceiling: an upper clamp meant every number past
      // it silently produced the same answer, which reads as the calculator
      // being broken rather than as a limit.
      const perYear = Math.max(1, Number(state.affPerYear) || 1);
      // No live prices to pick from — the affiliate types what a year is worth.
      const saleMinor = plan ? plan.amount : Math.max(0, Math.round((Number(state.affValue) || 0) * 100));
      const perPayment = commissionPerPayment(saleMinor);
      const proj = projectEarnings(perPayment, perYear, AFF_YEARS, false !== commission.recurring, commission.recurring_days || null);
      const peak = Math.max.apply(null, proj.yearly.concat([1]));

      // Earnings can be read in another currency, converted from the store's
      // own at the day's published rates. Only the currencies the rate feed
      // actually returned are offered — with no rates there is no switcher,
      // because a converted figure nobody can stand behind is worse than none.
      const rates = (aff.rates && 'object' === typeof aff.rates) ? aff.rates : {};
      const options = AFF_CURRENCIES.filter((c) => rates[c.code] > 0);
      const showSwitcher = options.length > 1;
      // Opens in Rands, where the affiliates are, falling back to the store's
      // own currency when the rate feed cannot offer them.
      const preferred = rates.zar > 0 ? 'zar' : currency;
      const shown = (showSwitcher && rates[state.affCurrency] > 0) ? state.affCurrency : preferred;
      const rate = rates[shown] > 0 ? rates[shown] : 1;
      // Converted from minor units to minor units, so the maths stays integer
      // all the way to the formatter.
      const inShown = (minor) => Math.round(minor * rate);

      const inputStyle = 'width:100%;box-sizing:border-box;background:#fff;border:1px solid rgba(11,21,51,.14);border-radius:13px;padding:12px 14px;font-family:inherit;font-size:14px;color:inherit';
      const figure = (id, label, value) => `
        <div style="background:#F7E9FF;border:1px solid rgba(101,0,159,.18);border-radius:14px;padding:14px 16px">
          <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:rgba(11,21,51,.5)">${esc(label)}</div>
          <div data-testid="${id}" style="margin-top:5px;font-size:21px;font-weight:800;letter-spacing:-0.02em;color:#65009F">${esc(value)}</div>
        </div>`;

      const picker = plans.length
        ? `<label style="display:block">
            <span style="display:block;font-size:13.5px;font-weight:700;margin-bottom:8px">Plan they sign up to</span>
            <select data-testid="aff-plan" data-act="aff-plan" style="${inputStyle}">
              ${plans.map((p) => `<option value="${esc(p.id)}"${p.id === (plan ? plan.id : '') ? ' selected' : ''}>${esc(p.name)} · ${esc(money(p.amount, p.currency))} ${esc(planIntervalLabel(p))}</option>`).join('')}
            </select>
          </label>`
        : `<label style="display:block">
            <span style="display:block;font-size:13.5px;font-weight:700;margin-bottom:8px">What one year's subscription is worth</span>
            ${/* type="text" for the same reason as the headcount above: a
                 number input cannot have its caret put back after a re-render. */''}
            <input data-testid="aff-value-input" data-act="aff-value" type="text" inputmode="decimal" autocomplete="off" value="${esc(state.affValue)}" style="${inputStyle}">
          </label>`;

      // Five years of income as a line rather than five bars: the shape is the
      // point — each year's sign-ups sitting on top of the ones still paying
      // from the years before — and a climbing line says that where five
      // separate bars only invite you to compare their heights.
      //
      // Drawn as SVG on a fixed viewBox, scaled to the panel width, so it needs
      // no measurement, no library and no second render pass.
      // The viewBox is sized to roughly the panel's real width, so the SVG
      // scales to about 1:1 and its labels come out the size they say they are
      // rather than magnified along with the drawing.
      const gW = 940;
      const gH = 290;
      const gLeft = 46;
      const gRight = 46;
      const gTop = 44;
      const gBottom = 46;
      const gFloor = gH - gBottom;
      const points = proj.yearly.map((v, i) => ({
        v,
        x: gLeft + (i * (gW - gLeft - gRight)) / Math.max(1, AFF_YEARS - 1),
        y: gTop + (1 - v / peak) * (gFloor - gTop)
      }));
      const linePath = points.map((p, i) => `${i ? 'L' : 'M'}${p.x.toFixed(1)} ${p.y.toFixed(1)}`).join(' ');
      const areaPath = `${linePath} L${points[points.length - 1].x.toFixed(1)} ${gFloor} L${points[0].x.toFixed(1)} ${gFloor} Z`;
      const graph = `
    <svg data-testid="aff-chart" viewBox="0 0 ${gW} ${gH}" role="img" aria-label="Commission year by year over five years" style="width:100%;height:auto;margin-top:22px;overflow:visible">
      <defs>
        <linearGradient id="as-aff-fill" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="#CD2DF5" stop-opacity=".28"></stop>
          <stop offset="100%" stop-color="#CD2DF5" stop-opacity="0"></stop>
        </linearGradient>
      </defs>
      <line x1="${gLeft - 16}" y1="${gFloor}" x2="${gW - gRight + 16}" y2="${gFloor}" stroke="rgba(11,21,51,.12)" stroke-width="1"></line>
      <path d="${areaPath}" fill="url(#as-aff-fill)"></path>
      <path d="${linePath}" fill="none" stroke="#65009F" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"></path>
      ${points.map((p, i) => `
      <g data-point>
        <circle cx="${p.x.toFixed(1)}" cy="${p.y.toFixed(1)}" r="5" fill="#fff" stroke="#65009F" stroke-width="2.5"></circle>
        <title>Year ${i + 1}: ${esc(money(inShown(p.v), shown))}</title>
        <text x="${p.x.toFixed(1)}" y="${(p.y - 15).toFixed(1)}" text-anchor="middle" font-size="13" font-weight="700" fill="#65009F">${esc(moneyShort(inShown(p.v), shown))}</text>
        <text x="${p.x.toFixed(1)}" y="${gFloor + 26}" text-anchor="middle" font-size="12" fill="rgba(11,21,51,.45)">${i + 1}</text>
      </g>`).join('')}
      <text x="${(gW / 2).toFixed(1)}" y="${gH - 6}" text-anchor="middle" font-size="12" fill="rgba(11,21,51,.45)">Year</text>
    </svg>`;

      // Degraded mode: no rate to multiply anything by, so the inputs and the
      // four figures give way to a single line pointing at where the rate
      // actually lives.
      const calculatorBody = rateKnown
        ? `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:20px">
      ${picker}
      <label style="display:block">
        <span style="display:block;font-size:13.5px;font-weight:700;margin-bottom:8px">People you sign up each year</span>
        ${/* Deliberately type="text" with a numeric inputmode rather than
             type="number": the panel re-renders on every keystroke, and the
             selection API a number input refuses to implement is exactly what
             puts the caret back where it was. With type="number" the caret
             silently returned to the start and "100" came out "001". Phones
             still get the number pad from inputmode. */''}
        <input data-testid="aff-per-year" data-act="aff-per-year" type="text" inputmode="numeric" autocomplete="off" value="${esc(state.affPerYear)}" style="${inputStyle}">
      </label>
      ${showSwitcher ? `
      <label style="display:block">
        <span style="display:block;font-size:13.5px;font-weight:700;margin-bottom:8px">Show your earnings in</span>
        <select data-testid="aff-currency" data-act="aff-currency" style="${inputStyle}">
          ${options.map((c) => `<option value="${esc(c.code)}"${c.code === shown ? ' selected' : ''}>${esc(c.label)}</option>`).join('')}
        </select>
      </label>` : ''}
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px">
      ${figure('aff-per-payment', 'Each renewal', money(inShown(perPayment), shown))}
      ${figure('aff-year-1', 'Year 1 annual revenue', money(inShown(proj.first), shown))}
      ${figure('aff-year-10', `Year ${AFF_YEARS} annual revenue`, money(inShown(proj.last), shown))}
      ${figure('aff-total', `${AFF_YEARS} year total earnings`, money(inShown(proj.total), shown))}
    </div>
    ${shown !== currency ? `
    <p data-testid="aff-converted" style="margin:12px 0 0;font-size:12.5px;line-height:1.6;color:rgba(11,21,51,.5)">Converted from ${esc(String(currency).toUpperCase())} at today's European Central Bank rates. SureCart still pays you in ${esc(String(currency).toUpperCase())}, so what lands in your account moves with the exchange rate.</p>` : ''}

    ${graph}

    <p style="margin:16px 0 0;font-size:12.5px;line-height:1.6;color:rgba(11,21,51,.5)">These figures assume the people you sign up stay subscribed — commission keeps coming for as long as they do, and stops if they cancel.</p>`
        : `
    <p data-testid="aff-rate-unknown" style="margin:0;font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.6)">Your commission rate is not set yet, so there is nothing to project. Open your SureCart dashboard above to see your rate, then come back and this calculator will work it out for you.</p>`;

      return `
  <div data-testid="affiliate-card" style="background:#fff;border:1px solid rgba(11,21,51,.08);border-radius:18px;padding:22px 23px 24px;margin:0 2px 20px;box-shadow:0 1px 2px rgba(11,21,51,.04)">
    ${/* Heading and button sit on one line on a roomy screen. Once the text
          block cannot hold 280px the button wraps underneath rather than
          squeezing it — the rate sentence is the part that must stay
          readable. */''}
    <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:14px 20px">
      <div style="flex:1 1 280px;min-width:0">
        <h2 style="margin:0 0 4px;font-size:17px;font-weight:800;letter-spacing:-0.01em">Your affiliate dashboard</h2>
        <p data-testid="affiliate-rate" style="margin:0;font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.58);max-width:720px">${esc(rateSentence())}</p>
      </div>
      <a data-testid="affiliate-portal-link" class="as-hover-primary" href="${esc(portalUrl)}" target="_blank" rel="noopener noreferrer" style="flex:0 0 auto;display:inline-block;background:#65009F;color:#fff;border-radius:13px;padding:13px 24px;font-weight:700;font-size:13.5px;text-decoration:none;white-space:nowrap;box-shadow:0 8px 18px -10px rgba(101,0,159,.7)">Open Dashboard ↗</a>
    </div>
    ${referral ? `
    <div style="margin-top:20px">
      <div style="font-size:13.5px;font-weight:700;margin-bottom:8px">Your referral link</div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <div data-testid="affiliate-referral" style="flex:1 1 240px;min-width:0;background:#F4F5F9;border:1px solid rgba(11,21,51,.1);border-radius:13px;padding:14px 16px;font-family:ui-monospace,Menlo,monospace;font-size:14.5px;overflow-wrap:anywhere">${esc(referral)}</div>
        <button class="as-hover-primary" style="${copyBtnStyle}" data-act="copy-referral">${state.copied === 'referral' ? 'Copied!' : 'Copy link'}</button>
      </div>
    </div>
    ${/* Straight-to-checkout links, referral code already attached. Same row
          shape as the referral link above, so they wrap the copy button under
          the URL on a narrow screen instead of crushing it. */''}
    <div data-testid="affiliate-buy-links" style="margin-top:20px">
      <div style="font-size:13.5px;font-weight:700;margin-bottom:4px">Your buy links</div>
      <p style="margin:0 0 12px;font-size:12.5px;line-height:1.6;color:rgba(11,21,51,.5)">These take someone straight to checkout with your referral code already on them, so the sale is credited to you. They are shown shortened to stay readable — Copy link gives you the full link to share.</p>
      ${buyLinks.map((b, i) => `
      <div style="margin-top:${i ? '16px' : '0'}">
        <div style="font-size:12.5px;font-weight:700;margin-bottom:2px;color:rgba(11,21,51,.72)">${esc(b.label)} <span style="font-weight:400;color:rgba(11,21,51,.5)">(${esc(b.note)})</span></div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:6px">
          ${/* The shortened link is the real one — an anchor, not a label — so
                it can be opened and checked before it goes out. */''}
          <a data-testid="affiliate-buy-${esc(b.key)}" href="${esc(b.url)}" target="_blank" rel="noopener noreferrer" style="flex:1 1 240px;min-width:0;background:#F4F5F9;border:1px solid rgba(11,21,51,.1);border-radius:13px;padding:14px 16px;font-family:ui-monospace,Menlo,monospace;font-size:13.5px;overflow-wrap:anywhere;color:#65009F;text-decoration:none;display:flex;align-items:center">${esc(b.display)}</a>
          <button class="as-hover-primary" style="${copyBtnStyle}" data-act="copy-buy" data-val="${i}">${state.copied === 'buy-' + b.key ? 'Copied!' : 'Copy link'}</button>
        </div>
      </div>`).join('')}
    </div>` : ''}
  </div>

  <div data-testid="affiliate-calculator" style="background:#fff;border:1px solid rgba(11,21,51,.08);border-radius:18px;padding:22px 23px 24px;margin:0 2px 20px;box-shadow:0 1px 2px rgba(11,21,51,.04)">
    <h2 style="margin:0 0 4px;font-size:17px;font-weight:800;letter-spacing:-0.01em">What you could earn</h2>
    <p style="margin:0 0 18px;font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.58);max-width:720px">Subscriptions run by the year, and commission is paid on every renewal. Each year's sign-ups keep paying you while the next year's start, which is what builds up over ten years.</p>
    ${calculatorBody}
  </div>`;
    }

    function filtersDrawer(results, searching) {
      const groups = [
        { key: 'type', label: 'Type', options: ['All Types', ...uniq(facetPool('type'), 'type').sort()] },
        { key: 'genre', label: state.type === 'Sport' ? 'Sport Type' : 'Genre', options: ['All Genres', ...uniq(facetPool('genre'), 'genre').sort()] },
        { key: 'country', label: 'Country', options: ['All Countries', ...uniq(facetPool('country').filter((x) => x.country), 'country').sort()] },
        { key: 'decade', label: 'Decade', options: ['All Decades', ...uniq(facetPool('decade').filter((x) => x.decade), 'decade').sort((a, b) => parseInt(b, 10) - parseInt(a, 10))] },
        { key: 'sort', label: 'Sort by', options: ['Recommended', 'A–Z', 'Newest', 'Top Rated'] },
      ];
      const filterGroups = groups.filter((g) => g.key === 'sort' || g.options.length > 2 || state[g.key] !== FILTER_DEFAULTS[g.key]);
      const applyLabel = searching ? `Show ${results.length} result${results.length === 1 ? '' : 's'}` : 'Done';

      return `
    <div class="as-scrim" data-act="close-filters" style="background:rgba(11,21,51,.45);z-index:60"></div>
    <div class="as-panel" data-testid="filters-drawer" style="width:min(380px,92vw);background:#fff;z-index:61;box-shadow:-24px 0 60px -30px rgba(11,21,51,.5)">
      <div style="flex:none;display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid rgba(11,21,51,.08)">
        <div style="font-size:17px;font-weight:800">Filters</div>
        <button class="as-hover-chip" data-act="close-filters" style="background:#F2F3F7;border:none;border-radius:999px;width:32px;height:32px;cursor:pointer;font-size:14px;color:#65009F;font-family:inherit">✕</button>
      </div>
      <div class="as-panel-body" style="padding:20px 22px;display:flex;flex-direction:column;gap:24px">
        ${filterGroups.map((g) => `
          <div>
            <div style="font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:rgba(11,21,51,.5);margin-bottom:10px">${esc(g.label)}</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              ${g.options.map((o) => `<button style="${subBtn(String(state[g.key]) === String(o))}" data-act="filter" data-key="${g.key}" data-val="${esc(o)}">${esc(o)}</button>`).join('')}
            </div>
          </div>`).join('')}
      </div>
      <div style="flex:none;display:flex;gap:10px;padding:16px 22px;border-top:1px solid rgba(11,21,51,.08)">
        <button class="as-hover-ghost" data-act="clear-filters" style="flex:1;background:#fff;border:1px solid rgba(11,21,51,.14);border-radius:12px;padding:12px;font-family:inherit;font-weight:700;font-size:13.5px;cursor:pointer;color:#65009F">Clear all</button>
        <button class="as-hover-primary" data-act="close-filters" style="flex:1.4;background:#65009F;color:#fff;border:none;border-radius:12px;padding:12px;font-family:inherit;font-weight:700;font-size:13.5px;cursor:pointer">${esc(applyLabel)}</button>
      </div>
    </div>`;
    }

    function watchSection() {
      const q = state.query.trim().toLowerCase();
      const searching = !!q || state.type !== 'All Types' || state.genre !== 'All Genres' || state.country !== 'All Countries' || state.decade !== 'All Decades' || state.sort !== 'Recommended';
      let results = searching ? facetPool(null) : [];
      if (state.sort === 'A–Z') results = [...results].sort((a, b) => a.t.localeCompare(b.t));
      else if (state.sort === 'Newest') results = [...results].sort((a, b) => b.year - a.year);
      else if (state.sort === 'Top Rated') {
        const rate = (x) => { const m = String(x.platform).match(/([\d.]+)/); return m ? +m[1] : -1; };
        results = [...results].sort((a, b) => rate(b) - rate(a));
      }

      const sub = state.subWatch;

      // On "All" the rows stay short — the page is a summary of everything, and
      // five long rows would bury the sections below it. Picking a category is
      // the user asking for that one thing, so the row is re-backed by the whole
      // catalog (best-rated first) instead of the short trending slice.
      const expand = (seed, match) => {
        const seen = new Set(seed.map((x) => x.t));
        const rest = INDEX.filter((x) => match(x) && !seen.has(x.t))
          .sort((a, b) => ratingOf(b) - ratingOf(a));
        return [...seed, ...rest].slice(0, EXPANDED_ROW_MAX);
      };

      const posterRows = [];
      if (!searching) {
        if (sub === 'All') posterRows.push({ h: 'Trending Movies', items: data.movies });
        else if (sub === 'Movies') posterRows.push({ h: 'Movies', items: expand(data.movies, (x) => x.type === 'Movies') });
        if (sub === 'All') posterRows.push({ h: 'Trending Series', items: data.series });
        else if (sub === 'Series') posterRows.push({ h: 'Series', items: expand(data.series, (x) => x.type === 'Series') });
        if (sub === 'Documentaries') posterRows.push({ h: 'Documentaries', items: expand([], (x) => /^(Docs|Documentary)$/.test(x.genre)) });
        if (sub === 'Kids') posterRows.push({ h: 'Kids & Family', items: expand([], (x) => x.genre === 'Kids' || x.genre === 'Family') });
        if (sub === 'All') posterRows.push({ h: 'New This Week', items: data.newWeek });
        else if (sub === 'New This Week') posterRows.push({ h: 'New This Week', items: expand(data.newWeek, (x) => /new|episode/i.test(x.meta || '')) });
      }

      const filterCount = Object.keys(FILTER_DEFAULTS).filter((k) => state[k] !== FILTER_DEFAULTS[k]).length;
      const filterBtnLabel = filterCount ? `Filters · ${filterCount}` : 'Filters';
      const filterBtnStyle = `flex:none;cursor:pointer;font-family:inherit;font-size:13.5px;font-weight:700;padding:12px 20px;border-radius:13px;white-space:nowrap;transition:background .15s,color .15s;background:${filterCount ? '#65009F' : '#fff'};color:${filterCount ? '#fff' : '#65009F'};border:1px solid ${filterCount ? '#65009F' : 'rgba(11,21,51,.12)'}`;

      const quickNav = ['All', 'Movies', 'Series', 'Sport', 'Documentaries', 'Kids', 'New This Week', 'Collections'];
      const showSport = !searching && (sub === 'All' || sub === 'Sport') && props.showSport;
      // Resolve each collection against the live catalog; hide thin ones (<3
      // matches) so a card never opens to an empty grid.
      const collectionMembers = (c) => {
        let items = INDEX.filter((x) => (x.type === 'Movies' || x.type === 'Series') && c.match(x));
        if (c.sort === 'rating') items = [...items].sort((a, b) => ratingOf(b) - ratingOf(a));
        return items;
      };
      const collections = (!searching && (sub === 'All' || sub === 'Collections'))
        ? COLLECTIONS.map((c) => ({ ...c, items: collectionMembers(c) })).filter((c) => c.items.length >= 3)
        : [];
      const showColl = collections.length > 0;

      return `
<section data-screen-label="What to Watch">
  <div style="margin:2px 2px 18px">
    <h1 style="margin:0 0 5px;font-size:clamp(21px,3vw,27px);font-weight:800;letter-spacing:-0.015em">What to Watch</h1>
    <p style="margin:0;font-size:13.5px;color:rgba(11,21,51,.58)">One place to see what's on across all your platforms — updated daily.</p>
  </div>

  <div style="display:flex;gap:10px;flex-wrap:wrap;margin:0 0 10px">
    <input value="${esc(state.query)}" data-act="query" placeholder="Search titles…" style="flex:1 1 220px;min-width:0;padding:12px 16px;border:1px solid rgba(11,21,51,.12);border-radius:13px;font-family:inherit;font-size:14px;background:#fff;color:#65009F;outline-color:#65009F">
    <button style="${filterBtnStyle}" data-act="open-filters">${esc(filterBtnLabel)}</button>
  </div>

  ${state.filtersOpen ? filtersDrawer(results, searching) : ''}

  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:26px">
    ${quickNav.map((l) => `<button style="${subBtn(l === sub)}" data-act="quicknav" data-val="${esc(l)}">${esc(l)}</button>`).join('')}
  </div>

  ${searching ? `
    <div style="display:flex;align-items:baseline;gap:14px;margin-bottom:14px">
      <div style="font-size:14px;font-weight:800">${results.length} result${results.length === 1 ? '' : 's'}</div>
      <button data-act="clear-filters" style="background:none;border:none;color:#65009F;font-weight:700;font-size:13px;cursor:pointer;padding:0;font-family:inherit;text-decoration:underline">Clear search &amp; filters</button>
    </div>
    <div class="as-grid-posters" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(146px,1fr));gap:16px">
      ${results.map(posterGridItem).join('')}
    </div>
    ${results.length === 0 ? `
      <div style="background:#fff;border:1px dashed rgba(11,21,51,.18);border-radius:15px;padding:32px;text-align:center;font-size:14px;color:rgba(11,21,51,.6)">Nothing matches your search — try a different title, genre or country.</div>` : ''}
  ` : `
    ${posterRows.map((row) => `
      <div style="margin-bottom:28px">
        <h2 style="margin:0 0 12px;font-size:17.5px;font-weight:800;letter-spacing:-0.01em">${esc(row.h)}</h2>
        <div data-dragscroll style="display:flex;gap:14px;overflow-x:auto;padding-bottom:12px;scroll-snap-type:x proximity">
          ${row.items.map(posterRowItem).join('')}
        </div>
      </div>`).join('')}

    ${showSport ? `
      <div style="margin-bottom:28px">
        <h2 style="margin:0 0 12px;font-size:17.5px;font-weight:800;letter-spacing:-0.01em">Live &amp; Upcoming Sport</h2>
        <div data-dragscroll style="display:flex;gap:14px;overflow-x:auto;padding-bottom:12px">
          ${data.sport.map((s) => `
            <div ${cardAttrs({ detailKind: 'sport', t: s.fx, comp: s.comp, time: s.time, ch: s.ch, chCountry: s.chCountry, live: s.live, bg: 'linear-gradient(150deg,#4A0073,#2A0047)' })} style="cursor:pointer;flex:none;width:236px;border-radius:14px;background:linear-gradient(150deg,#4A0073,#2A0047);color:#fff;padding:15px 16px;display:flex;flex-direction:column;gap:8px;min-height:118px">
              <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
                <span style="font-size:10.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.55)">${esc(s.comp)}</span>
                ${s.live ? '<span style="display:flex;align-items:center;gap:5px;font-size:10px;font-weight:800;color:#FF5A6E"><span style="width:7px;height:7px;border-radius:50%;background:#FF5A6E;animation:asPulse 1.4s infinite"></span>LIVE</span>' : ''}
              </div>
              <div style="font-size:15.5px;font-weight:800;line-height:1.25">${esc(s.fx)}</div>
              <div style="margin-top:auto;display:flex;justify-content:space-between;gap:8px;font-size:11.5px;color:rgba(255,255,255,.65)"><span>${esc(s.time)}</span><span data-sport-channel style="font-weight:700;color:rgba(255,255,255,.85);text-align:right">${esc(s.ch)}${s.chCountry ? `<span style="display:block;font-weight:600;color:rgba(255,255,255,.55)">${esc(s.chCountry)}</span>` : ''}</span></div>
            </div>`).join('')}
        </div>
      </div>` : ''}

    ${showColl ? `
      <div>
        <h2 style="margin:0 0 12px;font-size:17.5px;font-weight:800;letter-spacing:-0.01em">Collections</h2>
        <div data-dragscroll style="display:flex;gap:14px;overflow-x:auto;padding-bottom:12px">
          ${collections.map((c) => `
            <div ${cardAttrs({ detailKind: 'collection', t: c.name, desc: c.desc, count: `${c.items.length} titles`, items: c.items, bg: c.bg })} style="cursor:pointer;flex:none;width:250px;border-radius:15px;background:${c.bg};color:#fff;padding:18px;display:flex;flex-direction:column;gap:6px;min-height:132px">
              <div style="font-size:17px;font-weight:800;letter-spacing:-0.01em">${esc(c.name)}</div>
              <div style="font-size:12.5px;line-height:1.45;color:rgba(255,255,255,.8)">${esc(c.desc)}</div>
              <div style="margin-top:auto;font-size:10.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.6)">${esc(c.items.length)} title${c.items.length === 1 ? '' : 's'}</div>
            </div>`).join('')}
        </div>
      </div>` : ''}
  `}
  ${dataSource === 'tmdb' ? tmdbAttribution() : ''}
</section>`;
    }

    // A single premium poster card in the Editor Picks grid.
    function editorCard(m) {
      return `
      <div class="as-editor-card" ${cardAttrs(m)} style="cursor:pointer">
        <div style="width:100%;aspect-ratio:2/3;border-radius:16px;background:${m.bg};position:relative;overflow:hidden;box-shadow:0 14px 30px -20px rgba(11,21,51,.6)">
          ${m.poster ? `<img src="${esc(m.poster)}" alt="" loading="lazy" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover"><div style="position:absolute;inset:0;background:linear-gradient(180deg,rgba(5,9,24,0) 48%,rgba(5,9,24,.82))"></div>` : `<div style="position:absolute;top:-22px;right:-8px;font-size:130px;font-weight:800;color:rgba(255,255,255,.13);line-height:1;user-select:none">${esc(m.initial)}</div>`}
          <div style="position:absolute;top:10px;left:10px;width:30px;height:30px;border-radius:50%;background:rgba(5,9,24,.6);color:#F4C56B;font-weight:800;font-size:13px;display:flex;align-items:center;justify-content:center;border:1px solid rgba(244,197,107,.5)">${esc(m.rank || '')}</div>
        </div>
        <div style="margin-top:9px;font-size:13.5px;font-weight:700;line-height:1.25">${esc(m.t)}</div>
        <div style="margin-top:3px;font-size:11.5px;color:rgba(11,21,51,.55)">${esc(m.genre)} · ${esc(m.meta)}</div>
      </div>`;
    }

    function editorSection() {
      const picks = data.editorPicks || [];
      const tag = state.editorTag || 'All';
      const category = state.editorCategory || 'All';
      // The floor of the selected one-point band, or 0 for "Any".
      const ratingBand = Number(state.editorRating) || 0;

      // Three mutually exclusive content types rather than a genre dump: a
      // documentary is only ever a Documentary, never also Movies or Series, so
      // the pills partition the list instead of overlapping.
      const typeOf = (p) => (p.genre === 'Documentary' ? 'Documentaries' : p.type);
      const matchesTag = (p) => tag === 'All' || typeOf(p) === tag;
      // Only offer a type that actually has picks behind it.
      const present = new Set(picks.map(typeOf).filter(Boolean));
      const tagOpts = ['All', ...EDITOR_TYPES.filter((t) => present.has(t))];

      // Sub-categories, narrowed by the chosen type so the shelves on offer are
      // the ones that type actually has. Stays in EDITOR_CATEGORIES order,
      // which is alphabetical.
      const matchesCategory = (p) => category === 'All' || editorCategoryOf(p) === category;
      const categoryOpts = ['All', ...EDITOR_CATEGORIES.filter(
        (c) => picks.some((p) => matchesTag(p) && editorCategoryOf(p) === c)
      )];

      // Rating bands are offered only where they'd leave something on screen,
      // so the row never shows a pill that can only produce an empty grid.
      const ratingOpts = [0, ...EDITOR_RATING_STEPS.filter(
        (r) => picks.some((p) => matchesTag(p) && matchesCategory(p) && inRatingBand(p.rating, r))
      )];

      // Best first. Watchlist position only breaks ties, so an unrated pick
      // (rating null) sinks to the bottom rather than jumping the queue.
      let list = picks.filter((p) => matchesTag(p) && matchesCategory(p) && inRatingBand(p.rating, ratingBand));
      list = [...list].sort((a, b) => {
        const diff = (Number(b.rating) || 0) - (Number(a.rating) || 0);
        return diff || (a.rank || 0) - (b.rank || 0);
      });
      // Today's Pick — one title drawn from whatever is currently on screen,
      // stable for the whole day so it doesn't reshuffle on every render.
      const heroIdx = list.length ? dailyIndex(list.length) : -1;
      const hero = heroIdx >= 0 ? list[heroIdx] : null;
      // Renumber only after the hero is lifted out, so the badges below read
      // 1, 2, 3… with no gap where it used to sit.
      const grid = list
        .filter((_, i) => i !== heroIdx)
        .map((p, i) => ({ ...p, rank: i + 1 }));

      const heroArt = (m) => m.poster
        ? `<img src="${esc(m.poster)}" alt="${esc(m.t)}" loading="lazy" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover">`
        : `<div style="position:absolute;top:-30px;right:-6px;font-size:200px;font-weight:800;color:rgba(255,255,255,.10);line-height:1;user-select:none">${esc(m.initial)}</div>`;

      return `
<section data-screen-label="Editor Picks">
  <div style="margin:2px 2px 18px">
    <h1 style="margin:0 0 5px;font-size:clamp(21px,3vw,27px);font-weight:800;letter-spacing:-0.015em">Editor Picks</h1>
    <p style="margin:0;font-size:13.5px;color:rgba(11,21,51,.58)">Curated by the AfriStream editors — a hand-picked watchlist, refreshed regularly.</p>
  </div>
  ${picks.length ? `
  <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:center;margin-bottom:26px">
    ${tagOpts.length > 1 ? `
    <div role="group" aria-label="Filter by type" style="display:flex;gap:8px;flex-wrap:wrap">
      ${tagOpts.map((o) => `<button style="${subBtn(String(tag) === String(o))}" data-act="editor-filter" data-key="editorTag" data-val="${esc(o)}" aria-pressed="${String(tag) === String(o)}">${esc(o)}</button>`).join('')}
    </div>` : ''}
    ${categoryOpts.length > 1 ? `
    ${/* Labelled, because otherwise its "All" pill sits next to the type row's
          "All" with nothing to tell the two apart. */''}
    <div role="group" aria-label="Filter by category" data-testid="editor-categories" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <span style="font-size:11.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:rgba(11,21,51,.45)">Category</span>
      ${categoryOpts.map((o) => `<button style="${subBtn(String(category) === String(o))}" data-act="editor-filter" data-key="editorCategory" data-val="${esc(o)}" aria-pressed="${String(category) === String(o)}">${esc(o)}</button>`).join('')}
    </div>` : ''}
    ${ratingOpts.length > 1 ? `
    <div role="group" aria-label="Filter by IMDb rating" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <span style="font-size:11.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:rgba(11,21,51,.45)">Rating</span>
      ${ratingOpts.map((r) => `<button style="${subBtn(ratingBand === r)}" data-act="editor-filter" data-key="editorRating" data-val="${r}" aria-pressed="${ratingBand === r}">${r ? ratingBandLabel(r) : 'Any'}</button>`).join('')}
    </div>` : ''}
  </div>` : ''}
  ${hero ? `
  <div ${cardAttrs(hero)} style="cursor:pointer;position:relative;border-radius:22px;overflow:hidden;background:linear-gradient(120deg,#65009F 20%,#CD2DF5 80%);color:#fff;min-height:280px;display:flex;align-items:flex-end;margin-bottom:26px;box-shadow:0 24px 60px -34px rgba(11,21,51,.7)">
    <div style="position:absolute;inset:0">${heroArt(hero)}<div style="position:absolute;inset:0;background:linear-gradient(90deg,rgba(5,9,24,.86) 0%,rgba(5,9,24,.55) 46%,rgba(5,9,24,.2) 100%)"></div></div>
    <div style="position:relative;padding:clamp(22px,4vw,40px);max-width:620px;display:flex;flex-direction:column;gap:12px">
      <span style="align-self:flex-start;display:inline-flex;align-items:center;gap:7px;font-size:10.5px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:#F4C56B">★ Today's Pick</span>
      <div style="font-size:clamp(26px,4.5vw,40px);font-weight:800;letter-spacing:-0.02em;line-height:1.05">${esc(hero.t)}</div>
      <div style="font-size:13px;color:rgba(255,255,255,.75);display:flex;gap:8px;flex-wrap:wrap"><span style="font-weight:700">${esc(hero.genre)}</span><span>·</span><span>${esc(hero.meta)}</span>${hero.country ? `<span>·</span><span>${esc(hero.country)}</span>` : ''}<span>·</span><span>${esc(hero.platform)}</span></div>
    </div>
  </div>` : ''}
  ${grid.length ? `
  ${hero ? `<h2 style="margin:0 0 12px 2px;font-size:17.5px;font-weight:800;letter-spacing:-0.01em">Top rated</h2>` : ''}
  <div data-testid="editor-grid" class="as-grid-posters" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:18px 16px">
    ${grid.map(editorCard).join('')}
  </div>` : ''}
  ${picks.length && !grid.length && !hero ? `<div style="background:#fff;border:1px dashed rgba(11,21,51,.18);border-radius:15px;padding:32px;text-align:center;font-size:14px;color:rgba(11,21,51,.6)">No picks match these filters. <button data-act="clear-editor-filters" style="background:none;border:none;color:#65009F;font-weight:700;font-size:14px;cursor:pointer;padding:0;font-family:inherit;text-decoration:underline">Reset filters</button></div>` : ''}
  ${!picks.length ? `<div style="background:#fff;border:1px dashed rgba(11,21,51,.18);border-radius:15px;padding:32px;text-align:center;font-size:14px;color:rgba(11,21,51,.6)">The editors' list is refreshing — check back shortly.</div>` : ''}
  ${editorSource === 'imdb' ? tmdbAttribution() : ''}
</section>`;
    }

    // ------------------------------------------------------------ setup model
    //
    // One question: which device. Everything else follows from it. The old flow
    // asked three (family, then model, then install method) before showing a
    // single instruction, and the middle two only ever narrowed to the same
    // handful of routes.
    //
    // Three screens are shared between devices rather than repeated, because
    // Google TV, an Android box and a phone genuinely do the same things once
    // the device is powered up. Two devices have no screens at all: we register
    // those at our end, so they branch to a support card.

    const SETUP_ACCOUNT_HINT = 'Your username and password are in your AfriStream welcome email. Copy them rather than typing them in — capital letters matter.';

    // The last screen for every supported device: Downloader fetches the
    // player, then one sign-in. The note lists the fallbacks so a customer
    // whose player will not connect never has to come back and ask.
    // All four players, shown together rather than one recommended code with the
    // rest buried in a footnote. They are alternatives, not a fallback ladder:
    // the same username and password signs in to any of them, so a customer
    // whose player will not connect can pick another without coming back here.
    const SETUP_PLAYER_CODES = [
      { code: '6573365', app: 'IPTV Player', note: 'The most reliable, and the one we recommend starting with.' },
      { code: '617725', app: 'IBO Player', note: 'A solid alternative if IPTV Player will not connect.' },
      { code: '9469460', app: 'Sky App', note: 'Worth a try if neither of the first two works.' },
      { code: '569138', app: 'Sky Live', note: 'The fourth option — same login, different look.' }
    ];

    const SETUP_SCREEN_INSTALL = {
      title: 'Install AfriStream and sign in',
      sub: 'Last one. Downloader fetches the app, then you type in your username and password once.',
      note: 'It does not matter which of the four you install — your username and password work in all of them. If one will not connect, come back to this step and install another.',
      steps: [
        { t: 'Open Downloader, and click the empty box across the top of the screen.' },
        { t: 'Type in one of these codes, then press GO. Any of the four works — pick one.', codes: SETUP_PLAYER_CODES },
        { t: 'Let it install all the way through, then choose Open. Do not press Back while it is working.' },
        { t: 'If it asks to allow access to media, files, or installing apps, always choose Allow.' },
        { t: 'Choose Add profile, then type in your AfriStream username and password.', hint: SETUP_ACCOUNT_HINT },
        { t: 'Choose Connect, and give it about fifteen seconds to load.' }
      ]
    };

    const SETUP_SCREEN_PLAY_STORE = {
      title: 'Install the Downloader app',
      sub: 'Downloader is a small free app that fetches AfriStream for you. It is in the Play Store.',
      note: 'There is nothing to pay — Downloader is free.',
      steps: [
        { t: 'Open the Google Play Store from your home screen.' },
        { t: 'Search for Downloader, by AFTVnews.' },
        { t: 'Choose Install, and wait for it to finish.' }
      ]
    };

    const SETUP_SCREEN_GOOGLE_READY = {
      title: 'Two settings, then we are away',
      sub: 'This tells your device it is allowed to install our app. Nothing else changes.',
      note: 'No seven clicks and no unlocking on these devices — this is the only setting you touch.',
      steps: [
        { t: 'Join the WiFi network whose name ends in 5G, if you have one. It is the faster of the two and much better for video.' },
        { t: 'Open Settings, then Apps, then Security & restrictions, and switch on Unknown sources.', hint: 'Some devices only show this switch once Downloader is installed. If you cannot find it now, carry on and come back.' },
        { t: 'Check the device is signed in to a Google account — Settings, then Accounts. You need this for the Play Store.' }
      ]
    };

    // A box is a Google TV stick that also has to be plugged in, so it takes the
    // shared screen with one step in front rather than a screen of its own.
    const SETUP_SCREEN_BOX_READY = Object.assign({}, SETUP_SCREEN_GOOGLE_READY, {
      steps: [
        { t: 'Plug the box into a spare HDMI port on the TV, and into the wall for power.', hint: 'Use the plug it came with rather than a USB socket on the TV — a USB socket is the most common cause of a box restarting by itself.' }
      ].concat(SETUP_SCREEN_GOOGLE_READY.steps)
    });

    const SETUP_DEVICES = [
      {
        key: 'fire',
        title: 'Amazon Fire TV Stick',
        body: 'The black stick and remote most people already own. Get the 4K Max or 4K Plus if you are buying.',
        badge: '',
        screens: [
          {
            title: 'Unlock your stick first',
            sub: 'Fire TV blocks anything that did not come from Amazon until you flip two switches. This is the only fiddly part.',
            note: 'Nothing on your stick breaks and nothing is deleted. You are only telling it that you are allowed to install our app.',
            steps: [
              { t: 'From the Fire TV home screen, open Settings — the cog along the top row.' },
              { t: 'Choose My Fire TV, then Developer Options.', hint: 'No Developer Options in the list? Choose About, then click the Fire TV box seven times. It appears after that.' },
              { t: 'Switch on ADB Debugging.' },
              { t: 'Switch on Apps from Unknown Sources. Both switches need to be on, not just one.' }
            ]
          },
          {
            title: 'Install the Downloader app',
            sub: 'Downloader is a small free app that fetches AfriStream for you. On Fire TV it comes from the store app in your welcome email.',
            note: 'A warning about unknown apps is normal here — Fire TV shows it for anything not from Amazon. Choose Allow or Continue.',
            steps: [
              { t: 'Press HOME on the remote, then open Search — the magnifying glass, top left.' },
              { t: 'Search for the store app named in your welcome email, and install it.' },
              { t: 'Open it, and choose Join Room.' },
              { t: 'Type in this room code exactly as shown, then confirm.', code: '10325' },
              { t: 'The room shows a list of apps. Scroll down it, find Downloader, and install it.' }
            ]
          },
          SETUP_SCREEN_INSTALL
        ]
      },
      {
        key: 'googletv',
        title: 'Google TV or Android TV stick',
        body: 'Xiaomi, onn, Thomson, Nokia. Our pick if you are buying new — the quickest one to set up.',
        badge: 'EASIEST',
        screens: [SETUP_SCREEN_GOOGLE_READY, SETUP_SCREEN_PLAY_STORE, SETUP_SCREEN_INSTALL]
      },
      {
        key: 'box',
        title: 'Android box',
        body: 'A small box on an HDMI cable, a bit more powerful than a stick.',
        badge: '',
        screens: [SETUP_SCREEN_BOX_READY, SETUP_SCREEN_PLAY_STORE, SETUP_SCREEN_INSTALL]
      },
      {
        key: 'android',
        title: 'Android phone or tablet',
        body: 'Watch on the screen in your hand. Nothing to unlock, nothing to plug in.',
        badge: '',
        screens: [
          {
            title: 'One minute of getting ready',
            sub: 'Nothing to unlock on a phone or tablet — it just asks your permission as you go.',
            note: 'Watching on a phone counts as your one screen. Your login works on one device at a time.',
            steps: [
              { t: 'Join your home WiFi rather than using mobile data — a film uses a lot of data.' },
              { t: 'Have the username and password from your welcome email to hand.', hint: SETUP_ACCOUNT_HINT },
              { t: 'Expect a prompt later asking whether Downloader may install apps. That prompt is meant to happen — choose Allow.' }
            ]
          },
          SETUP_SCREEN_PLAY_STORE,
          SETUP_SCREEN_INSTALL
        ]
      },
      {
        key: 'smarttv',
        title: 'Smart TV, nothing plugged in',
        body: 'A Samsung or LG on its own. This works, but we have to register the TV at our end first.',
        badge: '',
        screens: null
      }
    ];

    const SETUP_PROGRESS_LABELS = ['Your device', 'Get ready', 'Downloader', 'AfriStream app'];

    const setupDeviceOf = () => SETUP_DEVICES.find((d) => d.key === state.setupDevice) || null;

    // Four stages, derived rather than stored, so state can never disagree with
    // itself — a device with no screens can only ever be at 'support'.
    function setupStage() {
      const device = setupDeviceOf();
      if (!device) return 'device';
      if (!device.screens) return 'support';
      return state.setupDone ? 'done' : 'steps';
    }

    // state.setupScreen clamped against this device's screen count, so a stale
    // index — left over from a longer device before "Change device" — can never
    // read off the end of a shorter one. Computed once per render in
    // setupSection() and threaded through to every reader below, so none of
    // them can see an index the others can't.
    function setupScreenIndex(device) {
      if (!device || !device.screens) return 0;
      return Math.min(state.setupScreen, device.screens.length - 1);
    }

    // The screen currently on show, from an already-clamped index.
    function setupScreenOf(device, idx) {
      if (!device || !device.screens) return null;
      return device.screens[idx] || null;
    }

    // Line icons rather than photographs: they ship in the plugin, need no
    // uploads, and stay legible at any size on any background.
    const SETUP_ICONS = {
      fire: '<svg viewBox="0 0 48 48" width="100%" height="100%" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="14" width="22" height="9" rx="3"/><path d="M28 18.5h6"/><rect x="34" y="10" width="9" height="28" rx="4"/><path d="M38.5 16v3M38.5 24h0M38.5 30h0"/></svg>',
      googletv: '<svg viewBox="0 0 48 48" width="100%" height="100%" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="16" width="26" height="14" rx="4"/><path d="M31 23h5"/><path d="M36 19h5v8h-5z"/><path d="M12 23h8"/></svg>',
      box: '<svg viewBox="0 0 48 48" width="100%" height="100%" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="14" width="24" height="18" rx="4"/><path d="M14 26h6"/><circle cx="27" cy="26" r="1.5"/><path d="M32 20h4a4 4 0 0 1 4 4v10"/></svg>',
      android: '<svg viewBox="0 0 48 48" width="100%" height="100%" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="10" width="14" height="26" rx="3"/><path d="M11 33h4"/><rect x="24" y="14" width="18" height="22" rx="3"/><path d="M31 32h4"/></svg>',
      smarttv: '<svg viewBox="0 0 48 48" width="100%" height="100%" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="10" width="36" height="23" rx="4"/><path d="M18 39h12M24 33v6"/></svg>'
    };

    const setupIcon = (key) => SETUP_ICONS[key] || '';

    // -------------------------------------------------------------- setup tab

    // Eyebrow, title and sub for the stage on show. Steps count from 2 because
    // choosing the device was step 1.
    function setupHeading(stage, idx, screen) {
      if (stage === 'steps' && screen) {
        return ['Step ' + (idx + 2) + ' of 4', screen.title, screen.sub];
      }
      if (stage === 'support') {
        return ['Step 1 of 4', 'We set this one up for you', 'This device cannot install the app on its own, so the profile is created at our end.'];
      }
      if (stage === 'done') {
        return ['All done', 'That is setup finished', 'Nothing left to install — your app is ready to watch.'];
      }
      return ['Step 1 of 4', 'Which of these do you have?', 'Pick the device you will be watching on and we will show you only the steps that apply to it. Nothing here needs any technical know-how.'];
    }

    // A numbered stepper rather than a bar with captions under it: the numbers
    // and the ticks are what say "these are steps, and you are on this one".
    // Labels drop away on a narrow screen, where the heading above already
    // names the current step.
    function setupProgress(stage, idx) {
      const active = stage === 'device' || stage === 'support' ? 0
        : stage === 'done' ? 3
          : idx + 1;
      const last = SETUP_PROGRESS_LABELS.length - 1;
      return `
  <ol data-testid="setup-progress" class="as-stepper" style="margin:0 2px 26px;padding:0;list-style:none;display:flex;align-items:center">
      ${SETUP_PROGRESS_LABELS.map((label, i) => {
    const done = i < active;
    const now = i === active;
    const circle = done
      ? 'background:#65009F;color:#fff;border:1px solid #65009F'
      : now
        ? 'background:#65009F;color:#fff;border:1px solid #65009F;box-shadow:0 0 0 4px #F7E9FF'
        : 'background:#fff;color:rgba(11,21,51,.45);border:1px solid rgba(11,21,51,.18)';
    return `
        <li style="display:flex;align-items:center;gap:10px;${i === last ? 'flex:none' : 'flex:1;min-width:0'}"${now ? ' aria-current="step"' : ''}>
          <span aria-hidden="true" style="flex:none;width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12.5px;font-weight:800;${circle}">${done ? '✓' : i + 1}</span>
          <span class="as-step-label" style="font-size:12.5px;white-space:nowrap;font-weight:${now ? '800' : done ? '700' : '600'};color:${now ? '#0B1533' : done ? 'rgba(11,21,51,.68)' : 'rgba(11,21,51,.42)'}">${esc(label)}</span>
          ${i === last ? '' : `<span aria-hidden="true" style="flex:1;min-width:12px;height:2px;border-radius:999px;margin:0 4px;background:${done ? '#65009F' : 'rgba(11,21,51,.12)'}"></span>`}
        </li>`;
  }).join('')}
  </ol>`;
    }

    function setupDeviceGrid() {
      return `
  <div data-testid="setup-device-picker" role="group" aria-label="Choose your device" class="as-grid-cards" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin:0 2px">
    ${SETUP_DEVICES.map((d) => `
      <button class="as-editor-card" data-act="setup-device" data-val="${esc(d.key)}" style="display:flex;flex-direction:column;gap:0;text-align:left;background:#fff;border:1px solid rgba(11,21,51,.08);border-radius:18px;padding:0;overflow:hidden;font-family:inherit;cursor:pointer;box-shadow:0 1px 2px rgba(11,21,51,.04)">
        <span aria-hidden="true" class="as-device-icon">${setupIcon(d.key)}</span>
        <span style="display:flex;flex-direction:column;gap:7px;padding:17px 19px 19px">
          <span style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <span style="font-size:15.5px;font-weight:800;letter-spacing:-0.01em;color:#0B1533">${esc(d.title)}</span>
            ${d.badge ? `<span style="flex:none;font-size:10px;font-weight:700;letter-spacing:.05em;padding:3px 8px;border-radius:999px;background:#F7E9FF;color:#65009F;border:1px solid rgba(101,0,159,.18)">${esc(d.badge)}</span>` : ''}
          </span>
          <span style="font-size:13px;line-height:1.55;color:rgba(11,21,51,.6)">${esc(d.body)}</span>
          <span style="margin-top:3px;font-size:13px;font-weight:700;color:#65009F">Set this up →</span>
        </span>
      </button>`).join('')}
  </div>`;
    }

    const SETUP_BUYING_BULLETS = [
      'Good WiFi matters most. Look for "WiFi 6" or "dual band" on the box — a stick has nowhere to plug a cable in.',
      'At least 2GB of memory so it stays quick, and 8GB of storage so there is room for the app.',
      'A 4K one if your TV is 4K. Otherwise the cheaper HD version is fine.',
      'A plug that goes into the wall. Running a stick off the TV\'s USB socket is the most common cause of it restarting by itself.',
      'If you are buying new, a Google TV stick such as the Xiaomi TV Stick 4K is the least fiddly to set up.',
      'Amazon\'s newest Fire sticks can only install apps from Amazon, so our app will not go on them. The 4K Max and 4K Plus are the last that work.'
    ];

    // Kept from the flow this replaces: the two devices we have actually tested,
    // and the WiFi advice. Nearly every "the picture freezes" email is a WiFi
    // problem rather than a device one, so it earns its place next to the
    // buying guidance rather than being dropped with the old taxonomy.
    const SETUP_BUY_LINKS = [
      {
        name: 'Amazon Fire TV Stick 4K Max',
        retailer: 'Takealot',
        note: 'The one most people buy. Works with us — unlike the newer Amazon sticks.',
        url: 'https://www.takealot.com/amazon-fire-tv-stick-4k-max-streaming-device-alexa-voice-remote-/PLID91995419'
      },
      {
        name: 'Xiaomi TV Stick 4K (2nd Gen)',
        retailer: 'Takealot',
        note: 'Our pick if you are buying new. Simpler to set up, and nothing Amazon can switch off later.',
        url: 'https://www.takealot.com/xiaomi-tv-stick-4k-2nd-gen-media-player/PLID100971431'
      }
    ];

    const SETUP_WIFI_TIPS = [
      'If your WiFi shows two networks with almost the same name, join the one ending in 5G. It is the faster of the two and much better for video.',
      'Walls are what slow WiFi down, not distance. One wall between your device and the router is fine — three walls and a floor is what causes the picture to freeze.',
      'If your stick is pushed in behind a big TV, use the short extension lead that came in the box to bring it out to the side. TVs block the signal.',
      'If the router is at the far end of the house, a WiFi booster in the TV room will help far more than buying a better stick.',
      'Microwaves and cordless phones can interrupt WiFi while you are watching. Joining the 5G network usually puts a stop to it.'
    ];

    function setupBuyingPanel() {
      const open = !!state.setupHelpOpen;
      return `
  <div data-testid="setup-buying" style="margin:20px 2px 0;border-radius:18px;border:1px solid rgba(11,21,51,.08);background:#fff;box-shadow:0 1px 2px rgba(11,21,51,.04);padding:20px 21px">
    <button data-act="setup-help" aria-expanded="${open ? 'true' : 'false'}" style="display:block;width:100%;text-align:left;background:transparent;border:none;padding:0;font-family:inherit;font-size:15.5px;font-weight:800;letter-spacing:-0.01em;color:#0B1533;cursor:pointer"><span aria-hidden="true">${open ? '–' : '+'} </span>Buying a device? What to look for</button>
    ${open ? `
    <ul style="margin:16px 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:10px">
      ${SETUP_BUYING_BULLETS.map((b) => `
        <li style="display:flex;gap:11px;font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.72)">
          <span aria-hidden="true" style="flex:none;width:5px;height:5px;border-radius:50%;background:#65009F;margin-top:8px"></span>
          <span>${esc(b)}</span>
        </li>`).join('')}
    </ul>
    <div data-testid="setup-buy-links" style="margin-top:20px;padding-top:18px;border-top:1px solid rgba(11,21,51,.08)">
      <div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(11,21,51,.45);margin-bottom:12px">Ones we know work</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px">
        ${SETUP_BUY_LINKS.map((b) => `
          <a href="${esc(b.url)}" target="_blank" rel="noopener noreferrer" style="display:flex;flex-direction:column;gap:5px;text-decoration:none;background:#FAFAFC;border:1px solid rgba(11,21,51,.1);border-radius:14px;padding:14px 16px">
            <span style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
              <span style="font-size:14.5px;font-weight:800;letter-spacing:-0.01em;color:#0B1533">${esc(b.name)}</span>
              <span style="flex:none;font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;padding:3px 8px;border-radius:999px;background:#F7E9FF;color:#65009F;border:1px solid rgba(101,0,159,.18)">${esc(b.retailer)}</span>
            </span>
            <span style="font-size:12.5px;line-height:1.55;color:rgba(11,21,51,.62)">${esc(b.note)}</span>
            <span style="font-size:13px;font-weight:700;color:#65009F">Buy on ${esc(b.retailer)} ↗</span>
          </a>`).join('')}
      </div>
      <p style="margin:12px 0 0;font-size:11.5px;line-height:1.6;color:rgba(11,21,51,.45)">South African retailers. Prices and stock change — the list above tells you what to match on anything else you find.</p>
    </div>
    <div data-testid="setup-wifi" style="margin-top:20px;padding-top:18px;border-top:1px solid rgba(11,21,51,.08)">
      <div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(11,21,51,.45);margin-bottom:12px">Getting the best from your WiFi</div>
      <ul style="margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:10px">
        ${SETUP_WIFI_TIPS.map((t) => `
          <li style="display:flex;gap:11px;font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.72)">
            <span aria-hidden="true" style="flex:none;width:5px;height:5px;border-radius:50%;background:#65009F;margin-top:8px"></span>
            <span>${esc(t)}</span>
          </li>`).join('')}
      </ul>
    </div>` : ''}
  </div>`;
    }

    function setupStepList(screen) {
      return `
  <ol data-testid="setup-steps" style="margin:0 2px;padding:0;list-style:none;display:flex;flex-direction:column;gap:22px">
    ${screen.steps.map((s, i) => `
      <li style="display:flex;gap:16px;align-items:flex-start">
        <span aria-hidden="true" style="flex:none;width:30px;height:30px;border-radius:50%;background:#65009F;color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800">${i + 1}</span>
        <span style="display:flex;flex-direction:column;gap:10px;flex:1;min-width:0;padding-top:3px">
          <span style="font-size:15.5px;line-height:1.55;color:rgba(11,21,51,.82)">${esc(s.t)}</span>
          ${s.code ? `
          <span style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <code data-testid="setup-code" style="border:1px solid rgba(101,0,159,.3);border-radius:11px;padding:11px 22px;font-family:ui-monospace,Menlo,monospace;font-size:22px;font-weight:700;letter-spacing:.12em;color:#65009F;background:#F7E9FF">${esc(s.code)}</code>
            <button class="as-hover-ghost" data-act="setup-copy" data-val="${esc(s.code)}" style="flex:none;background:#fff;border:1px solid rgba(11,21,51,.14);border-radius:11px;padding:10px 18px;font-family:inherit;font-weight:700;font-size:13px;cursor:pointer;color:#65009F">${state.copied === 'setup-' + s.code ? 'Copied' : 'Copy code'}</button>
          </span>` : ''}
          ${s.codes ? `
          <span data-testid="setup-code-options" style="display:flex;flex-direction:column;gap:10px">
            ${s.codes.map((c) => `
            <span data-setup-code-option style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;background:#fff;border:1px solid rgba(11,21,51,.08);border-radius:14px;padding:12px 14px">
              <code data-testid="setup-code" style="flex:none;min-width:8ch;text-align:center;border:1px solid rgba(101,0,159,.3);border-radius:11px;padding:9px 18px;font-family:ui-monospace,Menlo,monospace;font-size:20px;font-weight:700;letter-spacing:.12em;color:#65009F;background:#F7E9FF">${esc(c.code)}</code>
              <span style="flex:1 1 180px;min-width:0;display:flex;flex-direction:column;gap:2px">
                <span style="font-size:14.5px;font-weight:800;letter-spacing:-0.01em">${esc(c.app)}</span>
                <span style="font-size:12.5px;line-height:1.5;color:rgba(11,21,51,.58)">${esc(c.note)}</span>
              </span>
              <button class="as-hover-ghost" data-act="setup-copy" data-val="${esc(c.code)}" style="flex:none;background:#fff;border:1px solid rgba(11,21,51,.14);border-radius:11px;padding:10px 18px;font-family:inherit;font-weight:700;font-size:13px;cursor:pointer;color:#65009F">${state.copied === 'setup-' + c.code ? 'Copied' : 'Copy code'}</button>
            </span>`).join('')}
          </span>` : ''}
          ${s.hint ? `<span style="font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.58)">${esc(s.hint)}</span>` : ''}
        </span>
      </li>`).join('')}
  </ol>`;
    }

    // Which device you are on, and the way back out of it. Sits above the steps
    // so "this is not my device" is answerable before reading any of them.
    function setupChosenRow(device) {
      return `
  <div data-testid="setup-chosen" style="display:flex;align-items:center;gap:10px 12px;flex-wrap:wrap;background:#fff;border:1px solid rgba(11,21,51,.08);border-radius:15px;padding:13px 16px;margin:0 2px 22px">
    <span style="font-size:15px;font-weight:800">${esc(device.title)}</span>
    <span style="flex:1 1 20px"></span>
    <button class="as-hover-ghost" data-act="setup-restart" style="flex:none;background:#fff;border:1px solid rgba(11,21,51,.14);border-radius:11px;padding:9px 16px;font-family:inherit;font-weight:700;font-size:13px;cursor:pointer;color:#65009F">Change device</button>
  </div>`;
    }

    function setupNav(device, idx) {
      const last = idx >= device.screens.length - 1;
      return `
  <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;border-top:1px solid rgba(11,21,51,.08);margin:26px 2px 0;padding-top:22px">
    <button class="as-hover-ghost" data-testid="setup-back" data-act="setup-back" style="background:#fff;border:1px solid rgba(11,21,51,.14);border-radius:12px;padding:13px 24px;font-family:inherit;font-weight:700;font-size:14px;cursor:pointer;color:#65009F">Back</button>
    <button data-testid="setup-next" data-act="setup-next" style="background:linear-gradient(120deg,#65009F,#CD2DF5);border:none;border-radius:12px;padding:13px 28px;font-family:inherit;font-weight:800;font-size:15px;cursor:pointer;color:#fff">${last ? 'Done — I am watching' : 'Done, what is next'}</button>
  </div>`;
    }

    // The finished card. "See what to watch" reuses the nav action rather than
    // a bespoke one, so it lands on the same tab the top bar would.
    function setupDoneCard() {
      return `
  <div data-testid="setup-done" style="border-radius:22px;background:linear-gradient(120deg,#65009F 0%,#a01ad0 60%,#CD2DF5 130%);padding:clamp(24px,5vw,40px);display:flex;flex-direction:column;gap:14px;margin:0 2px;color:#fff">
    <span class="as-on-dark" style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.75)">Setup complete</span>
    <span class="as-on-dark" style="font-size:clamp(21px,3.4vw,28px);font-weight:800;letter-spacing:-0.015em;line-height:1.2;color:#fff">You are all set — now find something to watch</span>
    <span class="as-on-dark" style="font-size:14.5px;line-height:1.6;color:rgba(255,255,255,.85);max-width:520px">Your app is installed and signed in. Open the app on your device and everything is there — films, series, sport and live TV in one list.</span>
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:8px">
      <button class="as-hover-light" data-act="nav" data-val="watch" style="background:#fff;color:#65009F;border:none;border-radius:12px;padding:13px 26px;font-family:inherit;font-weight:800;font-size:14.5px;cursor:pointer">See what to watch</button>
      <button data-testid="setup-back" data-act="setup-back" style="background:rgba(255,255,255,.14);color:#fff;border:1px solid rgba(255,255,255,.35);border-radius:12px;padding:13px 24px;font-family:inherit;font-weight:700;font-size:14px;cursor:pointer">Back to the steps</button>
    </div>
  </div>
  <div style="margin:16px 2px 0;border-radius:13px;border:1px solid rgba(11,21,51,.08);background:#fff;padding:16px 18px;font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.7)">Picture freezing or an app that will not connect? Nine times out of ten it is WiFi. Email <a href="mailto:support@afristream.io">support@afristream.io</a> and we will walk through it with you.</div>`;
    }

    // Devices we register at our end. There is nothing for the customer to
    // install, so this branch offers the one action that helps — email us —
    // rather than steps that cannot work.
    const SETUP_SUPPORT_LINES = [
      'Send us an email and we will register your device and create the profile at our end — you do not have to install anything.',
      'Tell us which device you have and we will reply with what to open on screen. Allow one business day.',
      'Once it is registered, sign in with the username and password from your welcome email and you are watching.'
    ];

    function setupSupportCard() {
      return `
  <div data-testid="setup-support" style="border-radius:20px;border:1px solid rgba(11,21,51,.08);background:#fff;box-shadow:0 1px 2px rgba(11,21,51,.04);padding:clamp(20px,4vw,30px);margin:0 2px;display:flex;flex-direction:column;gap:15px">
    <span style="font-size:17.5px;font-weight:800;letter-spacing:-0.01em">We do this part for you</span>
    ${SETUP_SUPPORT_LINES.map((l) => `<span style="font-size:14.5px;line-height:1.65;color:rgba(11,21,51,.72)">${esc(l)}</span>`).join('')}
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:6px">
      <a href="mailto:support@afristream.io" style="background:linear-gradient(120deg,#65009F,#CD2DF5);border-radius:12px;padding:13px 26px;font-weight:800;font-size:14.5px;color:#fff;text-decoration:none">Email support</a>
      <button class="as-hover-ghost" data-act="setup-restart" style="background:#fff;border:1px solid rgba(11,21,51,.14);border-radius:12px;padding:13px 24px;font-family:inherit;font-weight:700;font-size:14px;cursor:pointer;color:#65009F">Change device</button>
    </div>
  </div>`;
    }

    function setupSection() {
      const device = setupDeviceOf();
      const stage = setupStage();
      const idx = setupScreenIndex(device);
      const screen = setupScreenOf(device, idx);
      const head = setupHeading(stage, idx, screen);

      const body = stage === 'steps' && screen && device
        ? setupChosenRow(device)
          + setupStepList(screen)
          + (screen.note
            ? `<div data-testid="setup-step-note" style="margin:22px 2px 0;border-radius:13px;border:1px solid rgba(101,0,159,.2);background:#F7E9FF;padding:16px 18px;font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.75)">${esc(screen.note)}</div>`
            : '')
          + setupNav(device, idx)
        : stage === 'support'
          ? setupSupportCard()
          : stage === 'done'
            ? setupDoneCard()
            : setupDeviceGrid() + setupBuyingPanel();

      return `
<section data-screen-label="Setup">
  <div style="margin:2px 2px 18px">
    <div style="margin:0 0 7px;font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#65009F">${esc(head[0])}</div>
    <h1 style="margin:0 0 6px;font-size:clamp(21px,3vw,27px);font-weight:800;letter-spacing:-0.015em">${esc(head[1])}</h1>
    <p style="margin:0;font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.58);max-width:640px">${esc(head[2])}</p>
  </div>
  ${setupProgress(stage, idx)}
  ${body}
  <p style="margin:26px 2px 0;font-size:11.5px;line-height:1.6;color:rgba(11,21,51,.45)">Stuck on a step? Email <a href="mailto:support@afristream.io">support@afristream.io</a> with your device and the step number — we reply within one business day.</p>
</section>`;
    }

    // Home-screen install guidance. Static: no manifest ships with this plugin,
    // so both platforms create a home-screen shortcut rather than a store
    // install — the copy is written to hold either way.
    //
    // Shaped like the Setup tab: pick the thing you are holding, then read only
    // the steps for it. Showing both platforms at once meant every reader
    // skipped half the page to find their half.
    const DOWNLOAD_PLATFORMS = [
      {
        key: 'ios',
        label: 'iPhone or iPad',
        body: 'Safari puts the portal on your Home Screen in four taps.',
        note: 'You must use Safari. Chrome and Firefox on iOS cannot add a site to the Home Screen.',
        steps: [
          'Open this portal in Safari.',
          'Tap the Share button — the square with an arrow pointing up, at the bottom of the screen.',
          'Scroll down the share sheet and tap Add to Home Screen.',
          'Give it a name — AfriStream works well — then tap Add.',
          'The AfriStream icon now sits on your Home Screen alongside your other apps.'
        ]
      },
      {
        key: 'android',
        label: 'Android phone or tablet',
        body: 'Chrome offers to install it straight from the menu.',
        note: 'These steps are for Chrome. Samsung Internet and Edge have the same option under their own menus.',
        steps: [
          'Open this portal in Chrome.',
          'Tap the three-dot menu button in the top right.',
          'Tap Install app, or Add to Home screen if you do not see Install app.',
          'Confirm the name, then tap Install or Add.',
          'The AfriStream icon now sits on your home screen alongside your other apps.'
        ]
      }
    ];

    const DOWNLOAD_ICONS = {
      ios: '<svg viewBox="0 0 48 48" width="100%" height="100%" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="6" width="17" height="32" rx="4"/><path d="M15 33h5"/><rect x="30" y="14" width="12" height="24" rx="3"/><path d="M34 34h4"/></svg>',
      android: '<svg viewBox="0 0 48 48" width="100%" height="100%" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="14" y="5" width="20" height="38" rx="4"/><path d="M20 38h8"/><path d="M24 12v12M18.5 18.5h11"/></svg>'
    };

    const downloadPlatformOf = () => DOWNLOAD_PLATFORMS.find((p) => p.key === state.downloadPlatform) || null;

    function downloadPicker() {
      return `
  <div data-testid="download-picker" role="group" aria-label="Choose your device" class="as-grid-cards" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;margin:0 2px">
    ${DOWNLOAD_PLATFORMS.map((p) => `
      <button class="as-editor-card" data-act="download-platform" data-val="${esc(p.key)}" data-testid="download-${esc(p.key)}" style="display:flex;flex-direction:column;gap:0;text-align:left;background:#fff;border:1px solid rgba(11,21,51,.08);border-radius:18px;padding:0;overflow:hidden;font-family:inherit;cursor:pointer;box-shadow:0 1px 2px rgba(11,21,51,.04)">
        <span aria-hidden="true" class="as-device-icon">${DOWNLOAD_ICONS[p.key] || ''}</span>
        <span style="display:flex;flex-direction:column;gap:7px;padding:17px 19px 19px">
          <span style="font-size:15.5px;font-weight:800;letter-spacing:-0.01em;color:#0B1533">${esc(p.label)}</span>
          <span style="font-size:13px;line-height:1.55;color:rgba(11,21,51,.6)">${esc(p.body)}</span>
          <span style="margin-top:3px;font-size:13px;font-weight:700;color:#65009F">Show me how →</span>
        </span>
      </button>`).join('')}
  </div>
  <div style="margin:20px 2px 0;background:#F7E9FF;border:1px solid rgba(101,0,159,.18);border-radius:15px;padding:16px 18px;font-size:13.5px;line-height:1.65;color:rgba(11,21,51,.72)">This adds an icon that opens the portal full screen — it is not an app store download, so there is nothing to update and nothing taking up space on your device. Looking to install the streaming app itself? That is on the <strong>Setup</strong> tab.</div>`;
    }

    function downloadSteps(platform) {
      return `
  <div data-testid="download-chosen" style="display:flex;align-items:center;gap:10px 12px;flex-wrap:wrap;background:#fff;border:1px solid rgba(11,21,51,.08);border-radius:15px;padding:13px 16px;margin:0 2px 22px">
    <span style="font-size:15px;font-weight:800">${esc(platform.label)}</span>
    <span style="flex:1 1 20px"></span>
    <button class="as-hover-ghost" data-act="download-restart" style="flex:none;background:#fff;border:1px solid rgba(11,21,51,.08);border-radius:11px;padding:9px 16px;font-family:inherit;font-weight:700;font-size:13px;cursor:pointer;color:#65009F">Change device</button>
  </div>
  <ol data-testid="download-steps" style="margin:0 2px;padding:0;list-style:none;display:flex;flex-direction:column;gap:22px">
    ${platform.steps.map((s, i) => `
      <li style="display:flex;gap:16px;align-items:flex-start">
        <span aria-hidden="true" style="flex:none;width:30px;height:30px;border-radius:50%;background:#65009F;color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800">${i + 1}</span>
        <span style="font-size:15.5px;line-height:1.55;color:rgba(11,21,51,.82);padding-top:3px">${esc(s)}</span>
      </li>`).join('')}
  </ol>
  <div data-testid="download-note" style="margin:22px 2px 0;border-radius:13px;border:1px solid rgba(101,0,159,.2);background:#F7E9FF;padding:16px 18px;font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.75)">${esc(platform.note)}</div>
  <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;border-top:1px solid rgba(11,21,51,.08);margin:26px 2px 0;padding-top:22px">
    <button class="as-hover-ghost" data-testid="download-back" data-act="download-restart" style="background:#fff;border:1px solid rgba(11,21,51,.08);border-radius:12px;padding:13px 24px;font-family:inherit;font-weight:700;font-size:14px;cursor:pointer;color:#65009F">Back</button>
  </div>`;
    }

    function downloadSection() {
      const platform = downloadPlatformOf();
      const head = platform
        ? ['Add to your device', 'Put AfriStream on your ' + (platform.key === 'ios' ? 'Home Screen' : 'home screen'), 'Five taps and the portal opens like any other app on the device.']
        : ['Add to your device', 'Add AfriStream to Your Device', 'Put this portal on your phone or tablet home screen so it opens like an app — no app store, no download.'];

      return `
<section data-screen-label="Download">
  <div style="margin:2px 2px 18px">
    <div style="margin:0 0 7px;font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#65009F">${esc(head[0])}</div>
    <h1 style="margin:0 0 6px;font-size:clamp(21px,3vw,27px);font-weight:800;letter-spacing:-0.015em">${esc(head[1])}</h1>
    <p style="margin:0;font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.58);max-width:640px">${esc(head[2])}</p>
  </div>
  ${platform ? downloadSteps(platform) : downloadPicker()}
</section>`;
    }

    function tipsSection() {
      return `
<section data-screen-label="Tips and Tricks">
  <div style="margin:2px 2px 18px">
    <h1 style="margin:0 0 5px;font-size:clamp(21px,3vw,27px);font-weight:800;letter-spacing:-0.015em">Tips &amp; Tricks</h1>
    <p style="margin:0;font-size:13.5px;color:rgba(11,21,51,.58)">Small habits that make every screen in the house run smoother.</p>
  </div>
  <div class="as-grid-cards" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px">
    ${TIPS_DATA.map((t, i) => `
      <div style="background:#fff;border:1px solid rgba(11,21,51,.08);border-radius:18px;padding:20px 20px 22px;display:flex;flex-direction:column;gap:10px;position:relative;overflow:hidden;box-shadow:0 1px 2px rgba(11,21,51,.04)">
        <div style="position:absolute;top:-16px;right:4px;font-size:78px;font-weight:800;color:rgba(11,21,51,.05);line-height:1;user-select:none">${String(i + 1).padStart(2, '0')}</div>
        <span style="${tagStyle(t.h)}">${esc(t.tag)}</span>
        <div style="position:relative;font-size:17px;font-weight:800;letter-spacing:-0.01em">${esc(t.title)}</div>
        <div style="position:relative;display:flex;flex-direction:column;gap:8px">
          ${t.items.map((li) => `
            <div style="display:flex;gap:10px;font-size:13.5px;line-height:1.55;color:rgba(11,21,51,.7)">
              <span style="flex:none;width:5px;height:5px;border-radius:50%;background:oklch(0.52 0.13 ${t.h});margin-top:8px"></span>
              <span>${esc(li)}</span>
            </div>`).join('')}
        </div>
      </div>`).join('')}
  </div>
  <div style="margin-top:18px;background:linear-gradient(120deg,#65009F,#CD2DF5);border-radius:18px;padding:20px 22px;display:flex;align-items:center;gap:14px 20px;flex-wrap:wrap;color:#fff">
    <div style="flex:1 1 300px">
      <div style="font-size:15.5px;font-weight:800;margin-bottom:3px">Something not working?</div>
      <div style="font-size:13px;line-height:1.55;color:rgba(255,255,255,.68)">Start with the Quick Fixes — they solve most playback and sign-in issues in under five minutes.</div>
    </div>
    <button class="as-hover-light" data-act="go-help" style="flex:none;background:#fff;color:#65009F;border:none;border-radius:12px;padding:12px 22px;font-family:inherit;font-weight:700;font-size:13.5px;cursor:pointer">Open Troubleshooting</button>
  </div>
</section>`;
    }

    // Names the filters actually narrowing the grid, so the empty state says
    // why nothing matched rather than just that nothing did.
    function activeFilterSummary() {
      return state.appsContent === 'All' ? 'these filters' : state.appsContent;
    }

    // Content category is the only filter. Devices are still listed on each card
    // and stepped through in the drawer, but they no longer narrow the grid —
    // picking a device is the Setup tab's job, not this one's.
    function filteredApps() {
      const list = appsData || [];
      return list.filter((a) => state.appsContent === 'All' || a.content.includes(state.appsContent));
    }

    const costBadge = (a) => `<span style="flex:none;font-size:10.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;padding:4px 9px;border-radius:999px;background:${a.cost === 'free' ? '#E7F8EF' : '#F7E9FF'};color:${a.cost === 'free' ? '#0B7A44' : '#65009F'}">${a.cost === 'free' ? 'Free' : 'Free tier'}</span>`;

    const appCard = (a) => `
      <div data-app-id="${esc(a.id)}" ${cardAttrs(a)} class="as-editor-card" style="background:#fff;border:1px solid rgba(11,21,51,.08);border-radius:18px;padding:16px 17px 18px;display:flex;flex-direction:column;gap:10px;cursor:pointer;box-shadow:0 1px 2px rgba(11,21,51,.04)">
        <div style="display:flex;align-items:center;gap:11px">
          <div style="flex:none;width:42px;height:42px;border-radius:12px;background:${a.bg};color:#fff;display:flex;align-items:center;justify-content:center;font-size:19px;font-weight:800">${esc(a.initial)}</div>
          <div style="flex:1;min-width:0;font-size:15.5px;font-weight:800;letter-spacing:-0.01em;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(a.name)}</div>
          ${costBadge(a)}
        </div>
        <div style="font-size:13px;line-height:1.55;color:rgba(11,21,51,.68)">${esc(a.blurb)}</div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          ${a.content.map((c) => `<span style="font-size:11px;font-weight:700;color:#65009F;background:#F7E9FF;border:1px solid rgba(101,0,159,.18);padding:3px 9px;border-radius:999px">${esc(c)}</span>`).join('')}
        </div>
        <div style="margin-top:auto;padding-top:4px;font-size:11.5px;color:rgba(11,21,51,.5)">${esc(a.devices.map(APP_DEVICE_LABEL).join(' · '))}</div>
      </div>`;

    function appsSection() {
      const shell = (inner) => `
<section data-screen-label="Free Streaming">
  <div style="margin:2px 2px 18px">
    <h1 style="margin:0 0 5px;font-size:clamp(21px,3vw,27px);font-weight:800;letter-spacing:-0.015em">Free Streaming</h1>
    <p style="margin:0;font-size:13.5px;color:rgba(11,21,51,.58)">Free films, series and sport you can watch alongside AfriStream. Open any one to see which devices it runs on and how to install it.</p>
  </div>
  ${inner}
  <p style="margin:22px 2px 0;font-size:11.5px;line-height:1.6;color:rgba(11,21,51,.45)">Availability and free tiers change without notice. AfriStream is not affiliated with any of the services listed here.</p>
</section>`;

      const notice = (testid, text, retry) => `
  <div data-testid="${testid}" style="background:#fff;border:1px dashed rgba(11,21,51,.18);border-radius:15px;padding:32px;text-align:center;font-size:14px;color:rgba(11,21,51,.6)">
    ${esc(text)}
    ${retry ? ` <button data-act="apps-retry" style="background:none;border:none;color:#65009F;font-weight:700;font-size:14px;cursor:pointer;padding:0;font-family:inherit;text-decoration:underline">Try again</button>` : ''}
  </div>`;

      if (appsState === 'loading' || appsState === 'idle') {
        return shell(`
  <div data-testid="apps-loading" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:16px">
    ${Array.from({ length: 6 }, () => `<div style="background:#fff;border:1px solid rgba(11,21,51,.08);border-radius:18px;height:196px"></div>`).join('')}
  </div>`);
      }
      if (appsState === 'unavailable') {
        return shell(notice('apps-unavailable', 'The app directory is not configured on this page.', false));
      }
      if (appsState === 'error') {
        return shell(notice('apps-error', "The app directory could not be loaded.", true));
      }

      const shown = filteredApps();
      const pill = (act, val, label, active) =>
        `<button style="${subBtn(active)}" data-act="${act}" data-val="${esc(val)}" aria-pressed="${active}">${esc(label)}</button>`;

      return shell(`
  <div style="display:flex;gap:12px 18px;flex-wrap:wrap;align-items:center;margin:0 2px 18px">
    <div data-testid="apps-content-filters" role="group" aria-label="Filter apps by content" style="display:flex;gap:8px;flex-wrap:wrap">
      ${pill('apps-content', 'All', 'All', state.appsContent === 'All')}
      ${APP_CONTENT.map((c) => pill('apps-content', c, c, state.appsContent === c)).join('')}
    </div>
  </div>
  ${shown.length
    ? `<div data-testid="apps-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:16px">${shown.map(appCard).join('')}</div>`
    : `<div data-testid="apps-empty" style="background:#fff;border:1px dashed rgba(11,21,51,.18);border-radius:15px;padding:32px;text-align:center;font-size:14px;color:rgba(11,21,51,.6)">No free apps match ${esc(activeFilterSummary())}. <button data-act="apps-reset" style="background:none;border:none;color:#65009F;font-weight:700;font-size:14px;cursor:pointer;padding:0;font-family:inherit;text-decoration:underline">Reset filters</button></div>`}`);
    }

    function helpSection() {
      return `
<section data-screen-label="Troubleshooting">
  <div style="margin:2px 2px 18px">
    <h1 style="margin:0 0 5px;font-size:clamp(21px,3vw,27px);font-weight:800;letter-spacing:-0.015em">AfriStream Troubleshooting Guide</h1>
    <p style="margin:0;font-size:13.5px;color:rgba(11,21,51,.58)">Follow these steps if you are experiencing connection issues.</p>
  </div>
  <div style="display:flex;flex-direction:column;gap:10px">
    ${TROUBLESHOOTING.map((g, i) => {
      const open = state.guideOpen === i;
      return `
      <div style="background:#fff;border:1px solid rgba(11,21,51,.09);border-radius:15px;overflow:hidden;box-shadow:0 1px 2px rgba(11,21,51,.03)">
        <button data-act="toggle-guide" data-val="${i}" aria-expanded="${open ? 'true' : 'false'}" style="display:flex;align-items:center;gap:12px;width:100%;background:none;border:none;padding:16px 18px;cursor:pointer;text-align:left;font-family:inherit">
          <span style="${badgeStyle(g.badge)}">${esc(g.badge)}</span>
          <span style="flex:1;font-size:15px;font-weight:700;color:#65009F">${esc(g.title)}</span>
          <span style="flex:none;transition:transform .2s;transform:rotate(${open ? 180 : 0}deg);font-size:13px;color:rgba(11,21,51,.5)">▾</span>
        </button>
        ${open ? `<div style="padding:6px 18px 18px;font-size:14px;line-height:1.65;color:rgba(11,21,51,.78);border-top:1px solid rgba(11,21,51,.06)">${g.body}</div>` : ''}
      </div>`;
    }).join('')}
  </div>
  <div style="margin-top:18px;background:#fff;border:1px dashed rgba(11,21,51,.18);border-radius:15px;padding:16px 18px;font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.7)">Still stuck? Email <a href="mailto:support@afristream.io">support@afristream.io</a> with your device type and a short description — we reply within one business day.</div>
</section>`;
    }

    // Apps database. Fetched lazily the first time the tab is opened — a user
    // who never opens it never pays for the request. 'unavailable' means no
    // data-apps-url was supplied at all (an older host page), which is a
    // different message from a failed fetch. A well-formed response whose
    // `apps` array is empty deliberately throws into the 'error' state too —
    // an empty bundled directory is a fault, not a valid state to render.
    let appsData = null;
    let appsState = 'idle';

    function loadApps() {
      if (!props.appsUrl || typeof fetch !== 'function') {
        appsState = 'unavailable';
        render();
        return;
      }
      appsState = 'loading';
      render();
      fetch(props.appsUrl)
        .then((res) => (res.ok ? res.json() : Promise.reject(new Error('HTTP ' + res.status))))
        .then((payload) => {
          const list = (payload && Array.isArray(payload.apps) ? payload.apps : [])
            .filter((a) => a && a.id && a.name)
            .map(prepApp);
          if (!list.length) throw new Error('empty');
          appsData = list;
          appsState = 'ready';
          render();
        })
        .catch(() => {
          appsState = 'error';
          render();
        });
    }

    // -------------------------------------------------------------- render

    // Tips & Tricks, Troubleshooting and Free Streaming are deliberately absent
    // here while they are hidden — their sections stay in SECTIONS below, so
    // they are still reachable via default_tab="tips"/"help"/"apps" and
    // restoring one to the portal nav is a matter of adding its row back.
    const NAV = [
      { id: 'profile', label: 'Account' },
      { id: 'setup', label: 'Setup' },
      { id: 'watch', label: 'What to Watch' },
      { id: 'editor', label: 'Editor Picks' },
      { id: 'download', label: 'Download' }
    ];
    // Affiliates is the one tab that is not for everyone: it appears only once
    // SureCart has confirmed this user is an active affiliate, and sits last so
    // its late arrival never shifts a tab out from under a click.
    const navItems = () => (affiliateState === 'ready' ? NAV.concat([{ id: 'affiliate', label: 'Affiliates' }]) : NAV);

    const SECTIONS = { profile: profileSection, setup: setupSection, watch: watchSection, apps: appsSection, editor: editorSection, download: downloadSection, affiliate: affiliateSection, tips: tipsSection, help: helpSection };
    // A deep link to a tab this user cannot have falls back to the Account tab,
    // the same way an unknown tab name already does.
    const currentSection = () => (
      'affiliate' === state.section && 'ready' !== affiliateState
        ? profileSection
        : (SECTIONS[state.section] || profileSection)
    );

    // Render-scoped registry of clickable cards: reg(obj) stashes the item
    // and returns its index so a data-card="<idx>" attribute can look it up
    // again in the click/keyboard handlers below. Reset at the top of every
    // render() pass since indices only need to stay stable within one pass.
    let cardRegistry = [];
    let detailFocusPending = false;
    // Return focus to the card that opened the detail panel, once, on close.
    let detailReturnCard = null;
    let detailReturnPending = false;
    // Original body overflow, saved while the panel scroll-locks the page.
    let prevBodyOverflow = null;
    const reg = (obj) => cardRegistry.push(obj) - 1;

    const overviewCache = new Map();
    const pendingSynopsis = new Set();

    // Horizontal scroll position of the top bar, which on a phone is the
    // difference between seeing where you are and seeing a strip that always
    // starts at "Account".
    //
    // render() rebuilds innerHTML, so the strip comes back at scrollLeft 0
    // every time. Two different things have to happen:
    //
    //   - an ordinary re-render (typing in search, opening a drawer) restores
    //     exactly where the strip was, so a position the user dragged to by
    //     hand survives and it looks as though nothing moved;
    //   - changing section animates the newly active tab to the centre.
    //
    // Centring is clamped to the scroll range at both ends, so the first and
    // last tabs sit against their own edge rather than being pulled into the
    // middle with empty space beside them. Only the tabs with room on both
    // sides actually land centred.
    let lastNavSection = null;

    // Scroll offset that centres `el` inside `box`, clamped to the scroll range
    // so the first and last items sit against their own edge instead of being
    // pulled into the middle with empty space beside them. Measured off
    // getBoundingClientRect rather than offsetLeft, because the sticky header is
    // a positioned ancestor and so the items' offsetParent is not the strip.
    function centredScrollLeft(box, el) {
      const max = box.scrollWidth - box.clientWidth;
      if (max <= 0) return 0;
      const boxRect = box.getBoundingClientRect();
      const elRect = el.getBoundingClientRect();
      const elLeft = elRect.left - boxRect.left + box.scrollLeft;
      return Math.max(0, Math.min(elLeft - (box.clientWidth - elRect.width) / 2, max));
    }

    function placeNavScroll(prevLeft, sectionChanged) {
      const strip = root.querySelector('.as-tabs');
      if (!strip) return;
      const max = strip.scrollWidth - strip.clientWidth;
      // Only advertise dragging when there is somewhere to drag to.
      strip.classList.toggle('as-draggable', max > 0);
      if (max <= 0) return;

      strip.scrollLeft = Math.min(prevLeft, max);
      if (!sectionChanged) return;

      const active = strip.querySelector('[data-act="nav"][data-val="' + state.section + '"]');
      if (!active) return;

      // Assigned directly rather than through scrollTo({behavior:'smooth'}).
      // Smooth scrolling is silently a no-op in enough environments — headless
      // Chromium among them — that relying on it means the tab sometimes never
      // moves at all. The strip is rebuilt on every render anyway, so there is
      // no continuity for an animation to preserve.
      strip.scrollLeft = centredScrollLeft(strip, active);
    }

    function fillSynopsis() {
      if (!state.detail || state.detail.detailKind && state.detail.detailKind !== 'title') return;
      const box = root.querySelector('[data-detail-synopsis]');
      if (!box) return;
      const obj = state.detail;
      if (!obj.id || !props.detailEndpoint || typeof fetch !== 'function') {
        box.textContent = 'No synopsis available.';
        return;
      }
      const kind = obj.type === 'Series' ? 'tv' : 'movie';
      const cacheKey = kind + ':' + obj.id;
      if (overviewCache.has(cacheKey)) {
        box.textContent = overviewCache.get(cacheKey) || 'No synopsis available.';
        return;
      }
      box.textContent = 'Loading synopsis…';
      if (pendingSynopsis.has(cacheKey)) return;
      pendingSynopsis.add(cacheKey);
      const sep = props.detailEndpoint.includes('?') ? '&' : '?';
      const url = props.detailEndpoint + sep + 'id=' + encodeURIComponent(obj.id) + '&type=' + kind;
      fetch(url)
        .then((res) => (res.ok ? res.json() : null))
        .then((payload) => {
          const text = (payload && payload.overview) || '';
          overviewCache.set(cacheKey, text);
          // Only fill if the same item is still open.
          if (state.detail === obj) {
            const el = root.querySelector('[data-detail-synopsis]');
            if (el) el.textContent = text || 'No synopsis available.';
          }
        })
        .catch(() => {
          if (state.detail === obj) {
            const el = root.querySelector('[data-detail-synopsis]');
            if (el) el.textContent = 'No synopsis available.';
          }
        })
        .finally(() => {
          pendingSynopsis.delete(cacheKey);
        });
    }

    const cardAttrs = (obj) => `data-act="detail" data-card="${reg(obj)}" role="button" tabindex="0" aria-label="View details for ${esc(obj.t)}"`;

    const posterGridItem = (m) => `<div ${cardAttrs(m)} style="cursor:pointer">${posterArt(m)}${posterMeta(m)}</div>`;
    const posterRowItem = (m) => `<div ${cardAttrs(m)} style="flex:none;width:148px;scroll-snap-align:start;cursor:pointer">${posterArt(m)}${posterMeta(m)}</div>`;

    function detailDrawer(obj) {
      let body;
      if (obj.detailKind === 'sport') {
        const rows = [
          ['Competition', obj.comp],
          ['When', obj.time],
          ['Channel', obj.ch],
          obj.chCountry ? ['Broadcast in', obj.chCountry] : null,
          obj.live ? ['Status', 'LIVE now'] : null,
        ].filter(Boolean);
        body = `<div style="display:flex;flex-direction:column;gap:12px">${rows.map(([k, v]) => `<div style="display:flex;justify-content:space-between;gap:16px;font-size:14px"><span style="color:rgba(11,21,51,.55);font-weight:700">${esc(k)}</span><span style="color:#65009F;text-align:right">${esc(v)}</span></div>`).join('')}</div>`;
      } else if (obj.detailKind === 'channel') {
        body = `<div style="font-size:14px;line-height:1.65;color:rgba(11,21,51,.75)"><span style="font-weight:700">${esc(obj.tag)}</span> · Live channel</div>`;
      } else if (obj.detailKind === 'collection') {
        const items = obj.items || [];
        body = `<div style="display:flex;flex-direction:column;gap:16px">
          <div style="font-size:14px;line-height:1.65;color:rgba(11,21,51,.75)">${esc(obj.desc || '')}</div>
          ${items.length
            ? `<div class="as-grid-posters" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(118px,1fr));gap:14px">${items.map(posterGridItem).join('')}</div>`
            : `<div style="font-size:13px;color:rgba(11,21,51,.55)">Nothing in this collection right now — check back after the next update.</div>`}
        </div>`;
      } else if (obj.detailKind === 'app') {
        const row = (k, v) => `<div style="display:flex;justify-content:space-between;gap:16px;font-size:14px"><span style="flex:none;color:rgba(11,21,51,.55);font-weight:700">${esc(k)}</span><span style="color:#65009F;text-align:right">${esc(v)}</span></div>`;
        const steps = obj.devices.map((d) => `
          <div data-install-device="${esc(d)}" style="display:flex;gap:12px;font-size:13.5px;line-height:1.55">
            <span style="flex:none;width:104px;font-weight:700;color:rgba(11,21,51,.62)">${esc(APP_DEVICE_LABEL(d))}</span>
            <span style="flex:1;color:rgba(11,21,51,.75)">${esc((obj.install && obj.install[d]) || APP_INSTALL_DEFAULTS[d] || '')}</span>
          </div>`).join('');
        body = `<div style="display:flex;flex-direction:column;gap:18px">
          <div style="display:flex;gap:8px;flex-wrap:wrap">${costBadge(obj)}${obj.content.map((c) => `<span style="font-size:12px;font-weight:700;color:#65009F;background:#F7E9FF;border:1px solid rgba(101,0,159,.18);padding:5px 11px;border-radius:999px">${esc(c)}</span>`).join('')}</div>
          <div style="font-size:14px;line-height:1.65;color:rgba(11,21,51,.75)">${esc(obj.blurb)}</div>
          <div style="display:flex;flex-direction:column;gap:12px">
            ${row('Where', obj.availability)}
          </div>
          <div>
            <div style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(11,21,51,.45);margin-bottom:10px">Install on</div>
            <div style="display:flex;flex-direction:column;gap:10px">${steps}</div>
          </div>
          ${obj.url ? `<a href="${esc(obj.url)}" target="_blank" rel="noopener noreferrer" style="display:block;text-align:center;background:#65009F;color:#fff;border-radius:13px;padding:13px 18px;font-weight:700;font-size:13.5px;text-decoration:none">Open ${esc(obj.name)} →</a>` : ''}
        </div>`;
      } else {
        const chips = [obj.genre, obj.meta, obj.country, obj.platform, obj.type]
          .filter(Boolean)
          .map((c) => `<span style="font-size:12px;font-weight:700;color:#65009F;background:#F7E9FF;border:1px solid rgba(101,0,159,.18);padding:5px 11px;border-radius:999px">${esc(c)}</span>`)
          .join('');
        body = `<div style="display:flex;flex-direction:column;gap:16px"><div style="display:flex;gap:8px;flex-wrap:wrap">${chips}</div><div data-detail-synopsis style="font-size:14px;line-height:1.65;color:rgba(11,21,51,.75)"></div></div>`;
      }
      const art = obj.poster
        ? `<img src="${esc(obj.poster)}" alt="" loading="lazy" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover"><div style="position:absolute;inset:0;background:linear-gradient(180deg,rgba(5,9,24,0) 40%,rgba(5,9,24,.85))"></div>`
        : `<div style="position:absolute;top:-30px;right:-8px;font-size:180px;font-weight:800;color:rgba(255,255,255,.12);line-height:1;user-select:none">${esc(obj.initial || (obj.t || '')[0] || '')}</div>`;
      return `
    <div class="as-scrim" data-act="close-detail" style="background:rgba(11,21,51,.5);z-index:70"></div>
    <div class="as-panel" data-testid="detail-drawer" role="dialog" aria-modal="true" aria-label="${esc(obj.t)} details" style="width:min(420px,94vw);background:#fff;z-index:71;box-shadow:-24px 0 60px -30px rgba(11,21,51,.5)">
      <div class="as-panel-body">
        <div style="position:relative;min-height:220px;background:${obj.bg || '#65009F'};color:#fff;display:flex;align-items:flex-end;padding:18px">
          ${art}
          <button data-act="close-detail" aria-label="Close details" style="position:absolute;top:14px;right:14px;background:rgba(5,9,24,.55);border:none;border-radius:999px;width:34px;height:34px;cursor:pointer;font-size:15px;color:#fff;font-family:inherit;z-index:1">✕</button>
          <div style="position:relative;font-size:22px;font-weight:800;line-height:1.15;text-shadow:0 1px 8px rgba(0,0,0,.5)">${esc(obj.t)}</div>
        </div>
        <div style="padding:20px 22px;display:flex;flex-direction:column;gap:16px">
          ${body}
        </div>
      </div>
    </div>`;
    }

    function render(preserveFocus) {
      cardRegistry = [];
      let caret = 0;
      let hadFocus = false;
      const active = document.activeElement;
      const activeAct = preserveFocus && active && active.getAttribute ? active.getAttribute('data-act') : null;
      // Inputs (and the plan picker) that re-render on every change have to
      // get their focus back, or a keyboard user changing the plan drops
      // straight to <body>. Text/number inputs also want their caret back,
      // or typing a two-digit number is impossible.
      const focusAct = ['query', 'aff-per-year', 'aff-value', 'aff-plan', 'aff-currency'].includes(activeAct) ? activeAct : null;
      if (focusAct) {
        hadFocus = true;
        caret = active.selectionStart;
      }

      // Read the tab strip's scroll offset before the rebuild wipes it.
      const prevStrip = root.querySelector('.as-tabs');
      const prevNavScroll = prevStrip ? prevStrip.scrollLeft : 0;

      root.innerHTML = `
<div style="min-height:100vh;display:flex;flex-direction:column">
<header style="position:sticky;top:0;z-index:40;background:linear-gradient(165deg,#65009F 40%,#4A0073);box-shadow:0 10px 30px -18px rgba(11,21,51,.55)">
  <nav class="as-nav" style="max-width:1180px;margin:0 auto;padding:0 clamp(16px,3vw,32px);display:flex;align-items:stretch;gap:14px">
    <div class="as-tabs" data-dragscroll style="display:flex;align-items:stretch;gap:26px;overflow-x:auto;flex:1 1 auto;min-width:0">
      ${navItems().map((n) => `<button style="${navBtn(n.id === state.section)}" data-act="nav" data-val="${n.id}" data-testid="nav-${n.id}">${esc(n.label)}</button>`).join('')}
    </div>
    <div class="as-plan" style="align-self:center;display:flex;align-items:center;gap:10px;flex:none">
      ${props.homeUrl ? `<a href="${esc(props.homeUrl)}" data-testid="header-home" style="display:flex;align-items:center;gap:5px;border:1px solid rgba(255,255,255,.14);border-radius:999px;padding:6px 14px;font-size:12px;font-weight:700;color:#fff;text-decoration:none;white-space:nowrap">Home<span aria-hidden="true" style="font-size:11px;line-height:1;opacity:.75">↗</span></a>` : ''}
      <span style="display:flex;align-items:center;gap:8px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);border-radius:999px;padding:6px 14px;font-size:12px;font-weight:700;color:#fff;white-space:nowrap">
        <span style="width:7px;height:7px;border-radius:50%;background:#3DD68C;flex:none"></span>Annual · Active
      </span>
    </div>
  </nav>
</header>
<main style="flex:1;width:100%;max-width:1180px;margin:0 auto;padding:clamp(22px,3.5vw,34px) clamp(16px,3vw,32px) 76px">
${currentSection()()}
</main>
<footer style="border-top:1px solid rgba(11,21,51,.08);padding:20px clamp(16px,3vw,32px);text-align:center;font-size:12px;color:rgba(11,21,51,.5)">Need help? <a href="mailto:support@afristream.io">support@afristream.io</a> · © 2026 AfriStream</footer>
${state.detail ? detailDrawer(state.detail) : ''}
</div>`;

      if (hadFocus) {
        const input = root.querySelector(`[data-act="${focusAct}"]`);
        if (input) {
          input.focus();
          try { input.setSelectionRange(caret, caret); } catch (e) { /* number/search inputs reject this — ignore */ }
        }
      }

      if (detailFocusPending) {
        detailFocusPending = false;
        const closeBtn = root.querySelector('[data-testid="detail-drawer"] [aria-label="Close details"]');
        if (closeBtn) closeBtn.focus();
      }

      placeNavScroll(prevNavScroll, lastNavSection !== state.section);
      lastNavSection = state.section;

      // Scroll-lock the page while a modal panel is open; restore the original
      // body overflow on close (so we don't clobber a host value). Both panels
      // count: the filters drawer is just as modal as the detail one — a fixed
      // panel over a full-viewport scrim — and on a phone a page left scrolling
      // underneath is obvious, because dragging the filter list takes the
      // catalogue behind it along too.
      const body = root.ownerDocument && root.ownerDocument.body;
      const modalOpen = !!state.detail || !!state.filtersOpen;
      if (body) {
        if (modalOpen && prevBodyOverflow === null) {
          prevBodyOverflow = body.style.overflow;
          body.style.overflow = 'hidden';
        } else if (!modalOpen && prevBodyOverflow !== null) {
          body.style.overflow = prevBodyOverflow;
          prevBodyOverflow = null;
        }
      }

      // Return focus to the triggering card after the panel closes.
      if (detailReturnPending) {
        detailReturnPending = false;
        const trigger = detailReturnCard != null && root.querySelector(`[data-card="${detailReturnCard}"]`);
        if (trigger) trigger.focus();
        detailReturnCard = null;
      }

      if (state.detail) fillSynopsis();
    }

    // -------------------------------------------------------------- events

    root.addEventListener('click', (e) => {
      const el = e.target.closest('[data-act]');
      if (!el || !root.contains(el)) return;
      const val = el.getAttribute('data-val');
      switch (el.getAttribute('data-act')) {
        case 'nav':
          setState({ section: val });
          if (val === 'apps' && appsState === 'idle') loadApps();
          break;
        case 'apps-retry': loadApps(); break;
        case 'apps-content': setState({ appsContent: val }); break;
        case 'apps-reset': setState({ appsContent: 'All' }); break;
        case 'setup-device': setState({ setupDevice: val, setupScreen: 0, setupDone: false }); break;
        case 'setup-copy': copy(val, 'setup-' + val); break;
        case 'setup-next': {
          const device = setupDeviceOf();
          if (!device || !device.screens) break;
          setState(state.setupScreen >= device.screens.length - 1
            ? { setupDone: true }
            : { setupScreen: state.setupScreen + 1 });
          break;
        }
        case 'setup-back': {
          const device = setupDeviceOf();
          if (state.setupDone && device && device.screens) {
            setState({ setupDone: false, setupScreen: device.screens.length - 1 });
          } else if (state.setupScreen === 0) {
            setState({ setupDevice: '', setupScreen: 0, setupDone: false });
          } else {
            setState({ setupScreen: state.setupScreen - 1 });
          }
          break;
        }
        case 'setup-restart': setState({ setupDevice: '', setupScreen: 0, setupDone: false }); break;
        case 'setup-help': setState({ setupHelpOpen: !state.setupHelpOpen }); break;
        case 'download-platform': setState({ downloadPlatform: val }); break;
        case 'download-restart': setState({ downloadPlatform: '' }); break;
        case 'acct': setState({ accIdx: +val, copied: '' }); break;
        case 'copy-user': copy((accounts[state.accIdx] || accounts[0] || {}).user || '', 'user'); break;
        case 'copy-bank': copy(val || '', 'bank'); break;
        case 'copy-pass': copy((accounts[state.accIdx] || accounts[0] || {}).pass || '', 'pass'); break;
        case 'copy-referral': copy((affiliate && affiliate.referral_url) || '', 'referral'); break;
        case 'copy-buy': {
          const link = affiliateBuyLinks()[+val];
          if (link) copy(link.url, 'buy-' + link.key);
          break;
        }
        case 'quicknav': setState({ subWatch: val }); break;
        case 'open-filters': setState({ filtersOpen: true }); break;
        case 'close-filters': setState({ filtersOpen: false }); break;
        case 'clear-filters': setState({ query: '', type: 'All Types', genre: 'All Genres', country: 'All Countries', decade: 'All Decades', sort: 'Recommended' }); break;
        case 'filter': setState({ [el.getAttribute('data-key')]: val }); break;
        case 'editor-filter': setState({ [el.getAttribute('data-key')]: val }); break;
        case 'clear-editor-filters': setState({ editorTag: 'All', editorCategory: 'All', editorRating: 0 }); break;
        case 'toggle-guide': {
          const i = +val;
          setState({ guideOpen: state.guideOpen === i ? -1 : i });
          break;
        }
        case 'go-help': setState({ section: 'help' }); break;
        case 'detail': {
          const idx = +el.getAttribute('data-card');
          const obj = cardRegistry[idx];
          if (obj) { detailReturnCard = idx; detailFocusPending = true; setState({ detail: obj }); }
          break;
        }
        case 'close-detail': detailReturnPending = true; setState({ detail: null }); break;
      }
    });

    root.addEventListener('input', (e) => {
      const act = e.target.getAttribute && e.target.getAttribute('data-act');
      if ('query' === act) {
        state.query = e.target.value;
        render(true);
      } else if ('aff-per-year' === act) {
        // Text inputs, so what arrives is whatever was typed — digits only for
        // a headcount, digits and one decimal point for a price.
        state.affPerYear = e.target.value.replace(/[^0-9]/g, '');
        render(true);
      } else if ('aff-value' === act) {
        state.affValue = e.target.value.replace(/[^0-9.]/g, '');
        render(true);
      }
    });

    root.addEventListener('change', (e) => {
      const act = e.target.getAttribute && e.target.getAttribute('data-act');
      // A direct mutate-and-render(true), like the other affiliate inputs
      // above, rather than setState()'s plain render() — the selects need
      // their focus restored after the rebuild, same as they do.
      if ('aff-plan' === act) {
        state.affPlan = e.target.value;
        render(true);
      } else if ('aff-currency' === act) {
        state.affCurrency = e.target.value;
        render(true);
      }
    });

    root.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && state.detail) { detailReturnPending = true; setState({ detail: null }); return; }
      // Trap Tab within the open panel so focus can't wander to the cards
      // behind the scrim (honouring the drawer's aria-modal contract).
      if (e.key === 'Tab' && state.detail) {
        const drawer = root.querySelector('[data-testid="detail-drawer"]');
        if (drawer) {
          const f = drawer.querySelectorAll('a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])');
          if (f.length) {
            const first = f[0];
            const last = f[f.length - 1];
            const activeEl = root.ownerDocument.activeElement;
            if (!drawer.contains(activeEl)) { e.preventDefault(); first.focus(); }
            else if (e.shiftKey && activeEl === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && activeEl === last) { e.preventDefault(); first.focus(); }
          }
        }
        return;
      }
      const card = e.target.closest && e.target.closest('[data-act="detail"]');
      if ((e.key === 'Enter' || e.key === ' ') && card) {
        const idx = +card.getAttribute('data-card');
        const obj = cardRegistry[idx];
        if (obj) { e.preventDefault(); detailReturnCard = idx; detailFocusPending = true; setState({ detail: obj }); }
      }
    });

    // Mouse drag-to-scroll for any [data-dragscroll] row. Pointer-based so it
    // unifies with wheel/touch scroll; a 5px threshold preserves poster clicks,
    // and once a real drag starts we swallow the trailing click.
    let drag = null;
    let draggedClick = false;
    root.addEventListener('pointerdown', (e) => {
      draggedClick = false;
      if (e.button !== 0) return;
      const row = e.target.closest('[data-dragscroll]');
      if (!row || !root.contains(row)) return;
      drag = { row, startX: e.clientX, startScroll: row.scrollLeft };
    });
    root.addEventListener('pointermove', (e) => {
      if (!drag) return;
      const dx = e.clientX - drag.startX;
      if (!draggedClick && Math.abs(dx) < 5) return;
      draggedClick = true;
      drag.row.style.cursor = 'grabbing';
      drag.row.style.userSelect = 'none';
      drag.row.scrollLeft = drag.startScroll - dx;
      e.preventDefault();
    });
    const endDrag = () => {
      if (!drag) return;
      drag.row.style.cursor = '';
      drag.row.style.userSelect = '';
      drag = null;
    };
    root.addEventListener('pointerup', endDrag);
    root.addEventListener('pointercancel', endDrag);
    root.addEventListener('pointerleave', endDrag);
    // Capture phase so this runs before the bubbling click handler below.
    root.addEventListener('click', (e) => {
      if (draggedClick) { draggedClick = false; e.stopPropagation(); e.preventDefault(); }
    }, true);

    render();

    if (state.section === 'apps') loadApps();

    // Pull live catalog + sport data from the watch endpoint (WP REST in
    // production, the preview server's /api/watch locally). Each array is
    // applied independently; anything missing or unhealthy leaves the
    // built-in curated list in place.
    if (props.endpoint && typeof fetch === 'function') {
      const prep = (arr) => (Array.isArray(arr) ? arr : [])
        .filter((x) => x && x.t)
        .map((x) => ({ ...x, initial: String(x.t)[0], bg: bg(x.genre) }));

      // "Sat · 15:00" in the viewer's own timezone, from the event's ISO date.
      const fmtKick = (iso) => {
        const d = new Date(iso);
        if (isNaN(d)) return '';
        const now = new Date();
        const midnight = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate());
        const days = Math.round((midnight(d) - midnight(now)) / 86400000);
        const day = days === 0 ? 'Today'
          : days === 1 ? 'Tomorrow'
          : d.toLocaleDateString([], days > 6 ? { weekday: 'short', day: 'numeric', month: 'short' } : { weekday: 'short' });
        return `${day} · ${d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
      };

      const prepSport = (arr) => (Array.isArray(arr) ? arr : [])
        .filter((s) => s && s.fx)
        .map((s) => ({
          comp: s.comp || '',
          code: s.code || '',
          country: s.country || '',
          fx: s.fx,
          ch: s.ch || '',
          chCountry: s.chCountry || '',
          live: !!s.live,
          time: s.live ? (s.time || 'LIVE now') : (s.iso ? fmtKick(s.iso) : (s.time || ''))
        }));

      fetch(props.endpoint)
        .then((res) => (res.ok ? res.json() : null))
        .then((payload) => {
          if (!payload || payload.source === 'fallback') return;
          const movies = prep(payload.movies);
          const series = prep(payload.series);
          const newWeek = prep(payload.newWeek);
          const catalog = prep(payload.catalog);
          const sport = prepSport(payload.sport);
          if (!movies.length && !series.length && !sport.length && !catalog.length) return;
          if (movies.length) data.movies = movies;
          if (series.length) data.series = series;
          if (newWeek.length) data.newWeek = newWeek;
          if (catalog.length) data.catalog = catalog;
          if (sport.length) data.sport = sport;
          if (movies.length || series.length || catalog.length) dataSource = 'tmdb';
          INDEX = buildIndex(data);
          render(true);
        })
        .catch(() => { /* endpoint unreachable — curated lists stay */ });
    }

    if (props.editorEndpoint && typeof fetch === 'function') {
      const prepPicks = (arr) => (Array.isArray(arr) ? arr : [])
        .filter((x) => x && x.t)
        .map((x) => ({ ...x, initial: String(x.t)[0], bg: bg(x.genre) }));
      // Resolving the watchlist through TMDB is time-budgeted on the server, so a
      // cold cache comes back with only part of the list and `partial: true`. Each
      // follow-up request resumes from the server's warm per-title cache and
      // returns more, so keep asking until the list is complete — one fetch alone
      // strands the visitor on the truncated version.
      const EDITOR_POLL_MS = 4000;
      const EDITOR_POLL_MAX = 25;
      const loadPicks = (attempt) => {
        // Cache-bust the retries: a proxy or service worker holding on to the
        // first (partial) response would otherwise stall the poll forever.
        const sep = props.editorEndpoint.indexOf('?') < 0 ? '?' : '&';
        const url = attempt ? props.editorEndpoint + sep + '_r=' + attempt : props.editorEndpoint;
        fetch(url, { cache: 'no-store' })
          .then((res) => (res.ok ? res.json() : null))
          .then((payload) => {
            if (!payload || payload.source === 'fallback') return;
            const picks = prepPicks(payload.picks);
            if (picks.length) {
              data.editorPicks = picks;
              editorSource = 'imdb';
              if (state.section === 'editor') render(true);
            }
            if (payload.partial && attempt < EDITOR_POLL_MAX) {
              setTimeout(() => loadPicks(attempt + 1), EDITOR_POLL_MS);
            }
          })
          .catch(() => { /* endpoint unreachable — built-in picks stay */ });
      };
      loadPicks(0);
    }

    // Fetch the logged-in user's real credentials for the Profile tab. Only
    // runs when the shortcode supplies a credentials endpoint (the WordPress
    // mount); the generic preview keeps its built-in demo profiles.
    if (props.credentialsEndpoint && typeof fetch === 'function') {
      const headers = props.restNonce ? { 'X-WP-Nonce': props.restNonce } : {};
      fetch(props.credentialsEndpoint, { headers, credentials: 'same-origin' })
        .then((res) => (res.ok ? res.json() : null))
        .then((payload) => {
          const list = (payload && Array.isArray(payload.profiles) ? payload.profiles : [])
            .filter((p) => p && (p.user || p.pass))
            .map((p, i) => ({ label: p.label || `Profile ${i + 1}`, user: p.user || '', pass: p.pass || '' }));
          if (list.length) {
            accounts = list;
            credState = 'ready';
          } else {
            accounts = [];
            credState = 'empty';
          }
          if (state.accIdx >= accounts.length) state.accIdx = 0;
          render(true);
        })
        .catch(() => {
          accounts = [];
          credState = 'error';
          render(true);
        });
    }

    // Is this person an affiliate? The tab is appended only on a yes, so the
    // markup never contains anything affiliate-related for anyone else — which
    // also means a page-cached portal cannot leak it.
    if (props.affiliateEndpoint && typeof fetch === 'function') {
      const affHeaders = props.restNonce ? { 'X-WP-Nonce': props.restNonce } : {};
      fetch(props.affiliateEndpoint, { headers: affHeaders, credentials: 'same-origin' })
        .then((res) => (res.ok ? res.json() : null))
        .then((payload) => {
          if (payload && payload.affiliate) {
            affiliate = payload;
            affiliateState = 'ready';
            const plans = Array.isArray(payload.plans) ? payload.plans : [];
            if (plans.length) state.affPlan = plans[0].id;
          } else {
            affiliateState = 'off';
            if (state.section === 'affiliate') state.section = 'profile';
          }
          render(true);
        })
        .catch(() => {
          affiliateState = 'off';
          if (state.section === 'affiliate') state.section = 'profile';
          render(true);
        });
    }
  }

  // ------------------------------------------------------------------- init

  function init() {
    document.querySelectorAll('[data-afristream-portal]').forEach(createPortal);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
