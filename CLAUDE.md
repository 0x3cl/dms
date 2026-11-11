# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository. See `AGENTS.md` for the module source map, working rules and the living-docs policy — this file adds the architectural "why" that `AGENTS.md` intentionally keeps out of the fast path.

## Project overview

**DocTrack** is a Laravel + Inertia document management system for DOST-PTRI. Staff upload an office document; the system stamps it with a tracking code and a printable QR label, and every view, download, acknowledgement and hand-off from that point on is written to an audit trail anyone holding the document can follow. A document is either public — readable by anyone with its tracking code — or private to a named list of recipients, who are notified in-app when it is shared with them.

Beside the tracked-document side sits a file manager: each account's own storage, what has been shared with it, a navigable trash, and an administrator's read across all of it.

Access is role-based: a user holds one role, a role holds `{module}.{action}` permissions, and every module route is gated on one.

## Commands

```bash
composer install && npm install    # install deps
php artisan storage:link           # REQUIRED — uploaded files and QR labels 404 without it
php artisan migrate                # apply schema
php artisan db:seed                # document types + roles (both REQUIRED) + first account (826 / password)
php artisan files:import-documents # once, to move pre-existing uploads off the public disk
composer run dev                   # serve + queue + vite together
npm run build                      # production frontend assets

php artisan test --compact         # full suite (MySQL database `dms_test`)
php artisan test --compact --filter=testName
vendor/bin/pint                    # format PHP after edits
```

CI (`.github/workflows/tests.yml`) runs Pint, the frontend build and Pest against a MySQL service container on every push and PR to `main`/`develop`.

## Architecture

### The frontend is Vue 3 in plain `.js` files, not `.vue` SFCs

Every file under `resources/js/pages` and `resources/js/components` is `defineComponent({ template: \`...\` })` — a JavaScript module whose template is a backtick string. `vite.config.js` aliases `vue` to `vue/dist/vue.esm-bundler.js`, the runtime **plus compiler** build, specifically so those strings compile in the browser bundle; page resolution in `resources/js/app.js` globs `./pages/**/*.js` to match.

This is inherited deliberately from PROCMIS, the sibling system this app's design was ported from, so that a developer moving between the two writes the same code in both. The consequence worth knowing: a template error is a **build-time** failure, not a type error your editor will catch, so run `npm run build` after touching a template.

### The design system is one CSS file plus a dozen components

`resources/css/app.css` is the whole visual language: the `@theme` brand tokens, the `.field` / `.error` / `.required-label` form classes, the custom `<select>` arrow, the table no-wrap rule, and the dark theme. Tailwind 4 is configured CSS-first — there is **no `tailwind.config.js`**, and adding one would be ignored.

Dark mode is the part most worth understanding before changing anything. Every Tailwind 4 color utility compiles to `var(--color-<family>-<shade>)`, so the dark theme remaps those custom properties under `html.dark` rather than adding a `dark:` variant to a few hundred class attributes. A utility keeps its *intent* — `bg-slate-50` is still "the page", `text-slate-900` still "the strongest text" — and only the value changes. Those rules sit **outside** `@layer` on purpose: Tailwind's utilities live in `@layer utilities`, and unlayered CSS beats layered CSS regardless of specificity, which is what lets the handful of per-utility corrections further down the file win. `--color-white` is the one neutral deliberately left alone, because it is both a card surface and a button label; `.bg-white` is patched as its own rule instead, and `ToggleSwitch`'s knob uses the literal `bg-[#ffffff]` so it cannot disappear into its track.

The theme preference lives in `localStorage` under `dms.preferences`, and an inline script in `resources/views/app.blade.php` applies it before first paint so a dark-mode user never sees a flash of light. That script mirrors the key and the shape in `resources/js/preferences.js` — change one and change the other.

See `documentation/design-system.md` for the tokens, the component APIs and the page recipes.

### The schema is one authoritative baseline, and lookups carry codes

`database/migrations` is a set of authoritative `create` migrations rather than an incremental history. The earlier twenty-two included four empty no-ops and several columns nothing read, and the fastest way to make the schema legible was to state it once. The cost is real and worth knowing: a deployment carrying data cannot migrate across to this, it has to dump and restore.

Two conventions run through it, both from PROCMIS:

