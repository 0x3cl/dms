# Blade → Inertia migration notes

A historical record of the conversion from the Blade/Bootstrap frontend to Inertia 2 + Vue 3 on PROCMIS v2's design system. **Append to this file; do not rewrite it.** It exists so the reasoning behind a surprising decision can be found later.

## Why

The app had two layouts that disagreed with each other, and neither was a design system:

- `layouts/app.blade.php` loaded **Bootstrap 5 and Font Awesome from CDNs** and carried a placeholder navbar whose own comment admitted it was a stand-in. It never called `@vite` at all.
- `layouts/guest.blade.php` expected Tailwind, but `resources/css/app.css` held only the three Tailwind **v3** `@tailwind` directives and **Tailwind was not installed** — no `tailwindcss` package, no config, no `node_modules/tailwindcss`. Every Tailwind class in the Breeze views was dead.
- `welcome.blade.php` papered over that with ~40 KB of Tailwind v4 output pasted inline as a `<style>` block.
- Around 700 lines of inline `<style>` were duplicated across the divisions, sections, employees and auth pages.

`Projects/procmis_v2` — the same author's Laravel 13 / Inertia 3 / Vue 3 / Tailwind 4 procurement system — already had a coherent, documented design system. The goal was to make DocTrack read as part of the same product family.

## What was ported, and what was not

**Ported from procmis_v2:** `resources/css/app.css` (tokens, base classes, dark theme) with the tour, rich-text and org-chart sections stripped; `AdminShell`; `Card`, `Button`, `Badge`, `IndexControls`, `Pagination`, `ToggleSwitch`, `UserAvatar`, `CountUp`, `ActionMenu`, `SearchableSelect`, `ConfirmActionModal`, `FeedbackModal`, `LoadingModal`, `CollapseTransition`, `NavigationSkeleton`, `ThemeToggle`; `preferences.js`, `initials.js`, `navigationState.js`; `useConfirmSubmit`, `useUrlTab`; `format-date.js`. Plus the page recipes — `divisions/{index,form}.js` are near-direct ports of their procmis counterparts.

**Deliberately not ported:** the guided-tour system (driver.js), Chart.js and `DashboardChart`, AOS, Echo/Reverb and all realtime, the supplier portal and shell, every purchase-request/signatory component, `ImageUploadField`, `RichTextEditor`, and the Spatie roles/permissions model.

**The editable Pages module was not ported.** procmis_v2 stores header/footer/login/landing copy and images in a database table so a superadmin can edit the front of house without a deploy. DocTrack serves one office, so that copy is hardcoded in `resources/js/organization.js` — one file to change, no table, no upload pipeline. If this app ever serves more than one office, that is the point to reconsider.

**`searchableSelects.js` was dropped.** procmis_v2's 355-line imperative `<select>` enhancer is mostly supplier-rating logic DocTrack has no use for; the `SearchableSelect` Vue component covers what this app needs.

## Decisions that differ from the plan

- **Inertia 2, not 3.** procmis_v2 runs `inertiajs/inertia-laravel:^3.3`, which requires Laravel 13. DocTrack is on Laravel 12, so Inertia **2** is the correct version here.
- **QR labels are SVG, not PNG.** `simple-qrcode` renders PNG through BaconQrCode's Imagick backend, and this environment has GD but not Imagick — `QrCode::format('png')` threw on *every* upload, before and after the port. SVG needs no extension and prints sharper at label size. Rows created before the switch keep their `.png` filenames and still work, because `downloadQr()` serves whatever the row points at.
- **Email verification was removed rather than wired up.** The plan said to make the unreachable Breeze auth pages work. Password reset was made to work (see below), but verification could not be: `users` has no `email_verified_at` column and `User` does not implement `MustVerifyEmail`, so there was nothing to verify. `EmailVerificationNotificationController`, `EmailVerificationPromptController` and `VerifyEmailController` were deleted, and `routes/auth.php` records what reinstating it would take.
- **Tests run on MySQL, not in-memory SQLite.** The plan called for uncommenting the SQLite lines in `phpunit.xml`; this environment has `pdo_mysql` and `pdo_pgsql` but no `pdo_sqlite`, so the suite runs against a `dms_test` database instead — the same arrangement procmis_v2 uses.
- **Deletes use `ConfirmActionModal`, not `DeleteConfirmationModal`.** procmis_v2's version fetches a four-digit challenge from a `POST /delete-challenge` endpoint. DocTrack's deletes are lower-stakes and it avoids adding a route.
- **Password reset needed a schema change.** `users` had no `email` column and `password_reset_tokens` had been re-keyed to `employee_id`, which Laravel's password broker cannot use — so the flow was dead even before its routes went unregistered. `2026_11_10_000000_add_email_to_users_and_fix_password_reset_tokens` adds a nullable unique `users.email` and restores the standard `email`-keyed token table. Sign-in is still by employee ID; the address exists only so the broker has something to look an account up by, and an account without one cannot self-serve a reset.

