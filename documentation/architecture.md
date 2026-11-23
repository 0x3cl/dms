# Architecture

## Request lifecycle

A browser request hits `public/index.php` → `bootstrap/app.php`, which registers `routes/web.php`, `routes/console.php` and — through its `then:` callback — `routes/auth.php`. The `web` middleware group gets two additions: `HandleInertiaRequests` and `AddLinkHeadersForPreloadedAssets`.

A controller returns `Inertia::render('documents/index', [...])`. On a first visit Inertia renders `resources/views/app.blade.php` with the page object embedded; on a subsequent visit it answers the XHR with JSON and the client swaps the page component. The page name is a path: `'documents/index'` resolves to `resources/js/pages/documents/index.js` through the `import.meta.glob('./pages/**/*.js')` map in `resources/js/app.js`.

`resources/views/app.blade.php` is the only Blade file in the project. It carries the CSRF meta tag, the pre-paint dark-theme script, `@vite`, `@inertiaHead` and `@inertia`.

## Error pages

Two refusals render as Inertia pages, both configured in `bootstrap/app.php`'s `withExceptions()` block and both keeping their real status code.

A permission failure on a **GET** renders `errors/403` inside `AdminShell`, because the sidebar is the answer to "then what *can* I open?"; anything else — a form post, a delete — goes `back()` with the `error` flash the shell already watches for, since a raw 403 body in an Inertia XHR shows the user nothing.

A `NotFoundHttpException` on a **GET** that does not expect JSON renders `errors/404`. Three things about it:

- **The shell depends on who is asking.** Signed in, it is `AdminShell`; signed out it is the standalone framing the share landing and public tracking pages use, because the reader who hits a 404 most often is following a share link and has no account.
- **The subject is derived from the path** — `s/*` is a share miss, `files/*` and `documents/*` a document-or-file miss, everything else a page miss — and it only changes the wording.
- **The wording never distinguishes "revoked" from "never existed".** Every refusal on the share and file routes answers 404 precisely so a token or identifier cannot be confirmed by probing; a page saying "this link has expired" would hand back the fact those routes refuse to state. It lists the possibilities, commits to none, and offers the doors that are real: ask the sender, track a code, or sign in.

A non-GET request and anything expecting JSON fall through to the framework untouched, because a JSON client asking for a missing record wants a status, not a rendered shell. `tests/Feature/NotFoundPageTest.php` holds all of it.

## Shared Inertia props

Defined once, in `app/Http/Middleware/HandleInertiaRequests::share()`:

| Prop | Shape |
| --- | --- |
| `auth.user` | `{ id, name, employee_id, division, section }`, or `null` when signed out. Hand-picked, not the model. |
| `notifications` | Lazy. `{ items: [{ id, message, url, read, created_at }], unread }` from `NotificationController::feedFor()`. |
| `flash.success` / `flash.error` | Lazy session reads. These are the **only** two flash keys in the app. A `success` raises a `ToastStack` toast; an `error` opens `FeedbackModal`. |
| `accessRequest` | Lazy. The tracking code a reader was just refused, in its own session key, so the refusal page can offer "ask the uploader". Never the title — that would disclose the subject of a document to somebody just told they may not read it. |
| `accessRequests.pending` | Eager count of requests addressed to the signed-in user, for the badge on `AccessRequestsMenu`. The worklist itself is **fetched** from `/access-requests`, not shipped on every page. |

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
    │            │           │        ├──< access_requests
    │            │           │        │
    │            │           │        └──> document_types ──< document_series
    │            │           │                   │
    │            │           │                   └──> document_type_groups
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

**`document_types`** — `id`, `group_id` (nullable, `nullOnDelete`), `code` (unique, the tracking-code abbreviation), `name` (unique), `description`, `is_active`, `position`, timestamps, `deleted_at`.

