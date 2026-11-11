# Architecture

## Request lifecycle

A browser request hits `public/index.php` → `bootstrap/app.php`, which registers `routes/web.php`, `routes/console.php` and — through its `then:` callback — `routes/auth.php`. The `web` middleware group gets two additions: `HandleInertiaRequests` and `AddLinkHeadersForPreloadedAssets`.

A controller returns `Inertia::render('documents/index', [...])`. On a first visit Inertia renders `resources/views/app.blade.php` with the page object embedded; on a subsequent visit it answers the XHR with JSON and the client swaps the page component. The page name is a path: `'documents/index'` resolves to `resources/js/pages/documents/index.js` through the `import.meta.glob('./pages/**/*.js')` map in `resources/js/app.js`.

`resources/views/app.blade.php` is the only Blade file in the project. It carries the CSRF meta tag, the pre-paint dark-theme script, `@vite`, `@inertiaHead` and `@inertia`.

## Shared Inertia props

Defined once, in `app/Http/Middleware/HandleInertiaRequests::share()`:

| Prop | Shape |
| --- | --- |
| `auth.user` | `{ id, name, employee_id, division, section }`, or `null` when signed out. Hand-picked, not the model. |
| `notifications` | Lazy. `{ items: [{ id, message, url, read, created_at }], unread }` from `NotificationController::feedFor()`. |
| `flash.success` / `flash.error` | Lazy session reads. These are the **only** two flash keys in the app. |

`auth.role` is the user's single role name; `auth.permissions` is a flat list of permission names. Both are eager, because `AdminShell` renders on every authenticated page. They drive **navigation and affordances only** — the real gate is the route middleware.

Because `auth.user` is hand-picked rather than serialized from the model, a page that needs a new field on the signed-in user has to add it here — it will not appear just because the column exists.

## Data model

```
divisions ──< sections ──< users
    │            │           │
    │            │           ├──< documents ──< document_logs
    │            │           │        │              │
    │            │           │        │              └──> document_downloads
    │            │           │        │
    │            │           │        ├──>< document_recipients >──< users
    │            │           │        │
    │            │           │        └──> document_types ──< document_series
    │            │           │
    │            │           └──< notifications
    │            │
    └────────────┴───────────> (document_logs / document_downloads also carry
                                division_id + section_id, each beside a snapshot
                                of the name it had at the time)

```

There is no `employees` table. It was a second record of the same people with
no sign-in attached, and it was merged into `users` and dropped — one person is
one row and one account.

### Tables

**`users`** — `id`, `public_id`, `employee_id` (unique, the sign-in credential), `first_name`, `last_name`, `name`, `email` (nullable, unique; exists only for password reset), `division_id`, `section_id`, `profile_image`, `avatar_seed`, `password`, `remember_token`, timestamps, `deleted_at`.

`profile_image` and `avatar_seed` are the two halves of one answer and are **exclusive**: a path on the `public` disk, or the seed of a generated avatar. Both null is the normal state and means "generate a face from `name`" — see `documentation/modules/account.md`.

A name is stored three ways and only two of them are input. `first_name` and `last_name` are what an administrator types and what the list sorts by; **`name` is derived** — `User::booted()` assembles it on `saving` whenever either half is dirty, so nothing should ever write it directly. It stays a real column rather than an accessor because it is read from far outside the model: every audit row copies it (`document_logs.actor_name`, `document_downloads.user_name`), every folder and file records its owner's (`files.owner_name`), and the download register searches it in SQL, where an accessor cannot be called.

There is no `email_verified_at`: this app has no email verification. The old `division_name` / `section_name` string columns are gone — they were an unmaintained second copy of what the foreign keys already say, and a rename updated one of the two. Where a *historical* office name matters it is snapshotted onto the audit row, not carried on the account.

**`document_types`** — `id`, `code` (unique, the tracking-code abbreviation), `name` (unique), `description`, `is_active`, `position`, timestamps.

