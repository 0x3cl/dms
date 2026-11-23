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
| `app/Models/FileVersion.php` | A file as it was before it was replaced — superseded versions only |
| `app/Contracts/StoredBytes.php` | Bytes on a disk, whichever version they are. **Never an authorization subject** |
| `app/Services/Files/FileVersionService.php` | Superseding, reverting, and assembling the history |
| `app/Services/Files/FilePathFactory.php` | **The only place a disk path is built** |
| `app/Services/Files/FileStorage.php` | Everything that reads or writes bytes |
| `app/Services/Files/FolderService.php` | Tree invariants: roots, paths, depths, the cycle guard |
| `app/Services/Access/AccessResolver.php` | **The one place access is decided** |
| `app/Http/Controllers/FileStreamController.php` | Serves bytes at the obfuscated URL |
| `app/Console/Commands/ImportDocumentsIntoStorage.php` | Carries pre-existing documents over |
| `tests/Feature/{FileAccessTest,DocumentImportTest,FileVersionTest}.php` | |

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

Per document: ensure a `Tracked Documents {year}` folder in the uploader's tree (this importer alone still does — it is placing files that *were* filed that way), create the `files` row, copy the bytes to the private disk, set `file_id`, and move the QR label. Idempotent, transactional per document, and a failure is reported and skipped so the rest carry over.

The bytes are **copied, not moved**, and the source is left in place: the command has to be re-runnable, and a half-finished move that has already deleted its source is not recoverable. Only once it reports nothing left to do is it safe to delete `storage/app/public/documents`.

> A document whose file has already gone is skipped rather than given an empty file row. The record, its code and its trail are still the office's account of what happened, and inventing a file would hide that the file is missing.

> A document whose uploader has been deleted is filed under an `UNASSIGNED` holding account. `documents.user_id` is `nullOnDelete`, so that is an expected state, not a corruption — but the bytes still need a folder and a folder needs an owner. The `files` row keeps `user_id` null and its `owner_name` snapshot.

## Versioning

A file is a sequence of versions. The first upload is version 1, a replacement is version 2, and **nothing is ever thrown away**.

**The `files` row is the current version.** Only superseded versions move to `file_versions`; the current one stays on the row, where `storage_path` has always been the single statement of where its bytes are.

That choice is what keeps everything else still:

| Invariant | Why it survives |
| --- | --- |
| `unique(folder_id, name)` | Still one row per file in `files`, so the index never had to move |
| `AccessResolver::scopeVisible()` and `FileListingTest` | A version is not a listed item. The listing rule did not change, and `test_a_version_is_never_a_listed_item` says so |
| `documents.file_id` (UNIQUE) | A document points at the same row forever, so a replacement leaves the tracking code, the stored QR label, the share link **and its token and view count**, every recipient row and the whole trail untouched |
| `public_id`, and therefore every URL | Unchanged by a replacement; `/files/{owner}/{file}` keeps working |
| `item_shares`, `share_links` | Attached to the file, so they survive too |

### What a replacement is

One sentence: **move the current-version descriptor off `files` into `file_versions` verbatim, then write the new descriptor onto `files`.** Nothing is invented and nothing is derived, which is why the history reads out of two tables with one shape.

`App\Services\Files\FileVersionService` does that; `FileManager::replace()` and `::revertTo()` own the authorization, the transaction and the audit row, because every structural file operation in this application goes through that class.

**A row describes the version it holds, not the act that ended it.** `file_versions` carries `created_by_id`/`created_by_name`, `note`, `reverted_from`, `became_current_at` and `superseded_at`, and `files` mirrors the first four as `version_created_by_*`, `version_note`, `version_reverted_from`, `version_created_at`. The obvious alternative — only "who superseded this" — records who put version 3 there on version 2's row, and who put the **current** version there nowhere at all. It cannot be borrowed from `activity_logs` either: that table is behind `activity-trails.read`, while this history must be readable by anyone who may read the file.

> **Trap.** A version carries a full copy of the metadata rather than a diff, because it has to be servable on its own — and because a replacement may be a **different format**. `report.doc` replaced by `report.docx` is ordinary office work, so `extension`, `mime_type`, `size_bytes` and `checksum` are per version, and the viewer is chosen from the version's.