The list of document types *and* the map to their code prefixes, seeded by `DocumentTypeSeeder`. This was `DocumentController::TYPES`, a PHP constant, so adding a type or fixing an abbreviation needed a deploy. `code` is immutable in practice — it is stamped into every tracking code the type has issued, and those are printed on paper. Retire a type with `is_active` rather than deleting it.

**`document_type_groups`** — `id`, `code` (unique, generated and never shown), `name`, `description`, `position`, timestamps.

What arranges the type picker: Documents, Assets, Images, Videos on a fresh install, built once per request by `DocumentType::grouped()` because the upload form and the file manager's tracking dialog both offer it. A table rather than an enum, for the reason `document_types` is one — naming, renaming or reordering a group is office business. `document_types.group_id` is `nullOnDelete`, so deleting a group rearranges the picker and never retires a type; an ungrouped type shows under **Other**, last. See `documentation/modules/document-types.md`.

**`access_requests`** — `id`, `document_id` (**`cascadeOnDelete`**), `document_code` + `document_title`, `requester_id` + `requester_name` + `requester_employee_id`, `division_id`/`division_name`, `section_id`/`section_name`, `owner_id` + `owner_name` (the uploader *at the time of asking*), `message`, `status` (`App\Enums\AccessRequestStatus`, indexed), `decided_by` + `decided_by_name`, `decision_note`, `decided_at`, timestamps.

Somebody asking the uploader to be let into a private or confidential document, and the answer. It follows the audit convention — a key for following the row back, a snapshot beside it for what was true then — but `document_id` cascades rather than nulling, unlike every audit table: a request to be let into a document that no longer exists grants nothing and is not history worth keeping. Granting one writes a `document_recipients` row and nothing else. See `documentation/modules/access-requests.md`.

**`document_series`** — `id`, `key` (unique, a code prefix such as `MEMO-20261110`), `last_number`, `counter`, `document_type_id`, `issued_on`, timestamps.

The high-water mark of one tracking-code series. See the lifecycle section below for why it exists alongside the code scan.

**`documents`** — `id`, `code` (unique), `title`, `document_type_id`, `type`, `file_path`, `file_name`, `qr_code_path`, `file_id`, `user_id`, `uploader_name`, `visibility`, timestamps, `deleted_at`.

**No lifecycle columns.** `status` and `ended_at` went with the End Action; `is_ended`, `target_division_id` and `target_section_id` were leftovers nothing read. A document is on record, and an uploader who wants one out of circulation deletes it.

`document_type_id` is the live type; `type` is its name as it stood at upload. Both on purpose: a tracking code is permanent, so renaming a type must not rewrite what the documents issued under its old name were filed as. `uploader_name` is the same idea for the account, and `user_id` is `nullOnDelete` — the document outlives a deleted uploader. A document with no uploader has no owner, so nobody can edit or delete it; that is the intended outcome.

Indexed on `[user_id, created_at]`, `[document_type_id, created_at]`, `[visibility, created_at]`, `created_at` and `qr_code_path` — every document list is "newest first inside a filter", and none of them had an index.

**`document_logs`** — `id`, `document_id`, `actor_id`, `actor_name`, `actor_employee_id`, `actor_avatar_seed`, `actor_profile_image`, `division_id`, `division_name`, `section_id`, `section_name`, `action`, `version`, `remarks`, `ip_address`, `occurred_at`, timestamps.

The document's travel history. Every row carries a foreign key **and** a snapshot of who the actor was and which office they sat in, and the snapshot is what the page renders — including the actor's **face**: `actor_avatar_seed` and `actor_profile_image` are the two inputs `resources/js/avatar.js` needs, nullable with no backfill, because a face is derived from `actor_name` when they are absent. Renames from the old table: `user_id` → `actor_id`, `received_at` → `occurred_at`; the dead `status` column is gone. `action` is an `App\Enums\DocumentAction` value, not free text.

Indexed on `[document_id, occurred_at]`, `[document_id, actor_id, action]`, `[actor_id, action]` and `[actor_id, occurred_at]`.

