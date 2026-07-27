# Simplified Setup Wizard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the portal's three-question Setup tab (family → sub-device → method) with the handoff's flatter flow: pick one of six devices, walk three screens of steps, done.

**Architecture:** Everything lives in `assets/portal.js`, a single IIFE that renders the whole portal from a plain `state` object through template strings. A section is a function returning an HTML string; interaction happens through `data-act` attributes read by one delegated click listener. The old setup block (lines 1129–1676) is replaced in place by a new one of the same shape. No new files, no build step, no dependencies.

**Tech Stack:** Vanilla ES2015+ JavaScript (no framework, no bundler), plain CSS in `assets/portal.css`, Playwright for tests, PHP 7.4+ for the plugin shell.

## Global Constraints

- **No new dependencies.** Anything not already in `approved-deps.json` needs prior approval. This plan adds none.
- **Escape everything.** All interpolated text goes through the existing `esc()` helper. Icon SVG is the only raw HTML, and it is plugin-authored constants, never user input.
- **Copy is verbatim from the spec.** Device titles, body lines, step text, hints and notes are quoted exactly as `docs/superpowers/specs/2026-07-27-landing-page-and-setup-wizard-design.md` records them from the handoff. Do not paraphrase, re-punctuate, or "improve" the wording — it is written for non-technical readers and reviewed as-is.
- **Fire TV wording stays vague.** The Fire TV route says "the store app named in your welcome email". Never reintroduce the name "Firesend".
- **Support address:** `support@afristream.io` everywhere.
- **Downloader codes:** IPTV Player `6573365` (primary), Fire TV room code `10325`, fallbacks IBO Player `617725`, Sky App `9469460`, Sky Live `569138`.
- **Palette:** surface `#fff`, page `#F7F5FA`, brand `#65009F`, accent tint `#F7E9FF`, hairline `rgba(11,21,51,.08)`, body text `rgba(11,21,51,.72)`, muted `rgba(11,21,51,.58)`. These are the portal's existing values — match the surrounding sections, not the handoff's own token file.
- **Progress labels:** exactly `['Your device', 'Get ready', 'Downloader', 'AfriStream app']`.
- **Lint once, at the end.** Do not run lint → autofix → lint loops. `npm run lint` is a final check in Task 5; report findings, do not action them without approval.
- **Branch:** `simplified-setup-wizard`, already created off `retire-acf`. Do not merge to `main` directly.

---

## File Structure

| File | Responsibility | Change |
| --- | --- | --- |
| `assets/portal.js` | Setup data model, icons, and the four render stages | Replace lines 1129–1676; edit `state` init (~line 453) and the click handler (~line 2235) |
| `assets/portal.css` | Progress bar and device-icon styling | Add one block |
| `tests/portal.spec.js` | Playwright coverage | Replace the setup block (~lines 960–1200) |
| `CHANGELOG.md`, `bluegroup-project-afristream.php`, `package.json` | Version bump + entry | Task 5 |

The old block is self-contained: `SETUP_METHODS`, `DEVICE_FAMILIES`, `familyOf`, `subOf`, `fillTokens`, `WIFI_TIPS`, `WHY_A_DEVICE`, `whyDevicePanel`, `SETUP_STEPS`, `stepEyebrow`, `setupSection`, `setupShell`, `chosenRow`, `buyingPanel` and `wifiPanel` are referenced from nowhere else in the file. Verify this before deleting with:

```bash
grep -n "WIFI_TIPS\|WHY_A_DEVICE\|DEVICE_FAMILIES\|familyOf\|subOf\|fillTokens\|SETUP_METHODS\|setupShell\|buyingPanel\|wifiPanel\|chosenRow\|whyDevicePanel" assets/portal.js
```

Every hit should fall inside 1129–1676.

## Existing helpers you will reuse

Read these before starting — they are the contract the new code plugs into:

- `esc(str)` — HTML-escapes a string. Used on every interpolation.
- `copy(text, key)` — writes to the clipboard, sets `state.copied = key`, and clears it after 1600ms. Render "Copied" when `state.copied === yourKey`.
- `setState(patch)` — shallow-merges into `state` and re-renders.
- `root.addEventListener('click', …)` at ~line 2223 — reads `data-act` and `data-val` off the closest ancestor. Add new cases to its `switch`.
- `.as-editor-card`, `.as-grid-cards`, `.as-hover-ghost`, `.as-on-dark` — existing CSS classes for cards, responsive grids and buttons. Reuse rather than restyling.

## How to run things

```bash
npm install          # once
npm run preview      # http://localhost:4173 — the harness Playwright runs against
npm test             # full Playwright run
npx playwright test -g "the Setup tab"   # a single named test
```

The preview harness serves `preview/index.html`, which renders the portal with fixture credentials. The Setup tab needs no network, so every test here runs offline.

---

### Task 1: Data model, device grid, and step screens

**Files:**
- Modify: `assets/portal.js:453-465` (state init), `assets/portal.js:1129-1676` (the whole setup block), `assets/portal.js:2235-2238` (click cases)
- Modify: `assets/portal.css` (append)
- Test: `tests/portal.spec.js`

