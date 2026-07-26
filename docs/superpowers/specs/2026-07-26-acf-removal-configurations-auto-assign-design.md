# Retire ACF, add a Configurations page, and auto-assign licences

Date: 2026-07-26
Status: approved (design)

## Why

Three things, in one piece of work because they all touch the same data:

1. **Retire ACF.** The plugin depends on Advanced Custom Fields for the `license`
   post type, four licence fields and one user field. That is a whole third-party
   plugin carried for a handful of fields, and it puts the data model somewhere
   the repo cannot see or version. Users and the system must lose no
   functionality in the swap.
2. **A Configurations page.** There is no single place that says what this plugin
   actually adds to the site. Finding out means reading four PHP files.
3. **Auto-assign licences to new paid customers**, but only when stock exists —
   and without ever handing the same licence to two people.

A fourth requirement surfaced during design and is in scope: users can hold more
than one licence, and that must not corrupt.

## What ACF does today

Captured from ACF → Tools → Generate PHP on the live site, 2026-07-26.

**Post type `license`** (registered through the ACF UI):
`public => true`, `show_in_rest => true`, `menu_icon => dashicons-tickets-alt`,
`supports => array( 'title', 'custom-fields' )`, `delete_with_user => false`.

**Field group "License Field Groups"** (`group_69d65c021b5d6`), location
`post_type == license`:

| Name | Type | Detail | Stored in postmeta as |
|---|---|---|---|
| `app_password` | text | — | the string |
| `expiry_date` | date_picker | display + return `d/m/Y` | `Ymd`, e.g. `20261231` |
| `license_provider` | select | `Shockwave`, `AfriStream` | the chosen value |
| `mobile_active` | radio | `Yes`, `No`, allow null | the chosen value |

**Field group "User Field Groups"** (`group_69d4efc782f53`), location
`user_role == all`:

| Name | Type | Detail | Stored in usermeta as |
|---|---|---|---|
| `active_license` | relationship | post type `license`, `max => 1`, returns objects | serialized array of post IDs |

ACF also writes a companion `_<name>` meta row per field holding the field key.
Those rows are left in place and simply ignored; they are inert once ACF is gone.

Consumers of these fields in this repo: `includes/licenses.php` (credentials,
de-duplication, admin columns, sorting), `includes/shortcodes.php`
(`[user_acf_fields]`), and `bluegroup-project-afristream.php`
(`afristream_portal_credentials_data()` feeding `/afristream/v1/credentials`).

## Design

### 1. Ownership moves to the licence

This is the load-bearing decision, and it is what makes multiple licences per
user safe.

Today an assignment exists only as an entry in a serialized array in usermeta.
That has three problems that all get worse with more than one licence per user:

- **Lost updates.** Assigning is a read-modify-write of an array. Two writes that
  overlap silently discard one.
- **No uniqueness.** Nothing structurally prevents licence 47 appearing in two
  users' arrays. Today that is defended by a validation filter, which only runs
  on the admin form and not on a programmatic write.
- **No cheap reverse lookup.** "Who holds licence 47" means loading every user
  with the meta key and unserializing each array. The Connected User column does
  this with a `LIKE '"47"'` query.

Instead, the authoritative record becomes a single scalar on the licence:

```
postmeta  afristream_assigned_user  =>  int user ID, or absent when free
```

One licence, one owner, by construction. Assignment becomes a single-row write,
and the reverse lookup becomes an indexed meta query.

`usermeta active_license` is kept, written as a **derived mirror** in exactly the
serialized-array shape ACF used. Nothing that reads it needs to change:
`[user_acf_fields]`, the credentials REST route, the Active Licenses user column,
and any Elementor or third-party reader all keep working. The mirror is rebuilt
from the authoritative postmeta whenever an assignment changes, never edited
piecemeal.

**Backfill.** A one-time upgrade routine walks every user's `active_license`
array and writes `afristream_assigned_user` on each licence. Where a licence
appears in more than one user's array, the earliest-registered user keeps it and
the conflict is written to the event log and surfaced on the Configurations page
rather than resolved silently. The routine is idempotent and guarded by a stored
schema version, so it runs once.

### 2. `includes/fields.php` — the ACF replacement

Registers the post type and owns all field access. No other file touches meta
directly.

**Post type.** `register_post_type( 'license', ... )` on `init` priority 5, with
the arguments copied verbatim from the ACF export above so the admin URLs, REST
visibility and menu position are unchanged.

**Accessors.** These replace every `get_field()` call:

- `afristream_license_meta( $post_id, $key )` — returns the same value ACF's
  `get_field()` returned, including converting `expiry_date` from the stored
  `Ymd` to `d/m/Y`. This is what keeps
  `afristream_portal_license_expiry_timestamp()` and the admin columns working
  without touching their logic.
- `afristream_license_owner( $license_id )` — int user ID or 0.
- `afristream_user_license_ids( $user_id )` — int[] of licences the user holds,
  read from the authoritative postmeta.
- `afristream_assign_license( $license_id, $user_id, $actor, $context )` and
  `afristream_unassign_license( $license_id, $actor, $context )` — the only two
  functions that mutate ownership. Each takes the lock (below), writes the
  postmeta, rebuilds the affected users' mirrors, and appends to the event log.

**Licence meta box.** Replaces the ACF field group on the licence editor: App
Password (text), Expiry Date (`<input type="date">`, rendered from and saved to
`Ymd`), License Provider (select), Mobile Active (radio, with an empty option so
null stays representable). Nonce-protected, capability-checked with
`current_user_can( 'edit_post', $post_id )`, each value sanitised to its type.

**User profile field.** Replaces the ACF relationship. A multi-select listing the
licences the user currently holds plus every free licence — a taken licence is
never offered, which is what the `acf/fields/relationship/query` filter did. On
save, each submitted licence is re-checked against the authoritative postmeta
inside the lock and rejected with an admin error if it was claimed in the
meantime — what `acf/validate_value` did, but now enforced on the write path
rather than only on the form.

**REST.** `register_meta()` for each licence key so the fields remain visible on
the REST-enabled post type exactly as ACF exposed them.

**Deletions.** The two `add_filter( 'acf/... )` blocks in `includes/licenses.php`
are removed, their behaviour having moved into the profile field. Every
`function_exists( 'get_field' )` guard goes with them — there is no longer an
optional dependency to degrade against.

### 3. `includes/license-log.php` — the event log

Per-licence history so an assignment can be traced and manually overridden.

Stored as `postmeta afristream_license_log`, a capped array of the most recent
100 entries, newest last. Postmeta rather than a custom table: there are eleven
licences, the log travels with the post through export and import, and there is
no schema to migrate.

Each entry records:

```php
array(
  'time'    => 1785000000,        // unix, UTC
  'event'   => 'assigned',        // assigned|unassigned|created|updated|conflict
  'user'    => 12,                // the licence holder involved, 0 if none
  'actor'   => 'auto-assign',     // display name of the admin, or a system tag
  'detail'  => 'expiry_date 20261231 → 20271231',
)
```

Written by `afristream_assign_license()`, `afristream_unassign_license()`, the
meta box save (field-level changes), the backfill, and the auto-assign routine.

Surfaces in three places:

- A **History** meta box on the licence editor, newest first.
- A **Last assigned** column on the Licenses list table — the holder's name and
  the date, linking to their profile.
- A **recent events** feed on the Configurations page, merged across licences.

### 4. `includes/auto-assign.php` — assignment on purchase

**Trigger.** `surecart/purchase_created` and `surecart/subscription_created`,
both funnelling into one idempotent entry point resolved to a WordPress user ID.
Everything SureCart-facing is guarded with `class_exists()` in the same style as
`includes/affiliates.php`, so the feature degrades to inert rather than fatal
when SureCart is absent.

**Entitlement = the user's count of active SureCart subscriptions.** One licence
per active subscription. Deliberately not purchase quantity or amount, so pricing
changes never affect allocation.

**Top-up, not grant.** On every event: count what the user holds, count their
active subscriptions, and assign the difference if positive. Running the routine
twice is a no-op, so a replayed or duplicated webhook cannot over-assign.

**Availability.** A licence is available when it is `publish`, has no
`afristream_assigned_user`, and its `expiry_date` is blank or today or later.
Candidates are ordered by expiry ascending then ID, so stock closest to expiring
is used first.

**Race safety.** The claim runs inside a lock (`afristream_assign_lock`, a
transient with a short TTL and bounded retry). Inside the lock, the licence is
re-read and re-verified as free immediately before the write. Two simultaneous
checkouts therefore cannot claim the same licence — and because ownership is a
single scalar on the licence, even a lock failure cannot produce a licence held
by two users. The worst case is that the second write wins and the first user
quietly loses one, which leaves their mirror disagreeing with the authoritative
postmeta. The Configurations page reports exactly that: any user whose
`active_license` mirror does not match the licences pointing at them, with the
event log showing which write displaced which.

