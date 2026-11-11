# File Manager

Personal document storage: folders, uploads, renaming, moving, copying, deleting and restoring, with a grid/table toggle and a fullscreen preview.

Built on the pieces that landed before it — the private disk and obfuscated URLs (`files.md`), the access resolver and sharing (`sharing.md`), and the audit spine (`activity-trail.md`). This doc is the screens and the operations.

## Key files

| File | Role |
| --- | --- |
| `app/Services/Files/FileManager.php` | **Every operation.** Authorizes, transacts, records |
| `app/Services/Files/ThumbnailGenerator.php` | Image thumbnails, via GD |
| `app/Http/Controllers/FileManagerController.php` | The three screens and their payloads |
| `app/Http/Controllers/FileActionController.php` | Create, upload, rename, move, copy, delete, purge, restore, privacy, confirmation codes, delete preview, destinations |
| `app/Http/Controllers/ShareController.php` | Share links, revoking access, and the share-state endpoint |
| `app/Services/Files/DeletionConfirmation.php` | Issues and spends the four-digit confirmation code |
| `app/Services/Files/FilePreview.php` | Which viewer a file needs, and the signed Office-viewer URL |
| `app/Enums/FileCategory.php` | The coarse file kinds the filter dialog offers |
| `resources/js/pages/files/{index,browse,trash}.js` | |
| `resources/js/components/{FileGrid,FileTable,FileIcon,ViewToggle,FileBreadcrumbs,ShareDrawer,FilePreviewModal,FolderPickerModal,FileFilterModal,ConfirmDeleteModal,LoadingFrame}.js` | |
| `resources/js/utils/file-rows.js` | One definition of a row's size, badge and action list, for both views |
| `tests/Feature/{FileManagerTest,FileLifecycleTest,FilePreviewTest}.php` | The screens; delete/restore/purge/move/copy; the viewers |

## The controllers decide nothing

Every operation goes through `App\Services\Files\FileManager`, which resolves the ability on the subject — and, for a move or copy, on the **destination** as well — inside the same transaction that writes, then records the activity row.

> **Trap.** Checking only the subject would let someone holding a share on one folder deposit files into another they cannot write. `move()` and `copy()` check both ends.

> **Trap.** A controller that decides access is a second place the rule lives. These ones validate shape and hand over; there is no `can` check in any of them.

Batch endpoints take arrays of `public_id`, revalidate **each item individually**, and run in one transaction — so a partial failure writes nothing.

A refusal is a `ValidationException`, not a 403, so it arrives as the flash the shell already knows how to render. The module permission on the route is the outer gate; the resolver decides which item it applies to.

## Deleting is reversible

`delete()` walks a folder's subtree and soft-deletes it, deepest first, writing **one audit row per subject**.

> **Trap.** This is why `folders.parent_id` is `restrictOnDelete` rather than `cascadeOnDelete`. A database cascade would hard-delete the descendants with no audit row and no way back — exactly what `employees.section_id` used to do, where deleting a section silently deleted everyone recorded in it.

**Restore is resolved against ownership, not the resolver.** A soft-deleted item is out of every listing, so a share on it cannot be the claim; only its owner or an administrator can bring it back.

A root folder cannot be renamed or deleted. It is an implementation detail named `root-{id}` and never rendered.

### A delete is confirmed with a code the server issued

`App\Services\Files\DeletionConfirmation` mints a four-digit code into the session, the dialog displays it, and the delete request carries what the person typed. A mismatch, an expiry or a missing code is a validation error and nothing is touched.

> **Trap.** It is deliberately **not** a browser-side check. A code the page invents and verifies itself is a label on a button: the request underneath it still deletes, so a replayed form, a script or a stale tab bypasses the whole thing. The code is spent on use, so one cannot be harvested and reused — asserted by `test_a_confirmation_code_works_once`.

> **Trap.** Two purposes, not one. `PURPOSE_DELETE` and `PURPOSE_PURGE` are separate because moving something to the trash is reversible and erasing it is not; a code issued for the first does not satisfy the second.

`GET /files/delete-preview` counts what a recursive delete would take — folders, files, bytes, and a few names — so the dialog can state the cost **before** it is paid rather than after. Every subject is resolved through `AccessResolver` first: a count of what is inside a folder is itself a disclosure about that folder.

### Permanent deletion