## Page-by-page

| Blade | Became |
| --- | --- |
| `auth/login.blade.php` | `pages/auth/login.js` — the split-image card |
| `auth/register.blade.php` | `pages/auth/register.js`; the jQuery-from-CDN division→section cascade became nested props + `SearchableSelect` |
| `auth/forgot-password`, `reset-password`, `confirm-password` | `pages/auth/*.js` — reachable for the first time |
| `auth/verify-email.blade.php` | deleted (see above) |
| `dashboard.blade.php` (7 Bootstrap tab panes) | `pages/dashboard/index.js` — KPI strip, quick actions, recent lists. The three panes that were whole unpaginated tables became their own paginated screens. |
| `documents/index.blade.php` + `partials/{my-files,received-files}` | `pages/documents/index.js` — the two partials are the two tabs |
| `documents/all.blade.php` (a bare, unrouted table fragment) | `pages/documents/all.js` + the new `documents.all` route |
| `documents/create`, `edit` | `pages/documents/form.js` (one component) |
| `documents/view.blade.php` | `pages/documents/show.js` |
| `documents/logs.blade.php` (unused; the route returned JSON) | `pages/documents/logs.js` + a real paginated page |
| `documents/scan.blade.php` | `pages/documents/scan.js` + `components/QrScanner.js` |
| `documents/download-logs.blade.php` | `pages/documents/download-logs.js` |
| `documents/upload-success.blade.php` | `pages/documents/upload-success.js` |
| `view.blade.php` (orphan track form) | `pages/documents/track.js` |
| `divisions/`, `sections/`, `employees/` × `{index,create,edit}` | `pages/<module>/{index,form}.js` |
| `*/delete.blade.php` × 4 (raw PHP/PDO scripts using `require '../db_connect.php'`) | deleted; deletes are a `ConfirmActionModal` on the index page |
| `profile/edit.blade.php` + 3 partials | `pages/account/index.js` — one tabbed page |
| `landing.blade.php` | `pages/index.js` |
| `welcome.blade.php`, `layouts/*`, `components/*`, `partials/navbar.blade.php`, `layouts/navigation.blade.php` | deleted |

`resources/views/app.blade.php` is the only Blade file left.

## Defects found and fixed

These were all pre-existing. The port surfaced them because a working UI finally reached the pages.

### Security and correctness

| Defect | Fix |
| --- | --- |
| `viewByCode()` skipped `isAuthorized()` entirely, so anyone holding a tracking code could read a private document | goes through the same gate as every other read |
| `edit()`, `update()`, `destroy()` had **no ownership check** — any authenticated user could rewrite or delete any document | gated on `owns()`, like `end()` already was |
| `downloadQr($file_name)` interpolated an unsanitised filename into `storage_path()` on a **public** route | the name is matched against a stored `qr_code_path`, then `basename()`d |
| Login did not use `LoginRequest`, so there was **no rate limiting** | `LoginRequest` rewritten onto `employee_id`, controller calls `authenticate()` |
| A document's log page had no visibility check | `DocumentLogController::canSee()` mirrors `isAuthorized()` |
| Tracking codes counted *all* documents created today plus one — a duplicate as soon as two types were uploaded in one day, and racy with itself | per-prefix series, highest issued plus one, under `lockForUpdate()` |
| QR filenames came from the document title (arbitrary user input; collided; traversal vector on a public route) | named after the tracking code |

### Broken pages

