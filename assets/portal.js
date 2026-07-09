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
    { comp: 'FIFA World Cup', code: 'Football', country: 'International', fx: 'Semi-final build-up', time: 'LIVE now', ch: 'FOX Sports', live: true },
    { comp: 'Premier League', code: 'Football', country: 'England', fx: 'Arsenal vs Spurs', time: 'Today · 21:00', ch: 'Sky Sports PL', live: false },
    { comp: 'Formula 1', code: 'Motorsport', country: 'International', fx: 'British GP · Qualifying', time: 'Sat · 15:00', ch: 'Sky Sports F1', live: false },
    { comp: 'UFC', code: 'MMA', country: 'International', fx: 'Fight Night Prelims', time: 'Sun · 02:00', ch: 'ESPN+', live: false },
    { comp: 'NBA', code: 'Basketball', country: 'United States', fx: 'Summer League opener', time: 'Sun · 22:00', ch: 'ESPN', live: false }
  ];
  const LIVE_TV = [
    { name: 'ESPN', tag: 'Sport' }, { name: 'Sky News', tag: 'News' },
    { name: 'BBC One', tag: 'Ent' }, { name: 'Sky Cinema', tag: 'Movies' },
    { name: 'National Geographic', tag: 'Docs' }, { name: 'Cartoon Network', tag: 'Kids' },
    { name: 'Eurosport', tag: 'Sport' }, { name: 'CNN International', tag: 'News' }
  ].map((t) => ({ ...t, bg: `linear-gradient(150deg, oklch(0.32 0.06 ${hue(t.tag)}), oklch(0.20 0.05 ${hue(t.tag)}))` }));
  const COLLECTIONS = [
    { name: 'Weekend Binge', count: '12 titles', desc: 'Three seasons or less — start Friday, done by Sunday.', h: 300 },
    { name: 'True Crime Deep Dive', count: '9 titles', desc: 'Docs and dramas ripped from the headlines.', h: 265 },
    { name: 'Family Movie Night', count: '15 titles', desc: 'Safe for the whole couch.', h: 210 },
    { name: 'Big Match Build-Up', count: '7 titles', desc: 'Sport docs to watch before kick-off.', h: 150 },
    { name: 'Award Season Catch-Up', count: '10 titles', desc: 'Everything nominated, in one row.', h: 25 }
  ].map((c) => ({ ...c, bg: `linear-gradient(135deg, oklch(0.42 0.12 ${c.h}), oklch(0.24 0.09 ${(c.h + 50) % 360}))` }));

  const EDITOR_FALLBACK = [
    { t: 'The Colour of Home', genre: 'Drama', platform: '★ 8.4', meta: '2024', type: 'Movies', country: 'South Africa', rank: 1 },
    { t: 'Harmattan', genre: 'Thriller', platform: '★ 8.1', meta: 'TV · 2023', type: 'Series', country: 'Nigeria', rank: 2 },
    { t: 'Salt & Silver', genre: 'Docs', platform: '★ 7.9', meta: '2022', type: 'Movies', country: 'Kenya', rank: 3 },
    { t: 'The Long Dry', genre: 'Drama', platform: '★ 7.7', meta: '2021', type: 'Movies', country: 'South Africa', rank: 4 },
    { t: 'Northern Lights', genre: 'Family', platform: '★ 7.5', meta: 'TV · 2020', type: 'Series', country: 'United Kingdom', rank: 5 },
  ].map((p) => ({ ...p, initial: p.t[0], bg: bg(p.genre) }));

  const TIPS_DATA = [
    { tag: 'Setup', h: 250, title: 'Setup Tips', items: ['Use a stable WiFi connection before starting.', 'Make sure the FireStick is fully signed into an Amazon account.', 'Complete all FireStick updates before installing apps.', 'Keep the remote nearby during every install step.'] },
    { tag: 'Permissions', h: 290, title: 'FireStick Permission Tips', items: ['Developer permissions may need to be enabled before downloads work.', 'Allow install permissions when prompted.', 'If an app will not install, check FireStick permission settings first.', 'Restart the FireStick if permissions do not apply immediately.'] },
    { tag: 'Downloads', h: 210, title: 'App Download Tips', items: ['Enter download codes carefully.', 'Double-check codes before pressing download.', 'Wait for each download to fully complete before moving on.', 'Do not exit the app during installation.'] },
    { tag: 'Login', h: 320, title: 'Login Tips', items: ['Usernames and passwords are case-sensitive.', 'Copy login details exactly as provided.', 'Avoid adding spaces before or after the username/password.', 'Save login details somewhere safe.'] },
    { tag: 'Devices', h: 160, title: 'Device Tips', items: ['One license may only allow one active connection at a time.', 'Android devices may support the same login.', 'Apple devices may need a separate player app.', 'Test one device fully before setting up extra devices.'] },
    { tag: 'Support', h: 60, title: 'Support Tips', items: ['Take screenshots of errors.', 'Confirm which device is being used before troubleshooting.', 'Confirm the app name before giving setup support.', 'Ask whether the issue is install, login, or connection related.'] }
  ];

  const GUIDES = {
    'Quick Fixes': [
      { badge: 'Note', title: 'Before You Start', body: 'Most playback issues clear up with the first two steps. Before you begin:\n• Check that your subscription is active — you should see "Annual · Active" at the top of this portal.\n• Your profile works on one device at a time; make sure the app is not signed in elsewhere.' },
      { badge: 'Step 1', title: 'Restart the App', body: 'Fully close AfriStream (do not just minimise it), wait 10 seconds, then reopen. On most devices: open recent apps, swipe AfriStream away, then relaunch.' },
      { badge: 'Step 2', title: 'Clear Cache & App Data', body: 'Old cache is the most common cause of buffering and login loops.\n1. Open your device Settings → Apps → AfriStream.\n2. Tap Clear Cache, then Clear Data.\n3. Reopen the app and sign in again.' },
      { badge: 'Step 3', title: 'Test Your Internet', body: 'AfriStream needs at least 10 Mbps for HD and 25 Mbps for 4K. Run a speed test on the same device. If it is slow, restart your router (off for 30 seconds), or try a mobile hotspot to rule out your line.' },
      { badge: 'Step 4', title: 'Reinstall the App', body: 'Uninstall AfriStream, restart your device, then reinstall the latest version and sign in again. This fixes most stubborn issues after app updates.' }
    ],
    'Smart TV': [
      { badge: 'Note', title: 'Supported TVs', body: 'AfriStream runs on Samsung (Tizen, 2018+), LG (webOS 4+), and any Android TV / Google TV. Older TVs work best with an external device such as a Fire TV Stick.' },
      { badge: 'Step 1', title: 'Update Your TV Software', body: 'Settings → Support / About → Software Update. Out-of-date firmware is the top cause of app crashes on smart TVs.' },
      { badge: 'Step 2', title: 'Cold-Boot the TV', body: 'Unplug the TV from the wall for 60 seconds (standby is not enough), then plug back in and relaunch AfriStream.' },
      { badge: 'Step 3', title: 'Check Date & Time', body: 'If the TV clock is wrong, secure streams will not connect. Set Date & Time to automatic in your TV settings.' },
      { badge: 'Step 4', title: 'Prefer a Wired Connection', body: 'If your TV is far from the router, buffering is usually Wi-Fi. Use an ethernet cable or move the router closer for stable 4K.' }
    ],
    'Firestick': [
      { badge: 'Note', title: 'Before You Start', body: 'These steps apply to the Fire TV Stick, Stick 4K and Fire TV Cube on Fire OS 6 or newer.' },
      { badge: 'Step 1', title: 'Force Stop & Clear Cache', body: 'Settings → Applications → Manage Installed Applications → AfriStream → Force Stop, then Clear Cache and Clear Data.' },
      { badge: 'Step 2', title: 'Restart the Firestick', body: 'Hold Select + Play/Pause for 10 seconds, or unplug the power for 30 seconds and plug it back in.' },
      { badge: 'Step 3', title: 'Free Up Storage', body: 'Fire OS misbehaves with under 1 GB free. Settings → My Fire TV → About → Storage, then uninstall apps you no longer use.' },
      { badge: 'Step 4', title: 'Reinstall the Latest Version', body: 'Uninstall AfriStream, then reinstall it using the Downloader code from your welcome email. Sign in again.' }
    ],
    'Phone & Tablet': [
      { badge: 'Note', title: 'Supported Devices', body: 'AfriStream supports Android 9+ and iOS 15+. Phones and tablets use the same app and profile.' },
      { badge: 'Step 1', title: 'Update the App', body: 'Install the latest version, then restart your device before opening the app again.' },
      { badge: 'Step 2', title: 'Clear the Cache', body: 'Android: Settings → Apps → AfriStream → Storage → Clear Cache.\niPhone / iPad: offload the app in Settings → General → Storage, then reinstall.' },
      { badge: 'Step 3', title: 'Check Battery Settings', body: 'Battery saver and data saver modes can kill streams in the background. Exclude AfriStream from both while watching.' },
      { badge: 'Step 4', title: 'Switch Networks', body: 'Try switching between Wi-Fi and mobile data. If it works on one and not the other, restart your router or contact your ISP.' }
    ]
  };

  const HELP_INTROS = {
    'Quick Fixes': 'Follow these steps in order if you are experiencing connection or playback issues.',
    'Smart TV': 'Fixes specific to Samsung, LG, Android TV and Google TV.',
    'Firestick': 'Fixes for the Fire TV Stick, Stick 4K and Fire TV Cube.',
    'Phone & Tablet': 'Fixes for Android and iOS phones and tablets.'
  };

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
      ...LIVE_TV.map((t) => ({ t: t.name, genre: t.tag, platform: 'Live TV', meta: 'Live channel', type: 'Live TV', initial: t.name[0], bg: t.bg })),
      ...COLLECTIONS.map((c) => ({ t: c.name, genre: 'Collection', platform: 'AfriStream', meta: c.count, type: 'Collection', initial: c.name[0], bg: c.bg })),
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

  const navBtn = (a) => `flex:none;border:none;background:none;cursor:pointer;font-family:inherit;font-weight:${a ? 800 : 600};font-size:14px;letter-spacing:.01em;padding:20px 2px 16px;color:${a ? '#fff' : 'rgba(255,255,255,.58)'};border-bottom:3px solid ${a ? '#4C7DFF' : 'transparent'};white-space:nowrap;transition:color .15s,border-color .15s`;
  const subBtn = (a) => `flex:none;cursor:pointer;font-family:inherit;font-size:12.5px;font-weight:700;padding:8px 15px;border-radius:999px;white-space:nowrap;transition:background .15s,color .15s;background:${a ? '#0B1533' : '#fff'};color:${a ? '#fff' : 'rgba(11,21,51,.62)'};border:1px solid ${a ? '#0B1533' : 'rgba(11,21,51,.12)'}`;
  const badgeStyle = (b) => `display:inline-flex;align-items:center;flex:none;background:${b === 'Note' ? '#0B1533' : '#2E5BE6'};color:#fff;font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;white-space:nowrap`;
  const tagStyle = (h) => `align-self:flex-start;position:relative;font-size:10.5px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:4px 10px;border-radius:999px;background:oklch(0.95 0.03 ${h});color:oklch(0.42 0.13 ${h})`;
  const copyBtnStyle = 'flex:none;background:#2E5BE6;color:#fff;border:none;border-radius:13px;padding:13px 24px;font-family:inherit;font-weight:700;font-size:13.5px;cursor:pointer;min-width:98px;box-shadow:0 8px 18px -10px rgba(46,91,230,.7)';

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

  const posterGridItem = (m) => `<div>${posterArt(m)}${posterMeta(m)}</div>`;
  const posterRowItem = (m) => `<div style="flex:none;width:148px;scroll-snap-align:start">${posterArt(m)}${posterMeta(m)}</div>`;

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
      editorEndpoint: root.getAttribute('data-editor-endpoint') || ''
    };
    // Live catalog data — starts as the built-in curated lists, replaced
    // per-array by whatever the watch endpoint returns (TMDB catalog and/or
    // ESPN sport fixtures).
    const data = { movies: MOVIES, series: SERIES, newWeek: NEW_WEEK, sport: SPORT, catalog: [], editorPicks: EDITOR_FALLBACK };
    let INDEX = buildIndex(data);
    let dataSource = 'built-in';
    let editorSource = 'built-in';
    const FILTER_DEFAULTS = { type: 'All Types', genre: 'All Genres', country: 'All Countries', decade: 'All Decades', sort: 'Recommended' };

    const state = {
      section: ['profile', 'watch', 'editor', 'tips', 'help'].includes(props.defaultTab) ? props.defaultTab : 'profile',
      subWatch: 'All',
      helpTab: 'Quick Fixes',
      accIdx: 0,
      copied: '',
      query: '',
      genre: 'All Genres',
      type: 'All Types',
      country: 'All Countries',
      decade: 'All Decades',
      sort: 'Recommended',
      editorType: 'All',
      editorGenre: 'All',
      editorSort: "Editor's order",
      open: {},
      filtersOpen: false
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

    // ------------------------------------------------------------ sections

    function profileSection() {
      const acc = ACCOUNTS[state.accIdx] || ACCOUNTS[0];
      return `
<section data-screen-label="App Profile">
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin:2px 0 18px">
    ${ACCOUNTS.map((a, i) => `<button style="${subBtn(i === state.accIdx)}" data-act="acct" data-val="${i}">${esc(a.label)}</button>`).join('')}
  </div>
  <div style="background:#fff;border:1px solid rgba(11,21,51,.08);border-radius:20px;overflow:hidden;box-shadow:0 1px 2px rgba(11,21,51,.04),0 16px 40px -30px rgba(11,21,51,.35)">
    <div style="background:linear-gradient(115deg,#0B1533 25%,#16327E 72%,#2E5BE6 118%);padding:clamp(22px,3.5vw,32px);color:#fff;display:flex;flex-wrap:wrap;gap:12px 24px;align-items:flex-end;justify-content:space-between">
      <div style="min-width:240px;flex:1 1 300px">
        <h1 style="margin:0 0 6px;font-size:clamp(21px,3vw,27px);font-weight:800;letter-spacing:-0.015em">Your AfriStream App Profile Details</h1>
        <p style="margin:0;font-size:13.5px;line-height:1.55;color:rgba(255,255,255,.72);max-width:560px">These will be used when accessing any AfriStream platforms or content. They cannot be edited.</p>
      </div>
      <div style="font-size:12px;font-weight:700;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22);padding:6px 14px;border-radius:999px;flex:none">${esc(acc.label)} of ${ACCOUNTS.length}</div>
    </div>
    <div style="padding:clamp(20px,3.5vw,30px);display:flex;flex-direction:column;gap:22px">
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
      <div style="background:#EEF3FE;border:1px solid rgba(46,91,230,.18);border-radius:13px;padding:14px 17px;font-size:13px;line-height:1.6;color:rgba(11,21,51,.72)">Each profile works on one device at a time. Switch between your profiles using the tabs above. Need an extra profile for another screen? Email <a href="mailto:support@afristream.io">support@afristream.io</a>.</div>
    </div>
  </div>
</section>`;
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
    <div data-act="close-filters" style="position:fixed;inset:0;background:rgba(11,21,51,.45);z-index:60"></div>
    <div data-testid="filters-drawer" style="position:fixed;top:0;right:0;bottom:0;width:min(380px,92vw);background:#fff;z-index:61;box-shadow:-24px 0 60px -30px rgba(11,21,51,.5);display:flex;flex-direction:column">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid rgba(11,21,51,.08)">
        <div style="font-size:17px;font-weight:800">Filters</div>
        <button class="as-hover-chip" data-act="close-filters" style="background:#F2F3F7;border:none;border-radius:999px;width:32px;height:32px;cursor:pointer;font-size:14px;color:#0B1533;font-family:inherit">✕</button>
      </div>
      <div style="flex:1;overflow-y:auto;padding:20px 22px;display:flex;flex-direction:column;gap:24px">
        ${filterGroups.map((g) => `
          <div>
            <div style="font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:rgba(11,21,51,.5);margin-bottom:10px">${esc(g.label)}</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              ${g.options.map((o) => `<button style="${subBtn(String(state[g.key]) === String(o))}" data-act="filter" data-key="${g.key}" data-val="${esc(o)}">${esc(o)}</button>`).join('')}
            </div>
          </div>`).join('')}
      </div>
      <div style="display:flex;gap:10px;padding:16px 22px;border-top:1px solid rgba(11,21,51,.08)">
        <button class="as-hover-ghost" data-act="clear-filters" style="flex:1;background:#fff;border:1px solid rgba(11,21,51,.14);border-radius:12px;padding:12px;font-family:inherit;font-weight:700;font-size:13.5px;cursor:pointer;color:#0B1533">Clear all</button>
        <button class="as-hover-primary" data-act="close-filters" style="flex:1.4;background:#2E5BE6;color:#fff;border:none;border-radius:12px;padding:12px;font-family:inherit;font-weight:700;font-size:13.5px;cursor:pointer">${esc(applyLabel)}</button>
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
      const posterRows = [];
      if (!searching) {
        if (sub === 'All' || sub === 'Movies') posterRows.push({ h: 'Trending Movies', items: data.movies });
        if (sub === 'All' || sub === 'Series') posterRows.push({ h: 'Trending Series', items: data.series });
        if (sub === 'Documentaries') posterRows.push({ h: 'Documentaries', items: INDEX.filter((x) => /^(Docs|Documentary)$/.test(x.genre)) });
        if (sub === 'Kids') posterRows.push({ h: 'Kids & Family', items: INDEX.filter((x) => x.genre === 'Kids' || x.genre === 'Family') });
        if (sub === 'All' || sub === 'New This Week') posterRows.push({ h: 'New This Week', items: data.newWeek });
      }

      const filterCount = Object.keys(FILTER_DEFAULTS).filter((k) => state[k] !== FILTER_DEFAULTS[k]).length;
      const filterBtnLabel = filterCount ? `Filters · ${filterCount}` : 'Filters';
      const filterBtnStyle = `flex:none;cursor:pointer;font-family:inherit;font-size:13.5px;font-weight:700;padding:12px 20px;border-radius:13px;white-space:nowrap;transition:background .15s,color .15s;background:${filterCount ? '#0B1533' : '#fff'};color:${filterCount ? '#fff' : '#0B1533'};border:1px solid ${filterCount ? '#0B1533' : 'rgba(11,21,51,.12)'}`;

      const quickNav = ['All', 'Movies', 'Series', 'Sport', 'Documentaries', 'Kids', 'New This Week', 'Collections'];
      const showSport = !searching && (sub === 'All' || sub === 'Sport') && props.showSport;
      const showColl = !searching && (sub === 'All' || sub === 'Collections');

      return `
<section data-screen-label="What to Watch">
  <div style="margin:2px 2px 18px">
    <h1 style="margin:0 0 5px;font-size:clamp(21px,3vw,27px);font-weight:800;letter-spacing:-0.015em">What to Watch</h1>
    <p style="margin:0;font-size:13.5px;color:rgba(11,21,51,.58)">One place to see what's on across all your platforms — updated daily.</p>
  </div>

  <div style="display:flex;gap:10px;flex-wrap:wrap;margin:0 0 10px">
    <input value="${esc(state.query)}" data-act="query" placeholder="Search titles…" style="flex:1 1 220px;min-width:0;padding:12px 16px;border:1px solid rgba(11,21,51,.12);border-radius:13px;font-family:inherit;font-size:14px;background:#fff;color:#0B1533;outline-color:#2E5BE6">
    <button style="${filterBtnStyle}" data-act="open-filters">${esc(filterBtnLabel)}</button>
  </div>

  ${state.filtersOpen ? filtersDrawer(results, searching) : ''}

  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:26px">
    ${quickNav.map((l) => `<button style="${subBtn(l === sub)}" data-act="quicknav" data-val="${esc(l)}">${esc(l)}</button>`).join('')}
  </div>

  ${searching ? `
    <div style="display:flex;align-items:baseline;gap:14px;margin-bottom:14px">
      <div style="font-size:14px;font-weight:800">${results.length} result${results.length === 1 ? '' : 's'}</div>
      <button data-act="clear-filters" style="background:none;border:none;color:#2E5BE6;font-weight:700;font-size:13px;cursor:pointer;padding:0;font-family:inherit;text-decoration:underline">Clear search &amp; filters</button>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(146px,1fr));gap:16px">
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
            <div style="flex:none;width:236px;border-radius:14px;background:linear-gradient(150deg,#13264E,#0A142E);color:#fff;padding:15px 16px;display:flex;flex-direction:column;gap:8px;min-height:118px">
              <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
                <span style="font-size:10.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.55)">${esc(s.comp)}</span>
                ${s.live ? '<span style="display:flex;align-items:center;gap:5px;font-size:10px;font-weight:800;color:#FF5A6E"><span style="width:7px;height:7px;border-radius:50%;background:#FF5A6E;animation:asPulse 1.4s infinite"></span>LIVE</span>' : ''}
              </div>
              <div style="font-size:15.5px;font-weight:800;line-height:1.25">${esc(s.fx)}</div>
              <div style="margin-top:auto;display:flex;justify-content:space-between;gap:8px;font-size:11.5px;color:rgba(255,255,255,.65)"><span>${esc(s.time)}</span><span style="font-weight:700;color:rgba(255,255,255,.85)">${esc(s.ch)}</span></div>
            </div>`).join('')}
        </div>
      </div>
      <div style="margin-bottom:28px">
        <h2 style="margin:0 0 12px;font-size:17.5px;font-weight:800;letter-spacing:-0.01em">Live TV Channels</h2>
        <div data-dragscroll style="display:flex;gap:12px;overflow-x:auto;padding-bottom:12px">
          ${LIVE_TV.map((t) => `
            <div style="flex:none;width:158px;aspect-ratio:16/10;border-radius:13px;background:${t.bg};color:#fff;display:flex;flex-direction:column;justify-content:center;align-items:center;gap:5px;padding:10px;text-align:center">
              <div style="font-size:13.5px;font-weight:800;line-height:1.2">${esc(t.name)}</div>
              <div style="font-size:9.5px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.55)">${esc(t.tag)}</div>
            </div>`).join('')}
        </div>
      </div>` : ''}

    ${showColl ? `
      <div>
        <h2 style="margin:0 0 12px;font-size:17.5px;font-weight:800;letter-spacing:-0.01em">Collections</h2>
        <div data-dragscroll style="display:flex;gap:14px;overflow-x:auto;padding-bottom:12px">
          ${COLLECTIONS.map((c) => `
            <div style="flex:none;width:250px;border-radius:15px;background:${c.bg};color:#fff;padding:18px;display:flex;flex-direction:column;gap:6px;min-height:132px">
              <div style="font-size:17px;font-weight:800;letter-spacing:-0.01em">${esc(c.name)}</div>
              <div style="font-size:12.5px;line-height:1.45;color:rgba(255,255,255,.8)">${esc(c.desc)}</div>
              <div style="margin-top:auto;font-size:10.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.6)">${esc(c.count)}</div>
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
      <div class="as-editor-card">
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
      const EDITOR_DEFAULTS = { editorType: 'All', editorGenre: 'All', editorSort: "Editor's order" };
      const filtering = Object.keys(EDITOR_DEFAULTS).some((k) => state[k] !== EDITOR_DEFAULTS[k]);

      // Chip options derived from the picks themselves (only what's present).
      const typeOpts = ['All', ...[...new Set(picks.map((p) => p.type).filter(Boolean))]];
      const genreOpts = ['All', ...[...new Set(picks.map((p) => p.genre).filter(Boolean))].sort()];
      const sortOpts = ["Editor's order", 'Top Rated', 'A–Z', 'Newest'];

      const rate = (x) => { const m = String(x.platform).match(/([\d.]+)/); return m ? +m[1] : -1; };
      const yr = (x) => { const m = String(x.meta).match(/\d{4}/); return m ? +m[0] : -1; };

      let list = picks.filter((p) =>
        (state.editorType === 'All' || p.type === state.editorType) &&
        (state.editorGenre === 'All' || p.genre === state.editorGenre)
      );
      if (state.editorSort === 'Top Rated') list = [...list].sort((a, b) => rate(b) - rate(a));
      else if (state.editorSort === 'A–Z') list = [...list].sort((a, b) => a.t.localeCompare(b.t));
      else if (state.editorSort === 'Newest') list = [...list].sort((a, b) => yr(b) - yr(a));
      else list = [...list].sort((a, b) => (a.rank || 0) - (b.rank || 0));

      const hero = filtering ? null : list[0];
      const grid = hero ? list.slice(1) : list;

      const heroArt = (m) => m.poster
        ? `<img src="${esc(m.poster)}" alt="${esc(m.t)}" loading="lazy" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover">`
        : `<div style="position:absolute;top:-30px;right:-6px;font-size:200px;font-weight:800;color:rgba(255,255,255,.10);line-height:1;user-select:none">${esc(m.initial)}</div>`;

      const chipRow = (label, key, options) => options.length <= 1 ? '' : `
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <span style="flex:none;font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(11,21,51,.45);min-width:52px">${esc(label)}</span>
      ${options.map((o) => `<button style="${subBtn(String(state[key]) === String(o))}" data-act="editor-filter" data-key="${key}" data-val="${esc(o)}">${esc(o)}</button>`).join('')}
    </div>`;

      return `
<section data-screen-label="Editor Picks">
  <div style="margin:2px 2px 18px">
    <h1 style="margin:0 0 5px;font-size:clamp(21px,3vw,27px);font-weight:800;letter-spacing:-0.015em">Editor Picks</h1>
    <p style="margin:0;font-size:13.5px;color:rgba(11,21,51,.58)">Curated by the AfriStream editors — a hand-picked watchlist, refreshed regularly.</p>
  </div>
  ${picks.length ? `
  <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:24px">
    ${chipRow('Type', 'editorType', typeOpts)}
    ${chipRow('Genre', 'editorGenre', genreOpts)}
    ${chipRow('Sort', 'editorSort', sortOpts)}
    ${filtering ? `<div><button data-act="clear-editor-filters" style="background:none;border:none;color:#2E5BE6;font-weight:700;font-size:13px;cursor:pointer;padding:2px 0;font-family:inherit;text-decoration:underline">Reset filters</button></div>` : ''}
  </div>` : ''}
  ${hero ? `
  <div style="position:relative;border-radius:22px;overflow:hidden;background:linear-gradient(120deg,#0B1533 20%,#16327E 80%);color:#fff;min-height:280px;display:flex;align-items:flex-end;margin-bottom:26px;box-shadow:0 24px 60px -34px rgba(11,21,51,.7)">
    <div style="position:absolute;inset:0">${heroArt(hero)}<div style="position:absolute;inset:0;background:linear-gradient(90deg,rgba(5,9,24,.86) 0%,rgba(5,9,24,.55) 46%,rgba(5,9,24,.2) 100%)"></div></div>
    <div style="position:relative;padding:clamp(22px,4vw,40px);max-width:620px;display:flex;flex-direction:column;gap:12px">
      <span style="align-self:flex-start;display:inline-flex;align-items:center;gap:7px;font-size:10.5px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:#F4C56B">★ Editors' No.1</span>
      <div style="font-size:clamp(26px,4.5vw,40px);font-weight:800;letter-spacing:-0.02em;line-height:1.05">${esc(hero.t)}</div>
      <div style="font-size:13px;color:rgba(255,255,255,.75);display:flex;gap:8px;flex-wrap:wrap"><span style="font-weight:700">${esc(hero.genre)}</span><span>·</span><span>${esc(hero.meta)}</span>${hero.country ? `<span>·</span><span>${esc(hero.country)}</span>` : ''}<span>·</span><span>${esc(hero.platform)}</span></div>
    </div>
  </div>` : ''}
  ${grid.length ? `
  ${hero ? `<h2 style="margin:0 0 12px 2px;font-size:17.5px;font-weight:800;letter-spacing:-0.01em">More from the list</h2>` : ''}
  <div data-testid="editor-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:18px 16px">
    ${grid.map(editorCard).join('')}
  </div>` : ''}
  ${picks.length && !grid.length && !hero ? `<div style="background:#fff;border:1px dashed rgba(11,21,51,.18);border-radius:15px;padding:32px;text-align:center;font-size:14px;color:rgba(11,21,51,.6)">No picks match these filters. <button data-act="clear-editor-filters" style="background:none;border:none;color:#2E5BE6;font-weight:700;font-size:14px;cursor:pointer;padding:0;font-family:inherit;text-decoration:underline">Reset filters</button></div>` : ''}
  ${!picks.length ? `<div style="background:#fff;border:1px dashed rgba(11,21,51,.18);border-radius:15px;padding:32px;text-align:center;font-size:14px;color:rgba(11,21,51,.6)">The editors' list is refreshing — check back shortly.</div>` : ''}
  ${editorSource === 'imdb' ? tmdbAttribution() : ''}
</section>`;
    }

    function tipsSection() {
      return `
<section data-screen-label="Tips and Tricks">
  <div style="margin:2px 2px 18px">
    <h1 style="margin:0 0 5px;font-size:clamp(21px,3vw,27px);font-weight:800;letter-spacing:-0.015em">Tips &amp; Tricks</h1>
    <p style="margin:0;font-size:13.5px;color:rgba(11,21,51,.58)">Small habits that make every screen in the house run smoother.</p>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px">
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
  <div style="margin-top:18px;background:linear-gradient(120deg,#0B1533,#16327E);border-radius:18px;padding:20px 22px;display:flex;align-items:center;gap:14px 20px;flex-wrap:wrap;color:#fff">
    <div style="flex:1 1 300px">
      <div style="font-size:15.5px;font-weight:800;margin-bottom:3px">Something not working?</div>
      <div style="font-size:13px;line-height:1.55;color:rgba(255,255,255,.68)">Start with the Quick Fixes — they solve most playback and sign-in issues in under five minutes.</div>
    </div>
    <button class="as-hover-light" data-act="go-help" style="flex:none;background:#fff;color:#0B1533;border:none;border-radius:12px;padding:12px 22px;font-family:inherit;font-weight:700;font-size:13.5px;cursor:pointer">Open Troubleshooting</button>
  </div>
</section>`;
    }

    function helpSection() {
      const items = GUIDES[state.helpTab] || [];
      return `
<section data-screen-label="Troubleshooting">
  <div style="margin:2px 2px 18px">
    <h1 style="margin:0 0 5px;font-size:clamp(21px,3vw,27px);font-weight:800;letter-spacing:-0.015em">AfriStream Troubleshooting Guide</h1>
    <p style="margin:0;font-size:13.5px;color:rgba(11,21,51,.58)">${esc(HELP_INTROS[state.helpTab] || '')}</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
    ${Object.keys(GUIDES).map((l) => `<button style="${subBtn(l === state.helpTab)}" data-act="helptab" data-val="${esc(l)}">${esc(l)}</button>`).join('')}
  </div>
  <div style="display:flex;flex-direction:column;gap:10px">
    ${items.map((g, i) => {
      const open = (state.open[state.helpTab] ?? 0) === i;
      return `
      <div style="background:#fff;border:1px solid rgba(11,21,51,.09);border-radius:15px;overflow:hidden;box-shadow:0 1px 2px rgba(11,21,51,.03)">
        <button data-act="toggle-guide" data-val="${i}" style="display:flex;align-items:center;gap:12px;width:100%;background:none;border:none;padding:16px 18px;cursor:pointer;text-align:left;font-family:inherit">
          <span style="${badgeStyle(g.badge)}">${esc(g.badge)}</span>
          <span style="flex:1;font-size:15px;font-weight:700;color:#0B1533">${esc(g.title)}</span>
          <span style="flex:none;transition:transform .2s;transform:rotate(${open ? 180 : 0}deg);font-size:13px;color:rgba(11,21,51,.5)">▾</span>
        </button>
        ${open ? `<div style="padding:13px 18px 18px;font-size:14px;line-height:1.65;color:rgba(11,21,51,.75);white-space:pre-line;border-top:1px solid rgba(11,21,51,.06)">${esc(g.body)}</div>` : ''}
      </div>`;
    }).join('')}
  </div>
  <div style="margin-top:18px;background:#fff;border:1px dashed rgba(11,21,51,.18);border-radius:15px;padding:16px 18px;font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.7)">Still stuck? Email <a href="mailto:support@afristream.io">support@afristream.io</a> with your device type and a short description — we reply within one business day.</div>
</section>`;
    }

    // -------------------------------------------------------------- render

    const NAV = [
      { id: 'profile', label: 'Profile' },
      { id: 'watch', label: 'What to Watch' },
      { id: 'editor', label: 'Editor Picks' },
      { id: 'tips', label: 'Tips & Tricks' },
      { id: 'help', label: 'Troubleshooting' }
    ];
    const SECTIONS = { profile: profileSection, watch: watchSection, editor: editorSection, tips: tipsSection, help: helpSection };

    function render(preserveFocus) {
      let caret = 0;
      let hadFocus = false;
      const active = document.activeElement;
      if (preserveFocus && active && active.getAttribute && active.getAttribute('data-act') === 'query') {
        hadFocus = true;
        caret = active.selectionStart;
      }

      root.innerHTML = `
<div style="min-height:100vh;display:flex;flex-direction:column">
<header style="position:sticky;top:0;z-index:40;background:linear-gradient(165deg,#0B1533 40%,#12224F);box-shadow:0 10px 30px -18px rgba(11,21,51,.55)">
  <nav style="max-width:1180px;margin:0 auto;padding:0 clamp(16px,3vw,32px);display:flex;align-items:stretch;gap:26px;overflow-x:auto">
    ${NAV.map((n) => `<button style="${navBtn(n.id === state.section)}" data-act="nav" data-val="${n.id}">${esc(n.label)}</button>`).join('')}
    <div style="margin-left:auto;align-self:center;display:flex;align-items:center;gap:8px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);border-radius:999px;padding:6px 14px;font-size:12px;font-weight:700;color:#fff;flex:none">
      <span style="width:7px;height:7px;border-radius:50%;background:#3DD68C;flex:none"></span>Annual · Active
    </div>
  </nav>
</header>
<main style="flex:1;width:100%;max-width:1180px;margin:0 auto;padding:clamp(22px,3.5vw,34px) clamp(16px,3vw,32px) 76px">
${(SECTIONS[state.section] || profileSection)()}
</main>
<footer style="border-top:1px solid rgba(11,21,51,.08);padding:20px clamp(16px,3vw,32px);text-align:center;font-size:12px;color:rgba(11,21,51,.5)">Need help? <a href="mailto:support@afristream.io">support@afristream.io</a> · © 2026 AfriStream</footer>
</div>`;

      if (hadFocus) {
        const input = root.querySelector('[data-act="query"]');
        if (input) {
          input.focus();
          try { input.setSelectionRange(caret, caret); } catch (e) { /* type=search quirk — ignore */ }
        }
      }
    }

    // -------------------------------------------------------------- events

    root.addEventListener('click', (e) => {
      const el = e.target.closest('[data-act]');
      if (!el || !root.contains(el)) return;
      const val = el.getAttribute('data-val');
      switch (el.getAttribute('data-act')) {
        case 'nav': setState({ section: val }); break;
        case 'acct': setState({ accIdx: +val, copied: '' }); break;
        case 'copy-user': copy((ACCOUNTS[state.accIdx] || ACCOUNTS[0]).user, 'user'); break;
        case 'copy-pass': copy((ACCOUNTS[state.accIdx] || ACCOUNTS[0]).pass, 'pass'); break;
        case 'quicknav': setState({ subWatch: val }); break;
        case 'open-filters': setState({ filtersOpen: true }); break;
        case 'close-filters': setState({ filtersOpen: false }); break;
        case 'clear-filters': setState({ query: '', type: 'All Types', genre: 'All Genres', country: 'All Countries', decade: 'All Decades', sort: 'Recommended' }); break;
        case 'filter': setState({ [el.getAttribute('data-key')]: val }); break;
        case 'editor-filter': setState({ [el.getAttribute('data-key')]: val }); break;
        case 'clear-editor-filters': setState({ editorType: 'All', editorGenre: 'All', editorSort: "Editor's order" }); break;
        case 'helptab': setState({ helpTab: val }); break;
        case 'toggle-guide': {
          const i = +val;
          const isOpen = (state.open[state.helpTab] ?? 0) === i;
          setState({ open: { ...state.open, [state.helpTab]: isOpen ? -1 : i } });
          break;
        }
        case 'go-help': setState({ section: 'help' }); break;
      }
    });

    root.addEventListener('input', (e) => {
      if (e.target.getAttribute && e.target.getAttribute('data-act') === 'query') {
        state.query = e.target.value;
        render(true);
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
      fetch(props.editorEndpoint)
        .then((res) => (res.ok ? res.json() : null))
        .then((payload) => {
          if (!payload || payload.source === 'fallback') return;
          const picks = prepPicks(payload.picks);
          if (!picks.length) return;
          data.editorPicks = picks;
          editorSource = 'imdb';
          if (state.section === 'editor') render(true);
        })
        .catch(() => { /* endpoint unreachable — built-in picks stay */ });
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