The list of document types *and* the map to their code prefixes, seeded by `DocumentTypeSeeder`. This was `DocumentController::TYPES`, a PHP constant, so adding a type or fixing an abbreviation needed a deploy. `code` is immutable in practice — it is stamped into every tracking code the type has issued, and those are printed on paper. Retire a type with `is_active` rather than deleting it.

**`document_series`** — `id`, `key` (unique, a code prefix such as `MEMO-20261110`), `last_number`, `counter`, `document_type_id`, `issued_on`, timestamps.

The high-water mark of one tracking-code series. See the lifecycle section below for why it exists alongside the code scan.

**`documents`** — `id`, `code` (unique), `title`, `document_type_id`, `type`, `file_path`, `file_name`, `qr_code_path`, `file_id`, `user_id`, `uploader_name`, `visibility`, timestamps, `deleted_at`.

**No lifecycle columns.** `status` and `ended_at` went with the End Action; `is_ended`, `target_division_id` and `target_section_id` were leftovers nothing read. A document is on record, and an uploader who wants one out of circulation deletes it.

`document_type_id` is the live type; `type` is its name as it stood at upload. Both on purpose: a tracking code is permanent, so renaming a type must not rewrite what the documents issued under its old name were filed as. `uploader_name` is the same idea for the account, and `user_id` is `nullOnDelete` — the document outlives a deleted uploader. A document with no uploader has no owner, so nobody can edit or delete it; that is the intended outcome.

Indexed on `[user_id, created_at]`, `[status, created_at]`, `[document_type_id, created_at]`, `[visibility, created_at]`, `created_at` and `qr_code_path` — every document list is "newest first inside a filter", and none of them had an index.

**`document_logs`** — `id`, `document_id`, `actor_id`, `actor_name`, `actor_employee_id`, `division_id`, `division_name`, `section_id`, `section_name`, `action`, `remarks`, `ip_address`, `occurred_at`, timestamps.

The document's travel history. Every row carries a foreign key **and** a snapshot of who the actor was and which office they sat in, and the snapshot is what the page renders. Renames from the old table: `user_id` → `actor_id`, `received_at` → `occurred_at`; the dead `status` column is gone. `action` is an `App\Enums\DocumentAction` value, not free text.

Indexed on `[document_id, occurred_at]`, `[document_id, actor_id, action]`, `[actor_id, action]` and `[actor_id, occurred_at]`.

**`document_downloads`** — `id`, `document_id`, `document_log_id`, `user_id`, `user_name`, `user_employee_id`, `division_id`, `division_name`, `section_id`, `section_name`, `ip_address`, `downloaded_at`, timestamps.

The register of file releases behind `/admin/download-logs`, snapshotted the same way, with `document_log_id` tying a release back to the trail entry that produced it. Indexed on `downloaded_at`, `[document_id, downloaded_at]`, `[user_id, downloaded_at]` and `user_name`.

**`folders`** — `id`, `public_id` (unique, opaque), `user_id` (`nullOnDelete`), `owner_name`, `parent_id` (`restrictOnDelete`), `is_root`, `name`, `path` (materialized ancestor path), `depth`, `visibility`, soft deletes, timestamps, `unique(parent_id, name)`.

**`files`** — `id`, `public_id` (unique, opaque), `user_id` (`nullOnDelete`), `owner_name`, `folder_id` (`restrictOnDelete`), `name`, `original_name`, `extension`, `mime_type`, `size_bytes`, `checksum`, `disk`, `path`, `visibility`, `source`, `has_thumbnail`, soft deletes, timestamps, `unique(folder_id, name)`.

The storage substrate. `documents.file_id` points a tracked document at its bytes; `documents.file_path` is the old public-disk path, now nullable and read-only. `users.public_id` is the owner segment of a file URL. See `documentation/modules/files.md` for the delete strategies, the one-root rule and why `path` is materialized.