> **Trap.** **Identical bytes are allowed and labelled, not refused.** `replace()` used to throw *"that is the file already stored"* when the incoming checksum matched, on the reasoning that an identical version is a fabricated entry in a history whose purpose is to be truthful. Right goal, wrong means, twice over. It is not true that nothing changed — a replacement rewrites `original_name`, the extension and the MIME type, so re-uploading a document under the name a browser gave it (`template(1).docx` for `template.docx`, which is what downloading your own file and sending it back produces) is a real change that this refused outright. And it fired from the *document edit form*, where the file picker sits beside the title: leaving the same file in it, which is the natural thing to do, failed the whole save and blamed the file for a title that would not update. `FileVersionService::isUnchanged()` is a label now, recorded as `identical_bytes` on the activity row and only when true, so the history stays truthful by saying what happened rather than by refusing to record it.

> **Trap.** Because of that, nothing describing the *bytes* may read `files.name`. The preview descriptor did, so a `.wav` uploaded over a `.docx` drew an audio player titled "template.docx" — mode, size, type and bytes all the new version's, and only the name the old one's. It is `downloadName()` now, which is the version's own `original_name` and what a download of it arrives as.

> **Trap.** A replacement must change the URL the preview is fetched from. `/files/{owner}/{file}` is stable across one by design — `public_id` never changes, which is what the tracking code, the stored QR label and the share link rest on — and the stream response is cacheable for ten minutes, so a descriptor that named the version only when superseded served the *previous* bytes at exactly the URL the new ones live at. An ETag cannot save this: `max-age` means "do not even ask", so the revalidating request is never made. Every preview URL names its version now, which also makes each one immutable, and the thumbnail URL in `FileGrid` carries it for the same reason.

> **Trap.** `files.name` is never rewritten by a replacement. A version is the same file by definition, and a rename would have to consult `assertNameFree()`, which can *refuse* — a replacement refused because somebody's trashed file holds the new name would be absurd. So the listing keeps the name it had while the download name, the viewer and the thumbnail follow the real bytes.

### Paths

`FilePathFactory` gained `forVersion($publicId, $version, $extension)` and `thumbnailForVersion($publicId, $version)`. Still a pure function of values this application generated; the only change is a `-v{N}` segment.

**Version 1 has no segment**, which is why nothing needed rewriting: every path already on disk is exactly what `forVersion(..., 1, ...)` produces. All of a file's versions also land under its own two-character shard, so "everything this file occupies" is one directory listing.

> **The rule: derive on write, read from the column.** The factory decides where the *next* write goes. `files.storage_path` and `file_versions.storage_path` are the only authority at read time, because a version's extension may differ and recomputing a path from the wrong one points at bytes that are not there.

> **Trap.** Thumbnails are versioned for a reason that is not tidiness. At one shared path a replacement would overwrite it, and every superseded image in the history list would render the *newest* picture — a quiet lie in exactly the screen the history exists for.

### A version is not an access subject

`AccessResolver` never learns about versions, and must not. Whoever may read a file may read its history: a version has no visibility, no shares and no links of its own, so `grant()` keeps its `File|Folder|Document` signature and the tracked-document rule still reaches a superseded version through `forFile()` → `forDocument()`.

`App\Contracts\StoredBytes` exists so this stays true in practice. `File` and `FileVersion` both implement it, and `FileStorage`, `ThumbnailGenerator` and `FilePreview` read bytes through it — so serving version 1 is the same code path as serving the current version, rather than `$version?->storage_path ?? $file->storage_path` repeated at nine call sites. Nothing on that interface is ever the subject of an authorization call.

**A download is named after the version it holds.** `report.docx` at version 3 arrives as `report (v3).docx`, through `App\Support\VersionedFilename`. Two downloads of one file used to land under the same name, which is the problem versioning exists to solve reproduced in the one place a reader keeps the file — a folder holding `report.docx` and `report(1).docx` says which one the browser saved second and nothing about which version either is. An existing stamp is replaced rather than appended to, because downloading version 1, re-uploading it as version 2 and downloading again is an ordinary sequence now that an identical replacement is allowed.

> **Trap.** The stamp is applied where a download is *served* — `FileStorage::download()` and the document file route — and never on `StoredBytes::downloadName()`. That method is the file's **name**, and `ActivityLog::record()` snapshots it as `subject_label`: stamping the model put "(v3)" into the trail's record of what a file was called, which is a presentation choice rewriting history. `DataIntegrityTest` caught it, and `test_the_version_stamp_does_not_reach_the_audit_label` holds the line.