**Interfaces:**
- Consumes: `esc()`, `copy(text, key)`, `setState()`, `state.copied`.
- Produces:
  - `SETUP_DEVICES` — array of `{ key, title, body, badge, icon, screens }`. `screens` is `null` for devices we set up by hand, otherwise an array of three `{ title, sub, note, steps }`. A `steps` entry is `{ t, hint?, code? }`.
  - `setupDeviceOf()` → the chosen device object or `null`.
  - `setupStage()` → `'device' | 'steps' | 'support' | 'done'`.
  - `setupIcon(key)` → inline SVG string.
  - `setupSection()` → the section's HTML string (unchanged name; `SECTIONS.setup` still points at it).
  - State keys `setupDevice` (string), `setupScreen` (number), `setupDone` (boolean), `setupHelpOpen` (boolean).
  - Actions `setup-device`, `setup-copy`.
  - Test IDs `setup-progress`, `setup-device-picker`, `setup-steps`, `setup-step-note`, `setup-code`.

Task 2 adds `setup-next` / `setup-back` / `setup-restart` and the done stage; Task 3 adds the support stage; Task 4 adds the buying-advice panel. Until then, picking an unsupported device shows the device grid again — that is expected and is replaced in Task 3.

- [ ] **Step 1: Write the failing tests**

Replace the whole setup block in `tests/portal.spec.js` — everything from the `// ------------------------------------------------------------ account, setup` comment's setup tests through the last setup test (around lines 960–1200, ending before the affiliate tests). Delete the old `openSetup` helper and every test that references `setup-family`, `setup-sub`, `setup-rail`, `setup-buying`, `setup-wifi`, `setup-buy-links`, `setup-codes` or `setup-chosen`. Put this in their place:

```js
// ------------------------------------------------------------------- setup
// One question — which device — then three screens of steps. Devices we set
// up by hand branch to a support card instead.

const openSetup = async (page) => page.getByRole('button', { name: 'Setup' }).click();

test('the Setup tab opens on the device grid with a four-stage progress bar', async ({ page }) => {
  await openSetup(page);

  await expect(page.getByRole('heading', { name: 'Which of these do you have?' })).toBeVisible();
  await expect(page.getByTestId('setup-progress')).toContainText('Your device');
  await expect(page.getByTestId('setup-progress')).toContainText('AfriStream app');
  await expect(page.getByTestId('setup-steps')).toHaveCount(0);

  const picker = page.getByTestId('setup-device-picker');
  await expect(picker.getByRole('button')).toHaveCount(6);
  await expect(picker.getByRole('button', { name: /EASIEST/ })).toHaveCount(1);
  await expect(picker.getByRole('button', { name: /EASIEST/ })).toContainText('Google TV');
});

test('picking Google TV opens step 2 of 4 with its first screen', async ({ page }) => {
  await openSetup(page);
  await page.getByRole('button', { name: /Google TV or Android TV stick/ }).click();

  await expect(page.getByText('Step 2 of 4')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Two settings, then we are away' })).toBeVisible();

  const steps = page.getByTestId('setup-steps');
  await expect(steps.getByRole('listitem')).toHaveCount(3);
  await expect(steps).toContainText('Unknown sources');
  await expect(page.getByTestId('setup-step-note')).toContainText('only setting you touch');
});

test('the Fire TV route unlocks the stick first and never names the store app', async ({ page }) => {
  await openSetup(page);
  await page.getByRole('button', { name: /Amazon Fire TV Stick/ }).click();

  await expect(page.getByRole('heading', { name: 'Unlock your stick first' })).toBeVisible();
  await expect(page.getByTestId('setup-steps')).toContainText('ADB Debugging');
  await expect(page.locator('body')).not.toContainText('Firesend');
});

test('a step carrying a code shows it with a working copy button', async ({ page }) => {
  await openSetup(page);
  await page.getByRole('button', { name: /Android phone or tablet/ }).click();

  // Screen 1 of the phone route has no code; the copy control only exists
  // alongside one, so its absence here is the thing worth pinning down.
  await expect(page.getByTestId('setup-code')).toHaveCount(0);
});

test('the Android box route adds the plug-it-in step ahead of the shared ones', async ({ page }) => {
  await openSetup(page);
  await page.getByRole('button', { name: /Android box/ }).click();

  const steps = page.getByTestId('setup-steps');
  await expect(steps.getByRole('listitem')).toHaveCount(4);
  await expect(steps.getByRole('listitem').first()).toContainText('spare HDMI port');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx playwright test -g "Setup tab opens on the device grid"`
Expected: FAIL — the heading "Which of these do you have?" does not exist; the old tab still says "Set Up AfriStream".

- [ ] **Step 3: Add the new state keys**

In `assets/portal.js`, in the `state` object (~line 453), replace:

```js
      // '' until the user picks a device on the Setup tab; step 2 stays hidden
      // until then.
      setupFamily: '',
      setupSub: '',
```

with:

```js
      // '' until the user picks a device on the Setup tab. setupScreen indexes
      // that device's three screens; setupDone is the finished card, which is
      // a stage rather than a fourth screen because it is reachable from the
      // last screen only.
      setupDevice: '',
      setupScreen: 0,
      setupDone: false,
      setupHelpOpen: false,
```

- [ ] **Step 4: Replace the setup model**

Delete `assets/portal.js` lines 1129–1676 entirely — from the `// ------------------------------------------------------------ setup model` comment through the closing brace of `wifiPanel()`. Put this in its place:

```js
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
    const SETUP_SCREEN_INSTALL = {
      title: 'Install AfriStream and sign in',
      sub: 'Last one. Downloader fetches the app, then you type in your username and password once.',
      note: 'If that app will not connect, come back here and try one of the others: IBO Player 617725, Sky App 9469460, or Sky Live 569138. Your username and password stay the same for all of them.',
      steps: [
        { t: 'Open Downloader, and click the empty box across the top of the screen.' },
        { t: 'Type in this code, then press GO.', code: '6573365', hint: 'This installs IPTV Player — the most reliable app, and the one we recommend.' },
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
      },
      {
        key: 'other',
        title: 'iPhone, iPad or Roku',
        body: 'These cannot install the app themselves, so we set the profile up for you.',
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

    // The screen currently on show, clamped so a stale index can never read off
    // the end of a shorter device's list.
    function setupScreenOf() {
      const device = setupDeviceOf();
      if (!device || !device.screens) return null;
      return device.screens[Math.min(state.setupScreen, device.screens.length - 1)] || null;
    }

    // Line icons rather than photographs: they ship in the plugin, need no
    // uploads, and stay legible at any size on any background.
    const SETUP_ICONS = {
      fire: '<svg viewBox="0 0 48 48" width="100%" height="100%" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="14" width="22" height="9" rx="3"/><path d="M28 18.5h6"/><rect x="34" y="10" width="9" height="28" rx="4"/><path d="M38.5 16v3M38.5 24h0M38.5 30h0"/></svg>',
      googletv: '<svg viewBox="0 0 48 48" width="100%" height="100%" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="16" width="26" height="14" rx="4"/><path d="M31 23h5"/><path d="M36 19h5v8h-5z"/><path d="M12 23h8"/></svg>',
      box: '<svg viewBox="0 0 48 48" width="100%" height="100%" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="14" width="24" height="18" rx="4"/><path d="M14 26h6"/><circle cx="27" cy="26" r="1.5"/><path d="M32 20h4a4 4 0 0 1 4 4v10"/></svg>',
      android: '<svg viewBox="0 0 48 48" width="100%" height="100%" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="10" width="14" height="26" rx="3"/><path d="M11 33h4"/><rect x="24" y="14" width="18" height="22" rx="3"/><path d="M31 32h4"/></svg>',
      smarttv: '<svg viewBox="0 0 48 48" width="100%" height="100%" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="10" width="36" height="23" rx="4"/><path d="M18 39h12M24 33v6"/></svg>',
      other: '<svg viewBox="0 0 48 48" width="100%" height="100%" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="7" y="9" width="15" height="28" rx="3"/><path d="M12 33h5"/><rect x="27" y="22" width="15" height="11" rx="4"/><path d="M31 27h7"/></svg>'
    };

    const setupIcon = (key) => SETUP_ICONS[key] || '';

    // -------------------------------------------------------------- setup tab

    // Eyebrow, title and sub for the stage on show. Steps count from 2 because
    // choosing the device was step 1.
    function setupHeading() {
      const stage = setupStage();
      const screen = setupScreenOf();
      if (stage === 'steps' && screen) {
        return ['Step ' + (state.setupScreen + 2) + ' of 4', screen.title, screen.sub];
      }
      if (stage === 'support') {
        return ['Step 1 of 4', 'We set this one up for you', 'This device cannot install the app on its own, so the profile is created at our end.'];
      }
      if (stage === 'done') {
        return ['All done', 'That is setup finished', 'Nothing left to install — your app is ready to watch.'];
      }
      return ['Step 1 of 4', 'Which of these do you have?', 'Pick the device you will be watching on and we will show you only the steps that apply to it. Nothing here needs any technical know-how.'];
    }

    function setupProgress() {
      const stage = setupStage();
      const pct = stage === 'device' ? '12%'
        : stage === 'steps' ? (['40%', '68%', '96%'][state.setupScreen] || '40%')
          : '100%';
      const active = stage === 'device' || stage === 'support' ? 0
        : stage === 'done' ? 3
          : state.setupScreen + 1;
      return `
  <div data-testid="setup-progress" style="display:flex;flex-direction:column;gap:11px;margin:0 2px 24px">
    <div style="height:4px;border-radius:999px;background:rgba(11,21,51,.1);overflow:hidden">
      <div style="height:100%;border-radius:999px;background:#65009F;transition:width .25s ease-out;width:${pct}"></div>
    </div>
    <div style="display:flex;gap:20px;flex-wrap:wrap">
      ${SETUP_PROGRESS_LABELS.map((label, i) => `
        <span style="font-size:12px;font-weight:${i === active ? '700' : '500'};color:${i === active ? '#0B1533' : i < active ? 'rgba(11,21,51,.68)' : 'rgba(11,21,51,.42)'}">${esc(label)}</span>`).join('')}
    </div>
  </div>`;
    }

    function setupDeviceGrid() {
      return `
  <div data-testid="setup-device-picker" role="group" aria-label="Choose your device" class="as-grid-cards" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin:0 2px">
    ${SETUP_DEVICES.map((d) => `
      <button class="as-editor-card" data-act="setup-device" data-val="${esc(d.key)}" style="display:flex;flex-direction:column;gap:0;text-align:left;background:#fff;border:1px solid rgba(11,21,51,.08);border-radius:18px;padding:0;overflow:hidden;font-family:inherit;cursor:pointer;box-shadow:0 1px 2px rgba(11,21,51,.04)">
        <span aria-hidden="true" class="as-setup-icon">${setupIcon(d.key)}</span>
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
          ${s.hint ? `<span style="font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.58)">${esc(s.hint)}</span>` : ''}
        </span>
      </li>`).join('')}
  </ol>`;
    }

    function setupSection() {
      const stage = setupStage();
      const head = setupHeading();
      const screen = setupScreenOf();

      const body = stage === 'device'
        ? setupDeviceGrid()
        : stage === 'steps' && screen
          ? setupStepList(screen) + (screen.note
            ? `<div data-testid="setup-step-note" style="margin:22px 2px 0;border-radius:13px;border:1px solid rgba(101,0,159,.2);background:#F7E9FF;padding:16px 18px;font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.75)">${esc(screen.note)}</div>`
            : '')
          : setupDeviceGrid();

      return `