`FileManager::purge()` erases a trashed item, a folder's whole subtree with it, and **the bytes go with the row** — a `files` row is the only thing that knows where its bytes are, so dropping it without unlinking them leaks storage nothing can ever find again.

The activity row is written **before** the delete, while there is still a name to snapshot. A purge that leaves no record of itself is the one audit gap a purge must not create.

> **Trap.** Only a trashed item, and only its owner or an administrator. Purging straight from a listing would mean an irreversible action one click from a folder view.

### Restoring puts it back where it was

Deleting a folder soft-deletes its subtree, so **a trashed item whose own container is also trashed is the normal case, not an edge one.** `restore()` revives the chain above the item along with it, shallowest first, and `Folder::ancestors()` is deliberately *not* used for that walk — it goes through the model's default query, where the soft-delete scope hides exactly the folders that need reviving.

Three outcomes, all recorded in the activity row's context:

| Situation | What happens | Context |
| --- | --- | --- |
| Its folder is still there | Straight back into it | `to` |
| Its folder is trashed | The chain above it is revived too | `to` |
| The chain cannot be rebuilt | Reattached to the owner's root | `reattached: true` |
| The name is taken | Numbered — `notes (2).pdf` | `renamed_to` |

> **Trap.** A restore has no second chance to ask, which is why it renames rather than refusing. `assertNameFree()` refuses a *move* or a *rename* because the person named a destination and deserves to be told it is occupied; the only alternatives for a restore are leaving the item in the trash forever or overwriting what is there.

> **Trap.** `(folder_id, name)` and `(parent_id, name)` are unique over **soft-deleted rows too**, so a name held by something in the trash is still taken as far as MySQL is concerned. Every name check here uses `withTrashed()`. Before that, re-uploading a filename somebody had deleted earlier the same day was a 500 — covered by `test_a_name_held_by_a_trashed_file_is_numbered_not_refused`.

> **Trap.** The "its folder was erased" branch is a fail-safe, not a routine path: `files.folder_id` and `folders.parent_id` are `restrictOnDelete`, so the database will not let a container be purged out from under a descendant. The branch that *is* reachable is a file of yours inside a folder of somebody else's — which is what taking a copy into a shared folder produces — and they delete the folder.

### A critical operation does not fail silently

`FileManager::attempt()` wraps the transaction behind every structural operation — move, copy, delete, restore, purge. On success the operation writes its own row as before; on failure it writes one carrying `outcome: 'failed'` and the exception's class, then **re-throws untouched** so the caller still answers the way it did.

> **Trap.** The failure row is written *outside* the transaction, after the rollback. Inside, it would be rolled back with the work it was recording — the trap that makes "log the error in the same transaction" useless exactly when it matters.

> **Trap.** Authorization refusals are deliberately outside this. They resolve before the transaction opens, and recording them would let anyone holding an id fill the trail with rows about items they cannot touch.

## The grid/table toggle

`view` (`grid|table`) lives in the **query string**, whitelisted server-side and echoed back in `filters`, alongside `search`, `sort`, `direction`, `entries`, `folder` and `page`.

That is what makes the requirement — "preserve the current page, filters, sorting and search when switching views" — true **by construction** rather than by a behaviour that could drift. It also makes the URL shareable: a colleague opening it sees what the sender saw.

> **Pagination is identical in both views because there is one query.** `FileGrid` and `FileTable` render the same paginator page and the same folder collection, and `Pagination.js` is reused untouched. The counts cannot disagree. Asserted by `test_switching_views_preserves_page_search_sort_and_filters`, which compares the two responses field by field.

Folders are returned **whole**, not paginated: a folder list is the navigation, and paginating it would hide the way out of a deep tree behind a page link.

> **Sorting is new to this codebase.** Before this, no controller read a `sort` parameter and no table header was clickable. The column whitelist is server-side in `FileManagerController::SORTS`; an out-of-whitelist `sort`, `view`, `direction` or `entries` falls back silently rather than erroring.

## Thumbnails: images only

`ThumbnailGenerator` scales images to a 480px long edge and writes webp **to the same private disk**, served through the same gated route with `?variant=thumb`.

> **Images only, and that is a deployment constraint rather than a choice.** These servers have GD but not Imagick — the same reason QR labels are SVG — so a PDF's first page cannot be rasterised. Documents show a MIME-keyed icon tile (`FileIcon`). Do not add PDF thumbnailing without first checking what the target server has installed.