**No stock.** The user is added to the `afristream_pending_licenses` option
(user ID plus the timestamp and how many they are short). The shortfall is shown
on the Configurations page and as an admin notice. The queue is drained
oldest-first whenever a licence becomes available — on `save_post_license` for a
new or republished licence, and on any unassignment including the existing
`delete_user` handler.

**Over-allocation is reported, never auto-revoked.** If a user holds more
licences than they have active subscriptions — a lapsed or cancelled
subscription — the Configurations page names the user and the surplus licences
and the event log records it. Nothing is stripped automatically. Removing a
paying-then-lapsed customer's access is a decision for a person, and a failed
payment often recovers.

### 5. `includes/configurations.php` — the Configurations page

A top-level admin menu, **Configurations**, `manage_options`, read-only.

The content is not a hand-maintained list, which would drift the first time
anyone adds a feature. Each file declares what it registers through a filter:

```php
add_filter( 'afristream_registry', function ( $items ) {
    $items[] = array(
        'group'  => 'Licences',
        'name'   => 'Auto-assign on purchase',
        'type'   => 'hook',
        'handle' => 'surecart/purchase_created',
        'file'   => 'includes/auto-assign.php',
        'status' => afristream_autoassign_status(),
    );
    return $items;
} );
```

Rendered as tables grouped by area — Portal, Licences, Affiliates, Content
sources, Admin UI — with columns Feature, Type, Handle, Source file, Status.
`Type` is one of shortcode, REST route, admin column, field, hook, integration,
setting.

Status is computed live, never stored, so the page cannot go stale. Examples:
`11 licences — 3 available`, `TMDB connected`, `SureCart active`,
`2 users awaiting a licence`, `1 user over-allocated`, `all mirrors consistent`.

Above the tables: plugin version, schema version, a link to Settings → AfriStream
Portal, and the recent-events feed from the licence log. The existing settings
page is left where it is; its URL is already in use.

### 6. Retiring ACF itself

Code alone does not remove ACF, and deactivating it blind would break any
Elementor page using an ACF dynamic tag. Before deactivation:

1. Scan `_elementor_data` on every page, post and Elementor template for `acf`
   dynamic tags.
2. Grep the other active plugins and the theme for `get_field(`, `the_field(`,
   `have_rows(` and `acf_`.
3. List every `acf-field-group`, `acf-post-type` and `acf-taxonomy` post beyond
   the two groups and one post type documented here.

The findings are reported before anything is deactivated. Only once that audit is
clean are ACF's own copies of the field groups and the post type deleted — they
must go, or ACF and this plugin would both register the `license` post type and
render duplicate meta boxes — and ACF deactivated.

## File layout

| File | Change |
|---|---|
| `includes/fields.php` | new — post type, accessors, meta box, profile field, assignment primitives, lock, backfill |
| `includes/license-log.php` | new — event log write and render |
| `includes/auto-assign.php` | new — SureCart triggers, entitlement, top-up, pending queue |
| `includes/configurations.php` | new — registry filter, admin page |
| `includes/licenses.php` | rewritten — ACF calls swapped for accessors, `acf/*` filters removed, Last assigned column added |
| `includes/shortcodes.php` | edited — `[user_acf_fields]` loses its ACF dependency, keeps its tag name |
| `bluegroup-project-afristream.php` | edited — require the new files, register the registry entries for the portal, bump version |

## Testing

Playwright, per the project's CI guardrail:

- Licence meta box: all four fields save and round-trip; `expiry_date` survives
  as `Ymd` and displays as `d/m/Y`.
- Profile field: assigning works; a licence already held by someone else is not
  offered and is rejected if forced.
- Multiple licences: a user holding two shows two profiles in the portal, and the
  second assignment does not disturb the first.
- Auto-assign: takes the soonest-expiring free licence; a second identical event
  assigns nothing; with no stock the user is queued; publishing a licence drains
  the queue.
- Configurations page: renders, lists the known features, and shows correct
  available and pending counts.

Plus a direct check that `[user_acf_fields]` and `/afristream/v1/credentials`
return the same shape with ACF deactivated as they do with it active.

## Out of scope

- Automatic revocation on subscription lapse (reported only, by decision).
- Emailing customers their credentials — the existing flow is unchanged.
- Moving the existing settings page.
- Any change to the `_<name>` field-key meta rows ACF leaves behind.

## Version

0.19.0 → 0.20.0, minor, changelog updated alongside.
