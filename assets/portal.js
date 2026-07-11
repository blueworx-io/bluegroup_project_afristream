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
      restNonce: root.getAttribute('data-rest-nonce') || ''
    };
    // Profile credentials. Without a credentials endpoint (e.g. the generic
    // local preview) the built-in demo ACCOUNTS are shown. With one (the
    // WordPress shortcode), they're fetched for the logged-in user: 'loading'
    // until the fetch resolves, then 'ready' (real profiles), 'empty' (no
    // license assigned) or 'error' (fetch failed).
    let accounts = ACCOUNTS.map((a) => ({ ...a }));
    let credState = props.credentialsEndpoint ? 'loading' : 'demo';
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
      guideOpen: 0,
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

    // ------------------------------------------------------------ sections

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
        <button class="as-hover-chip" data-act="close-filters" style="background:#F2F3F7;border:none;border-radius:999px;width:32px;height:32px;cursor:pointer;font-size:14px;color:#65009F;font-family:inherit">✕</button>
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
      const filterBtnStyle = `flex:none;cursor:pointer;font-family:inherit;font-size:13.5px;font-weight:700;padding:12px 20px;border-radius:13px;white-space:nowrap;transition:background .15s,color .15s;background:${filterCount ? '#65009F' : '#fff'};color:${filterCount ? '#fff' : '#65009F'};border:1px solid ${filterCount ? '#65009F' : 'rgba(11,21,51,.12)'}`;

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
            <div ${cardAttrs({ detailKind: 'sport', t: s.fx, comp: s.comp, time: s.time, ch: s.ch, live: s.live, bg: 'linear-gradient(150deg,#4A0073,#2A0047)' })} style="cursor:pointer;flex:none;width:236px;border-radius:14px;background:linear-gradient(150deg,#4A0073,#2A0047);color:#fff;padding:15px 16px;display:flex;flex-direction:column;gap:8px;min-height:118px">
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
            <div ${cardAttrs({ detailKind: 'channel', t: t.name, tag: t.tag, bg: t.bg })} style="cursor:pointer;flex:none;width:158px;aspect-ratio:16/10;border-radius:13px;background:${t.bg};color:#fff;display:flex;flex-direction:column;justify-content:center;align-items:center;gap:5px;padding:10px;text-align:center">
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
            <div ${cardAttrs({ detailKind: 'collection', t: c.name, desc: c.desc, count: c.count, bg: c.bg })} style="cursor:pointer;flex:none;width:250px;border-radius:15px;background:${c.bg};color:#fff;padding:18px;display:flex;flex-direction:column;gap:6px;min-height:132px">
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
    ${filtering ? `<div><button data-act="clear-editor-filters" style="background:none;border:none;color:#65009F;font-weight:700;font-size:13px;cursor:pointer;padding:2px 0;font-family:inherit;text-decoration:underline">Reset filters</button></div>` : ''}
  </div>` : ''}
  ${hero ? `
  <div ${cardAttrs(hero)} style="cursor:pointer;position:relative;border-radius:22px;overflow:hidden;background:linear-gradient(120deg,#65009F 20%,#CD2DF5 80%);color:#fff;min-height:280px;display:flex;align-items:flex-end;margin-bottom:26px;box-shadow:0 24px 60px -34px rgba(11,21,51,.7)">
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
  ${picks.length && !grid.length && !hero ? `<div style="background:#fff;border:1px dashed rgba(11,21,51,.18);border-radius:15px;padding:32px;text-align:center;font-size:14px;color:rgba(11,21,51,.6)">No picks match these filters. <button data-act="clear-editor-filters" style="background:none;border:none;color:#65009F;font-weight:700;font-size:14px;cursor:pointer;padding:0;font-family:inherit;text-decoration:underline">Reset filters</button></div>` : ''}
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
  <div style="margin-top:18px;background:linear-gradient(120deg,#65009F,#CD2DF5);border-radius:18px;padding:20px 22px;display:flex;align-items:center;gap:14px 20px;flex-wrap:wrap;color:#fff">
    <div style="flex:1 1 300px">
      <div style="font-size:15.5px;font-weight:800;margin-bottom:3px">Something not working?</div>
      <div style="font-size:13px;line-height:1.55;color:rgba(255,255,255,.68)">Start with the Quick Fixes — they solve most playback and sign-in issues in under five minutes.</div>
    </div>
    <button class="as-hover-light" data-act="go-help" style="flex:none;background:#fff;color:#65009F;border:none;border-radius:12px;padding:12px 22px;font-family:inherit;font-weight:700;font-size:13.5px;cursor:pointer">Open Troubleshooting</button>
  </div>