> **Trap.** The document page's Download button must carry `?version=` for the version on screen. It was a bare `/documents/{id}/file/download`, which always served the current bytes — so a reader on version 1, with the page warned and watermarked to say so, pressed Download and silently received version 3: the exact failure the warning exists to prevent, handed out by the control beside it. One computed `downloadUrl` feeds both buttons, because two links to one thing is how one keeps a bug the other fixed.

**Full screen is in the URL too.** `?fullscreen=true` beside `?version=`, for the same reason: it is part of what somebody is looking at, so a reload or a pasted link reproduces it rather than dropping the reader back to a closed frame. Only `true` is ever written — the absence of the parameter already means closed, and `?fullscreen=false` on every other URL would be a parameter that says nothing.

**The version history renders in two places and is written once.** `VersionList` is the drawer beside the document and the aside inside the full-screen preview; two copies of "what does a version row look like" is how one of them quietly stops showing something the other learned to show. It decides nothing — which versions exist and whether they may be downloaded are the caller's answers — and `dark` is its only concession to where it is used, because the drawer sits on white and the aside on near-black.

> **Trap.** Showing a version from the *drawer* closes it; showing one from the *full-screen aside* does not. They look inconsistent and are the same rule: close whatever is covering the thing that just changed. The drawer sits over the preview, so leaving it open hides the only visible effect of the press; at full size the file *is* the screen, so closing would throw that effect away.

**And a superseded version is tiled with a watermark.** The badge above the frame says which version is on screen; the watermark says it *on* the document, because the failure case is a screenshot, a print or a glance away — none of which carry a label outside the frame. The **current** file carries none: a mark on every document would make the whole register look like a draft, and a mark that is on everything stops being read. The condition is `shownVersion === null`, the same value the badge, the URL and the preview request read, so the four cannot disagree about which version this is. It is one repeating `background-image` built from an inline SVG rather than a grid of rotated nodes: one element, crisp at any zoom, and the only form that works over an `<iframe>`, whose content nothing on this page can draw inside. The layer is `pointer-events-none`, so the document underneath still scrolls, selects and clicks.

> **Trap.** The tile goes in a CSS `url("…")`, so the SVG uses single-quoted attributes and the whole string is `encodeURIComponent`'d — a double quote would close the url early and a raw `#` would be read as a fragment. Both fail by producing *no background at all* rather than an error, which is the kind of breakage nobody notices until a screenshot turns up without its watermark.

> **Trap.** The watermark is slate, not red, even for a superseded version. The badge and the field ring carry that alarm; a red wash over a page of text makes the text harder to read, which is the opposite of what somebody checking an old version needs.

**Which version a reader is looking at lives in the URL, always.** `documents/show` reads `?version=` on mount and writes it back with `history.replaceState` — including for the current version, so a document opened without one ends up naming the version it is showing. A reload, a bookmark or a pasted link therefore still shows the version that was being read; silently handing back the current file is the one outcome versioning exists to prevent. The parameter is written for the *current* version too because a bare document URL otherwise means "whatever is current when you open it", so a link shared today quietly becomes a link to different bytes after somebody replaces the file. A reader who wants "whatever is latest" deletes the parameter. The number drawn and the number current are tracked separately, since while an older version is on screen the descriptor's `version` is that older one. `replaceState` rather than an Inertia visit, because the page's props do not change when the preview does and a visit would re-render the trail and the recipient list to alter a query string; Inertia's own history state is passed through untouched. A version the file does not have falls back to the current file *and clears the parameter*, rather than leaving an empty frame under an address bar that still claims a version.

`test_a_superseded_version_answers_what_the_current_version_answers` holds the line differentially: for every kind of viewer and both dispositions, `?version=1` must answer exactly what the current version answers. It asserts the property rather than a list of status codes, so it cannot rot.

> **A reclassification protects the history for free.** `effectiveVisibility()` reads the file's own visibility and its ancestors', so marking a file confidential — or moving it into a confidential folder — closes its whole history at the same moment. Do not "optimise" this by caching a version's visibility when it is superseded.

### Who may replace