**`document_downloads`** — `id`, `document_id`, `document_log_id`, `user_id`, `user_name`, `user_employee_id`, `division_id`, `division_name`, `section_id`, `section_name`, `ip_address`, `downloaded_at`, timestamps.

The register of file releases behind `/admin/download-logs`, snapshotted the same way, with `document_log_id` tying a release back to the trail entry that produced it. Indexed on `downloaded_at`, `[document_id, downloaded_at]`, `[user_id, downloaded_at]` and `user_name`.

**`folders`** — `id`, `public_id` (unique, opaque), `user_id` (`nullOnDelete`), `owner_name`, `parent_id` (`restrictOnDelete`), `is_root`, `name`, `path` (materialized ancestor path), `depth`, `visibility`, soft deletes + `deleted_batch`, timestamps, `unique(parent_id, name)`.

**`files`** — `id`, `public_id` (unique, opaque), `user_id` (`nullOnDelete`), `owner_name`, `folder_id` (`restrictOnDelete`), `name`, `original_name`, `extension`, `mime_type`, `size_bytes`, `checksum`, `disk`, `path`, `visibility`, `source`, `has_thumbnail`, soft deletes + `deleted_batch`, timestamps, `unique(folder_id, name)`.

`deleted_batch` (on both tables, indexed, nullable) is the uuid one delete stamps across every row it touched, so a restore revives exactly that delete's members and nothing else. `deleted_at` cannot stand in for it: second-precision makes two unrelated deletes in the same second indistinguishable.

`files` also carries the **current version's descriptor**: `version` (default 1) beside `version_created_by_id` + `version_created_by_name`, `version_note`, `version_reverted_from` and `version_created_at`.

**`file_versions`** — `id`, `file_id` (**`cascadeOnDelete`**), `version`, `disk`, `storage_path`, `original_name`, `extension`, `mime_type`, `size_bytes`, `checksum`, `has_thumbnail`, `created_by_id` (`nullOnDelete`) + `created_by_name`, `note`, `reverted_from`, `became_current_at`, `superseded_at`, timestamps, `unique(file_id, version)`, indexes on `[file_id, version]` and `checksum`.

Only **superseded** versions. The current one is the `files` row, which is what keeps `(folder_id, name)` unique, keeps a file's `public_id` and URL stable across a replacement, and keeps the current bytes stated once. `file_id` cascades, unlike every key on an audit row: a version has no existence apart from its file, and what *happened* is recorded in `activity_logs` and `document_logs`, which survive independently. See `documentation/modules/files.md` → Versioning.

The storage substrate. `documents.file_id` points a tracked document at its bytes; `documents.file_path` is the old public-disk path, now nullable and read-only. `users.public_id` is the owner segment of a file URL. See `documentation/modules/files.md` for the delete strategies, the one-root rule and why `path` is materialized.

**`activity_logs`** — `id`, `actor_id` (`nullOnDelete`) + `actor_name` + `actor_employee_id`, `division_id`/`division_name`, `section_id`/`section_name`, `subject_type`/`subject_id` (a plain morph, **no foreign key**) + `subject_label` + `subject_public_id` + `subject_version`, `action`, `context` (json), `ip_address`, `user_agent`, `occurred_at`, timestamps.

The third audit table. The subject morph carries no key so a row outlives a purged file; the label is what the page renders. See `documentation/modules/activity-trail.md` for which events cross into `document_logs` and why most must not.

**`item_shares`** — `id`, `shareable_type`/`shareable_id` (File|Folder), `grantee_type` (`user|division|section|role`), `user_id`/`division_id`/`section_id`/`role_id` (nullable, **`cascadeOnDelete`**), `ability`, `granted_by_id` (`nullOnDelete`) + `granted_by_name`, `expires_at`, `revoked_at`, timestamps, unique on the full target tuple.

The grantee keys cascade, unlike every key on an audit row: a share is a live grant, not a record of something that happened, and one whose grantee is gone grants access to nobody. `granted_by_name` is still snapshotted, because "who gave me this" must outlive that person.