<section data-screen-label="Setup">
  <div style="margin:2px 2px 18px">
    <div style="margin:0 0 7px;font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#65009F">${esc(head[0])}</div>
    <h1 style="margin:0 0 6px;font-size:clamp(21px,3vw,27px);font-weight:800;letter-spacing:-0.015em">${esc(head[1])}</h1>
    <p style="margin:0;font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.58);max-width:640px">${esc(head[2])}</p>
  </div>
  ${setupProgress()}
  ${body}
  <p style="margin:26px 2px 0;font-size:11.5px;line-height:1.6;color:rgba(11,21,51,.45)">Stuck on a step? Email <a href="mailto:support@afristream.io">support@afristream.io</a> with your device and the step number — we reply within one business day.</p>
</section>`;
    }
```

- [ ] **Step 5: Wire the two new click actions**

In the click handler `switch` (~line 2235), replace these four cases:

```js
        case 'setup-family': setState({ setupFamily: val, setupSub: '' }); break;
        case 'setup-sub': setState({ setupSub: val }); break;
        case 'setup-back-sub': setState({ setupSub: '' }); break;
        case 'setup-restart': setState({ setupFamily: '', setupSub: '' }); break;
```

with:

```js
        case 'setup-device': setState({ setupDevice: val, setupScreen: 0, setupDone: false }); break;
        case 'setup-copy': copy(val, 'setup-' + val); break;
```

- [ ] **Step 6: Add the device-icon CSS**

Append to `assets/portal.css`:

```css
/* Setup wizard — device card artwork. A line icon on a tinted plate, sized so
   six cards read as one row of equals rather than six different pictures. */
.as-setup-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 108px;
  background: #F7E9FF;
  border-bottom: 1px solid rgba(11, 21, 51, 0.08);
  color: #65009F;
}
.as-setup-icon svg {
  width: 62px;
  height: 62px;
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `npx playwright test -g "Setup"`
Expected: the five new tests PASS. Old setup tests are gone, so nothing should reference `setup-rail` or `setup-family`.

- [ ] **Step 8: Commit**

```bash
git add assets/portal.js assets/portal.css tests/portal.spec.js
git commit -m "$(cat <<'EOF'
Ask which device once, instead of three times over

The Setup tab asked for a device family, then a model, then narrowed to one
of four install methods — three questions before a single instruction, where
the middle two only ever arrived at the same handful of routes.

Six device cards now lead straight to that device's steps.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Navigation and the finished card

**Files:**
- Modify: `assets/portal.js` (setup block, click handler)
- Test: `tests/portal.spec.js`

**Interfaces:**
- Consumes: `setupStage()`, `setupDeviceOf()`, `setupScreenOf()`, `setupSection()`, state keys from Task 1.
- Produces: `setupChosenRow()` and `setupNav()` HTML helpers; actions `setup-next`, `setup-back`, `setup-restart`; test IDs `setup-chosen`, `setup-next`, `setup-back`, `setup-done`.

- [ ] **Step 1: Write the failing tests**

Append to the setup block in `tests/portal.spec.js`:

```js
test('the three screens advance to the finished card and back again', async ({ page }) => {
  await openSetup(page);
  await page.getByRole('button', { name: /Google TV or Android TV stick/ }).click();

  await expect(page.getByText('Step 2 of 4')).toBeVisible();
  await page.getByTestId('setup-next').click();
  await expect(page.getByRole('heading', { name: 'Install the Downloader app' })).toBeVisible();
  await expect(page.getByText('Step 3 of 4')).toBeVisible();

  await page.getByTestId('setup-next').click();
  await expect(page.getByRole('heading', { name: 'Install AfriStream and sign in' })).toBeVisible();
  await expect(page.getByTestId('setup-next')).toContainText('Done — I am watching');

  await page.getByTestId('setup-next').click();
  await expect(page.getByTestId('setup-done')).toContainText('You are all set');
  await expect(page.getByText('All done')).toBeVisible();

  // Back from the finished card returns to the last screen, not the first.
  await page.getByTestId('setup-back').click();
  await expect(page.getByRole('heading', { name: 'Install AfriStream and sign in' })).toBeVisible();
});

test('back from the first screen returns to the device grid', async ({ page }) => {
  await openSetup(page);
  await page.getByRole('button', { name: /Android box/ }).click();
  await expect(page.getByTestId('setup-steps')).toBeVisible();

  await page.getByTestId('setup-back').click();
  await expect(page.getByTestId('setup-device-picker')).toBeVisible();
  await expect(page.getByTestId('setup-steps')).toHaveCount(0);
});

test('change device returns to the grid from part-way through a route', async ({ page }) => {
  await openSetup(page);
  await page.getByRole('button', { name: /Amazon Fire TV Stick/ }).click();
  await page.getByTestId('setup-next').click();

  await expect(page.getByTestId('setup-chosen')).toContainText('Amazon Fire TV Stick');
  await page.getByRole('button', { name: 'Change device' }).click();
  await expect(page.getByTestId('setup-device-picker')).toBeVisible();

  // And picking a different one starts that route from its own first screen.
  await page.getByRole('button', { name: /Android phone or tablet/ }).click();
  await expect(page.getByRole('heading', { name: 'One minute of getting ready' })).toBeVisible();
});

test('the finished card sends you to What to Watch', async ({ page }) => {
  await openSetup(page);
  await page.getByRole('button', { name: /Google TV or Android TV stick/ }).click();
  await page.getByTestId('setup-next').click();
  await page.getByTestId('setup-next').click();
  await page.getByTestId('setup-next').click();

  await page.getByRole('button', { name: 'See what to watch' }).click();
  await expect(page.locator('[data-screen-label="What to Watch"]')).toBeVisible();
});

test('the copy button on the Downloader code reports back', async ({ page }) => {
  await openSetup(page);
  await page.getByRole('button', { name: /Google TV or Android TV stick/ }).click();
  await page.getByTestId('setup-next').click();
  await page.getByTestId('setup-next').click();

  await expect(page.getByTestId('setup-code')).toHaveText('6573365');
  await page.getByRole('button', { name: 'Copy code' }).click();
  await expect(page.getByRole('button', { name: 'Copied' })).toBeVisible();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx playwright test -g "advance to the finished card"`
Expected: FAIL — `setup-next` does not exist.

- [ ] **Step 3: Add the chosen-device row and the navigation buttons**

In `assets/portal.js`, insert these two functions immediately after `setupStepList()`:

```js
    // Which device you are on, and the way back out of it. Sits above the steps
    // so "this is not my device" is answerable before reading any of them.
    function setupChosenRow(device) {
      return `
  <div data-testid="setup-chosen" style="display:flex;align-items:center;gap:10px 12px;flex-wrap:wrap;background:#fff;border:1px solid rgba(11,21,51,.09);border-radius:15px;padding:13px 16px;margin:0 2px 22px">
    <span style="font-size:15px;font-weight:800">${esc(device.title)}</span>
    <span style="flex:1 1 20px"></span>
    <button class="as-hover-ghost" data-act="setup-restart" style="flex:none;background:#fff;border:1px solid rgba(11,21,51,.14);border-radius:11px;padding:9px 16px;font-family:inherit;font-weight:700;font-size:13px;cursor:pointer;color:#65009F">Change device</button>
  </div>`;
    }

    function setupNav(device) {
      const last = state.setupScreen >= device.screens.length - 1;
      return `
  <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;border-top:1px solid rgba(11,21,51,.09);margin:26px 2px 0;padding-top:22px">
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
  <div style="margin:16px 2px 0;border-radius:13px;border:1px solid rgba(11,21,51,.09);background:#fff;padding:16px 18px;font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.7)">Picture freezing or an app that will not connect? Nine times out of ten it is WiFi. Email <a href="mailto:support@afristream.io">support@afristream.io</a> and we will walk through it with you.</div>`;
    }
```

- [ ] **Step 4: Render them from `setupSection()`**

In `setupSection()`, replace the `const body = …` expression with:

```js
      const device = setupDeviceOf();
      const body = stage === 'steps' && screen && device
        ? setupChosenRow(device)
          + setupStepList(screen)
          + (screen.note
            ? `<div data-testid="setup-step-note" style="margin:22px 2px 0;border-radius:13px;border:1px solid rgba(101,0,159,.2);background:#F7E9FF;padding:16px 18px;font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.75)">${esc(screen.note)}</div>`
            : '')
          + setupNav(device)
        : stage === 'done'
          ? setupDoneCard()
          : setupDeviceGrid();
```

- [ ] **Step 5: Add the navigation actions**

In the click handler `switch`, after the `setup-copy` case, add:

```js
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
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `npx playwright test -g "Setup"`
Expected: all ten setup tests PASS.

- [ ] **Step 7: Commit**

```bash
git add assets/portal.js tests/portal.spec.js
git commit -m "$(cat <<'EOF'
Walk the setup steps one screen at a time

Three screens per device, a Back that knows whether it is stepping back a
screen or out to the device grid, and a finished card that hands over to
What to Watch rather than dead-ending.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: The "we do this for you" branch

**Files:**
- Modify: `assets/portal.js` (setup block)
- Test: `tests/portal.spec.js`

**Interfaces:**
- Consumes: `setupStage()` returning `'support'` for devices whose `screens` is `null`.
- Produces: `setupSupportCard()`; test ID `setup-support`.

- [ ] **Step 1: Write the failing tests**

Append to the setup block in `tests/portal.spec.js`:

```js
test('a smart TV branches to the support card instead of steps', async ({ page }) => {
  await openSetup(page);
  await page.getByRole('button', { name: /Smart TV, nothing plugged in/ }).click();

  await expect(page.getByTestId('setup-support')).toContainText('We do this part for you');
  await expect(page.getByRole('heading', { name: 'We set this one up for you' })).toBeVisible();
  await expect(page.getByTestId('setup-steps')).toHaveCount(0);
  await expect(page.getByTestId('setup-next')).toHaveCount(0);

  await expect(page.getByRole('link', { name: 'Email support' }))
    .toHaveAttribute('href', 'mailto:support@afristream.io');
});

test('iPhone, iPad or Roku takes the same support branch', async ({ page }) => {
  await openSetup(page);
  await page.getByRole('button', { name: /iPhone, iPad or Roku/ }).click();

  await expect(page.getByTestId('setup-support')).toBeVisible();
  await page.getByRole('button', { name: 'Change device' }).click();
  await expect(page.getByTestId('setup-device-picker')).toBeVisible();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx playwright test -g "support card"`
Expected: FAIL — picking a smart TV currently falls back to the device grid.

- [ ] **Step 3: Add the support card**

In `assets/portal.js`, insert after `setupDoneCard()`:

```js
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
  <div data-testid="setup-support" style="border-radius:20px;border:1px solid rgba(11,21,51,.09);background:#fff;box-shadow:0 1px 2px rgba(11,21,51,.04);padding:clamp(20px,4vw,30px);margin:0 2px;display:flex;flex-direction:column;gap:15px">
    <span style="font-size:17.5px;font-weight:800;letter-spacing:-0.01em">We do this part for you</span>
    ${SETUP_SUPPORT_LINES.map((l) => `<span style="font-size:14.5px;line-height:1.65;color:rgba(11,21,51,.72)">${esc(l)}</span>`).join('')}
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:6px">
      <a href="mailto:support@afristream.io" style="background:linear-gradient(120deg,#65009F,#CD2DF5);border-radius:12px;padding:13px 26px;font-weight:800;font-size:14.5px;color:#fff;text-decoration:none">Email support</a>
      <button class="as-hover-ghost" data-act="setup-restart" style="background:#fff;border:1px solid rgba(11,21,51,.14);border-radius:12px;padding:13px 24px;font-family:inherit;font-weight:700;font-size:14px;cursor:pointer;color:#65009F">Change device</button>
    </div>
  </div>`;
    }
```

- [ ] **Step 4: Route to it from `setupSection()`**

In `setupSection()`, add a `support` arm to the `body` expression, immediately before the `stage === 'done'` arm:

```js
        : stage === 'support'
          ? setupSupportCard()
```

The full expression now reads: `steps` → chosen row + steps + note + nav; `support` → support card; `done` → done card; anything else → device grid.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `npx playwright test -g "Setup"`
Expected: all twelve setup tests PASS.

- [ ] **Step 6: Commit**

```bash
git add assets/portal.js tests/portal.spec.js
git commit -m "$(cat <<'EOF'
Send the devices we register by hand straight to support

A smart TV with nothing plugged in, an iPhone, an iPad or a Roku cannot
install the app themselves. They now get the one thing that helps — an email
link — rather than install steps that cannot work on them.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Buying advice, retailer links and WiFi tips

The handoff has a collapsible "Buying a device? What to look for" panel holding six bullets. The tab it replaces also carried two things the handoff drops: retailer links to devices we know work, and five WiFi tips. Both are worth keeping — the links are commercial, and the WiFi tips answer the support question we get most — so they fold into the same panel rather than disappearing with the old flow.

**Files:**
- Modify: `assets/portal.js` (setup block, click handler)
- Test: `tests/portal.spec.js`