`FileManager::mayReplace()` is the one source for both the refusal and the `can.replace` flag on a file row, so a button and a 422 cannot disagree.

Two conditions: `FileAbility::Rename` on the file, **and**, when the file carries a tracking code, that the actor owns the document.

> **Trap, and the most security-relevant line here.** `Rename` is reachable through a share — `ShareAbility::Manage` carries it. Without the second condition, anybody holding a manage share could swap the bytes that a tracking code *printed on a folder* names, keeping the code, the label and the link intact, and the uploader would never have approved the content their name sits beside. There is deliberately **no** `FileAbility::Replace`: no grant source wants to express "may replace" independently of "may write", and an ability nothing distinguishes is the dormant-capability lie this file warns about elsewhere.

### Reverting goes forward

Reverting to version 1 **copies it forward** as the next version rather than moving the pointer back, so the history stays append-only and still reads as a true sequence of what the file was over time. `version_reverted_from` records what it is a copy of.

A revert runs no upload-policy check, deliberately: these bytes satisfied the policy once, and re-checking would let a policy tightened since make a file's own history unrevertable — a settings change deciding something about the past.

### Two things that must not be forgotten

1. **`FileStorage::delete()` unlinks every version's bytes.** `file_versions.file_id` cascades, so a purge drops the version *rows* by itself — which is exactly the danger: without this the rows vanish while their bytes stay on disk, referenced by nothing that could ever find them again. Covered by `test_a_purge_removes_every_versions_bytes`.
2. **`replace()` takes `lockForUpdate()` on the row.** Two replacements arriving together would both read version 1 and both try to become version 2. `unique(file_id, version)` turns that into a failed insert rather than a corrupted history; the lock turns it into a correct version 3. Both, not either.

Beyond those, `PurgeTrashedFiles::reasonToKeep()` needed **no** new clause, and the `(folder_id, name)` index needed no change — see the retraction below.

### What the old note predicted, and what actually happened

This section replaced one titled *"Built to allow versioning later, not built for it"*. Its inventory of what already helped was right — `storage_path` derived from `public_id`, one path builder, `documents.file_id` as the whole document→bytes link, a keyless subject morph, morph-based shares, one service with transactions and audit rows, a stored checksum. Two of its predictions were wrong, and both are worth recording so nobody goes looking for a change that never happened:

- It said **"a current-version pointer has to be the thing `(folder_id, name)` is unique over"**. That assumed a `files` row per version, which would indeed have broken the index, since versions share a name by definition. Making the current row *be* the pointer avoids the question entirely: the index is untouched.
- It said **`PurgeTrashedFiles::reasonToKeep()` would need a clause** for "a later version points at it". It does not — a version cannot outlive its file. The clause that *was* needed is in `FileStorage::delete()`, above.

Its trap has been narrowed rather than kept. It said a version action "belongs in `activity_logs` only". That holds for an untracked file; for a **tracked document** the file *is* the document, so a replacement also writes `DocumentAction::FileReplaced` — see `documents.md`. The crossover is written directly by `FileManager::replace()`, not through `FileEventRecorder::DOCUMENT_EQUIVALENTS`, which stays at two entries.

### Retention, stated plainly

Every version is kept indefinitely. **Nothing prunes them**: `files:purge --orphans` only knows `files` rows, so a superseded version's bytes are reclaimed only when the whole file is purged. Storage therefore grows with every replacement. That is a deliberate choice for a document register, and if it ever needs changing, a pruning flag on an audit trail is the most dangerous kind of capability to add quietly.

Pre-existing orphans — the rows the old replacement left behind — are **unattributable by construction** and are swept rather than adopted as version 1. The old `update()` wrote nothing to either trail and overwrote `documents.file_name`, so nothing records which document a given orphan belonged to; all of one uploader's tracked documents for a year sit in one folder, and `created_at` can only order them. Inventing that linkage would put a fabricated version 1 under a real tracking code.

## Testing

`Storage::fake('documents')` **and** `Storage::fake('public')` in `setUp()`: the first for the real path, the second so a legacy-path read in a test cannot reach the real filesystem.

> **Trap.** `actingAs()` persists for the rest of a test. A "signed out visitor" case has to call `Auth::logout()` explicitly, or it passes while asserting nothing. `FileAccessTest` does, and also asserts the resolver directly for a null user.