**`share_links`** — `id`, `token` (char 64, unique), `shareable_type`/`shareable_id`, `ability` (restricted, view or download only — see sharing), `expires_at`, `revoked_at`, `max_views`, `view_count`, `last_accessed_at`, `created_by_id` (`nullOnDelete`) + `created_by_name`, timestamps.

See `documentation/modules/sharing.md` for the resolution order, the inheritance rules and why every refusal on these routes is a 404.

**`document_recipients`** — `id`, `document_id`, `user_id`, `notified_at`, timestamps, `unique(document_id, user_id)`. The pivot behind `Document::recipients()` ⇄ `User::receivedDocuments()`. The unique pair is new: the same person could be attached twice, which notified them twice and duplicated every visibility check that counted those rows.

**`divisions`** — `id`, `code` (unique), `name` (unique), `description`, timestamps. **`sections`** — `id`, `division_id`, `code`, `name`, `description`, timestamps, `unique(division_id, code)` and `unique(division_id, name)`.

A `code` is the short, stable handle: it survives a rename, which is what makes it safe to print or compare across systems. The section constraints are per division because two divisions may each run a "Records" section — and before they existed, one division could hold the same section twice and quietly split that office's staff and audit rows between two rows.

**`upload_settings`** — `id`, `scope` (unique), `allowed_types` (json: an entry per permitted extension with its own min/max window), `min_size_kb`, `max_size_kb`, archive limits (`max_files_per_upload`, `max_archive_size_kb`, `max_archive_entries`, `max_extracted_kb`, `max_archive_depth`, `allow_nested_archives`), `updated_by_id` + `updated_by_name`, timestamps.

**One row**, and `scope` survives only as its unique key so "there is exactly one policy" is a constraint the database enforces — nothing branches on it. `allowed_types` is the one list an administrator edits; the MIME types an extension may legitimately sniff as, and the refusal of program and script formats, live in `App\Support\FileTypes` in code, because neither is an office decision. The legacy `allowed_extensions`, `allowed_mime_types` and `prohibited_extensions` columns were merged into it by `2026_11_11_000003_unify_the_upload_policy`, which **widens and never narrows**. See `documentation/modules/upload-settings.md`.

**`notifications`** — Laravel's standard database-notification table, plus an index on `[notifiable_type, notifiable_id, read_at]` for the header bell's unread count.

### Snapshots beside foreign keys

Several tables carry both `division_id` and `division_name` (and the same for sections, actors, uploaders and document types). This is the PROCMIS pattern and it is deliberate, not duplication:

- The **foreign key** is for filtering, joining and following a row back to the live record. It is `nullOnDelete` everywhere on an audit row, so the row outlives what it points at.
- The **snapshot** is what the page renders. It says what was true when it happened.

Without the snapshot, the history was assembled by joining live rows: renaming a division silently rewrote where a document had been two years ago, and deleting one blanked it. An audit trail that changes when the org chart changes is not an audit trail. The snapshots also mean the trail pages need no eager loading at all.

The same convention explains the `version_*` columns mirrored onto `files`. A superseded version's descriptor lives in `file_versions`; the current version's lives on the file, so the history reads out of two tables with one shape and no reader needs a join. Mirroring a handful of **snapshots** is the cost this schema already pays everywhere (`owner_name` beside `user_id`); the rejected alternative duplicated the *storage facts* — a second statement of where the current bytes are, which is the one duplication `documents.file_path` is on record as a mistake for.

### The two audit tables are different things

`document_logs` is the document's travel history, shown to anyone who can read the document. `document_downloads` is the narrower question of whose hands a copy ended up in, and backs `/admin/download-logs` — it carries the IP a copy went to, which has no business on a document page. A download writes a row to both, and the second points at the first.

## Access model

### Per-document rules — real, and enforced