| Defect | Fix |
| --- | --- |
| `/profile` failed three ways: `<x-app-layout>` with a `$slot` the layout never rendered, two routes that did not exist, and a read of a non-existent `email` column | replaced by `pages/account/index.js` |
| `employees/{index,create,edit}` threw — they eager-loaded `section.department`, but `Section` has `division()` | all three load `section.division` |
| `documents/edit` referenced undefined `$departments`, `$sections`, `$user` | replaced by `documents/form.js` |
| `/admin/download-logs` read `$log->department` / `$log->section` (columns that do not exist), `DocumentDownload` was an **empty model class**, and nothing ever wrote to `document_downloads` | model given its fillable and relations; `recordDownload()` writes a row on every download; the page reads the relations |
| The dashboard's "Docs Ended" KPI counted logs with `action = 'ended'` while `end()` writes `'Ended'`, so it was permanently 0 | counts `documents.status = 'ended'` |
| `documents.show` was declared by the resource route but had **no method**, so any hit threw | redirects to the tracking-code page |
| `/track` rendered a view that did not exist | redirects to `documents.viewByCode` |

### Routing and dead code

- `routes/auth.php` was **never loaded** — `bootstrap/app.php` registered only `web.php` — so password reset, verification and password confirmation were all unreachable. It is registered now, and the duplicate login/register/logout definitions were removed from `web.php`.
- `app/Http/Kernel.php` was an inert Laravel-10-style kernel referencing six middleware classes that did not exist. Deleted.
- `app/Providers/RouteServiceProvider.php` was unregistered and referenced a non-existent `routes/api.php`; `AuthenticatedSessionController` still imported it for `::HOME`. Deleted, and the controller uses `route('dashboard')`.
- `DocumentController::myFiles()` and `allDocuments()` were unrouted dead methods. `allDocuments()` became the routed `all()`; `myFiles()` was dropped (the index page's "mine" tab covers it).
- `LandingController` was unrouted while `/` used an inline closure. `/` points at the controller.
- `Document::getCurrentStatusAttribute()` read `document_logs.status`, a column nothing writes, so it always returned `'unknown'`. Removed.
- `DocumentLog` used the deprecated `protected $dates`. Now `casts()`.

### Environment and tooling

- `composer.json` did not declare **`laravel/framework`** at all — only the lock file had it. Added, along with the emptied-out `scripts` block.
- Tailwind 4, `@tailwindcss/vite`, `@vitejs/plugin-vue`, Vue, Inertia, Poppins, Font Awesome and `html5-qrcode` installed; `alpinejs` and `axios` removed.
- `php artisan storage:link` had never been run here, so every `asset('storage/…')` URL 404'd — including uploaded documents and QR labels.
- The stale `public/hot` file was deleted.
- `phpunit.xml` ran against the **real** `dms` database; it now uses `dms_test`.
- Pest 3 was installed with no `tests/Pest.php`, so its Laravel bindings were never wired and both existing tests were the stock examples. Added, examples removed, and regression tests written for the fixes above.
- `.github/workflows/tests.yml` triggered on `master` and `*.x`, neither of which this repo has, so **CI had never run**. Rewritten for `main`/`develop` with Pint, the frontend build and Pest on a MySQL service container. `update-changelog.yml` was removed along with the stock `CHANGELOG.md`.
- The stock Laravel `README.md` was replaced with a real one.
- `documentations/overview.md` (an accurate, untracked hand-written overview) was folded into `documentation/architecture.md` and the module docs, and the duplicate folder removed, so there is one documentation tree.

## Left alone

- **No roles were added.** The brief was explicit that DocTrack has no access model and one was not to be invented. `/admin/download-logs` and all directory CRUD therefore remain open to any authenticated account, and registration remains self-service. Recorded in `documentation/architecture.md` → Known open items.
- Dead columns (`users.division_name`/`section_name`, `documents.is_ended`, `documents.target_*`, `document_logs.status`) were documented rather than dropped; the four empty no-op migrations were left in place.
- Replacing a document's file still leaves the previous upload on disk.

---

## 2026-11-10 — Database refactor to PROCMIS conventions

A schema pass, not a feature change. The target was the sibling system's conventions: `foreignId()->constrained()` with deliberate delete behaviour, lookup tables with codes, backed enums in `app/Enums`, a service for number issuance, and audit rows that snapshot their actor instead of joining one.

**Migrations consolidated.** The twenty-two incremental migrations — four of them empty no-ops, several adding columns nothing ever read — were replaced by eleven authoritative `create` migrations. This is the one change that is not backward compatible: an installation carrying data must dump and restore, not migrate across.

**Columns dropped**, none of which anything read or wrote: `users.division_name`, `users.section_name`, `documents.is_ended`, `documents.target_division_id`, `documents.target_section_id`, `document_logs.status`.

**New tables.** `document_types` (was `DocumentController::TYPES`, a PHP constant, so adding a type needed a deploy) and `document_series` (a series' high-water mark, so a deleted document's tracking code is never reissued).

**The trail reshaped.** `document_logs` renamed `user_id` → `actor_id` and `received_at` → `occurred_at`, and both audit tables gained snapshots of the actor and their office beside the foreign keys, plus `ip_address`. `document_downloads` gained `document_log_id`. Every foreign key on an audit row is `nullOnDelete`, including `documents.user_id`, which previously cascaded — so deleting an account deleted every document it had uploaded.

**Constraints added.** `unique(document_id, user_id)` on `document_recipients`; `unique(division_id, code)` and `unique(division_id, name)` on `sections`; `divisions.code`; `employees.user_id` (nullable, unique) linking a directory entry to an account. `employees.section_id` changed from `cascadeOnDelete` to `nullOnDelete` — deleting a section used to delete everyone recorded in it.

**Indexes added** on every column a list actually sorts or filters by: the five on `documents`, four on `document_logs`, four on `document_downloads`, `notifications.[notifiable_type, notifiable_id, read_at]`, `employees.[last_name, first_name]`.

**Enums** (`DocumentStatus`, `DocumentVisibility`, `DocumentAction`) replaced bare strings compared by hand in four controllers and three Vue pages. Stored action values are slugs; `label()` holds the sentence. `DocumentAction::postable()` is now the whitelist for the hand-off endpoint, which previously validated `action` as `string|max:255`.

**`TrackingCodeService`** replaced `DocumentController::nextTrackingCode()`, with the counter as `max(codes on record, series counter) + 1`.

Twelve tests were added or rewritten, including `tests/Feature/DocumentTrailTest.php` for the snapshot guarantees. Suite: 41 passing.

---

## Private file storage and obfuscated URLs (2026-11-10)

Appended, not rewritten — see the policy at the top of this file.

### What moved

Documents were stored with `$file->store('documents', 'public')` and read with
`storage_path("app/public/{$document->file_path}")`. That put every upload
behind the `public/storage` symlink, so **every document in the office was
readable at `/storage/documents/<hash>.pdf` by anyone holding the URL, with no
authorization check of any kind.**

They now live on a new private `documents` disk (`storage/app/private/documents`),
which has no entry in `config/filesystems.php`'s `links`, and are served only by
`FileStreamController` after `AccessResolver` has allowed the request.

### Deployment steps, in order

```bash
php artisan migrate                 # adds users.public_id, folders, files, documents.file_id
php artisan files:import-documents  # carries existing uploads onto the private disk
```

The import is idempotent, transactional per document, and **copies** rather than
moves — it has to be re-runnable, and a half-finished move that has already
deleted its source is not recoverable.

`Document::storedFile()` prefers `file_id` and falls back to `file_path`, so the
application keeps serving pre-existing documents throughout. The import can
therefore be run at leisure rather than as part of the deploy.

### Only then

Once `files:import-documents` reports nothing left to import and you have a
backup, `storage/app/public/documents` and `storage/app/public/qrcodes` can be
deleted. **Until then they are still being read from.**

### A deployment carrying data

The schema remains a set of authoritative `create` migrations plus additive
changes on top. A deployment carrying data from before the baseline still has to
dump and restore rather than migrate across; the additions above do migrate
normally on top of the baseline.

### Not done here

- `documents.file_path` and `file_name` are kept as legacy read-only columns.
  Dropping them is a later change, once every deployment has imported.
- Replacing a document's file leaves the superseded `files` row and its bytes in
  place. No longer web-reachable, so this is wasted space rather than an
  exposure, but nothing prunes it yet.

---

## Sharing, inheritance and share links (2026-11-10)

Adds `item_shares` and `share_links`, and renames `files.path` to
`files.storage_path`.

### The rename matters

`folders.path` is the materialized *ancestor* path. `files.path` was the
location of the bytes on disk. A query that touches both and writes `path` gets
whichever the optimiser resolves it to, and the visibility listing was in fact
searching a file's disk path for a folder id — which never matches, so every
ancestor rule silently evaluated as "this item has no ancestors". A public file
inside a private folder was listed to everyone, and an inherited share reached
nobody.

The column is `storage_path` now so the collision cannot recur.
`AccessResolver::ancestorPathSql()` is the one place the distinction lives, and
`FileListingTest`'s differential test is what caught it.

### Deployment

```bash
php artisan migrate
```

Additive. Nothing to backfill: there were no shares before this.

### Not done here

- There is no UI for granting a share or minting a link yet — the services and
  the resolver are in place and tested, and the File Manager screens that drive
  them are the next phase. `ShareLinkController` and the `/s/{token}` landing
  page are live, so a link created from a console or a test is fully usable.
- `files.manage`, `files.share` and the rest of the `files.*` permissions are
  not yet in `App\Support\Modules`, so they cannot be granted from the Roles
  screen. `AccessResolver` reads `files.manage` already, and a direct per-user
  grant works. They join the registry with the File Manager module, which is
  the four-edit process that file documents.

---

## The activity trail (2026-11-10)

Adds `activity_logs`, and the `activity-trails` module.

### Deployment

```bash
php artisan migrate
```

Two migrations, both additive: the table, and the grant that gives the existing
`SUPERADMIN` role `activity-trails.read`.

### Why the grant is a migration

`RolePermissionSeeder` only runs on a fresh install. Without the grant
migration, an already-deployed administrator silently cannot reach the module
that was just shipped — the sidebar entry is filtered out and the route 403s,
and nothing says why.

This is edit 4 of the four that adding a module requires; the list is in
`App\Support\Modules`. The pattern is PROCMIS's, and
`2026_11_10_000012_grant_activity_trails_to_superadmin_role.php` is the template
to copy. Its `down()` is deliberately empty: taking the permission away while
the route still gates on it locks the module rather than restoring it.

### Nothing to backfill

Rows start accumulating from the moment this deploys. Activity before it was not
recorded anywhere this table could be reconstructed from — `document_logs` holds
the document events, and those remain where they are.

---

## The File Manager (2026-11-10)

Adds no tables. One migration, granting the `files.*` permissions to the
existing `SUPERADMIN` and `Staff` roles.

### Deployment

```bash
php artisan migrate
npm run build
```

### Why `Staff` gets a grant too

Unlike every earlier grant migration, this one touches two roles. The file
manager is not purely administrative — a user's own storage is the point of the
feature — so withholding it from `Staff` would ship a module nobody could use.
`Staff` deliberately does **not** get `files.manage`, which is the reach across
everyone else's storage and is a read even for an administrator.

### Carried over from the previous phase

`files.manage` was already read by `AccessResolver` but could not be granted
from the Roles screen, because the `files.*` permissions were not in
`App\Support\Modules`. They are now, so that gap is closed.

### Not done here

- Zip and extract (`ArchiveService`).
- The admin-configurable upload policy. Upload limits are still inline in
  `FileActionController` (`max:10240`, 20 files per upload), mirroring the
  document rules.
- A folder-tree picker for move and copy. The service and endpoints accept any
  target; the UI currently aims at the folder in view, or at the user's own root
  when copying from someone else's tree.

---

## Upload rules and archives (2026-11-10)

Adds `upload_settings` (two seeded rows) and the `upload-settings` module.

### Deployment

```bash
php artisan migrate
php artisan db:seed --class=UploadSettingSeeder
npm run build
```

`ext-zip` is now required and is declared in `composer.json`.

### Nothing changes on arrival

The `documents` policy is seeded with exactly the limits that were hard-coded in
`DocumentController` — `pdf,doc,docx` at 10 MB — so the whole existing test
suite passed unchanged when the policy was swapped in. That was the point: a
settings module whose arrival loosens the rules is not a settings module.

The `files` policy is seeded broadly (documents, spreadsheets, presentations,
images, text, csv, zip at 50 MB), which is a **widening** of what the file
manager accepted before — it previously inherited the document rules.

### The seeder does not overwrite edits

`firstOrCreate` on `scope`. Re-running it after an administrator has tightened a
policy leaves their row alone.

### Not done here

- Nothing prunes superseded files. Replacing a document's file still leaves the
  old `files` row and its bytes on the private disk — no longer web-reachable,
  so wasted space rather than an exposure.
- Phase 8's ownership transfer and the `files:purge` command are still open.

---

## Soft deletes, ownership transfer and purging (2026-11-10)

Adds `deleted_at` to `users`, `divisions`, `sections` and `documents`.
(`employees` was in that list until the merge below dropped the table.)

### Deployment

```bash
php artisan migrate
```

Additive. Nothing to backfill: an existing row has a null `deleted_at`, which
is exactly "not deleted".

**Add a cron entry** if one does not exist, or the weekly purge never fires:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

### The behaviour change to know about

`$user->delete()` is now a **soft** delete, so `documents.user_id` is no longer
nulled when an account is closed. That is an improvement — the document keeps an
identifiable owner who can be transferred — but it is a change, and five tests
that asserted the old semantics were updated to use `forceDelete()` where they
meant a permanent erasure.

`ProfileController::destroy()` now **refuses** to close an account that still
owns files, folders or documents unless the request names someone to hand them
to. Any client posting to `DELETE /profile` needs to send `transfer_to`.

### A known consequence

`users.employee_id` is unique and the constraint ignores soft deletes, so a
closed account still holds its employee ID and it cannot be reused. Arguably
correct — the ID identifies a person the office has records about — but it is a
surprise if you expect to re-register someone. Reusing one means erasing the old
account with `forceDelete()`, which also severs its audit links to
`nullOnDelete` (the snapshots still read).

### Closes a standing leak

`files:purge --orphans` removes superseded document uploads. Replacing a
document's file left the old `files` row and its bytes in place forever with
nothing pruning them.

---

## Guided tours (2026-11-10)

No migration. Frontend-only.

### Deployment

```bash
npm install     # adds driver.js
npm run build
```

`public/images/mascot/staff/` carries eighteen PNG poses, about 490 KB, copied
from PROCMIS. They are committed rather than fetched, so there is nothing to
install and no CDN in the request path.

### What was not ported

PROCMIS's runner also supports sample-data tour pages, a second "supplier"
audience with its own seven-pose costume, and nested overlay tracking. DocTrack
has no tour that uses any of them, so none of it was carried across — including
the supplier PNGs.

The CSS prefix is `.doctrack-tour`, not `.procmis-tour`: a shared prefix would
imply a shared stylesheet that does not exist.

---

## The employee directory becomes the Users module (2026-11-10)

`employees` and `users` were two records of the same person. Both carried an
employee ID, a name and a section; `employees.user_id` existed only to say that
an entry and an account were the same human — a link that granted nothing and
that somebody had to remember to make. So an office maintained every person
twice, and the two copies disagreed the moment one was edited.

The earlier directory notes said that unifying them "is a migration and a
decision, not a refactor". This was the migration.

### What replaced it

One **Users** module under Directory, at `/users`, managing real accounts: the
old employee fields plus the role and the password, across two tabs
(`?tab=personal`, `?tab=security`). It is `SUPERADMIN`'s — it hands out roles
and sets other people's passwords.

| Gone | Now |
| --- | --- |
| `employees` table, `Employee` model, `EmployeeFactory` | merged into `users` |
| `EmployeeController`, `resources/js/pages/employees/` | `UserController`, `resources/js/pages/users/` |
| `employees.{read,write,delete}` | `users.{read,write,delete}` |
| `Staff` held `employees.read` | `Staff` holds neither; `users.read` is SUPERADMIN's |
| `employees.user_id`, the linked-account picker | nothing — a row *is* an account |

`users` gained `first_name` and `last_name`. **`name` stayed** as a stored
column, now assembled from the two by a `saving` hook on the model, because
over twenty call sites read it from outside: every audit snapshot copies it,
every folder and file records its owner's, and the download register searches
it in SQL where an accessor cannot be called.

### Deployment

```bash
php artisan migrate
npm run build
```

`2026_11_10_000017_merge_employees_into_users.php` does the whole thing in one
transaction-free, ordered pass: adds the two columns nullable, splits each
existing `name` on its last space, lets a linked entry's own halves overwrite
that split, turns every unlinked entry into a `Staff` account with a random
password, makes the columns required, drops `employees`, then grants `users.*`
to `SUPERADMIN` and deletes the `employees.*` permission rows.

An entry whose employee ID already belongs to an account is **skipped** — that
is the duplicate the change exists to end, and the account is the copy to keep.
An entry whose email collides with an account's keeps its row and loses the
address.

`down()` is empty. The reverse of a merge is a split, and what a split would
need — which rows were directory entries and which were always sign-ins — is
exactly what the merge discards.

> The baseline migrations were also updated, so a fresh install never creates
> `employees` at all. The merge migration exists only for an install that
> already holds data.

### Watch for

- **Nothing may write `users.name`.** `ProfileUpdateRequest` and
  `UserController` both refuse it as input. A seeder, importer or factory that
  sets it is writing a value the next save overwrites — `UserFactory`
  deliberately does not, so a regression in the hook fails a test.
- **Registration and `/account` changed shape**, posting `first_name` /
  `last_name` instead of `name`.
- **Staff lost its people lookup.** That is deliberate, not an oversight; see
  `App\Support\Roles`.
- `useUrlTab` gained an opt-in `{ always: true }` so the Users form keeps
  `?tab=` in the address on the default tab. Every other tab set is unchanged.

---

## The 2026-11-11 consolidation

Not a Blade-to-Inertia conversion — the office asked for a set of changes that
mostly *removed* screens, so it is recorded here for the same reason the rest of
this file is: the shape of the application changed, and the reasons do not
survive in a diff.

### Screens that are now filters

| Was | Is |
| --- | --- |
| `/documents` (two tabs) + `/documents/all` | `/documents`, three tabs: all / uploaded by me / shared with me |
| sidebar "Upload Document" | the Upload button on that page, at `/documents/upload` |
| sidebar "Scan QR Code" | one half of the Track document modal on that page |
| `/files` + `/files/shared` | `/files`, with shared items listed at the top of a person's own storage |

Every absorbed route either moved into the page that took it over or stayed as a
redirect. `/documents/all` redirects; `/scan` and `/documents/track` stay public,
because a QR label has to resolve for whoever is holding the folder. `pages/documents/all.js`
and `pages/files/shared.js` are deleted.

### What was removed outright

- **The End Action.** Route, `DocumentController::end()`, `App\Enums\DocumentStatus`,
  `DocumentAction::Ended`, the `StatusBadge` component, and the `documents.status`
  / `ended_at` columns. `AccessResolver::READER_ABILITIES` became a flat constant,
  because ending a document was the only state that ever narrowed a reader's grant.
- **Three permissions**: `files-shared.read`, `files-browse.read`, `files.write`.
  The first two had their screens absorbed; the third gated nothing and never had.
  Browse Storage is gated on `files.manage`, which is what that grant already
  meant. See `2026_11_11_000001_retire_merged_module_permissions.php`.
- **`ShareController::store()`** and the directory the share-state endpoint shipped
  on every open. Sharing from the drawer is link-based; people are named where the
  thing is created.

### What was added

`DeletionConfirmation` (server-issued delete codes), `FilePreview` (the viewer
decision and the signed Office-viewer URL), `FileCategory` (the filter's file
kinds), `FileManager::purge()` / folder-aware `copy()` / chain-aware `restore()`
/ `attempt()` (failure recording), a navigable trash with both views,
`FolderPickerModal` / `FileFilterModal` / `ConfirmDeleteModal`,
`resources/js/avatar.js` with `users.avatar_seed`, and `StaffSeeder`.

### Watch for

- **`documents-all.read` and `documents.share` are resolved in code, not on a
  route.** `ModuleRegistryTest` names both. The first gates a *tab*, which has to
  degrade rather than refuse; the second gates one branch of `documents.store`.
- **Every name check needs `withTrashed()`.** `(folder_id, name)` is unique over
  soft-deleted rows, so re-uploading a filename somebody deleted that morning was
  a 500 before this.
- **A delete confirmation is server-issued and spent on use.** Moving that check
  into the browser turns it back into a label on a button.
- **Office previews go through Microsoft's viewer**
  (`view.officeapps.live.com/op/embed.aspx`), which means a Word, Excel or
  PowerPoint file's bytes travel to Microsoft while it is being previewed. A
  deliberate decision over rendering locally (needs LibreOffice on a target that
  has neither it nor Imagick) and over refusing those previews.
  `FilePreview::VIEWER_EXTENSIONS` is the one place to reverse it. **PDFs are
  not part of that trade** — the Office viewer cannot render them, so they
  stream from us and the browser renders them.
- **An embedded viewer fetches from *our* server, so it cannot work on
  `localhost`.** `FilePreview::viewerCanReachUs()` mints nothing when `app.url`
  is plainly local, and the empty state says which reason applies. Nothing is
  wrong with the markup when an Office preview is blank on a developer machine —
  PDFs, images and video are unaffected, because none of them leave.
- **A person can no longer be added to an existing folder from the UI.** Tag at
  creation. The service still takes any grantee.
- **File versioning is still not implemented**, and `documentation/modules/files.md`
  records what the current shape supports so the next phase does not have to
  re-derive it.