> **A thumbnail of a confidential file is still a disclosure of that file.** It needs the same `view` ability and comes through the same gate, never a public asset. It is sent `Cache-Control: private` so a proxy cannot hold one viewer's thumbnail and hand it to the next.

> Thumbnail requests are **not** recorded. A grid of twenty cards would write twenty preview rows for one glance at a folder, burying the events that matter.

## The preview modal

`FilePreviewModal` fills the viewport over a scrim, with close (X, Escape **and** scrim click), ←/→ navigation across the current page's previewable files, and a Download action rendered only when the resolver granted it.

> **Three behaviours here have no precedent in this design system**, so they are built rather than copied: none of the three existing modals traps focus, closes on Escape, closes on the scrim, or locks body scroll. A fullscreen overlay that leaves the page scrolling behind it and lets Tab wander out is worse than no overlay. The scroll lock is also released in `onBeforeUnmount`, because a navigation can unmount the modal while it is open and would otherwise leave the body permanently unscrollable.

> `h-[100dvh]`, not `h-screen`. On mobile Safari `100vh` is the viewport *without* browser chrome, so a full-height overlay is taller than the screen and its close button sits under the address bar.

> **The iframe points at the stream route, never at `documents.accessFile`.** That route writes a `viewed` trail entry on *every* response, so an embedded frame there would forge a history — claiming the person opened the document on every re-render. The stream route debounces a preview per person per five minutes.

### Which viewer, and who decides

`App\Services\Files\FilePreview` decides, and `GET /files/preview` is where a page asks. Six modes:

| Mode | Rendered as | Source |
| --- | --- | --- |
| `document` — Word, Excel, PowerPoint | iframe | **Microsoft's Office viewer** (`view.officeapps.live.com/op/embed.aspx?src=…`), over a signed URL to our stream |
| `document` — PDF | the same iframe | **our own stream**, rendered by the browser. A PDF is never sent to the Office viewer: it does not handle them |
| `image` | `<img>`, `object-contain` | our stream route, `?disposition=inline` |
| `video` / `audio` | `<video>` / `<audio>`, `controls preload="metadata"` | our stream route, with Range support |
| `none` | a "Download to view" panel | — |

One `document` mode for both, deliberately: the page then has one frame and one set of controls rather than a branch per format. *Which* of the two it is comes from `viewer_url` and `renders_locally`, not from the mode — and a PDF keeps working on an installation no outside service can reach, because it never needed one.

> **Trap.** The page does not choose the viewer. Which one a type needs — and whether the bytes may be handed to one at all — is a property of the file and of the person asking, so it is resolved once, server-side, behind the same `view` ability as the bytes. A template choosing for itself would be choosing whether a confidential document leaves the building. This is also why it is a request per file and not a prop on the listing: forty cards would otherwise mint forty live credentials per navigation.

> **Trap.** Media is served as a real `BinaryFileResponse`, not a streamed one. A `<video>` seeks with a Range request, and a streamed response answers one with the whole file and a 200 — so the player either cannot seek or re-downloads from the start on every scrub.

> **Trap.** `image/svg+xml` is deliberately absent from both the image mode and `FileStorage::INLINE_TYPES`. An SVG is a document that can carry script, and rendering one inline executes it against this origin.

### The Office viewer is the one door the private disk opens

The viewer runs on Microsoft's servers and **fetches the file itself**, so the bytes have to be reachable without our session for as long as the render takes. That is the only relaxation of the private disk in the system, and it is made as narrow as it can be:

- the URL is minted only after `AccessResolver` has granted `view` to the signed-in person asking;
- it is signed, expires in `FilePreview::VIEWER_URL_TTL_MINUTES`, and names one file (`files.viewer`);
- a **confidential** item never gets one — the same rule that stops a confidential item having a share link;
- the visibility is **re-checked on every fetch**, because an item can be reclassified after a URL has been handed out. A signature says the URL was issued, not that the item is still shareable.

### An embedded viewer has to be able to *reach* the file

This is the thing that surprises people, and it is worth saying plainly: **an embedded viewer is not a renderer running in the browser.** Google fetches the URL from their own servers, so the file has to be reachable from the public internet. On a laptop serving `http://localhost:8000`, or inside an office network with no inbound route, no such viewer can ever work however correct the markup is — the frame just stays empty.