- **A lookup row carries a `code` as well as a `name`.** `divisions`, `sections` and `document_types` all do. The code is the short, stable handle that survives a rename, which is what makes it safe to print on a label or match against another system; the name is what people read. A section's code and name are unique *within* its division, because two divisions may each run a "Records" section — but one may not run two, which it previously could, quietly splitting that office's staff and audit rows across two rows.
- **A foreign key constrains with intent.** `cascadeOnDelete` only where the child is meaningless without its parent (a section without its division, a recipient row without its document). Everything that is a *record of something that happened* is `nullOnDelete`, so it outlives what it points at. `employees.section_id` used to cascade, so deleting a section silently deleted everyone recorded in it; `users.section_id`, which inherited that job when the two tables merged, nulls.

There was a third convention and it has been retired. `employees` and `users` were two records of the same person: both carried an employee ID, a name and a section, and `employees.user_id` existed only to say that an entry and an account were the same human — a link that granted nothing and that somebody had to remember to make. So an office maintained every person twice, and the two copies disagreed the moment one was edited.

They are one table now, and the Users module is what lists them. The merge migration splits each existing `name`, prefers a linked entry's own halves over that split, turns every unlinked entry into a `Staff` account, and moves `employees.*` to `users.*`. Its `down()` is empty on purpose: the reverse of a merge is a split, and what a split would need — which rows were directory entries and which were always sign-ins — is exactly what the merge discards.

The cost of that is one new rule. `users.first_name` and `users.last_name` are the input; **`users.name` is derived** from them by a `saving` hook on the model, and nothing may write it directly. It stays a stored column rather than an accessor because it is read from far outside the model — every audit row copies it, every file records its owner's, and the download register searches it in SQL, where an accessor cannot be called. A controller that accepts `name` is accepting a value the next save overwrites, which is why `ProfileUpdateRequest` refuses it.

### Document access is one rule, in one place

`DocumentController::isAuthorized()` decides every document read: a public document is readable by anyone, the uploader can always read their own, and a named recipient can read a private one. Nothing else may decide this.

That rule now lives in `App\Services\Access\AccessResolver`, and `isAuthorized()`, `owns()` and `DocumentLogController::canSee()` all delegate to it.

Those were three hand-copied statements of the same thing. `canSee()` had to exist because a document's travel history is exactly as sensitive as the document — a log listing who received a confidential case file is a disclosure of its own — and it carried a comment, as did `isAuthorized()`, saying the pair must change together. A comment is not a mechanism. One resolver is.

The document clauses are reproduced in the resolver **verbatim and in the same order**, because `tests/Feature/DocumentAuthorizationTest.php` is their contract and that file did not move when they were lifted.

Two things about the callers are load-bearing. They keep returning `unauthorizedRedirect()` rather than `$this->authorize()`, because that test asserts a redirect to the dashboard with a flash, not a 403 — the person is looking at a page and deserves an explanation. And the resolver decides a *file's* access by its document's rule when the file carries a tracking code, so the obfuscated file URL cannot become a second, looser way to reach a document's bytes than the one the trail and the recipient list were built around.

Separately, `::owns()` gates editing and deleting. These are not the same question as reading: a *public* document is readable by everyone and still editable only by its uploader.

**A document has no lifecycle state, and that is a removal rather than an omission.** The End Action was taken out at the office's request, and taken out completely: the route, `DocumentController::end()`, `App\Enums\DocumentStatus`, `DocumentAction::Ended`, the `StatusBadge` component and the `documents.status` / `ended_at` columns are all gone, and `AccessResolver::READER_ABILITIES` is a flat constant because ending a document was the only state that ever narrowed a reader's grant.

The alternative was leaving the columns in place and hiding the button. That is worse here for a specific reason: a lifecycle state nothing writes is exactly the condition that produced the original bug in this module, where the dashboard's "ended" count sat at zero for months because one comparison looked for `'ended'` while the writer stored `'Ended'` — and nobody noticed, because nothing depended on the answer. An uploader who wants a document out of circulation withdraws it, which is a delete; `documents` is soft-deleted and the trail is `nullOnDelete` beside a name snapshot, so the history survives.

### Access to a file is resolved once, and listed by the same rule

`App\Services\Access\AccessResolver` decides every read of a file, folder or document, first-match-wins: a share link, then the tracked-document rule, then ownership, then a share on the item, then a share inherited from the nearest ancestor folder, then effective visibility, then the administrator's read. Everything fails closed.

Three things about it are worth knowing before changing anything.

**Inheritance is "most restrictive wins", and the root is excluded.** A file marked public inside a confidential folder is confidential; the rule cannot be widened from below. But every account's root folder is created `private`, and it is a container nobody sees or sets — leaving it in the chain clamped *everything* to private and made a public folder impossible. Excluding it weakens nothing, because a folder created under the root still inherits `private` as its own value; what changes is that it becomes the item's privacy rather than an unalterable ceiling. A test found that, not a reading of the code.

