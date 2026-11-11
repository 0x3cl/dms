# File storage

The substrate every document's bytes sit on, and the obfuscated URL they are served at.

## Why it exists

Documents used to be stored with `$file->store('documents', 'public')`. That put every upload on the **public** disk, behind the `public/storage` symlink, which means every document in the office was readable at `/storage/documents/<hash>.pdf` by anyone who had or guessed the URL — **with no authorization check of any kind**. The three read paths also built their paths with `storage_path()` by hand, which hard-coupled them to that disk and put them out of reach of `Storage::fake()`.

## Key files

| File | Role |
| --- | --- |
| `config/filesystems.php` | The private `documents` disk. **No entry in `links`** |
| `app/Models/Concerns/HasPublicId.php` | The opaque identifier, and route binding by it |
| `app/Models/{Folder,File}.php` | The tree and the files in it |
| `app/Services/Files/FilePathFactory.php` | **The only place a disk path is built** |
| `app/Services/Files/FileStorage.php` | Everything that reads or writes bytes |
| `app/Services/Files/FolderService.php` | Tree invariants: roots, paths, depths, the cycle guard |
| `app/Services/Access/AccessResolver.php` | **The one place access is decided** |
| `app/Http/Controllers/FileStreamController.php` | Serves bytes at the obfuscated URL |
| `app/Console/Commands/ImportDocumentsIntoStorage.php` | Carries pre-existing documents over |
| `tests/Feature/{FileAccessTest,DocumentImportTest}.php` | |

## The obfuscated URL

```
GET /files/{ownerPublicId}/{filePublicId}[?disposition=inline]
```

Both segments are `public_id` — 26 characters of `Str::random()`, 155 bits from the CSPRNG, in a `char(26) ascii_bin` unique column. The primary key stays a bigint; this is an extra column, so every existing foreign key, index and join is untouched.

> **Not a ULID or a UUIDv7.** Both lead with a timestamp, so they leak creation order and time. The requirement is that an identifier be *unpredictable*.

> **Not 12 characters**, as the brief's example showed. 48 bits is enumerable by a patient script, on a route whose entire job is to resist enumeration.

Three things make the URL safe, and they are layered on purpose:

1. **The route constraints.** `->where(['owner' => '[A-Za-z0-9]{26}', 'file' => '[A-Za-z0-9]{26}'])`. A segment that must be exactly 26 alphanumeric characters cannot carry `..`, a slash or a null byte, so traversal is refused by the router before any controller code runs.
2. **The owner segment is checked**, not decorative. It must match the file's actual owner or the request 404s, so a file identifier someone was legitimately given cannot be recombined under a different owner segment to probe what else exists.
3. **Everything 404s.** A refusal, a wrong owner and a non-existent file are indistinguishable. A 403 would confirm the identifier is real.

> **Trap.** The 404-for-refusal rule is specific to this route. Everywhere else an unauthorized read redirects to the dashboard with an explanation, because the person is looking at a *page* and deserves one. Here the caller is an iframe, an `<img>` or a download, which cannot render an explanation.

## On disk

Path is `{first two characters of public_id}/{public_id}.{ext}`, built only by `FilePathFactory`.

**Nothing a user supplied ever reaches the filesystem.** The uploaded name is kept as `original_name` metadata, used for the download filename and nothing else.

That is the whole defence against traversal and collision, and it is structural rather than a filter: there is no input to sanitise because there is no input. It is also why extracting a zip will be safe — an archive entry never gets to choose where its bytes land.

The two-character shard keeps the tree from becoming one flat directory of hundreds of thousands of files.

> `throw => true` on the disk, deliberately. The `public` disk's silent `false` on a failed write is how a file that was never stored becomes a confusing 404 much later, at download time.

## Serving bytes

`FileStorage::inline()` renders in place **only** for an allow-list — `application/pdf` and the four common image types.

> **Trap.** This is an allow-list, not a block-list, and it is enforced in the service rather than at each call site. An uploaded SVG or HTML file rendered inline executes its own script against this origin; on a file that is also reachable by a share-link holder, that is a cross-account disclosure. Anything outside the list is handed back as an attachment instead.

Inline responses also carry `X-Content-Type-Options: nosniff` and a `Content-Security-Policy: sandbox`, because a PDF can carry JavaScript and a sandboxed document has no origin of its own to use it against.

## The folder tree

Every account has exactly **one** `is_root` folder, created on demand by `FolderService::rootFor()` under a row lock. Everything else has a parent.

> **Trap.** That one-root rule is what makes `unique(parent_id, name)` work at all. MySQL treats NULLs as distinct, so a unique index over a nullable parent silently permits duplicates at the top level. The constraint is in the index for children and in `rootFor()` for roots — not in one place.

`folders.path` is the materialized ancestor path (`/{public_id}/{public_id}/`). Visibility inheritance and ancestor lookups read it, so they cost one query regardless of depth instead of one per level.

> **Trap.** `path` and `depth` are derived columns. Set them anywhere but `FolderService` and they drift — and a drifted path breaks visibility inheritance, which fails **open** for a confidential subtree whose ancestor chain is wrong.

> **Trap.** `parent_id` and `folder_id` are `restrictOnDelete`, not `cascadeOnDelete`. Deleting a tree is a service operation that soft-deletes the subtree in a transaction and writes an audit row per subject; a database cascade would hard-delete descendants with no record of it. That is exactly what `employees.section_id` used to do, where deleting a section silently deleted everyone recorded in it.