</section>`;
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

    // -------------------------------------------------------------- render

    const NAV = [
      { id: 'profile', label: 'Profile' },
      { id: 'watch', label: 'What to Watch' },
      { id: 'editor', label: 'Editor Picks' },
      { id: 'tips', label: 'Tips & Tricks' },
      { id: 'help', label: 'Troubleshooting' }
    ];
    const SECTIONS = { profile: profileSection, watch: watchSection, editor: editorSection, tips: tipsSection, help: helpSection };

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
        const rows = [['Competition', obj.comp], ['When', obj.time], ['Channel', obj.ch], obj.live ? ['Status', 'LIVE now'] : null].filter(Boolean);
        body = `<div style="display:flex;flex-direction:column;gap:12px">${rows.map(([k, v]) => `<div style="display:flex;justify-content:space-between;gap:16px;font-size:14px"><span style="color:rgba(11,21,51,.55);font-weight:700">${esc(k)}</span><span style="color:#65009F;text-align:right">${esc(v)}</span></div>`).join('')}</div>`;
      } else if (obj.detailKind === 'channel') {
        body = `<div style="font-size:14px;line-height:1.65;color:rgba(11,21,51,.75)"><span style="font-weight:700">${esc(obj.tag)}</span> · Live channel</div>`;
      } else if (obj.detailKind === 'collection') {
        body = `<div style="display:flex;flex-direction:column;gap:10px"><div style="font-size:12px;font-weight:700;color:rgba(11,21,51,.55)">${esc(obj.count || '')}</div><div style="font-size:14px;line-height:1.65;color:rgba(11,21,51,.75)">${esc(obj.desc || '')}</div></div>`;
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
    <div data-act="close-detail" style="position:fixed;inset:0;background:rgba(11,21,51,.5);z-index:70"></div>
    <div data-testid="detail-drawer" role="dialog" aria-modal="true" aria-label="${esc(obj.t)} details" style="position:fixed;top:0;right:0;bottom:0;width:min(420px,94vw);background:#fff;z-index:71;box-shadow:-24px 0 60px -30px rgba(11,21,51,.5);display:flex;flex-direction:column;overflow-y:auto">
      <div style="position:relative;min-height:220px;background:${obj.bg || '#65009F'};color:#fff;display:flex;align-items:flex-end;padding:18px">
        ${art}
        <button data-act="close-detail" aria-label="Close details" style="position:absolute;top:14px;right:14px;background:rgba(5,9,24,.55);border:none;border-radius:999px;width:34px;height:34px;cursor:pointer;font-size:15px;color:#fff;font-family:inherit;z-index:1">✕</button>
        <div style="position:relative;font-size:22px;font-weight:800;line-height:1.15;text-shadow:0 1px 8px rgba(0,0,0,.5)">${esc(obj.t)}</div>
      </div>
      <div style="padding:20px 22px;display:flex;flex-direction:column;gap:16px">
        ${body}
      </div>
    </div>`;
    }

    function render(preserveFocus) {
      cardRegistry = [];
      let caret = 0;
      let hadFocus = false;
      const active = document.activeElement;
      if (preserveFocus && active && active.getAttribute && active.getAttribute('data-act') === 'query') {
        hadFocus = true;
        caret = active.selectionStart;
      }

      root.innerHTML = `
<div style="min-height:100vh;display:flex;flex-direction:column">
<header style="position:sticky;top:0;z-index:40;background:linear-gradient(165deg,#65009F 40%,#4A0073);box-shadow:0 10px 30px -18px rgba(11,21,51,.55)">
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
${state.detail ? detailDrawer(state.detail) : ''}
</div>`;

      if (hadFocus) {
        const input = root.querySelector('[data-act="query"]');
        if (input) {
          input.focus();
          try { input.setSelectionRange(caret, caret); } catch (e) { /* type=search quirk — ignore */ }
        }
      }

      if (detailFocusPending) {
        detailFocusPending = false;
        const closeBtn = root.querySelector('[data-testid="detail-drawer"] [aria-label="Close details"]');
        if (closeBtn) closeBtn.focus();
      }

      // Scroll-lock the page while the modal panel is open; restore the
      // original body overflow on close (so we don't clobber a host value).
      const body = root.ownerDocument && root.ownerDocument.body;
      if (body) {
        if (state.detail && prevBodyOverflow === null) {
          prevBodyOverflow = body.style.overflow;
          body.style.overflow = 'hidden';
        } else if (!state.detail && prevBodyOverflow !== null) {
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
        case 'nav': setState({ section: val }); break;
        case 'acct': setState({ accIdx: +val, copied: '' }); break;
        case 'copy-user': copy((accounts[state.accIdx] || accounts[0] || {}).user || '', 'user'); break;
        case 'copy-pass': copy((accounts[state.accIdx] || accounts[0] || {}).pass || '', 'pass'); break;
        case 'quicknav': setState({ subWatch: val }); break;
        case 'open-filters': setState({ filtersOpen: true }); break;
        case 'close-filters': setState({ filtersOpen: false }); break;
        case 'clear-filters': setState({ query: '', type: 'All Types', genre: 'All Genres', country: 'All Countries', decade: 'All Decades', sort: 'Recommended' }); break;
        case 'filter': setState({ [el.getAttribute('data-key')]: val }); break;
        case 'editor-filter': setState({ [el.getAttribute('data-key')]: val }); break;
        case 'clear-editor-filters': setState({ editorType: 'All', editorGenre: 'All', editorSort: "Editor's order" }); break;
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
      if (e.target.getAttribute && e.target.getAttribute('data-act') === 'query') {
        state.query = e.target.value;
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