**Confidential is the one level inheritance does not reach into.** A confidential item is reachable only by a share naming the item itself — never inherited from a folder, never through a link. That is what the word means here, and it is why the link check runs again on every redemption rather than only at creation: an item can be reclassified after a link has been handed out.

**The listing is the same rule in SQL, and the two are kept honest by a differential test.** A paginated listing cannot resolve rows one at a time, so `scopeVisible()` restates the rule as a query. Restating a security rule is exactly how two copies drift, so `FileListingTest` builds one scene containing every case the rule distinguishes and, for four viewpoints, asserts the listing returns precisely the set `grant()` allows. It asserts no hand-written expectation, so it cannot rot — and it has already caught the root clamp above and a column collision where `files.path` (the bytes' location on disk) was being searched for a folder id, which silently made every ancestor rule a no-op.

### One screen per question, not one per filter

Two merges, and the same reasoning behind both: **which subset of a list somebody wants is a filter, not a destination.**

"My Documents" and "All Documents" were two sidebar entries over two controller methods and two Vue pages listing one table through different `where` clauses. They are three tabs on one page now — All, Uploaded by me, Shared with me. "Shared with Me" was its own screen on its own permission, which meant a file a colleague sent you lived somewhere you had to remember to go and look; the items shared with a person are listed at the top of their own storage now, each badged so they read apart from their own files.

Three things about the merges are load-bearing.

**A tab can be a different grant, and that grant is resolved in the controller.** "Every document in the office" is not "the documents I am party to", so the All tab still needs `documents-all.read` — but a tab is a filter on one screen, so route middleware would turn a stale link into a refusal of the whole page. `DocumentController::tab()` degrades to "Uploaded by me" instead, and `documents-all.read` is named in `ModuleRegistryTest`'s `$resolvedInCode` because it gates something real that is not a route.

**The shared entry points are *not* `scopeVisible()`.** That scope is the right answer to "what may this person see", which also admits every public item in the agency and, for an administrator, everyone's storage. "What did somebody give me" is narrower, so the entry points are queried from `item_shares` directly — and only at the top of the person's own storage, because a shared folder repeated at every level is noise.

**The badge comes from the grant's own source, not from ownership.** `AccessResolver` already records *why* it allowed something (`AccessSource`), so the card says "Shared with you", "Shared folder", "Office-wide" or "Administrator view" on that authority. Inferring it from `user_id !== $viewer->id` would tell an administrator reading somebody's storage that they had been given it.

The screens that absorbed the removed entries took their routes with them rather than leaving them gating nothing: `/documents/all` is a redirect, `/scan` and `/documents/track` stay public because a QR label must resolve for whoever is holding the folder, and `files-shared.read` and `files-browse.read` were **retired** — the second because Browse Storage is exactly what `files.manage` already meant, and two names for one grant is how a role ends up holding half of it.

### A destructive action is confirmed against the server

`App\Services\Files\DeletionConfirmation` issues a four-digit code into the session; the dialog shows it, the person types it, and the delete endpoint refuses a request whose code does not match, has expired, or has already been spent.

The obvious implementation is to generate and check it in the browser, and it is worth being precise about why that is not a smaller version of this but a different thing entirely: the request underneath such a dialog still deletes on its own, so a replayed form, a script or a stale tab bypasses the whole control. Four digits is not a secret — there are ten thousand of them and anyone who can replay the request can guess — so what it defends against is the muscle memory that confirms a dialog without reading it. Making the server the one that issues and spends it is what turns a label on a button into a gate, and spending it means a confirmation cannot be harvested once and reused.

Two purposes rather than one, because moving something to the trash and erasing it are not the same act: a code issued for the reversible one does not satisfy the irreversible one.

Beside the code, `GET /files/delete-preview` counts what a recursive folder delete would actually take — folders, files, bytes and a few names. "Delete folder?" does not say that, and the count has to be stated before the cost is paid rather than discovered after. Every subject in that count is resolved through `AccessResolver` first: a count of what is inside a folder is itself a disclosure about the folder.

### Restoring is about the chain, not the row

Deleting a folder soft-deletes its whole subtree, so **an item whose own container is also trashed is the normal case in the trash, not an edge one.** Restoring the row alone would leave it inside a folder no listing can reach: present in the table, absent from every screen, and impossible to delete again.

So `FileManager::restore()` revives the chain above the item with it, shallowest first — and deliberately does not use `Folder::ancestors()` for that walk, because that goes through the model's default query, where the soft-delete scope hides exactly the folders that need reviving. When the chain genuinely cannot be rebuilt the item is reattached to the owner's root and the activity row says `reattached`; when the name is taken it is numbered and the row says `renamed_to`. A restore has no second chance to ask, which is why it renames where a move or a rename refuses.

Two details found by writing the tests rather than by reading the code. `(folder_id, name)` and `(parent_id, name)` are unique **over soft-deleted rows too**, so every name check needs `withTrashed()` — before that, deleting `notes.pdf` and uploading another one the same afternoon was a 500. And the "its folder was erased underneath it" branch is a fail-safe rather than a routine path, because `files.folder_id` and `folders.parent_id` are `restrictOnDelete`: the database will not let a container be purged out from under a descendant. The branch that *is* reachable is a file of yours inside a folder of somebody else's, which is what taking a copy into a shared folder produces, and they delete the folder.

### A critical operation records its own failure

Every operation in `FileManager` wrote a row on success. `attempt()` adds the other half for the structural ones — move, copy, delete, restore, purge: on failure it writes a row carrying `outcome: 'failed'` and the exception's class, then re-throws untouched so the caller answers exactly as it did.

The subtlety is *where* that row is written. Inside the transaction it would be rolled back along with the work it was recording, which is the trap that makes "log the error in the same transaction" useless precisely when it matters. So the row is written after the rollback, outside. Authorization refusals are deliberately left out of it: they resolve before the transaction opens, and recording them would let anyone holding an identifier fill the trail with rows about items they cannot touch.

### A preview is a server decision, and the Office viewer is the one door that opens

`App\Services\Files\FilePreview` decides which viewer a file needs — Word, Excel and PowerPoint through Microsoft's Office viewer, PDFs, images, video and audio rendered by the browser from our own stream, everything else honestly reported as having no preview — and `GET /files/preview` is where a page asks, behind the same `view` ability as the bytes.

A page choosing for itself is not merely untidy. Three of the four viewers are served by this application out of the private disk; the fourth hands a URL to a third party. A template deciding between them is a template deciding whether a confidential document leaves the building. It is also why this is a request per file rather than a prop on the listing: forty cards in a folder would otherwise mint forty live credentials on every navigation, all but one of them unused.

Word, Excel and PowerPoint cannot be rendered by a browser at all, and the viewer that can runs on Microsoft's servers and **fetches the file itself** — so the bytes have to be reachable without this application's session for as long as the render takes. That is the only relaxation of the private disk anywhere in the system, and it is made as narrow as it can be: the URL is minted only after the resolver has granted `view` to the signed-in person asking, it is signed and expires in minutes, it names one file, a confidential item never gets one, and the item's visibility is re-checked on **every** fetch — because a signature says the URL was issued, not that the item is still shareable, and an item can be reclassified after one has been handed out. The two alternatives were rendering Office formats locally, which needs LibreOffice on a deployment target that has neither it nor Imagick, and refusing those previews outright.

**PDFs deliberately do not go through it.** The Office viewer does not render them — one handed to it loads a frame that then reports it cannot open the file — and a browser renders a PDF perfectly well on its own. They share the `document` mode so the page has one frame and one set of controls, but a PDF streams from us: which of the two a document is comes from `viewer_url` and `renders_locally`, never from the mode. The practical payoff is that PDF preview keeps working on an installation no outside service can reach, and nothing but an Office file ever leaves.

**And an embedded viewer has to be able to reach us.** It is not a renderer running in the browser; Microsoft fetches the URL from their own servers. On a laptop serving `localhost`, or inside an office network with no inbound route, no such frame can ever load however correct the markup is — so `viewerCanReachUs()` reads `app.url` and mints nothing when it is plainly local, the PDF falls back to the browser, and the empty state says which of the two reasons applies. A spinner that will never resolve is the worst available answer, and it is the default one if nobody checks.

Two smaller things sit here for the same reason. Media is served as a real `BinaryFileResponse` rather than a streamed one, because a `<video>` seeks with a Range request and a streamed response answers one with the whole file and a 200. And `image/svg+xml` is deliberately absent from both the image mode and `FileStorage::INLINE_TYPES`: an SVG is a document that can carry script, and rendering one inline executes it against this origin.

### Sharing is granted where the thing is made

The share drawer offers links and nothing else. Naming a person, a division, a section or a role happens in the New folder dialog and on the document upload form, both of which show the tags as they are made.

That is a reduction in what the UI can do — somebody cannot be added to an *existing* folder from a panel any more — and it is deliberate. A second place to grant access, reached later and out of context, is how an item ends up shared with people nobody remembers choosing; the moment somebody is deciding who a folder is *for* is the moment the answer is most likely to be right. `ShareController::store()` went with the picker, and so did the directory the share-state endpoint used to ship on every open: every account, division, section and role, for a control that no longer exists. Revoking stayed, because taking access away has to be possible from wherever you can see it.

A link's two parameters are enforced rather than displayed. `ShareLink::isUsable()` and the item's effective visibility are both re-evaluated on every redemption, which is the same reason the Office-viewer URL re-checks: an item can become confidential after a link has been sent, and the link has to stop working at that moment rather than when somebody remembers to revoke it.

### Every account has a face, and nobody has to choose one

An account with no uploaded photo used to render two grey initials. Those are legible and completely interchangeable — in a list of thirty staff, half a dozen "DC"s say nothing about which Dela Cruz is which — so a face in a hand-off trail was something to read rather than something to recognise.

Now there are three answers, in order: the uploaded photo, a generated avatar from the seed the person picked, or a generated avatar from their name. The third is what matters: every account has a distinct, stable face from the moment it is created, with nothing to configure. `users.avatar_seed` exists because a name-derived face cannot be changed without changing the name, so the picker stores a seed instead; the style and the framing live in `resources/js/avatar.js` and never in the column, or changing either would mean rewriting every row.

Two rules hold it together. A photo and a seed are **exclusive** — setting either clears the other and deletes the bytes the other was using, because an account holding both renders differently depending on which screen reads it first. And the generated image is a third-party request, so it is allowed to fail: `UserAvatar` falls back to initials on error, which is why it is a component with state rather than a bare `<img>`.

### A delete says what the child is

Three strategies, and which one a foreign key uses is a statement rather than a preference. `nullOnDelete` for anything that is a record of something that happened, or a live record that outlives its office — it must survive what it points at. `cascadeOnDelete` only where the child is meaningless without the parent: a share whose grantee is gone grants access to nobody and must not linger as a half-row that every resolver has to special-case. `restrictOnDelete` on the folder tree, because deleting a tree is a *service* operation that soft-deletes the subtree and writes an audit row per subject, where a database cascade would hard-delete descendants with no record and no way back.

On top of that, soft deletes on `users`, `divisions`, `sections` and `documents` — and the distinction between the two kinds of deletion is the thing to hold onto:

- A **soft** delete protects the **link**. A closed account cannot sign in, because the auth provider applies the model's global scopes, while everything it owns still knows who owns it.
- A **hard** delete protects the **history**. `nullOnDelete` fires and the name snapshot on each audit row is what the page renders.

Both matter, and the tests assert them separately. Before soft deletes, a hard delete preserved the document row and destroyed its link, so a document survived its uploader and immediately had *no owner* — nobody could edit or delete it. That was the intended outcome of a worse earlier design, where `documents.user_id` cascaded and deleting an account deleted its documents outright.

Which is why an account that still owns anything cannot be closed without handing it over. `OwnershipTransferService` moves folders, files and documents, and pointedly leaves two things alone: the audit trail, because ownership is a fact about now and an audit row is a fact about then; and `documents.uploader_name`, because that is the snapshot of who filed a document and is printed beside a tracking code — the successor can edit and end it, they did not upload it.

### An archive never chooses where its bytes land

`App\Services\Files\ArchiveService` extracts a zip without ever calling `ZipArchive::extractTo()`. Every entry is streamed out by index and written to a path *this application* generates, through `FilePathFactory`, from a fresh `public_id` — so no name, directory component or symlink target from the archive reaches a filesystem path.

That is the difference between zip-slip being **impossible** and being **filtered against**: there is no attacker-controlled path to sanitise, because no path comes from the archive at all. The entry-name checks are a second line, kept because refusing an evidently hostile archive tells the person something, where silently flattening it does not.

The same property is why uploads are safe: `files.storage_path` is derived from the row's own identifier, and the name the user typed survives only as `original_name` metadata for the download filename.

Two further things are easy to get wrong here. **Validation runs over the whole archive before the transaction opens**, so a hostile entry halfway through means nothing was written — not "everything up to it". And **disk writes are not transactional**, so the extraction collects every path it writes and unlinks them if the transaction rolls back; without that, a rollback leaves orphaned bytes nothing points at.

### Upload limits are a row an administrator edits

`App\Services\Files\UploadPolicy` reads `upload_settings`, one row per scope, and both upload paths use it. The limits were `mimes:pdf,doc,docx` and `max:10240` written into `DocumentController`, so widening what the office accepts was a deploy.

Two scopes rather than one, because a tracked document and a file in someone's personal storage are not the same kind of thing: a document gets a tracking code and a printed label, so the office keeps those to the formats it files, while a filing cabinet restricted to three document types is not a filing cabinet. One shared policy would force a choice between the two.

The `documents` scope is seeded with exactly the old hard-coded limits, so the module changed nothing on the day it landed. A settings screen that silently loosens the rules on arrival is not a settings screen.

The policy checks the extension **and** the sniffed MIME type, because either alone is bypassable — only the extension lets a renamed executable through, and only the type lets through a file still named `.php`, which is what a misconfigured web server executes. `prohibited_extensions` is applied last so it overrides the allow-list, which is how one risky format is retired without re-auditing everything already permitted. A missing policy row degrades to the document formats with archives off: too strict, never "anything goes".

### A tracking code is a per-type daily series, issued from two sources at once

`App\Services\TrackingCodeService::issue()` builds `ABBR-YYYYMMDD-NNNN`. The abbreviation is `document_types.code` — one table that is both the list of types offered on the upload form and the map to their prefixes, so the two cannot drift. It is a table rather than the PHP constant it used to be because adding a type, correcting an abbreviation or retiring one is office business, not a deploy; `code` is immutable in practice, since it is stamped into every code that type has already issued and those are printed on paper.

The counter is `max(highest code already on record, the series' own counter) + 1`, read under a row lock inside a transaction. The two sources are both load-bearing, and each fixes what the other cannot:

- **Reading it back off the codes in `documents`** is self-correcting: importing an office's historical records is all it takes for a series to continue from where their paper trail left off. On its own, though, deleting the day's last document hands its tracking code to the next upload — two different documents, one code, in a system whose entire job is to be the record of which is which.
- **The `document_series` row** never goes backwards, so a number that has been issued is never issued again. On its own it would restart every series at 1 on a fresh install.

This is the PROCMIS `DocumentSeries` / `DocumentNumberService` pattern, which solves the same problem for procurement document numbers.

It is a service rather than a controller method for three reasons: issuing a number is the one piece of this system that must never be wrong twice, it has to be callable from outside a web request (an importer, a console command), and it needs its own tests.

The obvious implementation was wrong in two further ways at once, both now covered: counting every document created today gave the second type uploaded that day a number the first series had used up, and two uploads arriving together both read the same count.

### QR labels are SVG because these servers have no Imagick

`simple-qrcode` renders PNG through BaconQrCode's Imagick backend, and the deployment target has GD but not Imagick — so `QrCode::format('png')` threw on *every* upload. Labels are SVG now, which needs no extension at all and prints sharper at label size.

The file is named after the tracking code, never the document title. A title is arbitrary user input: it collided between documents of the same name, and because `documents.qrcode.download` is a **public** route that takes the filename, it was a path-traversal vector. `downloadQr()` now matches the requested name against a stored `qr_code_path` before it touches the disk, which also means rows from before the switch keep their `.png` names and keep working.

### Three audit tables, on purpose

`document_logs` is the document's travel history — the upload, views, downloads, acknowledgements and hand-offs — rendered on the document page and its full-history page to anyone who can read the document.

`document_downloads` is the narrower question of whose hands a *copy* ended up in, and it backs `/admin/download-logs`. A download writes a row to both, and the second points at the first through `document_log_id`.

They are not merged, and the reason is the audience rather than the shape: the trail is shown to everyone who can read the document, while the register is a record of file releases and carries the IP a copy went to, which has no business being rendered on a document page.

`document_downloads` existed from the start and nothing ever wrote to it, while `DocumentDownload` was an empty model class that the report still eager-loaded relations from — so the page was permanently blank and would have thrown the moment a row appeared. Both halves are implemented now.

`activity_logs` is the third, added with the file manager: everything that happens to a file or folder, behind `activity-trails.read`.

It is a separate table rather than an extension of `document_logs` for the same reason the first two are separate — the audience. A document's trail is rendered to **everyone who can read that document**, so a `copied`, `extracted` or `link_created` row appearing in it would turn the travel history into a disclosure about the file manager: who took a copy into their own storage, what else an archive held. Exactly two actions cross over, `previewed` and `downloaded`, because they are the only two that already mean the same thing in a document's history. `App\Services\Files\FileEventRecorder` is the one place the two tables are written together, so that rule exists once.

A sibling `file_logs` was the alternative and would have been a third copy of the same snapshot factory. One generic table with a morph subject is one — and its subject morph deliberately carries no foreign key, so a row stays readable after the file it refers to has been purged.

Two things about recording are easy to get wrong and are covered by tests. **A preview is debounced and a download is not**: an embedded iframe re-requests the same file on a resize, a re-mount or a reload, and a row per render is a forged history that says someone opened a document nine times when they opened it once — while each download genuinely is a separate copy leaving the server. And **no audit row ever carries a live credential**: a share link's ability, expiry and view cap are recorded, but never its token, because that would make the audit report a way to obtain the credential it was written to monitor.

### An audit row snapshots its actor; it does not join one

Every row in both tables carries a foreign key **and** a copy of who the actor was and which office they sat in at the time: `actor_id` beside `actor_name`, `division_id` beside `division_name`, `section_id` beside `section_name`. The snapshot is what the page renders. This is the PROCMIS `purchase_request_process` shape, and it is not duplication:

- The **foreign key** is for filtering, joining, and following a row back to the live record. Every one of them is `nullOnDelete`, so the row outlives what it points at.
- The **snapshot** says what was true when it happened.

The old tables stored only the keys, so the history was assembled by joining live rows. Renaming a division silently rewrote where a document had been two years ago; deleting one blanked it; deleting an account took the document with it, because `documents.user_id` cascaded — and `/profile` lets a user delete their own account. An audit trail that changes when the org chart changes is not an audit trail.

The snapshots pay for themselves twice over: the trail pages now need **no eager loading at all**, and the download report's search reads one table instead of running a `whereHas` against `users` and another against `documents` on every keystroke.

Write these rows only through `DocumentLog::record()` and `DocumentDownload::register()`. Those are the one place the shape is built, so every writer produces the same row.

### Statuses, visibilities and actions are enums

`App\Enums\DocumentStatus`, `DocumentVisibility` and `DocumentAction` replace bare strings that were compared by hand across four controllers and three Vue pages. The stored action value is a slug and the sentence is `label()`, which means the wording on screen can be corrected without rewriting history.

This is not tidiness. The dashboard's "ended" count was once permanently zero because one comparison looked for a lowercase `'ended'` while the writer stored `'Ended'`; the hand-off endpoint validated `action` as `string|max:255`, so a client could post any text it liked, including a forged receipt it had not earned. An enum makes the first a type error and `DocumentAction::postable()` makes the second impossible.

`DocumentStatus` has since been deleted outright with the End Action it described, which is the stronger version of the same lesson: the safest state is the one that does not exist.

### File responses are the one place Inertia gets out of the way

`accessFile`, `receiveFile` and `downloadQr` return `response()->file()` or `->download()`. An Inertia XHR visit cannot follow those, so they are reached with a plain `<a href>`, a real form post, or `window.location` — never `router.visit`.

`documents/show.js` is where this is least obvious: "Receive & download" both records an acknowledgement and hands back the file, so it posts a real `<form>` nested inside the confirmation modal, and reads the CSRF token out of the meta tag in the root view because it is not going through Inertia's own handling.

### The QR scanner owns the camera's lifetime

`resources/js/components/QrScanner.js` wraps `html5-qrcode` and stops the stream in `onBeforeUnmount`. That is the whole reason it is a component rather than inline page code: an Inertia visit unmounts the page without reloading the document, so nothing else would ever release the camera. The Blade version loaded the library twice from a CDN — once in the layout, again in the page — and leaked the camera on every navigation.

A scan may carry either a full tracking URL (our own labels) or a bare code (anything else), so `documents/scan.js` normalises both to the same visit.

### Tutorials are frontend-only, and leaner than the port they came from

`resources/js/tutorials/` is a driver.js runner, a registry, and one file per tour. There is no route, no table and no permission of its own: a tour declares the permission of the feature it teaches and `TutorialMenu` filters the list against the shared `auth.permissions` prop, so nobody is offered a walkthrough of a page they cannot open.

The mascot was ported from PROCMIS almost verbatim, assets included, so the two systems read as one product family. Three parts of PROCMIS's runner were deliberately left behind — sample-data pages, the second supplier audience and its costume, and nested overlay tracking — because DocTrack has no tour that uses any of them. Porting that machinery would have been dead code pretending to be capability, which is the same reason `App\Support\Modules` holds only permissions that gate something.

Two details are easy to get wrong. A step whose element is missing must either show a `fallback` and end the tour or be dropped — an empty folder has no first row to point at, and a tour that stops without saying why is worse than no tour. And **an anchor a tour references but no markup defines is a silent break**, because the step is simply dropped; the check is to scan the tour files for `[data-tour="…"]` and diff against the markup, which is how two missing anchors were caught here.

### Flash messages are a modal, not a toast

`HandleInertiaRequests` shares exactly two flash keys, `success` and `error`. `AdminShell` watches both and opens a `FeedbackModal`. There is no toast component anywhere in the design system, and controllers that used to flash a third key (`status`) were normalised to these two.

### Sign-in is by employee ID; email exists only for password reset

`users.employee_id` is the credential. `users.email` is nullable and exists so Laravel's password broker has something to look an account up by — an account without one simply cannot self-serve a reset and needs an administrator.

Authentication goes through `App\Http\Requests\Auth\LoginRequest`, which is also where the throttling lives. The controller previously called `Auth::attempt()` itself and skipped the request class entirely, which meant there was no rate limiting on login at all.

There is no email verification: `users` has no `email_verified_at` column and `User` does not implement `MustVerifyEmail`, so there is nothing to verify. Reinstating it means adding both first.

### Access is role-based, and both halves land together

Permissions are named `{module}.{action}` and the whole vocabulary lives in one place, `App\Support\Modules` — the registry the roles screen, the seeder, the route middleware and the sidebar are all built from. It holds only what actually gates something: a permission that gates nothing is a checkbox granting the administrator nothing, and a name someone will later gate a route on believing it was already assignable.

`App\Support\Roles` names the two roles the *code* depends on. `SUPERADMIN` is short-circuited to allowed by the `Gate::before` hook in `AppServiceProvider` — returning `null` rather than `false` on a miss, so a non-superadmin still falls through to every other check. `Staff` is what self-registration assigns, so whatever it carries is what anyone who can reach the sign-up page gets.

The `admin.` prefix on the download-logs route name used to be a label over an open route; it is a real gate now, because that report carries the IP address every copy of a document went to.

`users` is the one module whose grant deliberately *narrowed* when it was built. Staff used to hold `employees.read` — a people lookup, a list of names and sections. The screen that replaced it is where roles are handed out and other people's passwords are set, so reaching it is reaching the access model itself. Those are not the same grant and the first does not imply the second, so `users.read` is SUPERADMIN's alone and Staff keeps `divisions.read` and `sections.read`: the office structure without the people.

`HandleInertiaRequests` shares `auth.role` and `auth.permissions`, and `AdminShell` filters its `MODULES` array against the latter. Each entry names the permission that reaches it, which is not always `{key}.read` — Browse Storage is reached by `files.manage`, the administrator's read across other people's storage. Entries whose route is deliberately public (track-by-code) carry `permission: null`.

Four entries were folded away rather than hidden, and the distinction is the same one as everywhere else here: All Documents is a tab on Documents, Upload Document is a button on it, Scan QR Code is one half of its tracking modal, and Shared with Me is part of My Files. Each one's route either moved into the page that absorbed it or stayed as a redirect — none was left gating nothing.

**The sidebar filter and the route middleware are one mechanism in two halves, and they land together.** A hidden entry over an open route reads as protection that is not there, which is worse than an honest open list. The sidebar's quick-search reads the same filtered list, so it can never jump someone to a page the sidebar is hiding.

Two distinctions worth keeping straight:

- **A module permission is not a per-record permission.** `documents.delete` says the capability exists for this user; `owns()` still decides which document. That is why `Staff` holds it — withholding it would not protect anyone else's upload, it would stop a user withdrawing their own.
- **`auth.permissions` is for navigation, not authorization.** A button's visibility comes from a server-computed `can` prop on the page; the real gate is the route middleware. Nothing is ever decided by that array.

The CRUD modules are declared action by action rather than through `Route::resource()`, because a resource cannot carry per-action middleware and reading a division is not the same grant as deleting one. Every route *name* is the one the resource generated, so no `to_route()` call or `<Link>` had to change — if you touch that block, diff `php artisan route:list --json` before and after, because a silently renamed route breaks the frontend without failing a test. Ordering is load-bearing there too: every literal `documents/<word>` segment must precede `documents/{document}`, or it is captured as a document id.

The per-document rules (`isAuthorized()`, `owns()`) are a separate, real access model and are unaffected by any of this.

## Documentation map

| File | Owns |
| --- | --- |
| `AGENTS.md` | The fast path: source map, working rules, invariants. Read first. |
| `CLAUDE.md` | This file — architectural "why" for cross-module work. |
| `documentation/project-stack.md` | Stack table and directory map. |
| `documentation/design-system.md` | Tokens, component inventory, page recipes. |
| `documentation/architecture.md` | Request lifecycle, shared props, data model, routes. |
| `documentation/migration-notes.md` | The Blade-to-Inertia conversion record. Append, do not rewrite. |
| `documentation/modules/*.md` | One doc per module. |