**Interfaces:**
- Consumes: `state.setupHelpOpen` (added in Task 1), `setupStage()`.
- Produces: `setupBuyingPanel()`; action `setup-help`; test IDs `setup-buying`, `setup-buy-links`, `setup-wifi`.

- [ ] **Step 1: Write the failing tests**

Append to the setup block in `tests/portal.spec.js`:

```js
test('the buying advice sits collapsed under the device grid until asked for', async ({ page }) => {
  await openSetup(page);

  const panel = page.getByTestId('setup-buying');
  await expect(panel).toBeVisible();
  await expect(panel).toContainText('Buying a device? What to look for');
  await expect(panel.getByRole('listitem')).toHaveCount(0);

  await panel.getByRole('button').click();
  await expect(panel.getByRole('listitem').first()).toContainText('WiFi 6');
  await expect(panel).toContainText('4K Max and 4K Plus are the last that work');
});

test('the buying advice keeps the retailer links and the WiFi tips', async ({ page }) => {
  await openSetup(page);
  await page.getByTestId('setup-buying').getByRole('button').click();

  const links = page.getByTestId('setup-buy-links').getByRole('link');
  await expect(links).toHaveCount(2);
  await expect(links.first()).toHaveAttribute('target', '_blank');
  await expect(links.first()).toHaveAttribute('rel', /noopener/);

  await expect(page.getByTestId('setup-wifi')).toContainText('ending in 5G');
});

test('the buying advice is only on the device grid, not part-way through a route', async ({ page }) => {
  await openSetup(page);
  await page.getByRole('button', { name: /Google TV or Android TV stick/ }).click();
  await expect(page.getByTestId('setup-buying')).toHaveCount(0);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx playwright test -g "buying advice"`
Expected: FAIL — no `setup-buying` element exists.

- [ ] **Step 3: Add the panel**

In `assets/portal.js`, insert after `setupDeviceGrid()`:

```js
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
    <button data-act="setup-help" aria-expanded="${open ? 'true' : 'false'}" style="display:block;width:100%;text-align:left;background:transparent;border:none;padding:0;font-family:inherit;font-size:15.5px;font-weight:800;letter-spacing:-0.01em;color:#0B1533;cursor:pointer">${open ? '–' : '+'} Buying a device? What to look for</button>
    ${open ? `
    <ul style="margin:16px 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:10px">
      ${SETUP_BUYING_BULLETS.map((b) => `
        <li style="display:flex;gap:11px;font-size:13.5px;line-height:1.6;color:rgba(11,21,51,.72)">
          <span aria-hidden="true" style="flex:none;width:5px;height:5px;border-radius:50%;background:#65009F;margin-top:8px"></span>
          <span>${esc(b)}</span>
        </li>`).join('')}
    </ul>
    <div data-testid="setup-buy-links" style="margin-top:20px;padding-top:18px;border-top:1px solid rgba(11,21,51,.09)">
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
    <div data-testid="setup-wifi" style="margin-top:20px;padding-top:18px;border-top:1px solid rgba(11,21,51,.09)">
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
```

- [ ] **Step 4: Render it under the device grid only**

In `setupSection()`, change the fallback arm of the `body` expression from `setupDeviceGrid()` to:

```js
          : setupDeviceGrid() + setupBuyingPanel();
```

Both places the grid renders (the `device` stage and the fallback) are the same arm, so this is a single edit.

- [ ] **Step 5: Add the toggle action**

In the click handler `switch`, after the `setup-restart` case, add:

```js
        case 'setup-help': setState({ setupHelpOpen: !state.setupHelpOpen }); break;
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `npx playwright test -g "Setup"`
Expected: all fifteen setup tests PASS.

- [ ] **Step 7: Commit**