**`activity_logs`** — `id`, `actor_id` (`nullOnDelete`) + `actor_name` + `actor_employee_id`, `division_id`/`division_name`, `section_id`/`section_name`, `subject_type`/`subject_id` (a plain morph, **no foreign key**) + `subject_label` + `subject_public_id`, `action`, `context` (json), `ip_address`, `user_agent`, `occurred_at`, timestamps.

The third audit table. The subject morph carries no key so a row outlives a purged file; the label is what the page renders. See `documentation/modules/activity-trail.md` for which events cross into `document_logs` and why most must not.

**`item_shares`** — `id`, `shareable_type`/`shareable_id` (File|Folder), `grantee_type` (`user|division|section|role`), `user_id`/`division_id`/`section_id`/`role_id` (nullable, **`cascadeOnDelete`**), `ability`, `granted_by_id` (`nullOnDelete`) + `granted_by_name`, `expires_at`, `revoked_at`, timestamps, unique on the full target tuple.

The grantee keys cascade, unlike every key on an audit row: a share is a live grant, not a record of something that happened, and one whose grantee is gone grants access to nobody. `granted_by_name` is still snapshotted, because "who gave me this" must outlive that person.

**`share_links`** — `id`, `token` (char 64, unique), `shareable_type`/`shareable_id`, `ability` (view or download only), `expires_at`, `revoked_at`, `max_views`, `view_count`, `last_accessed_at`, `created_by_id` (`nullOnDelete`) + `created_by_name`, timestamps.

See `documentation/modules/sharing.md` for the resolution order, the inheritance rules and why every refusal on these routes is a 404.

**`document_recipients`** — `id`, `document_id`, `user_id`, `notified_at`, timestamps, `unique(document_id, user_id)`. The pivot behind `Document::recipients()` ⇄ `User::receivedDocuments()`. The unique pair is new: the same person could be attached twice, which notified them twice and duplicated every visibility check that counted those rows.

**`divisions`** — `id`, `code` (unique), `name` (unique), `description`, timestamps. **`sections`** — `id`, `division_id`, `code`, `name`, `description`, timestamps, `unique(division_id, code)` and `unique(division_id, name)`.

A `code` is the short, stable handle: it survives a rename, which is what makes it safe to print or compare across systems. The section constraints are per division because two divisions may each run a "Records" section — and before they existed, one division could hold the same section twice and quietly split that office's staff and audit rows between two rows.

**`notifications`** — Laravel's standard database-notification table, plus an index on `[notifiable_type, notifiable_id, read_at]` for the header bell's unread count.

### Snapshots beside foreign keys

Several tables carry both `division_id` and `division_name` (and the same for sections, actors, uploaders and document types). This is the PROCMIS pattern and it is deliberate, not duplication:

- The **foreign key** is for filtering, joining and following a row back to the live record. It is `nullOnDelete` everywhere on an audit row, so the row outlives what it points at.
- The **snapshot** is what the page renders. It says what was true when it happened.

Without the snapshot, the history was assembled by joining live rows: renaming a division silently rewrote where a document had been two years ago, and deleting one blanked it. An audit trail that changes when the org chart changes is not an audit trail. The snapshots also mean the trail pages need no eager loading at all.

### The two audit tables are different things

`document_logs` is the document's travel history, shown to anyone who can read the document. `document_downloads` is the narrower question of whose hands a copy ended up in, and backs `/admin/download-logs` — it carries the IP a copy went to, which has no business on a document page. A download writes a row to both, and the second points at the first.

## Access model

### Per-document rules — real, and enforced

`DocumentController::isAuthorized()` is the single gate for **reading** a document:

```
public document                      → allowed
the signed-in user is the uploader   → allowed
the user is in document_recipients   → allowed
otherwise                            → redirect with an error
```