So `FilePreview::viewerCanReachUs()` reads `app.url` and, when it is plainly local (`localhost`, a `127.*` address, a `.test` or `.local` domain, a private range, a bare hostname with no dot), **no viewer URL is minted at all** and the empty state says so. PDFs are unaffected.

> **Trap.** `viewer_unreachable` is true only when the *network* is the reason. A confidential Office document has no viewer URL either, and telling somebody "nothing outside this network can reach this file" there would send them looking at a firewall when the answer is that the item is classified.

> **Trap.** Do not add `pdf` to `FilePreview::VIEWER_EXTENSIONS`. The Office viewer does not render PDFs — one handed to it loads a frame that then reports it cannot open the file — and routing them there would also break PDF previews on every install the internet cannot reach. Covered by `test_a_pdf_renders_locally_and_is_never_sent_to_the_viewer`.

> **Trap.** The preview is recorded when the *descriptor* is requested, debounced, not when the bytes are fetched. For an Office document the fetch is made by Microsoft, so recording on that side would write a preview with no actor.

> This does mean an Office document's bytes travel to Microsoft while it is being previewed. That was a deliberate decision, taken over rendering locally (which needs LibreOffice on a deployment target that has neither it nor Imagick) and over refusing those previews entirely. `FilePreview::VIEWER_EXTENSIONS` is the one place to reverse it: empty it and nothing leaves, at the cost of Office previews. PDFs are not part of that trade — they never leave.

## The share drawer is link-based

`ShareDrawer` is a slide-in panel offering **share links** — access (view, or view and download), expiry, a cap on opens, copy and revoke — and, read-only beneath that, who already holds access, with a revoke beside each.

**Naming people happens where the thing is created**: the New folder dialog has a recipient picker, and so does the document upload form. A second place to grant access, opened later from a panel, is how an item ends up shared with people nobody remembers choosing — and the tag is far more likely to be right at the moment somebody is deciding who a folder is for.

> `ShareController::store()` is gone with the picker, along with the directory the share-state endpoint used to ship on every open: every account, division, section and role, for a control that no longer exists. `destroy()` stays, because taking access away has to be possible from wherever you can see it.

> **Consequence worth knowing.** A person cannot be added to an *existing* folder from the drawer. Tag them when the folder is created, or create a folder for them. This is what "limit the sharing menu to link-based sharing only" means in practice.

> **There was no drawer component in this design system.** The only off-canvas panel was baked into `AdminShell`'s mobile sidebar, so this extracts the pattern rather than duplicating it inline.

A link's two parameters are **enforced, not displayed**: `ShareLink::isUsable()` and the item's visibility are both re-evaluated on every redemption. A confidential item reports `linkable: false`, so the panel explains instead of offering a control that would be refused — and a link made before an item was classified stops working at that moment.

## The three screens

| Screen | What it is |
| --- | --- |
| `/files` | The user's own tree **and what has been shared with them** |
| `/files/browse` | Other people's storage, by account — `files.manage` only |
| `/files/trash` | Soft-deleted items: navigable, restore, permanent delete |

### Shared with Me is a filter, not a screen

It used to be its own page on its own permission, which meant a file somebody sent you lived somewhere you had to remember to go and look. The merge is one rule: **at the top of your own storage**, the items other people have shared with you by name, division, section or role are listed beside your own; deeper folders list only their contents, because a shared entry point repeated at every level is noise.

Each row carries `access` — `owner`, `shared`, `public` or `administrative` — resolved from the **grant's own source**, plus `shared_by` from the share row's snapshot. The badge on the card is therefore the same answer the resolver gave.

> **Trap.** The entry points are queried from `item_shares` directly, *not* through `scopeVisible()`. Public items and the administrator's reach are not things somebody *gave* this person, and pulling them in would mean "shared with me" listed every public file in the agency. Covered by `test_my_files_lists_what_was_shared_with_me_beside_my_own`.

> **Trap.** A badge that read "shared with you" for an administrator's `files.manage` view would tell somebody they had been given something they had not. That is why `access` comes from `AccessSource` rather than from `user_id !== $viewer->id`.