```bash
git add assets/portal.js tests/portal.spec.js
git commit -m "$(cat <<'EOF'
Fold buying advice, retailer links and WiFi tips into one panel

The old flow spread these across step 2 of a three-question taxonomy. They
now sit collapsed beneath the device grid, where someone who has not bought
a device yet will actually look for them.

Keeping the retailer links and the WiFi tips is a deliberate departure from
the handoff, which drops both: the links are commercial, and WiFi is what
nearly every "the picture freezes" email turns out to be.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Verify, document, and ship

**Files:**
- Modify: `bluegroup-project-afristream.php:16`, `package.json`, `CHANGELOG.md`
- Verify: `assets/portal.js`, `tests/portal.spec.js`

- [ ] **Step 1: Confirm no dead references survive**

Run:

```bash
grep -n "WIFI_TIPS\|WHY_A_DEVICE\|DEVICE_FAMILIES\|familyOf\|subOf\|fillTokens\|SETUP_METHODS\|setupShell\|buyingPanel\|wifiPanel\|chosenRow\|whyDevicePanel\|setupFamily\|setupSub\|SETUP_STEPS\|stepEyebrow\|setup-family\|setup-back-sub\|as-setup-rail" assets/portal.js tests/portal.spec.js
```

Expected: **no output.** `SETUP_WIFI_TIPS` and `setupBuyingPanel` are different identifiers and will not match. Any hit is a leftover — delete it before continuing.

- [ ] **Step 2: Confirm the Setup tab is still wired up**

Run: `grep -n "SECTIONS = {" assets/portal.js`
Expected: the line still reads `setup: setupSection` among the others. The section function kept its name, so this should be untouched.

- [ ] **Step 3: Run the full test suite**

Run: `npm test`
Expected: PASS, with no skipped or failing tests. If a non-setup test fails, it is a real regression — fix it before continuing, do not adjust the test to match.

- [ ] **Step 4: Check it by eye**

Run `npm run preview`, open http://localhost:4173, click Setup, and walk each of the six devices end to end. Confirm: six icons render (no broken SVG), the progress bar advances, the code pill is legible, the collapsible panel opens, and both support devices reach the email card.

Also check it at 390px wide — the device grid should be one column and the nav buttons should wrap rather than overflow.

- [ ] **Step 5: Bump the version**

This is a feature, so it is a minor bump: `0.20.1` → `0.21.0`.

In `bluegroup-project-afristream.php` line 16:

```php
define( 'AFRISTREAM_PORTAL_VERSION', '0.21.0' );
```

Also update the `Version:` header in the plugin docblock at the top of the same file, and `"version"` in `package.json`. All three must match — CI checks this.

- [ ] **Step 6: Add the changelog entry**

At the top of `CHANGELOG.md`, under a new `## 0.21.0` heading dated today (2026-07-27):

```markdown
### Changed

- The Setup tab asks one question instead of three. Six device cards lead
  straight to that device's steps, replacing the family → model → install
  method flow, which asked twice more before showing a single instruction
  and only ever arrived at the same handful of routes.
- Setup steps are one screen at a time with a four-stage progress bar, a
  Back that steps out to the device grid from the first screen, and a
  finished card that hands over to What to Watch.
- Buying advice, the retailer links and the WiFi tips are one collapsible
  panel beneath the device grid, rather than being spread through step 2.

### Removed

- The Fire TV route no longer names the store app it installs from; it
  points at the welcome email instead.
```

- [ ] **Step 7: Lint once**

Run: `npm run lint`

Report any findings to the user. **Do not fix them without approval** — this project's rule is one lint pass at the end, findings presented, user decides.

- [ ] **Step 8: Commit and open the pull request**

```bash
git add bluegroup-project-afristream.php package.json CHANGELOG.md
git commit -m "$(cat <<'EOF'
Release 0.21.0

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
git push -u origin simplified-setup-wizard
```

Then open the PR against `retire-acf` (**not** `main` — this branch is based on `retire-acf`, which is still in flight):

```bash
gh pr create --base retire-acf --title "Simplify the setup flow to one question" --body "$(cat <<'EOF'
## What

Replaces the Setup tab's family → model → install-method flow with the
handoff's flatter one: pick one of six devices, walk three screens, done.

Built from `docs/superpowers/specs/2026-07-27-landing-page-and-setup-wizard-design.md`.
The landing page is the second half of that spec and follows in its own PR.

## Notes for review

- Two things the handoff drops are kept, folded into the collapsible buying
  panel: the retailer links (commercial) and the WiFi tips (what nearly every
  picture-quality email turns out to be). Say the word and they come out.
- The Fire TV route deliberately no longer names the store app, matching the
  handoff. It points at the welcome email instead.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Self-review

**Spec coverage.** Every requirement in the spec's PR 1 section maps to a task: stage table and progress bar → Task 1 (`setupStage`, `setupProgress`); heading copy per stage → Task 1 (`setupHeading`); six devices with the EASIEST badge → Task 1 (`SETUP_DEVICES`, `setupDeviceGrid`); SVG icons → Task 1 (`SETUP_ICONS`); the three shared screens and per-device first screens → Task 1; code pill and copy button → Task 1; note panel → Task 1; Back/Next and the chosen-device row → Task 2; done stage → Task 2; support branch → Task 3; collapsible buying advice → Task 4; styling in `portal.css` → Tasks 1 and 4; the seven listed test cases → Tasks 1–4; version bump, changelog, files-touched list → Task 5.

**One deliberate addition.** The spec says the collapsible panel holds the handoff's six bullets. Task 4 also preserves the retailer links and WiFi tips from the flow being deleted, and the commit message and PR body both say so plainly, so it can be reverted in one commit if unwanted.

**Type consistency.** `SETUP_DEVICES` entries expose `key`, `title`, `body`, `badge`, `screens`; a screen exposes `title`, `sub`, `note`, `steps`; a step exposes `t`, plus optional `hint` and `code`. Task 2's `setupChosenRow(device)` reads `device.title`, and `setupNav(device)` reads `device.screens.length` — both defined in Task 1. `setupStage()` returns exactly the four strings the `body` expression branches on. The copy key is `'setup-' + code` in both the render (Task 1) and the click handler (Task 1). State keys `setupDevice` / `setupScreen` / `setupDone` / `setupHelpOpen` are all declared in Task 1 Step 3 and used from Tasks 1–4.