It is applied by `view()`, `viewByCode()` (track-by-code), `accessFile()` and `receiveFile()`. `DocumentLogController::canSee()` is a deliberate mirror of it, because a document's history is as sensitive as the document itself — change one and change the other.

`DocumentController::owns()` is a separate question and gates **writing**: `edit`, `update`, `destroy` and `end`. A public document is readable by everyone and still editable only by its uploader.

An ended document can no longer be downloaded or received by anyone but its uploader.

Covered by `tests/Feature/DocumentAuthorizationTest.php`.

### Module access — role-based

**Access is role-based** (`spatie/laravel-permission`). A user holds exactly one role; a role holds `{module}.{action}` permissions; every module route carries a `permission:` gate. The vocabulary is `App\Support\Modules`, the two code-level roles are `App\Support\Roles`, and `SUPERADMIN` is short-circuited by `Gate::before` in `AppServiceProvider`.

This replaced a deliberate open state, recorded here through the app's first life, in which any authenticated user could:

- open `/admin/download-logs` (the `admin.` route-name prefix was a label, not a gate);
- create, edit and delete divisions, sections and employee directory entries (the directory is the Users module now, and it is SUPERADMIN's);
- read every public document in the office register.

All three are now gated. `HandleInertiaRequests` shares `auth.role` and `auth.permissions`, and `AdminShell` filters `MODULES` against the latter — each entry naming the permission that reaches it. The filter and the middleware are one mechanism in two halves and must change together.

`Staff` is the least-privilege role self-registration assigns: read the directory, and create, revise, withdraw, download and share documents. Not the download register, and no directory write. Note that `documents.write`/`documents.delete` are a *module* capability, not a licence over other people's uploads — `owns()` still decides which record.

This is recorded rather than hidden. If roles are introduced later, the sidebar filter and the route middleware must land in the same change: hiding a sidebar entry while leaving its route open reads as protection that is not there, which is strictly worse than the honest open list.

Registration is self-service (`POST /register`) with no approval step, so anyone who can reach the app can still create an account. What that account *gets* is now the `Staff` role rather than the whole application, which is the point of the floor being least-privilege. An approval step remains the honest fix and is still an open item.

## Routes

### Public

| Method | URI | Action |
| --- | --- | --- |
| GET | `/` | `LandingController@index` |
| GET | `/login`, `/register`, `/forgot-password`, `/reset-password/{token}` | `Auth\*` |
| POST | `/login`, `/register`, `/forgot-password`, `/reset-password` | `Auth\*` |
| GET | `/documents/view/{code}` | `DocumentController@view` — what a QR label resolves to |
| GET | `/documents/track` | `DocumentController@viewByCode` |
| GET | `/track` | redirect to `documents.viewByCode` |
| GET | `/documents/success/{code}/{file_name}` | `DocumentController@success` |
| GET | `/documents/qr/download/{file_name}` | `DocumentController@downloadQr` |
| GET | `/scan` | `DocumentController@scan` — still public for a printed label; no longer in the sidebar, since the Track document modal on `/documents` is the way in |
| GET | `/sections/by-division/{division}` | `SectionController@byDivision` (JSON) |

The document routes are public by design: the gate is the document's own visibility, not the route. `downloadQr` validates the requested filename against a stored `qr_code_path` before touching the disk.

`GET /s/{token}` and `GET /s/{token}/file` are the share-link routes, unauthenticated because the token is the credential. Both re-resolve the link on every request, and both answer 404 for a refusal, a revocation, an expiry, a spent view cap and a token that never existed alike.

`GET /files/{owner}/{file}` is public for the same reason, and gated by `AccessResolver`. Both segments are 26-character opaque identifiers constrained in the route definition, so traversal is refused by the router; the owner segment is verified against the file's real owner, and every refusal answers 404 rather than 403.

`GET /files/viewer/{owner}/{file}` is the one door the private disk opens without a session: Microsoft's Office viewer renders Word, Excel and PowerPoint on their own servers and fetches the file itself. A PDF never comes through here — the browser renders those. It carries the `signed` middleware, the signature expires in minutes, it names one file, a confidential item never gets a URL, and the item's visibility is re-resolved on **every** fetch — because a signature says the URL was issued, not that the item is still shareable. Four segments, so it cannot collide with the three-segment byte route above.

No URL is minted at all when `app.url` names a host the internet cannot resolve — an embedded viewer fetches from *our* server, so a frame pointing at `localhost` can only ever stay empty.

### Authenticated (`auth`)

| Method | URI | Action |
| --- | --- | --- |
| GET | `/dashboard` | `DashboardController@index` |
| GET | `/documents` | `DocumentController@index` — one page, three tabs (all / uploaded by me / shared with me). The All tab needs `documents-all.read`, resolved **in the controller** so a stale link degrades instead of refusing the page |
| GET | `/documents/all` | `DocumentController@all` — a redirect to `/documents?tab=all`, kept for bookmarks |
| GET | `/documents/upload` | `DocumentController@create` — still named `documents.create`. Every literal `documents/<word>` must be declared **before** `documents/{document}` or it is captured as an id |
| action-by-action | `/documents/*` | `DocumentController` (`show` redirects to the tracking-code page) |
| GET | `/documents/{document}/file/{view\|download}` | `DocumentController@accessFile` — returns a file |
| POST | `/documents/{document}/receive` | `DocumentController@receiveFile` — returns a file |
| GET | `/documents/{document}/logs` | `DocumentLogController@index` |
| POST | `/document/log` | `DocumentLogController@store` |
| GET | `/admin/download-logs` | `DocumentController@downloadLogs` |
| resource | `/divisions/*` (no `show`), `/sections/*`, `/users/*` | directory CRUD |
| PUT | `/users/{user}/password` | `UserController@updatePassword` (administrator reset; no current password) |
| GET/PATCH/DELETE | `/profile` | `ProfileController` |
| GET | `/notifications/{id}/read`, `/notifications/mark-all-read` | `NotificationController` |
| GET | `/files`, `/files/trash` | `FileManagerController` — My Files (own storage **and** what was shared) and the navigable trash |
| GET | `/files/browse` | `FileManagerController@browse` — `files.manage` only |
| POST | `/files/{folders,upload,rename,move,copy,delete,purge,restore,visibility,zip,extract}` | `FileActionController` |
| POST | `/files/confirm-code` | `FileActionController@confirmCode` — issues the four-digit delete confirmation into the session |
| GET | `/files/delete-preview` | `FileActionController@deletePreview` — what a recursive delete would take |
| GET | `/files/destinations` | `FileActionController@destinations` — the folders a move or copy may aim at |
| GET | `/files/preview` | `FileStreamController@previewDescriptor` — which viewer this file needs |
| POST/DELETE | `/files/share-links`, `/files/shares/{share}` | `ShareController` — links, and revoking access |
| POST | `/profile/avatar`, `/users/{user}/avatar` | a photo **or** an `avatar_seed`; the service clears whichever was not sent |
| POST | `/logout`, PUT `/password`, GET/POST `/confirm-password` | `Auth\*` |

### File responses

`accessFile`, `receiveFile` and `downloadQr` return `response()->file()` or `->download()`. An Inertia XHR visit cannot follow those, so the frontend reaches them with a plain `<a href>`, a real form post, or `window.location` — never `router.visit`.

## A document's course

(Not a *lifecycle* — there is no state column. See step 4.)

1. **Upload** (`DocumentController@store`) — validate (title, an active `document_types` row, a PDF/DOC/DOCX up to 10 MB, visibility, distinct recipients when private); issue a tracking code; store the file through `FileStorage` onto the **private** `documents` disk, which creates the `files` row the document points at; render the QR label as SVG onto the same private disk; create the row with the type's name and the uploader's name snapshotted; open the trail with an `uploaded` entry; attach and notify recipients if private; redirect to the success page.

   The file used to go to the **public** disk, which meant every document in the office was readable at `/storage/documents/<hash>.pdf` with no authorization check at all. See `documentation/modules/files.md`.
2. **Tracking code** — `ABBR-YYYYMMDD-NNNN` from `App\Services\TrackingCodeService::issue()`. The abbreviation is the `document_types.code`, so the list of types the form offers and the map to prefixes are one table and cannot drift.

   The counter is `max(highest code already on record, the series' own counter) + 1`, read under a row lock inside a transaction. **Both halves are load-bearing:**

   - Reading it back off the codes in `documents` is self-correcting, so importing an office's historical records makes the series continue from where their paper trail left off. On its own, though, deleting the day's last document hands its tracking code to the next upload — two different documents, one code, in the system whose whole job is to be the record of which is which.
   - The `document_series` row never goes backwards, so an issued number is never issued again. On its own it would restart every series at 1 on a fresh install.

   The naive version was wrong in two further ways at once, both covered by tests: counting every document created today gave the second type uploaded that day a number the first series had used, and two uploads arriving together read the same count.

3. **Circulation** — each view, download, acknowledgement and receipt writes a `document_logs` row; a download also registers a `document_downloads` row pointing at that entry. Receipt (`received`) can be logged once per user.
4. **Withdrawal** — there is no fourth stage. The End Action has been removed; an uploader who wants a document out of circulation deletes it, which is soft, so the trail and the register survive. See `documentation/modules/documents.md`.

### Recorded actions

`App\Enums\DocumentAction` is the whole list. The stored value is a slug and the sentence is `label()`, so the wording on screen can be corrected without rewriting history, and a mistyped comparison is a type error rather than a check that quietly never matches — which is how the dashboard's "ended" figure came to be permanently zero.

| Action | `label()` | Written by |
| --- | --- | --- |
| `uploaded` | Uploaded | `store` |
| `viewed` | Viewed file | `accessFile` with `view` |
| `downloaded` | Downloaded file | `accessFile` with `download` |
| `received` | Received and downloaded | `receiveFile`, once per user |
| `acknowledged`, `reviewed`, `returned` | Acknowledged / Forwarded, Reviewed, Returned | `DocumentLogController@store` |

`DocumentAction::postable()` is the subset a user may submit through the hand-off form; everything else is written by the system when it happens. The endpoint used to validate `action` as `string|max:255`, so a client could post any text — including a forged receipt it had not earned.

`DocumentAction::deliveries()` is what counts as having taken delivery (`downloaded`, `received`), read by `User::deliveredDocumentIds()`.

## Known open items

- **Self-service registration with no approval step** (above). A new account lands on `Staff` rather than on the whole application, but nothing stops it being created.
- **Per-user permission overrides have no screen yet.** `spatie`'s `model_has_permissions` works and is covered by a test, so a direct grant can be made from a console; the users module has no UI for it.
- **There is no users-and-roles assignment screen.** A role can be created and edited at `/roles`, but assigning one to an account is still a console operation.
- A person cannot be added to an **existing** folder from the UI: tags are made when a folder is created or a document is uploaded. The service still takes any grantee, so this is one screen's reach rather than a limit of the model.
- **File versioning is deliberately not built.** `documentation/modules/files.md` records what the current shape already supports and the two things that would have to change, so the next phase starts from facts rather than from a re-reading of the code.
- There is no generic audit table (the PROCMIS `audits` / `owen-it/laravel-auditing` layer). Document circulation is covered by `document_logs`; edits to divisions, sections and accounts are not recorded anywhere.
- The schema was consolidated into eleven authoritative `create` migrations; the earlier incremental history, including four empty no-ops, was replaced. A deployment carrying data must dump and restore rather than migrate across.