> **Trap.** `locateFolder()` gates the `folder` parameter on the **ability**, not on ownership: on My Files the folder in view may belong to somebody else, because that is what opening a shared folder is. A folder the viewer cannot see is a 404 either way — a fall back to their own root would be a different and more revealing answer.

### The filter dialog

`FileFilterModal` holds a **draft** and applies on confirm: sharing (everything / mine / shared with me), kind, file type (`App\Enums\FileCategory`), owner, an uploaded-date range, and sort plus direction. Applied, every one of them goes into the query string like `view` does.

> **Trap.** It is a dialog rather than a row of selects because six filters that each reload the page is six reloads to describe one search. It is *not* local state once applied — a filtered view has to be linkable.

> **Trap.** `FileCategory` matches on **extension first**. Every modern Office document is a zip archive underneath, so its sniffed type would file a `.docx` under Archives. `Other` is expressed as "none of the known extensions", so a format nobody enumerated still turns up somewhere.

> **Trap.** The owner filter is `owned_by`, by `public_id`. `owner` already means "whose storage am I looking at" on Browse Storage, and one name for two questions is how a filter starts quietly narrowing a page it was never applied to.

### Browse Storage is the administrator's

Gated on **`files.manage`** — the same permission `AccessResolver` resolves the reach across everyone else's storage by, so the screen and the rule cannot be granted apart. `files-browse.read` was a second name for one grant and has been retired, along with `files-shared.read` and the never-gating `files.write`.

Its account picker is still scoped to accounts with at least one item the viewer may see, which for an administrator is everyone holding storage.

### The trash is navigable

The top level lists only items whose **container is not itself trashed** — the things that were actually deleted. Deleting a folder trashes its whole subtree, so a flat list showed one act as a hundred rows with no way to tell they belonged together. A trashed folder opens to show what went with it, and every listing in here is `onlyTrashed()`, so nothing is reachable by its ordinary route and nothing is restored by looking at it.

It renders through the **same** `FileGrid` and `FileTable` in `mode="trash"`, which is what gives it a grid and a table without a second listing component to keep in step. Each row carries `restores_to`, so a restore that cannot go home says so before it is clicked.

### Move and copy ask where

`FolderPickerModal` walks the tree from `GET /files/destinations` — one flat request rather than one per level — and the server has already narrowed it to folders this person may **write into**, resolved one at a time. A read-only share is not a destination, and offering one would mean a dialog whose choice the move then refuses.

The folder being moved, and everything inside it, is excluded from the picker: a folder cannot be moved into itself.

> **Trap.** "Copy to my storage" became **Copy**, with a destination. The old shape worked exactly once — for taking something out of somebody else's tree — and was useless for duplicating a file beside itself, which is what people actually asked for. Omitting `target` still means the root, so taking a copy from Browse Storage keeps working.

> **Trap.** A copy of a **folder** takes its contents. This used to be skipped outright — the endpoint ignored anything that was not a file — so selecting a folder and choosing Copy did nothing and reported success. The subtree is walked in one transaction with the ability resolved on **every** descendant, and a descendant the actor cannot copy is skipped and counted in the activity row rather than silently included.

> **Trap.** Unlike a move, a copy does not refuse a name collision: duplicating something into the folder it already sits in is the ordinary case, so the copy is numbered the way a second upload of one name is.

## Per-row abilities

Each file and folder payload carries a server-resolved `can` object, and the kebab menu is built from it.

> **This is the codebase convention and it matters here most.** A module permission says the capability exists for this user; only the resolver knows whether it applies to *this* item. A template reading `auth.permissions` would show a Delete button on a file shared read-only. Covered by `test_each_row_carries_the_abilities_resolved_for_this_viewer`.

## Not done here

- **Drag-and-drop upload.** The modal takes a multi-file input; there is no dropzone.
- **File versioning.** Deliberately not built — see `files.md`, which records what the current shape already supports and the two things that would have to change.
- **Selecting folders in a listing.** The checkbox column is files only, so a bulk Move, Copy or Delete acts on files; a folder is moved, copied or deleted from its own kebab menu.

### Done since this doc was first written

Zip and extract (`ArchiveService`), the administrator-editable upload policy (`upload_settings`), the folder picker behind Move and Copy, a navigable trash with permanent deletion, the filter dialog, Office and media previews, and the merge of Shared with Me into My Files.