`App\Services\Access\AccessResolver::forDocument()` is the single gate for **reading** a document, and `DocumentController::isAuthorized()`, `::owns()` and `DocumentLogController::canSee()` all delegate to it. The clauses are reproduced there verbatim and in the same order, because `tests/Feature/DocumentAuthorizationTest.php` is their contract:

```
public document (not restricted to named people) → allowed
the signed-in user is the uploader               → allowed
the user is in document_recipients               → allowed
otherwise                                         → redirect with an error
```

It is applied by `view()`, `viewByCode()` (track-by-code), `accessFile()` and `preview()`. `canSee()` used to be a hand-copied mirror of it carrying a comment saying the pair must change together — a document's travel history is exactly as sensitive as the document, since a log listing who received a confidential case file is a disclosure of its own. A comment is not a mechanism; one resolver is.

**Three levels, answering two questions.** `public`, `private` and `confidential` (`App\Enums\DocumentVisibility`). Private and confidential read **identically** — the uploader and the people tagged on the document. What separates them is the link: a public or private document can be given a URL that opens without an account, a confidential one never can, so the link path asks `linkableNow()` rather than adding a fourth clause here. The public clause asks `restrictedToNamedPeople()` rather than `=== Public`, so a level added later cannot join the public side by being forgotten.

A reader refused a private or confidential document is offered the chance to **ask** — see `documentation/modules/access-requests.md`. Granting writes one `document_recipients` row, so the clauses above are unchanged by that feature.

`DocumentController::owns()` is a separate question and gates **writing**: `edit`, `update`, `destroy`, the share-link routes and `downloadQr()`. A public document is readable by everyone and still editable only by its uploader.

Covered by `tests/Feature/DocumentAuthorizationTest.php`, and — for the broadcast channel that carries the same trail — `tests/Feature/ChannelAuthorizationTest.php`.

### Module access — role-based

**Access is role-based** (`spatie/laravel-permission`). A user holds exactly one role; a role holds `{module}.{action}` permissions; every module route carries a `permission:` gate. The vocabulary is `App\Support\Modules`, the two code-level roles are `App\Support\Roles`, and `SUPERADMIN` is short-circuited by `Gate::before` in `AppServiceProvider`.

This replaced a deliberate open state, recorded here through the app's first life, in which any authenticated user could:

- open `/admin/download-logs` (the `admin.` route-name prefix was a label, not a gate);
- create, edit and delete divisions, sections and employee directory entries (the directory is the Users module now, and it is SUPERADMIN's);
- read every public document in the office register.

All three are now gated. `HandleInertiaRequests` shares `auth.role` and `auth.permissions`, and `AdminShell` filters `MODULES` against the latter — each entry naming the permission that reaches it. The filter and the middleware are one mechanism in two halves and must change together.

`Staff` is the least-privilege role (`App\Support\Roles::STAFF_PERMISSIONS`), and what an administrator gives an ordinary account: create, revise, withdraw, download and share documents; ask for access to one and see what they asked for; their own storage, including what was shared with them; and the office structure — `divisions.read` and `sections.read`. Not the download register, which carries the IP every copy went to. Not `files.manage`, which is the reach across everyone else's storage. And **not `users.read`**: that is the one grant that narrowed when the staff directory became the Users module, because the screen that replaced a names-and-sections lookup is where roles are handed out and other people's passwords are set. Note that `documents.write`/`documents.delete` are a *module* capability, not a licence over other people's uploads — `owns()` still decides which record.

The sidebar filter and the route middleware are one mechanism in two halves and must land in the same change: hiding a sidebar entry while leaving its route open reads as protection that is not there, which is strictly worse than an honest open list.

There is **no self-registration**. `POST /register` created a working account on the spot with no approval and no check that the employee ID was real; accounts are created by an administrator on the Users screen, which is where the role is chosen deliberately per account. `Staff` is still the least-privilege floor, it is simply no longer what a stranger assigns themselves. See `documentation/modules/auth.md`.

## Routes

### Public

| Method | URI | Action |
| --- | --- | --- |
| GET | `/` | `LandingController@index` |
| GET | `/login`, `/forgot-password`, `/reset-password/{token}` | `Auth\*` — there is no `/register` |
| POST | `/login`, `/forgot-password`, `/reset-password` | `Auth\*` |
| GET | `/sections/by-division/{division}` | `SectionController@byDivision` (JSON) |
| GET | `/s/{token}`, `/s/{token}/file` | `ShareLinkController` — the token is the credential |
| POST | `/s/{token}/identify` | `ShareLinkController@identify` — who the visitor says they are, or "stay anonymous" |
| GET | `/files/{owner}/{file}` | `FileStreamController@show` — opaque ids, gated by `AccessResolver` |
| GET | `/files/viewer/{owner}/{file}` | `FileStreamController@viewer` — `signed`, for the Office viewer only |

**The tracking surface is two doors, and only one of them is public.** `/documents/view/{code}`, `/documents/track`, `/documents/success/...`, `/documents/qr/download/{file_name}` and `documents.preview` sit inside an `auth` group. `/track` (`PublicTrackingController@show`, throttled) does not, and `/scan` forwards a guest to it. A tracking code is **printed on the folder** and is a short, sequential, per-type daily series, so it is not a credential and buys nothing beyond what `AccessResolver` already grants a null user: a public document is described and previewed, a private or confidential one answers with its classification and nothing else, and a signed-in reader the resolver grants `view` is redirected to `documents.view`. Somebody who needs a *copy* is given a **share link**: one URL with an ability, an optional expiry and an optional view cap, revocable, and recorded on every redemption.

`downloadQr` validates the requested filename against a stored `qr_code_path` before touching the disk, and then requires `owns()` — answering **404**, not 403, so "not yours" and "no such label" are indistinguishable. It matters more than it used to: a label now encodes the document's *share link*, which makes the PNG a bearer credential.

The share-link routes are unauthenticated because the token is the credential. Both re-resolve the link on every request, and both answer 404 for a refusal, a revocation, an expiry, a spent view cap and a token that never existed alike. A visitor with no account is asked once, on the landing page, who they are and may decline — `App\Services\Files\GuestIdentity` mints a stable per-device handle for that answer, and nothing is recorded and no view is spent until they give one.

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
| GET | `/documents/{document}/logs` | `DocumentLogController@index` |
| POST | `/document/log` | `DocumentLogController@store` |
| GET | `/admin/download-logs` | `DocumentController@downloadLogs` — `download-logs.read` |
| GET/POST | `/access-requests` | `AccessRequestController` — the worklist as JSON (`access-requests.read`), and asking by tracking code (`access-requests.create`) |
| PATCH | `/access-requests/{accessRequest}` | `AccessRequestController@update` — grant or decline. **No `permission:` middleware**: who may answer is a per-record question, so the controller checks the document's live `user_id` (or SUPERADMIN) |
| GET | `/documents/{document}/preview` | `DocumentController@preview` — which viewer the file needs, for the in-page pane; `?version=N` for a superseded one |
| GET | `/documents/{document}/versions` | `DocumentController@versions` — the file's history, behind the document's own read rule rather than a `permission:` gate |
| POST/PATCH/DELETE | `/documents/{document}/share-links[/{link}]` | `DocumentController` — one live link per document, minted against the **document**, `documents.share` |
| POST | `/documents/{document}/share-links/{link}/regenerate` | `DocumentController` — a new address for that link, terms kept, old URL dead |
| GET | `/document-types`, `/document-types/group/{group}` | `DocumentTypeController` — the group shelf, then what is on it (`{group}` is an id, `other` or `all`) |
| GET/POST/PATCH/DELETE | `/document-type-groups/*` | `DocumentTypeGroupController` — behind the same `document-types.{write,delete}` grants; there is deliberately no Groups module |
| GET/PUT | `/upload-settings` | `UploadSettingController` — the one upload policy row |
| GET | `/activity-trails` | `ActivityTrailController` — `activity-trails.read` |
| GET/POST/PATCH/DELETE | `/roles/*` | `RoleController` — permissions per role |
| resource | `/divisions/*` (no `show`), `/sections/*`, `/users/*` | directory CRUD |
| PUT | `/users/{user}/password` | `UserController@updatePassword` (administrator reset; no current password) |
| GET/PATCH/DELETE | `/profile` | `ProfileController` |
| GET | `/notifications/{id}/read`, `/notifications/mark-all-read` | `NotificationController` |
| GET | `/files`, `/files/trash` | `FileManagerController` — My Files (own storage **and** what was shared) and the navigable trash |
| GET | `/files/browse` | `FileManagerController@browse` — `files.manage` only |
| POST | `/files/{folders,upload,rename,move,copy,delete,purge,restore,visibility,zip,extract}` | `FileActionController` |
| POST | `/files/replace`, `/files/revert` | `FileActionController` — the next version of a file, and bringing an earlier one forward (`files.edit`) |
| GET | `/files/versions` | `FileStreamController@versions` — a file's history, fetched not shipped |
| POST | `/files/confirm-code` | `FileActionController@confirmCode` — issues the four-digit delete confirmation into the session |
| GET | `/files/delete-preview` | `FileActionController@deletePreview` — what a recursive delete would take |
| GET | `/files/destinations` | `FileActionController@destinations` — the folders a move or copy may aim at |
| GET | `/files/preview` | `FileStreamController@previewDescriptor` — which viewer this file needs |
| POST/DELETE | `/files/share-links`, `/files/shares/{share}` | `ShareController` — links, and revoking access |
| POST | `/profile/avatar`, `/users/{user}/avatar` | a photo **or** an `avatar_seed`; the service clears whichever was not sent |
| POST | `/logout`, PUT `/password`, GET/POST `/confirm-password` | `Auth\*` |

### File responses

`accessFile` and `downloadQr` return `response()->file()` or `->download()`. An Inertia XHR visit cannot follow those, so the frontend reaches them with a plain `<a href>` or `window.location` — never `router.visit`.

## A document's course

(Not a *lifecycle* — there is no state column. See step 4.)

1. **Upload** (`DocumentController@store`) — validate (title, an active `document_types` row, a file the **administrator-edited** `upload_settings` row permits by extension *and* by sniffed contents, visibility, distinct recipients when the level is restricted); store the file through `FileStorage` onto the **private** `documents` disk, which creates the `files` row the document points at; then hand the stored `File` to `App\Services\Documents\DocumentRegistrar`, which issues the tracking code, renders the QR label as **PNG** with the seal embedded, creates the document row with the type's name and the uploader's name snapshotted, opens the trail with an `uploaded` entry, mints the share link the label points at, and tags and notifies recipients.

   The registrar exists because tracking an upload has **two doors**: this form, and the Track-this-file dialog in My Files. Everything after the bytes are stored is identical, so it lives in one service and both controllers call it. The limits were once `mimes:pdf,doc,docx` and `max:10240` written into this controller, so widening what the office accepts was a deploy.

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
| `received` | Received | posted from the Log action form, once per user |
| `acknowledged`, `reviewed`, `returned` | Acknowledged / Forwarded, Reviewed, Returned | `DocumentLogController@store` |
| `link_shared`, `link_regenerated`, `link_revoked` | Shared by link, Share link address replaced, Share link revoked | `DocumentController`, when a link is minted, re-addressed or revoked |
| `file_replaced` | File replaced | `FileManager::replace()` / `::revertTo()`, for a tracked document |

`DocumentAction::postable()` is the subset a user may submit through the hand-off form; everything else is written by the system when it happens. The endpoint used to validate `action` as `string|max:255`, so a client could post any text — including a forged receipt it had not earned.

`DocumentAction::deliveries()` is what counts as having taken delivery (`downloaded`, `received`), read by `User::deliveredDocumentIds()`.

## Realtime

Notifications and both audit trails update without a reload, over **Laravel Reverb** (`composer run dev` starts `reverb:start` beside the server, the queue and Vite).

- **A trail broadcasts a signal, not the row.** `App\Events\DocumentTrailUpdated` and `ActivityTrailUpdated` carry an id and a timestamp and are dispatched from `DocumentLog::record()` and `ActivityLog::record()` — the same single factories that build the rows, so nothing can write to a trail without the page hearing about it. They are `ShouldBroadcastNow`, because queued they would reach no browser until a worker ran. A payload would be a second serializer of a row the controller already serializes correctly, it could not be re-authorized per viewer, and *no audit row ever carries a live credential* is an invariant a signal cannot violate.
- **The client decides what to do with it.** `resources/js/composables/useLiveTrail.js` reloads just that prop when the reader is on page one with no filter, and otherwise counts and offers a pill — a viewer on page two must not have page-one rows injected under them, and a filtered viewer must not be told a new row matched their filter when nobody has checked. A burst is coalesced into one reload, and nothing happens while the tab is hidden.
- **`routes/channels.php` is the whole gate.** `/broadcasting/auth` carries `web` and `auth` and **no** `permission:` middleware, so anything a callback forgets is open. `documents.{document}` asks `AccessResolver` — the same rule that decides whether the trail page opens — plus `documents.read`; `activity-trails` asks `activity-trails.read`; `users.{id}` compares ids. `tests/Feature/ChannelAuthorizationTest.php` resolves a matrix of readers and levels through the resolver and asserts the channel answers identically, so the two cannot drift.
- **It fails soft.** `resources/js/echo.js` builds no connection without `VITE_REVERB_APP_KEY` and `listen()` returns a no-op unsubscribe, so every page still works from its Inertia props on a deployment with no websocket. `phpunit.xml` sets `BROADCAST_CONNECTION=null`, so the suite needs no server.
- **`REVERB_APP_SECRET` must never gain a `VITE_` twin.** Anything prefixed that way is compiled into the browser bundle. Only the key, host, port and scheme are exposed.

See `documentation/modules/realtime.md`.

## Known open items

- ~~Self-service registration with no approval step.~~ **Closed by removal.** `/register` is gone — controller, page and both routes. Accounts are created by an administrator on the Users screen, which is also where the role is chosen. See `documentation/modules/auth.md`.
- **Per-user permission overrides have no screen yet.** `spatie`'s `model_has_permissions` works and is covered by a test, so a direct grant can be made from a console; the users module has no UI for it.
- ~~There is no users-and-roles assignment screen.~~ **Done.** A role is created and edited at `/roles` and assigned on the user form; `UserController` refuses the two changes that would lock the application out of its own access model — changing your own role, and removing the last SUPERADMIN.
- A person cannot be added to an existing **folder** from the UI: folder tags are made when the folder is created. A tracked **document**'s recipient list *is* editable, on the edit form that made it. The service takes any grantee either way, so this is one screen's reach rather than a limit of the model.
- ~~File versioning is deliberately not built.~~ **Done.** The `files` row is the current version and superseded ones move to `file_versions`; see `documentation/modules/files.md` → Versioning.
- There is no generic audit table (the PROCMIS `audits` / `owen-it/laravel-auditing` layer). Document circulation is covered by `document_logs`; edits to divisions, sections and accounts are not recorded anywhere.
- The schema was consolidated into a set of authoritative `create` migrations; the earlier incremental history, including four empty no-ops, was replaced. A deployment carrying data must dump and restore rather than migrate across. Everything added **since** that baseline is an ordinary forward migration (`2026_11_1*`, `2026_11_12_*`, `2026_11_13_*`), so an installation already on the baseline migrates normally.
- `App\Support\Modules::ALWAYS_VISIBLE` still carries `scan` and `track`, and its comment still says those routes are public. They are behind `auth` now, so the entries are harmless — every viewer of the sidebar is signed in — but the comment is wrong and the list is wider than it needs to be.