`user_id` is `nullOnDelete` beside an `owner_name` snapshot, the same rule the audit tables follow: a file outlives the account that uploaded it.

## Visibility

`App\Enums\FileVisibility` — `private`, `public`, `confidential`.

**Effective visibility is the most restrictive value on an item and every folder above it.** A file marked public inside a confidential folder is confidential: the rule cannot be widened from below, and it fails closed. It lives in `FileVisibility::mostRestrictive()` so the resolver, the listing scopes and the share-link service cannot each implement it differently.

`FileVisibility::linkable()` is `false` for `confidential`, which is what keeps a confidential item out of a share link.

> `case PublicItem = 'public'` — `public` is a reserved word and cannot be a case name. The *value* is what is stored and what the UI sends.

## Documents are a layer over this

A tracked document is a `files` row **plus** its existing `documents` row — a tracking jacket over the bytes. `documents.file_id` is the whole of the link.

`documents.code` is printed on paper, and `document_logs`, `document_recipients` and `document_downloads` all point at `documents.id`, so repointing those was the disruptive option and adding one foreign key was not. `document_logs` and `document_downloads` are entirely unchanged.

`file_id` is `nullOnDelete`: a document's *record* — its code, its trail, its recipients — must outlive the bytes. With the file gone, `accessFile()` reports the stored file is missing, which it already did for a file deleted off the disk.

> **Trap.** A tracked file's access is decided by the **document's** rule, not by the file's visibility. There must not be a second, looser way to reach a document's bytes than the one the trail and the recipient list were built around. `AccessResolver::forFile()` checks for a document first and defers to it.

### `documents.file_path` is legacy and read-only

It is nullable now and nothing writes it. It holds the old public-disk path so rows that predate the move keep serving, via `Document::storedFile()`, which prefers `file_id` and falls back. Keeping it populated as well would be a second, drifting copy of where a file lives.

## Importing pre-existing documents

```bash
php artisan files:import-documents [--dry-run]
```

Per document: ensure a `Tracked Documents {year}` folder in the uploader's tree, create the `files` row, copy the bytes to the private disk, set `file_id`, and move the QR label. Idempotent, transactional per document, and a failure is reported and skipped so the rest carry over.

The bytes are **copied, not moved**, and the source is left in place: the command has to be re-runnable, and a half-finished move that has already deleted its source is not recoverable. Only once it reports nothing left to do is it safe to delete `storage/app/public/documents`.

> A document whose file has already gone is skipped rather than given an empty file row. The record, its code and its trail are still the office's account of what happened, and inventing a file would hide that the file is missing.

> A document whose uploader has been deleted is filed under an `UNASSIGNED` holding account. `documents.user_id` is `nullOnDelete`, so that is an expected state, not a corruption — but the bytes still need a folder and a folder needs an owner. The `files` row keeps `user_id` null and its `owner_name` snapshot.

## Built to allow versioning later, not built for it

Versioning is a later phase and **is not implemented**. No `version` column, no `version_of_file_id`, no `is_current_version` — a dormant column is the same kind of lie as a permission that gates nothing: it reads as capability and grants none, and the first person to trust it writes against a shape nothing maintains.

What matters is that nothing in the current shape has to be undone to add it. Recorded here so the next phase starts from facts rather than from a re-reading of the code:

| Already true | Why it helps |
| --- | --- |
| `files.storage_path` is derived from the row's own `public_id` | Two rows are two paths. A second version is a new row with its own bytes — no path scheme to invent, and no file to overwrite in place |
| Nothing but `FilePathFactory` builds a path | One place to teach about a version segment, if one is ever wanted |
| `documents.file_id` is the whole of the document→bytes link | Pointing a document at a different version is one column, and `documents.code`, its trail and its recipients are untouched |
| `activity_logs.subject` is a morph **with no foreign key** | A row stays readable after the file it refers to is purged, so a superseded version's history survives it |
| `item_shares` and `share_links` are morphs to the item | A share can be attached to whatever a version turns out to be, without a second sharing table |
| Every operation goes through `FileManager`, which transacts and records | A version swap gets authorization, a transaction and an audit row by being written there |
| `files.checksum` is already stored | "Is this actually a new version?" is answerable without reading bytes |

Two things would have to change, and both are additions rather than rewrites:

1. **A current-version pointer**, however it is modelled, has to be the thing `(folder_id, name)` is unique over. Today that unique index is what makes two files of one name in one folder impossible; versions of one file share a name by definition, so the index has to move to the *current* row or to a parent record.
2. **`PurgeTrashedFiles::reasonToKeep()`** would need a clause for "a later version points at it", beside the ones for a tracked document, a share and a live link.

> **Trap for that phase.** `FileEventRecorder` mirrors exactly two actions into `document_logs`. A new `version_created` action belongs in `activity_logs` only — a document's travel history is rendered to everyone who can read the document, and "somebody replaced the file" is a different audience from "somebody received it".

## Testing

`Storage::fake('documents')` **and** `Storage::fake('public')` in `setUp()`: the first for the real path, the second so a legacy-path read in a test cannot reach the real filesystem.

> **Trap.** `actingAs()` persists for the rest of a test. A "signed out visitor" case has to call `Auth::logout()` explicitly, or it passes while asserting nothing. `FileAccessTest` does, and also asserts the resolver directly for a null user.
