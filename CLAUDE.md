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

**A document now has three levels, and they answer two questions rather than one.** Public is readable by anyone holding the tracking code; private and confidential are readable by the uploader and the people tagged on the document — *identically*. What separates them is the link: a public or private document can be given a URL that opens it without an account, and a confidential one never can. That is not a redundancy but the whole distinction the office asked for: a private document is restricted and still something you can send to a colleague with no account, while a confidential one is the named list and nothing else, so no URL exists that would open it for whoever holds it. Whether a private document's link *is* such a URL is a term on the link rather than a property of the level — minted restricted, widened deliberately; see "One link per item".

Which is why `AccessResolver::forDocument()` has three clauses and not four — confidential joins the recipient clause — and the link path asks `linkableNow()` instead. A fourth clause repeating the third would be a second statement of one rule, which is what this resolver exists to end. The public clause asks `restrictedToNamedPeople()` rather than `=== Public`, so a level added later cannot join the public side by being forgotten.

A document's link is minted against the **document**, never against its `File` row. The document is what somebody decided to share, what the trail records, and what carries the classification the link has to obey; a link on the file would answer to the file's own visibility and become precisely the looser second door that `forFile()` exists to close. `linkCovers()` is the one place that indirection lives — a document's link reaches that document's bytes and nothing else.

**The recipient list is optional at every level.** Confidential required one, because a confidential document with nobody tagged is readable by its uploader alone and by no link. That reading was right and the conclusion drawn from it was wrong: the refusal did not produce a tagged document, it produced an arbitrarily tagged one, since the way past the form was to name whoever came to mind — and a tag somebody has to notice and undo is worse than an empty list, which is just a document waiting to be addressed. Filing before you know who it goes to is ordinary office work. The form says what the consequence is; tagging somebody later is an edit.

The document **type** is a tag and gates nothing. It picks the tracking-code prefix and labels the row, so a document of type "Confidential" is not thereby confidential; somebody has to classify it.

That rule now lives in `App\Services\Access\AccessResolver`, and `isAuthorized()`, `owns()` and `DocumentLogController::canSee()` all delegate to it.

Those were three hand-copied statements of the same thing. `canSee()` had to exist because a document's travel history is exactly as sensitive as the document — a log listing who received a confidential case file is a disclosure of its own — and it carried a comment, as did `isAuthorized()`, saying the pair must change together. A comment is not a mechanism. One resolver is.

The document clauses are reproduced in the resolver **verbatim and in the same order**, because `tests/Feature/DocumentAuthorizationTest.php` is their contract and that file did not move when they were lifted.

Two things about the callers are load-bearing. They keep returning `unauthorizedRedirect()` rather than `$this->authorize()`, because that test asserts a redirect to the dashboard with a flash, not a 403 — the person is looking at a page and deserves an explanation. And the resolver decides a *file's* access by its document's rule when the file carries a tracking code, so the obfuscated file URL cannot become a second, looser way to reach a document's bytes than the one the trail and the recipient list were built around.

Separately, `::owns()` gates editing and deleting. These are not the same question as reading: a *public* document is readable by everyone and still editable only by its uploader.

**A document has no lifecycle state, and that is a removal rather than an omission.** The End Action was taken out at the office's request, and taken out completely: the route, `DocumentController::end()`, `App\Enums\DocumentStatus`, `DocumentAction::Ended`, the `StatusBadge` component and the `documents.status` / `ended_at` columns are all gone, and `AccessResolver::READER_ABILITIES` is a flat constant because ending a document was the only state that ever narrowed a reader's grant.

The alternative was leaving the columns in place and hiding the button. That is worse here for a specific reason: a lifecycle state nothing writes is exactly the condition that produced the original bug in this module, where the dashboard's "ended" count sat at zero for months because one comparison looked for `'ended'` while the writer stored `'Ended'` — and nobody noticed, because nothing depended on the answer. An uploader who wants a document out of circulation withdraws it, which is a delete; `documents` is soft-deleted and the trail is `nullOnDelete` beside a name snapshot, so the history survives.

**And a withdrawal is reversible, which it was in the data and was not on any screen.** The row was soft-deleted from the start, but nothing listed a withdrawn document and no route restored one — so Delete was a one-way door whose only undo was a hand-written `UPDATE`, and the dialog said "cannot be undone", which was true of the screens and false of the table. The register's Deleted tab is `Document::onlyTrashed()->uploadedBy($user)` and `POST /documents/{document}/restore` clears the stamp; the tab appears only when it holds something, lists the viewer's own uploads even for an administrator, and the route binds `withTrashed()` or the model binding hides every subject it exists to reach. Restoring writes no trail row, because withdrawing writes none: a restore recorded on its own would say a document came back from somewhere it is never recorded as having gone. **The Trash lists withdrawn documents as well**, in a section of their own — it is the screen named after "I deleted something, where is it", so anything soft-deleted that appears nowhere on it is something people hunt for. That is one list in two places rather than two lists: both read `Document::withdrawnBy()`. On the Trash they are rows in the same `FileGrid` and `FileTable` the files use — a panel of their own read as two trashes on one screen, and left the table beneath it saying "nothing here" while the trash held five things. What a row *is* differs (a document shows "The register" where a file shows its folder, and "File kept" where a file shows its size, because withdrawing a document never deleted its bytes); what a trash is does not. A document has no folder, so it is listed at the top level only and never inside a deleted folder. The file is untouched either way — deleting a document removes the register entry, not the bytes, which stay in My Files and which `files:purge` still refuses to collect while a trashed document points at them.

### Access to a file is resolved once, and listed by the same rule

`App\Services\Access\AccessResolver` decides every read of a file, folder or document, first-match-wins: a share link, then the tracked-document rule, then ownership, then a share on the item, then a share inherited from the nearest ancestor folder, then effective visibility, then the administrator's read. Everything fails closed.

Three things about it are worth knowing before changing anything.

**Inheritance is "most restrictive wins", and the root is excluded.** A file marked public inside a confidential folder is confidential; the rule cannot be widened from below. But every account's root folder is created `private`, and it is a container nobody sees or sets — leaving it in the chain clamped *everything* to private and made a public folder impossible. Excluding it weakens nothing, because a folder created under the root still inherits `private` as its own value; what changes is that it becomes the item's privacy rather than an unalterable ceiling. A test found that, not a reading of the code.

**Confidential is the one level inheritance does not reach into.** A confidential item is reachable only by a share naming the item itself — never inherited from a folder, never through a link. That is what the word means here, and it is why the link check runs again on every redemption rather than only at creation: an item can be reclassified after a link has been handed out.

**The listing is the same rule in SQL, and the two are kept honest by a differential test.** A paginated listing cannot resolve rows one at a time, so `scopeVisible()` restates the rule as a query. Restating a security rule is exactly how two copies drift, so `FileListingTest` builds one scene containing every case the rule distinguishes and, for four viewpoints, asserts the listing returns precisely the set `grant()` allows. It asserts no hand-written expectation, so it cannot rot — and it has already caught the root clamp above and a column collision where `files.path` (the bytes' location on disk) was being searched for a folder id, which silently made every ancestor rule a no-op.

### One screen per question, not one per filter

Two merges, and the same reasoning behind both: **which subset of a list somebody wants is a filter, not a destination.**

"My Documents" and "All Documents" were two sidebar entries over two controller methods and two Vue pages listing one table through different `where` clauses. They are tabs on one page now — All, Uploaded by me, Shared with me, and Deleted where the viewer has withdrawn something. "Shared with Me" was its own screen on its own permission, which meant a file a colleague sent you lived somewhere you had to remember to go and look; the items shared with a person are listed at the top of their own storage now, each badged so they read apart from their own files.

Three things about the merges are load-bearing.

**A tab can be a different grant, and that grant is resolved in the controller.** "Every document in the office" is not "the documents I am party to", so the All tab still needs `documents-all.read` — but a tab is a filter on one screen, so route middleware would turn a stale link into a refusal of the whole page. `DocumentController::tab()` degrades to "Uploaded by me" instead, and `documents-all.read` is named in `ModuleRegistryTest`'s `$resolvedInCode` because it gates something real that is not a route.

**The shared entry points are *not* `scopeVisible()`.** That scope is the right answer to "what may this person see", which also admits every public item in the agency and, for an administrator, everyone's storage. "What did somebody give me" is narrower, so the entry points are queried from `item_shares` directly — and only at the top of the person's own storage, because a shared folder repeated at every level is noise.

**The badge comes from the grant's own source, not from ownership.** `AccessResolver` already records *why* it allowed something (`AccessSource`), so the card says "Shared with you", "Shared folder", "Office-wide" or "Administrator view" on that authority. Inferring it from `user_id !== $viewer->id` would tell an administrator reading somebody's storage that they had been given it.

**The register's All tab carries the same badge, and asks the live list for it.** All is every document the office has logged, so a row somebody is party to is otherwise indistinguishable from one their role merely lets them see — the thing most worth knowing on a register that is mostly other people's work. It is `withExists` over `Document::recipients()`, which is a boolean per row rather than every document's tagging eager-loaded to render a chip, and which carries `wherePivotNull('revoked_at')` — so a removed viewer stops being told the document is shared with them without the listing restating that rule. The other two tabs do not show it: there the tab has already said it, and a badge on every row is noise.

The screens that absorbed the removed entries took their routes with them rather than leaving them gating nothing: `/documents/all` is a redirect, `/documents/track` moved behind `auth` while `/scan` forwards a visitor to the public `/track` (see "A tracking code opens one page" below), and `files-shared.read` and `files-browse.read` were **retired** — the second because Browse Storage is exactly what `files.manage` already meant, and two names for one grant is how a role ends up holding half of it.

### An irreversible action is confirmed against the server

`App\Services\Files\DeletionConfirmation` issues a four-digit code into the session; the dialog shows it, the person types it, and the **purge** endpoint refuses a request whose code does not match, has expired, or has already been spent.

The obvious implementation is to generate and check it in the browser, and it is worth being precise about why that is not a smaller version of this but a different thing entirely: the request underneath such a dialog still deletes on its own, so a replayed form, a script or a stale tab bypasses the whole control. Four digits is not a secret — there are ten thousand of them and anyone who can replay the request can guess — so what it defends against is the muscle memory that confirms a dialog without reading it. Making the server the one that issues and spends it is what turns a label on a button into a gate, and spending it means a confirmation cannot be harvested once and reused.

**Moving to trash no longer carries a code, and that is the correction rather than a relaxation.** There were two purposes, on the reasoning that moving something to the trash and erasing it are not the same act and a code issued for the reversible one must not satisfy the irreversible one. That reasoning was right, and the conclusion drawn from it was wrong: it put the *same* friction on both. Friction applied to everything stops being read — people learned to type the four digits as the way to get past the dialog, which is precisely the muscle memory the code was introduced to interrupt. Worse, the reversible act was the heavier dialog on screen, a `max-w-3xl` card with a code entry, while the one that cannot be undone looked identical.

So it is spent only where nothing can undo the outcome. Trash is an ordinary small confirmation that still states what goes with it, because that count is information the reader needs either way. `$purpose` survives as a validated parameter with a single value, because the purpose is what binds a code to the act it was issued for, and `/files/confirm-code` refuses to mint one for anything else: a code nothing will check is a credential handed out for nothing.

Beside the code, `GET /files/delete-preview` counts what a recursive folder delete would actually take — folders, files, bytes and a few names. "Delete folder?" does not say that, and the count has to be stated before the cost is paid rather than discovered after. Every subject in that count is resolved through `AccessResolver` first: a count of what is inside a folder is itself a disclosure about the folder.

### Restoring is about the delete, not the row

Deleting a folder soft-deletes its whole subtree, so **an item whose own container is also trashed is the normal case in the trash, not an edge one.** Restoring the row alone would leave it inside a folder no listing can reach: present in the table, absent from every screen, and impossible to delete again.

So `FileManager::restore()` revives the chain above the item with it, shallowest first — and deliberately does not use `Folder::ancestors()` for that walk, because that goes through the model's default query, where the soft-delete scope hides exactly the folders that need reviving. When the chain genuinely cannot be rebuilt the item is reattached to the owner's root and the activity row says `reattached`; when the name is taken it is numbered and the row says `renamed_to`. A restore has no second chance to ask, which is why it renames where a move or a rename refuses.

**It also revives everything the same delete took, and that needs an identifier rather than a timestamp.** Reviving the chain *above* an item was only half the job: restoring a trashed folder brought the folder back empty, because nothing walked downward — its children stayed soft-deleted, and since the trash lists only what was deleted directly, they were unreachable from My Files *and* from the trash, while the dialog promised the opposite. No test caught it because every restore test used a single file.

"Revive everything trashed under here" is the wrong fix. A child somebody threw away last week, inside a folder deleted today, must stay in the trash when the folder comes back — that person never asked for it back. So `files.deleted_batch` and `folders.deleted_batch` carry a uuid stamped across every row one delete touched, and `restoreSubtreeOf()` revives exactly the rows sharing the stamp. `deleted_at` cannot do this job: it is second-precision, so two unrelated deletes in the same second are indistinguishable, and inferring intent by comparing timestamps is the kind of guess an audit trail must not make. Both columns are nullable with no backfill, and a null batch restores as before — item and ancestors only — rather than dragging back every other unstamped row.

Two details found by writing the tests rather than by reading the code. `(folder_id, name)` and `(parent_id, name)` are unique **over soft-deleted rows too**, so every name check needs `withTrashed()` — before that, deleting `notes.pdf` and uploading another one the same afternoon was a 500. And the "its folder was erased underneath it" branch is a fail-safe rather than a routine path, because `files.folder_id` and `folders.parent_id` are `restrictOnDelete`: the database will not let a container be purged out from under a descendant. The branch that *is* reachable is a file of yours inside a folder of somebody else's, which is what taking a copy into a shared folder produces, and they delete the folder.

### A file is a sequence of versions, and the current one is the row

Replacing a file used to be unrecorded, and in the one place it already happened it quietly lost the old one: `DocumentController::update()` stored the replacement as a **new `files` row**, repointed `documents.file_id` at it and left the previous row orphaned — nothing in either trail, no transaction, not even a thumbnail. `files:purge --orphans` exists to sweep up that wreckage, which is the clearest statement available that the feature was unfinished. In personal storage the same act was worse disguised: re-uploading a file of one name produced `notes (2).pdf`, so the second version of a document read as a second, unrelated document.

**The `files` row is the current version; only superseded versions move to `file_versions`.** The obvious alternative — a row per version with a current-version flag — is what `documentation/modules/files.md` predicted, and it would have cost the `(folder_id, name)` unique index, because versions share a name by definition. MySQL also cannot express "exactly one current row per file" as an index, so that pointer would have been a PHP-only invariant in a schema that keeps this class of rule *in* the index.

Keeping the current row as the pointer is what makes a replacement leave everything else standing: the tracking code, the stored QR label, the share link **and its token and view count**, every recipient row, the whole trail, and the file's `public_id` and therefore its URL. `documents.file_id` never changes, which is the single fact all of that rests on. It also means the current bytes are stated in exactly one place, `files.storage_path`, as they always were — a `file_versions` row for the current version would be a second statement of that, and the first drift is a download serving the wrong bytes.

A replacement is therefore one sentence: **move the current-version descriptor off `files` into `file_versions` verbatim, then write the new descriptor onto `files`.** Which is why each row describes *the version it holds* rather than the act that ended it, and why `files` mirrors the same five columns. The tempting shape — only "who superseded this" — records who put version 3 there on version 2's row and who put the **current** version there nowhere at all; and it cannot be borrowed from `activity_logs`, because that table is behind `activity-trails.read` while this history must be readable by anyone who may read the file.

**One mechanism, two doors**, as with a tracked upload: the document edit form and My Files both call `FileManager::replace()`, which owns the ability check, the row lock, the transaction and the audit row — including the failure row — because every structural file operation in this application goes through that class.

**A version adds no reach, and `AccessResolver` never learns about one.** A version has no visibility, no shares and no links; whoever may read a file may read its history, resolved against the `File` or, for a tracked file, its `Document` exactly as before. `App\Contracts\StoredBytes` exists to keep that true in practice — `File` and `FileVersion` both implement it, so serving version 1 is the same code path as serving the current version instead of `$version?->storage_path ?? $file->storage_path` repeated at nine call sites — and nothing on that interface is ever an authorization subject. A differential test asserts that `?version=1` answers exactly what the current version answers, for every kind of viewer.

Two decisions about who and where are worth stating, because both are narrower than they look. **Only a tracked document's uploader may replace its file**, even against somebody holding a manage share on the `files` row: `FileAbility::Rename` is reachable through such a share, and without that guard anyone holding one could swap the bytes a tracking code *printed on a folder* names, keeping the code, the label and the link intact. And **a share link reaches the current version only** — anything else would silently widen every token already in circulation on the day this shipped, which is the exact inverse of the rule that a link's reach is only ever narrowed after it has been sent.

The two things most easily forgotten are both covered by tests. `FileStorage::delete()` must unlink **every** version's bytes, because `file_versions.file_id` cascades: a purge drops the rows by itself, and without this their bytes stay on disk referenced by nothing that could ever find them. And `replace()` takes `lockForUpdate()`, because two replacements arriving together would both read version 1 — `unique(file_id, version)` makes that a failed insert rather than a corrupted history, and the lock makes it a correct version 3.

Everything is kept indefinitely and **nothing prunes a version**, which is a deliberate choice for a document register rather than an oversight: a flag that prunes an audit trail is the most dangerous kind of capability to add quietly. See `documentation/modules/files.md` → Versioning.

### A comment is content, and it belongs to a version

`document_comments` holds what people say about a document; `documents.comments_enabled` is the uploader's switch for whether it collects any.

**Who may comment is the document's own read rule, asked of `AccessResolver` and not restated.** A comment is addressed to exactly the audience that may open the document, so a second gate — a `comments.write` permission, say — would be a second statement of one rule, and the kind that drifts into granting somebody discussion of a document they cannot read. There is deliberately no module permission for it, for the same reason there is none for deciding an access request.

A **share-link visitor may not comment**, which is not an oversight. `GuestIdentity` exists so an audit row can say *who* opened a link, and it works there because the suffix "(link visitor)" qualifies the claim. Content is different: an unverified name sitting in a thread of real ones is a statement somebody may act on.

**A comment names the version it was written about.** "This needs the annex" said about version 1 is not advice about version 3, and a thread that silently carries forward across a replacement tells the next reader that a resolved objection is still open. So the panel shows the comments for the bytes on screen and states how many the rest of the history holds — hiding the count would make a thread look empty when it is only elsewhere. The version is sent by the page rather than inferred, because the page knows which bytes the person was reading; inferring it would attribute a comment on version 1 to whatever became current while it was being typed.

**The trail records that a comment was written and never what it said.** `DocumentAction::Commented` carries the actor, the time and the version; the body lives in `document_comments`. A comment is content its author may delete, and `document_logs` is a record of events that must not change — copying the text in would put a mutable paragraph inside an immutable record.

**Turning comments off closes the thread and erases nothing.** The panel stays readable with the box gone. Deleting other people's words as a side effect of a setting is not something a toggle should do, and a reader who finds a thread cut off mid-discussion learns something true, where one who finds it vanished learns something false.

The flag defaults to **true** at the column, so an upload from My Files' tracking dialog — which does not offer the control — gets the default without `DocumentRegistrar` knowing the setting exists. Only a deliberate "off" is written by the register's own controller.

### A trail says which version it was about

`document_logs.version` and `activity_logs.subject_version` record which bytes an event concerned, because once a file has a history "downloaded this document" stops being a complete sentence — the reader who took a copy on Monday and the one who took a copy on Friday may be holding different files.

A column rather than a key into `file_versions`, for the reason every other column on these tables is a snapshot: it says what was true when it happened, survives the row it refers to, and can be indexed and filtered where `context->version` could not. Nullable with no backfill, and null is meaningful rather than missing — a folder has no version, and neither does history written before the column existed.

**The version is derived, not threaded through every caller.** `DocumentLog::record()` reads `$document->file?->version` when none is passed, which is what kept every existing writer correct untouched: a view, a download, a receipt and a link share each concern whatever version was current at that moment. Exactly one path passes it explicitly — the one serving a *superseded* version, where the current number would be a lie.

**A replacement is in the document's travel history**, as `DocumentAction::FileReplaced`, and that is a deliberate exception to the audience rule rather than a drift in it. `copied`, `extracted` and `link_created` stay out of `document_logs` because they disclose the file manager — where bytes live, who took a copy, what an archive held. But for a tracked document the file *is* the document: a reader who downloaded version 1 and acted on it must learn that version 2 exists, and the row discloses nothing they could not see by opening the file. The counter-argument is real — every case added to that enum is a permanent widening of what readers learn, and "revised twice on a Friday afternoon" is editorial information about the uploader — and it loses, because a reader acting on a superseded copy with no way to know it is superseded is the worse outcome.

It is written **directly** by `FileManager::replace()`, not through `FileEventRecorder::DOCUMENT_EQUIVALENTS`. That map exists for actions fired from four different doors which all need one mirroring point; a replacement has exactly one writer, and routing it through the map would leave every future action added beside those two entries one typo away from appearing in every document's public trail. A revert gets no document action of its own — to a reader it is a replacement, and "these bytes are a copy of version 1" is storage mechanics that belongs in the activity log.

The filter follows the same rules as the rest of the trail vocabulary: one builder, one parser, and the versions offered are **derived from the rows** — `DocumentLog::versionsFor()` for the headings, `versionTabsFor()` for the tabs — so neither can present a version the trail holds nothing against. The grouping is client-side, because the rows already arrive newest-first carrying their own version and grouping on the server would be a second ordering to keep in step with the paginator's.

**The version is a tab bar, not a select in the filter dialog**, and that is a move rather than an addition. Which version of the file a trail is being read against is the first question somebody asks of a replaced document, and the answer was five controls deep behind a Filter button that then said only "1 filter active" — so a reader looking at version 2's history had no way to see, without opening the dialog, that they were not looking at the whole of it. `TrailVersionTabs` is the one bar, shared by the panel and the full-history page exactly as `TrailFilters` is, and the select went out of the dialog when it arrived: one filter with two controls is how a screen ends up contradicting itself, and a dialog draft re-seeded on open would have reverted a tab clicked since. `Clear all filters` still clears the version, because "all" has to mean all, and the Filter button's badge deliberately does not count it — a badge counting a choice spelled out in plain sight says the trail is narrowed by something hidden.

It renders **nothing at all until a file has been replaced**, since one version is not a choice, which is most documents. Each tab carries its own count, grouped on the server **under every filter in force except the version itself**: a tab states what choosing it would show, so counting with the version left in would report zero against every tab but the open one, and counting without the search would send somebody to an empty tab. `All versions` is the sum of the rest rather than a second query, because asking twice is how two numbers over one fact disagree — which is also why the unversioned rows are a tab (`version=none`) rather than a silent remainder: a reader must not see forty entries over tabs accounting for thirty. Switching tabs returns to page one, because version 2's page three is usually past the end of version 1.

### A critical operation records its own failure

Every operation in `FileManager` wrote a row on success. `attempt()` adds the other half for the structural ones — move, copy, delete, restore, purge: on failure it writes a row carrying `outcome: 'failed'` and the exception's class, then re-throws untouched so the caller answers exactly as it did.

The subtlety is *where* that row is written. Inside the transaction it would be rolled back along with the work it was recording, which is the trap that makes "log the error in the same transaction" useless precisely when it matters. So the row is written after the rollback, outside. Authorization refusals are deliberately left out of it: they resolve before the transaction opens, and recording them would let anyone holding an identifier fill the trail with rows about items they cannot touch.

### A preview is a server decision, and the Office viewer is the one door that opens

`App\Services\Files\FilePreview` decides which viewer a file needs — Word, Excel and PowerPoint through Microsoft's Office viewer, PDFs, images, video and audio rendered by the browser from our own stream, everything else honestly reported as having no preview — and `GET /files/preview` is where a page asks, behind the same `view` ability as the bytes.

A page choosing for itself is not merely untidy. Three of the four viewers are served by this application out of the private disk; the fourth hands a URL to a third party. A template deciding between them is a template deciding whether a confidential document leaves the building. It is also why this is a request per file rather than a prop on the listing: forty cards in a folder would otherwise mint forty live credentials on every navigation, all but one of them unused.

**Word and Excel are drawn in the browser now, and that is the door closing rather than a new one opening.** `docx-preview` and SheetJS render OOXML from our own stream, so for those formats no URL is minted, nothing leaves the building, the preview works on a laptop and on an office LAN — where the hosted viewer renders nothing whatsoever — and **a confidential item becomes previewable for the first time**, because the thing that had to be refused was the outside URL, not the rendering. A renderer running in the browser of somebody the resolver has already granted `view` to adds no reach at all.

What remains behind the viewer is PowerPoint and the legacy OLE2 `.doc`/`.xls`, which are not OOXML and which no browser library renders faithfully; a half-drawn slide is worse than an honest "download to view". For those the rest of this section still holds exactly.

The cost is that the office's spreadsheets are now rendered by a library rather than by Excel, so an exotic chart or a macro sheet will look poorer than it did. That is the trade the office asked for when they asked for previews that are fast and that work inside the building.

PowerPoint and legacy Office cannot be rendered by a browser at all, and the viewer that can runs on Microsoft's servers and **fetches the file itself** — so the bytes have to be reachable without this application's session for as long as the render takes. That is the only relaxation of the private disk anywhere in the system, and it is made as narrow as it can be: the URL is minted only after the resolver has granted `view` to the signed-in person asking, it is signed and expires in minutes, it names one file, a confidential item never gets one, and the item's visibility is re-checked on **every** fetch — because a signature says the URL was issued, not that the item is still shareable, and an item can be reclassified after one has been handed out. The two alternatives were rendering Office formats locally, which needs LibreOffice on a deployment target that has neither it nor Imagick, and refusing those previews outright.

**PDFs deliberately do not go through it.** The Office viewer does not render them — one handed to it loads a frame that then reports it cannot open the file — and a browser renders a PDF perfectly well on its own. They share the `document` mode so the page has one frame and one set of controls, but a PDF streams from us: which of the two a document is comes from `viewer_url` and `renders_locally`, never from the mode. The practical payoff is that PDF preview keeps working on an installation no outside service can reach, and nothing but an Office file ever leaves.

**And an embedded viewer has to be able to reach us.** It is not a renderer running in the browser; Microsoft fetches the URL from their own servers. On a laptop serving `localhost`, or inside an office network with no inbound route, no such frame can ever load however correct the markup is — so `viewerCanReachUs()` reads `app.url` and mints nothing when it is plainly local, the PDF falls back to the browser, and the empty state says which of the two reasons applies. A spinner that will never resolve is the worst available answer, and it is the default one if nobody checks.

Two smaller things sit here for the same reason. Media is served as a real `BinaryFileResponse` rather than a streamed one, because a `<video>` seeks with a Range request and a streamed response answers one with the whole file and a 200. And `image/svg+xml` is deliberately absent from both the image mode and the inline list: an SVG is a document that can carry script, and rendering one inline executes it against this origin.

**Which types stream inline is derived from `App\Support\FileTypes`, not written out again.** `FileStorage::inlineTypes()` takes every MIME type the catalogue associates with an image, audio or video extension, adds `application/pdf`, and excludes SVG by name. It was a hand-written constant, and it failed in the way two lists of one fact always fail: it named `audio/wav`, the type `finfo` actually produces for a WAV is `audio/x-wav`, so every WAV preview fell through to the download branch and the `<audio>` element was handed an attachment — which plays nothing, silently. That is the same shape as the `allowed_extensions` / `allowed_mime_types` split the upload policy already collapsed, for the same reason: a format admitted in one vocabulary and refused in another, with nobody maintaining the first having reason to know about the second. The test asserts the *property* over the catalogue rather than a list of types, so it cannot rot as formats are added.

### A QR Tracker points at the share link

A **public** document's label encodes its **share link**, not its tracking URL, and `SharingService::ensureLinkFor()` mints one with the document so the label has something real to point at.

**Every level but confidential gets one, and what varies is what redeeming it does.** The label is therefore one shape: a `/s/` link on a public document and on a private one, and the tracking URL only where no link may exist.

That rests on `/s/{token}` answering **the link's own term** rather than the document's level. `ShareLinkController::opensForHolder()` asks `ShareAbility::isBearer()`: a view or download link is redeemed by whoever holds the URL, and a `restricted` one is an address that resolves to the holder's own access — so it opens for the uploader and the people tagged on the document and tells anybody else only that it exists and is restricted, page and bytes alike, or the page would be decoration.

**A restricted document's link is minted on that term**, which is what keeps the printed label safe: `SharingService::defaultLinkAbility()` returns `Restricted` the moment the level is a list of names, so a label made automatically, by a registrar that was never asked about sharing, cannot hand the document to whoever photographs the folder.

**The term is offered at every level, public included, and it means the named list wherever it is set.** Both halves of that were wrong together. The dialog withheld the option on a public document, reasoning that "people who already have access" is everybody there — and the resolver agreed, because it answered the term by asking what the holder could do with *no link at all*, which on a public document is "everything a reader may do". So the one control that says "only the people on this document" silently did nothing on exactly the documents somebody is most likely to be careless with.

`AccessResolver::forNamedAudience()` is the correction: a restricted link resolves the uploader and the live recipient list, and nobody else, whatever the level. It is `forDocument()` with the *level* clause removed rather than a new rule beside it, and it goes through `recipients()`, so a removed viewer loses the link with everything else. On a public document that closes the link and nothing else — the tracking code still opens the document for anyone, which is what the level means, and the dialog says so rather than letting the control read as a way to take a public document back.

The term used to be read off the level instead, and the uploader had no say. That was wrong about the office rather than about the risk: a document restricted to three named colleagues is the ordinary case in which somebody outside has to be sent a copy, and with no way to widen the link the only answer was to reclassify the document as public — opening it to the whole institute so that one outsider could read it. Widening one URL is narrower in every direction, because a link is an object: revocable, re-addressable, capped, expiring and counted, while the recipient list goes on governing every other way in.

Two consequences are the point of the shape. A printed restricted label is **not a bearer credential** unless its uploader deliberately made it one: whoever photographs the folder learns the classification and nothing else. And a signed-in reader who may open the document is **redirected to `documents.view`** rather than shown the share card — the token has done its job by naming which document, and the share page is a worse version of the page they are entitled to, with no trail, no comments and no version history.

A guest is never redirected there, because `documents.view` forwards one to the public lookup; see "A tracking code opens one page" above.

That follows from the section above. Once the tracking URL required an account, a label encoding it sent whoever scanned the folder to a sign-in page — no use at all to the courier it was stuck there for. A share link is the thing that opens for somebody with no account *and* can be revoked, counted and expired. A confidential document has no link and never will, so its label falls back to the tracking URL: staff scan it and sign in, which is what the word means.

**The consequence is worth stating plainly: the printed label is now a bearer credential.** Whoever photographs the folder can open the document until the link is revoked. That is strictly better than the code was — a code could not be revoked at all — but it is a trade, not a free win, and two things follow from it. `downloadQr()` requires an account and then `owns()` — the uploader, and nobody else — because a route handing the PNG to anyone who could guess `{CODE}.png` would be a quieter way to obtain a link the uploader never sent; it answers **404** rather than 403 for a label that is not theirs, matching the share routes, so "not yours" and "no such label" stay indistinguishable. And `qrCodeSvg` on the document page is the link's symbol for the uploader and the *tracking URL's* for everybody else, since a reader must not be handed a credential by opening a page.

The stored PNG is rewritten when a link is created, regenerated or revoked — the three events that change the target — and deliberately not when one is edited, because the point of an edit is that the URL survives it.

### A document page shows the file, and keeps the trail behind a button

The file is rendered in the page, under the details card. There was a "View file" button, which is a button in front of the one thing somebody opening a document came to do.

The trail went the other way, into a half-width slide-over opened by **Audit Trail**: it is the longest thing on the page and the least often read, and a paginated history standing permanently below the document pushed the file off the screen entirely.

Two things hold it together. `FilePreviewPane` is the renderer, and the modal in the file manager now delegates to it — two implementations of "what does a docx look like" is how one quietly stops handling a format the other learned about. And the descriptor is still *fetched*, never a page prop, because for PowerPoint and legacy Office it mints a URL live for minutes; rendering automatically does mean opening a document writes a `previewed` row, which `FileEventRecorder` debounces per person per five minutes.

### A trail is filtered by the same vocabulary everywhere

The document trail takes search, action, office, a date range and a page size — what the activity report already took. `DocumentLog::scopeTrail()` is one builder, `DocumentLogController::filters()` one parser, and `TrailFilters` one dialog, shared by the panel and the full-history page, because they are the same list at two lengths and a filter added to one and not the other is a filter somebody will look for and not find.

Two of those are load-bearing. **Every filter reads a snapshot column**; nothing joins. The office filter in particular narrows by where the actor sat *when it happened* — filtering by where they sit now would hide the rows from when they sat somewhere else, which is the one thing an audit trail may not do. And **the offices offered are derived from the rows**, so the filter cannot present a choice that matches nothing, and a division renamed since cannot silently drop every row recorded under its old name.

The controls sit in a dialog rather than a row, matching `FileFilterModal`: five controls that each reload the page is five reloads to describe one question. Search stays outside it, because it is the one people actually use.

### Document types are grouped, and the groups are rows

`document_type_groups` arranges the type picker — Documents, Assets, Images, Videos on a fresh install — and `DocumentType::grouped()` builds the arrangement once on the server, because the upload form and the file manager's tracking dialog both offer it.

A table rather than an enum, for the reason `document_types` is a table rather than the PHP constant it replaced: naming a group, renaming one or reordering them is office business, and the office will invent a group nobody writing the seeder thought of.

**The module opens on the groups, and a group is opened to see its types.** A flat table of every type answered the wrong question first: an office looking at twenty rows wants to know what *kinds* of thing it files before which exact type. So `/document-types` is the shelf — a card per group with what it holds, Add group above — and `/document-types/group/{group}` is what is on it, with Add type carrying the group so a type opened from inside one is already filed there.

`{group}` is a group's id, `other`, or `all`. **"Other" is a set with a page, not a gap**: `group_id` is `nullOnDelete`, so deleting a group produces ungrouped types, and a set that exists and cannot be opened is how rows go missing from a screen that looks complete. The group is a path segment because it is the thing somebody navigated *to*; search and status stay query parameters, because those really are filters on what is already there.

**A group has its own create, edit and delete.** It began as a name typed into the type form, on the reasoning that a heading is something you name in passing rather than sit down to manage. That was half right and the conclusion was wrong: a thing you can create but never rename, reorder or remove is a thing the office is stuck with the first time somebody mistypes one. What survives of the reasoning is that there is no Groups *module* — a group and a type are one piece of office vocabulary, so `document-types.{read,write,delete}` gate both, and two sets of permission names is how a role ends up holding half of one grant.

A group's `code` is generated and never shown, which is the difference from a type's. A type's code is stamped into every tracking code it has issued and printed on paper, so it is asked for and then locked; a group's never leaves the database — it is only the handle the seeder matches on — so renaming a group deliberately does *not* regenerate it, or the next `db:seed` would recreate the group it replaced.

See `documentation/modules/document-types.md`.

`group_id` is nullable and `nullOnDelete`. A group is an arrangement of a picker; a type is part of the record, stamped into every tracking code it has issued. Deleting a group must rearrange the picker and never retire a type, so an ungrouped type shows under **Other**, last.

### A tracking code opens one page, and that page is not the document

`/track` is public. `/scan` is public and forwards a visitor to it, and **`/documents/view/{code}` forwards one too**. `/documents/track`, `/documents/success/...`, the QR download and `documents.preview` still require an account.

That makes the tracking-code URL one link that answers whoever opens it: the uploader and a tagged reader get the document, a signed-in reader who is neither gets `documents/restricted` with the ask, and a visitor with no account is forwarded to `/track` — which describes a public document and tells a refused reader only that they cannot open it. It is a forward rather than a widening: the code buys exactly what the public page has always given it, on the throttled route, and `AdminShell` — which assumes an account — never renders for a guest. The forward happens *before* the lookup, so a guest cannot tell a live code from a dead one by whether the route 404s. The register's own lookup box, `/documents/track`, is a screen inside the application and still asks for an account.

A tracking code is **not a credential**, and that has not changed. It is printed on the folder, it is a per-type daily series, and it is therefore short, sequential and guessable — so anyone who photographed a desk, or simply counted, could walk it. That is why the whole tracking surface was closed: treating the code as a credential meant it could be used as one.

The mistake in that closure was the **answer** it gave, not the rule it enforced. Every scan by somebody without an account landed on a sign-in form, which says nothing about the document and nothing about why — and the courier holding the folder, who is the entire reason a label is printed on it, is exactly the person who has no account.

So `App\Services\Access\AccessResolver` is untouched and `PublicTrackingController` asks it rather than deciding anything itself. What a code buys is precisely what the resolver has always said it buys and nothing more:

- A **public** document is described and previewed for a visitor with no account. That is not a relaxation — `forDocument()` returns `View` and `Download` to a null user for a public document and always has, and `/files/{owner}/{file}` has always been outside the auth group for the same reason.
- A **private or confidential** document answers with **nothing about itself**: no title, no uploader, no type, no file, no trail — and, on screen, not the level either. The page says it exists, says the reader cannot open it, and points at the two real doors: sign in, or ask whoever filed it.

  The level used to be the message — "This document is private, and you do not have access rights to open it", in that level's own colours. It was dropped from the page for two reasons. It is not actionable: a reader cannot argue a classification into opening, and naming it invites them to try rather than to ask the one person who can decide. And it goes plainly wrong once a link carries its own term — a **public** document whose link is Restricted announced that it was public, directly above a refusal to open it. The payload still carries `visibility`, because the same shape describes an open document on the same page; what changed is that a refusal does not render it.
- A **signed-in reader the resolver grants `view`** is redirected to `documents.view`, so coming in through the public door is never a worse page than coming in through the register.

Two costs are real and are the price of answering the courier at all, rather than oversights.

**Existence is disclosed.** Somebody walking the series learns that a code is live and how it is classified. The route is throttled, which does not make a short series unguessable — it makes walking one slow enough to be worth noticing — and a restricted document discloses nothing beyond its level. The alternative, answering "no such document" for a confidential one, was rejected: it is a lie told to the uploader's own colleagues as often as to anyone else, and the thing being protected is the document's *contents*, which this page does not hold.

**A visitor's preview is recorded against nobody.** `files.stream` writes the trail entry, with a null actor, so a public document previewed from a printed label reads as "Unknown" — the same gap `GuestIdentity` closed for share links, and not closed here.

Somebody outside the office who needs more than a look is still given a **share link**, and the difference is not formality: a link is an object. It carries an ability, an optional expiry and an optional view cap, every redemption is recorded against it, and it can be revoked. A printed code has none of those and never can — which is why `FilePreview` reports `can_download` false for a signed-out reader, so a code is a look and a link is a copy.

### Who opened a share link

A share link is unauthenticated by construction — the token is the credential — which left every audit row it wrote saying "Unknown". For a hand-off trail that is the least useful thing it could say: "Unknown opened this four times" is indistinguishable from four different people, which is exactly the question the trail exists to answer.

So a visitor with no account is asked once, on the landing page, and may decline. `App\Services\Files\GuestIdentity` holds it, and three things about it are load-bearing.

**Declining is an answer, not a dead end.** "Stay anonymous" mints a generated handle — "Anonymous Nightwatch" — stored per device, so the trail can say *the same person* came back twice without ever learning who they are. A prompt that refuses to let somebody through until they identify themselves would be a worse version of the account they deliberately do not have.

**Nothing is recorded and no view is spent until they answer**, and the bytes are behind the same question the page is. Otherwise the prompt would be decoration — `/s/{token}/file` would still serve a stranger — and a link capped at three opens would be emptied by a page nobody read.

**It is not authentication and is labelled so everywhere.** Nothing is verified, so the name decides nothing: not access, not ability, not which link resolves. It is snapshotted into `actor_name` with `actor_id` null and the suffix "(link visitor)", because a self-asserted name sitting unqualified beside a column of real accounts is a claim the trail cannot support. `DocumentLog::record()` grew one optional `$actorLabel` parameter for it, which a real account always overrides.

### One link per item, whose terms are edited and whose address can be replaced

An item has **one live share link**. `SharingService::createLink()` refuses a second and `updateLink()` changes the one that exists, keeping its token.

Several links to one thing is several credentials with separate expiries and caps, and no screen can say which of them the message somebody sent last week carried — so revoking the wrong one is the normal outcome rather than the unlucky one. One link is a thing a person can reason about.

Editing rather than replacing is the other half of the same thought. The alternative to an edit is revoke-and-recreate, which silently breaks every message the old URL was pasted into: somebody narrowing a link from download to view would cut off precisely the people they were narrowing it for. `view_count` is deliberately untouched by an edit — views are a record of what happened, not a budget — so raising `max_views` on a spent link revives it, which is the honest reading of "give it a few more opens".

The rule lives in the service and not in the two controllers that mint links, for the reason everything else here lives in one place. Both re-check the item's linkability on edit as well as on creation, because an item can be reclassified confidential after a link has been handed out and widening its link must not be the way back in.

**Which leaves one act an edit cannot perform, and it is the one people actually need: the URL has gone further than they meant it to.** `regenerateLink()` is that act — a new token on the same row, keeping the ability, the expiry and the cap. The obvious implementation is revoke-then-create, and it is wrong twice over: it leaves the document briefly with no link at all, which is what a public one's *printed label* points at, and it starts a fresh row, so the screen can no longer say what the link had been doing.

`view_count` **is** reset here, and that is the one place this parts company with an edit. There, views are a record of what happened and raising a cap on a spent link revives it. Here the URL those opens were spent against no longer exists, so carrying the count forward would hand somebody a brand-new link already partly — or entirely — used up. What it was goes onto the audit row, which is where a count that has stopped being true belongs.

It is recorded on both trails, as `ActivityAction::LinkRegenerated` and `DocumentAction::LinkRegenerated`. The second is a deliberate addition to what every reader of a document learns, on the same reasoning as `FileReplaced`: a regeneration *ends a hand-off*, every copy of the URL stops working at that moment, and a reader holding a dead link is entitled to learn from the document's own history why rather than concluding the document was withdrawn. Neither row carries a token, old or new — an audit row holding a live credential is a way to obtain that credential by reading the audit report.

Revoking survives beside it, quieter on screen and still a different act: it ends the link rather than re-addressing it, leaves the item with none, and a public document's label then falls back to the tracking URL. Both are confirmed, and neither confirmation asks "are you sure" — each states what stops working and for whom, because the people affected are precisely the ones not on screen.

### The share landing page previews through the same resolver

`/s/{token}` renders `FilePreview`'s descriptor, resolved through the link instead of through a session, and the page uses the same `FilePreviewModal` the file manager does.

It used to ask its own narrower question — is this MIME type in `FileStorage::INLINE_TYPES`? — which is a second answer to something `FilePreview` already owns, and a wrong one: every OOXML file sniffs as `application/zip`, so a shared Word document offered no preview, and on a view-only link the page then said *"This link does not permit opening the file"*. That was not true of the link; it was true of the check. `describe()` takes an optional `ShareLink` now, which swaps the session-bound stream route for the token's own and resolves the abilities through the link — nothing else about it changes, because which viewer draws a file is the same question whoever is asking.

### Sharing is granted where the thing is made

The share drawer offers links and nothing else. Naming a person, a division, a section or a role happens in the New folder dialog and on the document upload form, both of which show the tags as they are made.

That is a reduction in what the UI can do — somebody cannot be added to an *existing* folder from a panel any more — and it is deliberate. A second place to grant access, reached later and out of context, is how an item ends up shared with people nobody remembers choosing; the moment somebody is deciding who a folder is *for* is the moment the answer is most likely to be right. `ShareController::store()` went with the picker, and so did the directory the share-state endpoint used to ship on every open: every account, division, section and role, for a control that no longer exists. Revoking stayed, because taking access away has to be possible from wherever you can see it.

**A tracked document's recipient list is corrected on the form that made it**, and that is not a second place to grant access but the same one, reopened. The edit form carries the same `RecipientPicker` the upload form does, rendered wherever the level in force tags people — private and confidential — so the whole list is on screen at once while somebody changes it, which is exactly the property the share panel lacked. It had to exist: the list is optional at every level *by design*, the form invites filing a document before anybody knows who it is for, and the only way past a name left off was for that person to request access to a document they had no reason to know existed.

The **level** stays an upload-time decision and is not on the edit form. Reclassifying is a different question — about readers who already hold the document — and the part of it the office actually needs is answered on the share panel, by the link.

Four things make the edit safe to repeat. It is a **diff**, not a sync: a person already tagged is touched by neither half, so correcting a title cannot re-notify everybody or re-stamp rows that did not change. A removal goes through the same `revokeViewer()` the chip's own button does — one place for the stamp and its trail row — so a viewer dropped here is *revoked, not deleted*, and `refusalMessage()` can still tell them from somebody never tagged. An addition goes through `DocumentRegistrar::tag()`, because clearing `revoked_at` on re-tagging lives there and `(document_id, user_id)` is unique. And the rows and their trail entries commit **with** the rest of the edit while the notifications are sent **after** it, because a notification sent inside a transaction that then rolls back is a message about something that did not happen.

`DocumentAction::ViewerAdded` joins `ViewerRemoved` for the reason that one exists, read the other way round: a trail recording every withdrawal and no later grant *understates* who can read the document. One row per person, with the name in the remarks, so the trail can answer when a *particular* person was given it. Tagging at upload writes none of these — the `Uploaded` row already says the document was filed with the list it carries, and a row per name beside it is the same fact twice.

A link's two parameters are enforced rather than displayed. `ShareLink::isUsable()` and the item's effective visibility are both re-evaluated on every redemption, which is the same reason the Office-viewer URL re-checks: an item can become confidential after a link has been sent, and the link has to stop working at that moment rather than when somebody remembers to revoke it.

### Every account has a face, and nobody has to choose one

An account with no uploaded photo used to render two grey initials. Those are legible and completely interchangeable — in a list of thirty staff, half a dozen "DC"s say nothing about which Dela Cruz is which — so a face in a hand-off trail was something to read rather than something to recognise.

Now there are three answers, in order: the uploaded photo, a generated avatar from the seed the person picked, or a generated avatar from their name. The third is what matters: every account has a distinct, stable face from the moment it is created, with nothing to configure. `users.avatar_seed` exists because a name-derived face cannot be changed without changing the name, so the picker stores a seed instead; the style and the framing live in `resources/js/avatar.js` and never in the column, or changing either would mean rewriting every row.

Two rules hold it together. A photo and a seed are **exclusive** — setting either clears the other and deletes the bytes the other was using, because an account holding both renders differently depending on which screen reads it first. And the generated image is a third-party request, so it is allowed to fail: `UserAvatar` falls back to initials on error, which is why it is a component with state rather than a bare `<img>`.

### A notification says who and what kind

`App\Enums\NotificationKind` is the vocabulary of the header bell — `shared`, `access_requested`, `access_granted`, `access_declined` — and it sits beside `DocumentAction` for the same reason that one does: the row stores a **slug**, and the wording, the symbol and the colour are read off it when the feed is built. A notification is history, so correcting an icon next year must not mean rewriting rows already written.

The bell was a sentence and a timestamp per row, which rendered three different events identically: a document arriving for you, somebody asking to open one of yours, and an answer to something you asked for. A reader with nine of them had to read all nine to find whether any needed an answer. Each row now carries the actor's face with the kind badged on it — the same shape the audit trail draws an actor in — and the badge's colour is the thing actually being read: blue for something arriving, amber for something waiting on you, green for a yes, rose for a no.

**The face is a snapshot**, exactly as on an audit row: `actor_name`, `actor_profile_image` and `actor_avatar_seed` are copied into the notification's payload when it is sent, so it still renders after that account is closed and does not silently change when somebody edits their profile. Neither value is a credential. Where only the name is a snapshot and the face is not — an access request, which already carries `requester_name` — the face is taken from the live account and is simply *absent* once it is gone.

**An older row is read from its structure, never from its sentence.** `feedFor()` falls back to `sender_name` for the actor and to the stored `icon` for the kind, because both are fields the notification classes have always written — a shared-document row from before the snapshot knows whose name it is about and only lacks the face, which `avatar.js` derives from the name. An older *access* notification recorded no name anywhere, so it has no face and says so; the row's own sentence still names the person, and reading one out of prose would be an invention rendered as a record. What matches nothing keeps a plain bell. `NotificationKind::tryFrom()` is how every reader gets at the stored value, so a missing kind is a gap rather than an error, and this reading of old rows is never a second place kinds are decided: everything written from now on stores its own.

The name leads the row, above the sentence: in a list of nine, "who" is the first question, and the sentence buries it mid-clause.

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

`App\Services\Files\UploadPolicy` reads `upload_settings` and every upload path uses it — the document register, the file manager, and each entry inside an archive. The limits were `mimes:pdf,doc,docx` and `max:10240` written into `DocumentController`, so widening what the office accepts was a deploy.

**One policy, not one per module.** There were two rows, `documents` and `files`, with a `$scope` argument on every method of the gate to pick between them, on the reasoning that a tracked document and a file in personal storage are not the same kind of thing. They are: the two modules are one workflow over one store — a tracked upload already writes a `files` row beside its `documents` row — so two policies were two answers to one question, and the gate's answer depended on which screen an upload came from rather than on what the file is. An administrator also had to find the right tab before their change meant anything, with nothing on screen saying which form governed which upload. `scope` survives only as the single row's unique key, so that "there is exactly one policy" is a constraint the database enforces; nothing branches on it.

The migration that merged them **widens and never narrows** — the union of the permitted types, the wider window wherever the two disagreed, the larger file count and archive allowances — because one store means a file that may be uploaded has to be uploadable from either screen, and narrowing would revoke a permission in silence.

The policy was once seeded with exactly the old hard-coded limits, so the module changed nothing on the day it landed — a settings screen that silently loosens the rules on arrival is not a settings screen. That restraint has since been spent deliberately: the office asked for images, PDF, Office documents and video, and what is seeded now is every safe type in every category, derived from the catalogue rather than restated, so a format added there is permitted on a fresh install without a second list to remember. SVG is the one exclusion, because it is a document that can carry script. The seeder repairs a policy that permits *nothing* — which cannot be a deliberate edit, since the screen refuses to save an empty list — and leaves a merely narrow one alone.

**The policy is one list, and that is the correction worth understanding.** It was three — `allowed_extensions`, `allowed_mime_types` and `prohibited_extensions`, all administrator-edited, all of which an upload had to satisfy. It failed the way two copies of one fact always fail. Somebody added `xlsx` to the extensions, which is the list the screen presents as the answer to "what may be uploaded", and had no reason to know that a second list, written in a vocabulary nobody types by hand, also needed `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`. So a real spreadsheet was refused with *"is not the kind of file it claims to be"* — naming the very type that should have admitted it.

Now `allowed_types` is the list: an entry per permitted extension with its own size window, because a 200 MB video and a 2 MB signature scan are both reasonable and one ceiling cannot say so. Not in the list means refused. The two derived halves moved into `App\Support\FileTypes`, in code, because neither is an office decision: the MIME types an extension may legitimately sniff as are a property of the format, and the refusal of program and script formats is a floor rather than a preference — a veto an administrator can empty is not a veto.

The extension **and** the file's contents are still both checked, because either alone is bypassable — only the extension lets a renamed executable through, and only the type lets through a file still named `.php`, which is what a misconfigured web server executes. What changed is what counts as disagreement: only a *positive contradiction* is a refusal. Every OOXML and OpenDocument file is a zip archive, so a correctly-sniffed `.xlsx` is `application/zip`; legacy Office is an OLE2 container reported under four different names; text has no magic number at all; and `finfo` answers `application/octet-stream` for anything it has no signature for. Treating an inconclusive sniff as a lie is what produced the false rejections. A `.pdf` whose bytes are `application/x-dosexec` is still refused.

Two traps live in that shape. Laravel's `max:` runs before anything has looked at the extension, so the coarse rule has to be the **widest** window any permitted type has and the real one is applied per file — a coarse bound at the scope default would refuse a 60 MB video before the rule permitting it was consulted. And the settings screen offers a catalogue rather than a text box, so an administrator cannot name a type the gate could not verify: a token typed into a box is a permission nobody can check, where a stray dot or a capital letter silently never matches and reads on screen as a grant that is not there.

A missing policy row still degrades to the document formats with archives off: too strict, never "anything goes".

### A tracking code is a per-type daily series, issued from two sources at once

`App\Services\TrackingCodeService::issue()` builds `ABBR-YYYYMMDD-NNNN`. The abbreviation is `document_types.code` — one table that is both the list of types offered on the upload form and the map to their prefixes, so the two cannot drift. It is a table rather than the PHP constant it used to be because adding a type, correcting an abbreviation or retiring one is office business, not a deploy; `code` is immutable in practice, since it is stamped into every code that type has already issued and those are printed on paper.

The counter is `max(highest code already on record, the series' own counter) + 1`, read under a row lock inside a transaction. The two sources are both load-bearing, and each fixes what the other cannot:

- **Reading it back off the codes in `documents`** is self-correcting: importing an office's historical records is all it takes for a series to continue from where their paper trail left off. On its own, though, deleting the day's last document hands its tracking code to the next upload — two different documents, one code, in a system whose entire job is to be the record of which is which.
- **The `document_series` row** never goes backwards, so a number that has been issued is never issued again. On its own it would restart every series at 1 on a fresh install.

This is the PROCMIS `DocumentSeries` / `DocumentNumberService` pattern, which solves the same problem for procurement document numbers.

It is a service rather than a controller method for three reasons: issuing a number is the one piece of this system that must never be wrong twice, it has to be callable from outside a web request (an importer, a console command), and it needs its own tests.

The obvious implementation was wrong in two further ways at once, both now covered: counting every document created today gave the second type uploaded that day a number the first series had used up, and two uploads arriving together both read the same count.

### QR Trackers are PNG with the seal embedded, because a different library uses GD

`simple-qrcode` renders PNG through BaconQrCode's **Imagick** backend, and the deployment target has GD but not Imagick — so `QrCode::format('png')` threw on *every* upload, and `merge()` (the logo path) needed the same missing extension. Labels were SVG and unbranded because of one absent PHP extension.

`endroid/qr-code` renders PNG through **GD**, which is present. `App\Services\Documents\QrLabelService` is the one place a label is made: PNG for the stored file and the download, SVG for rendering inline on screen.

**The drawing is ours; only the encoding is the library's.** `endroid` draws flat black squares and offers a single foreground colour, so a label in the brand's colours cannot be asked of it. `QrLabelRenderer` takes its *matrix* — the encoder, error correction and module placement all untouched, because a styled label that moved a module would not be a styled label, it would be a different code — and does the drawing: rounded modules, a diagonal gradient in the brand blues, the finder patterns as whole shapes in the deepest one, and the seal punched out of the middle.

**One geometry, two backends.** `shapes()` states the drawing once and `svg()` and `png()` only write it out, because two renderers are two answers to "what does our label look like" and the first drift is a printed label that is not the one on screen.

Four of those choices look cosmetic and are load-bearing. `QrLabelTest` is what holds them, because a label that breaks scanning is invisible to the eye, throws nothing, and surfaces only when somebody points a phone at a printed folder and nothing happens.

- **Error correction is High**, which reconstructs roughly 30% of a damaged symbol — that is what buys the room the seal occupies; at the default level the same logo produces a label that looks right and does not read. The logo is punched out and bounded to 22% of the symbol's width, so the modules under it are cleared rather than blended and the damaged area stays well inside what the correction level can recover.
- **Corners are rounded; modules are not shrunk.** The obvious way to get the look is to draw each module as a small dot with space around it, and it is the way that breaks decoding — a decoder samples the *centre* of each module and thresholds on local contrast, so shrinking every module throws away the dark area that threshold depends on. A module keeps its full square and is rounded only at corners with no dark neighbour, so runs fuse into blobs while every module keeps its area.
- **The palette is the brand's dark end, and the cyan is deliberately absent.** A decoder binarises on luminance, and `#00aeef` against white is roughly 2.5:1 — bright enough to fail on a grey photocopy or through a phone camera's auto-exposure. The modules run `#0a6bb8` → `#07559e` across the diagonal and the finder patterns take `#043d78`. The gradient is visible and every stop of it is dark.
- **The finder patterns are drawn as whole shapes.** They are what a decoder locates the symbol *with*, so each is the exact 7×7 ring and 3×3 core the spec requires, rounded as one object rather than as 33 squares, which keeps the 1:1:3:1:1 run ratio a scanner measures across them intact.

The PNG is supersampled 3× and resampled down, because GD does not antialias a filled arc and at final size the rounded corners come out visibly stepped. **That resampling has one consequence worth recording, because it was measured and it looks alarming**: the styled PNG carries some 200 grey levels at its edges, and GD's `IMG_FILTER_CONTRAST` breaks it while leaving a plain black label intact. A *binariser* — what a scanner actually runs — collapses those levels instead, and the label decodes through one, at 128px, and in grey-scale. So the test models a binariser, and the contrast filter is not a scanning risk but a property of that filter.

The SVG embeds the seal as base64 into every page that renders a symbol, so it uses `icon-192.png` — generated from the same seal by `brand:icons`, already oversampled for the ~113px it is drawn at, and a seventh the weight of the full `ptri.png`, which the PNG keeps because that is the file somebody prints.

The file is named after the tracking code, never the document title. A title is arbitrary user input: it collided between documents of the same name, and because `documents.qrcode.download` takes the filename straight from the URL, it was a path-traversal vector. `downloadQr()` matches the requested name against a stored `qr_code_path` before it touches the disk, which also means rows from before the switch keep their `.svg` names and keep working.

One oddity is recorded in that test so nobody chases it twice: `khanamiryan/qrcode-detector-decoder` (a dev-only dependency) reads the *branded* label and returns false for the unbranded one, although the two are pixel-identical outside the seal's area, at every margin. A symbol cannot be invalid and then become valid by having a hole punched in it, so that is the decoder, not the label — and the unbranded case therefore asserts shape rather than decodability.

### The icon set is derived from one logo, by command

`public/ptri.png` is the DOST-PTRI seal at full resolution and the only brand image anybody edits. Everything a browser asks for in an icon slot — `favicon.ico` plus `icon-{16,32,48,180,192,512}.png` — is generated from it by `php artisan brand:icons`, so replacing the seal is one file and one command rather than seven files somebody has to keep in step by hand.

Three things it does are the reason it is a command rather than a note in a README. It **letterboxes rather than stretches**: the seal is 810×706 and an icon slot is square, so resampling onto a square canvas without centring and padding squashes it, which reads as a mistake at every size. It writes a **real `favicon.ico`** carrying 16, 32 and 48 as PNG-compressed entries, because a browser requests `/favicon.ico` unprompted whatever the markup says and the file that used to sit there was zero bytes — worse than absent, since an empty body is a broken image rather than a fallback. And it uses **GD, not Imagick**, which is the same constraint that decided the QR label library: these servers have the first and not the second.

The generated files live beside the seal in `public/` and are referenced from `resources/views/app.blade.php`, so a deployment needs no image toolchain — the command is run when the logo changes, not on deploy.

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

**The actor's face is snapshotted too, for the same reason their name is.** `document_logs.actor_avatar_seed` and `actor_profile_image` are the two inputs `resources/js/avatar.js` needs, copied onto the row beside `actor_name`. The obvious implementation eager-loads `User` and reads the photo off the live record, which is exactly what this convention exists to prevent: the trail would silently change when somebody updated their profile, and render nothing once the account was erased. Neither value is a credential — a seed is an arbitrary token the person picked, and the image path is already served to anyone who can see them in a list. Both are nullable with no backfill, because a row written before the columns existed still has a distinct, stable face: `avatar.js` derives one from `actor_name` alone, and inventing seeds for history nobody can verify would be fabricating a snapshot.

### Statuses, visibilities and actions are enums

`App\Enums\DocumentStatus`, `DocumentVisibility` and `DocumentAction` replace bare strings that were compared by hand across four controllers and three Vue pages. The stored action value is a slug and the sentence is `label()`, which means the wording on screen can be corrected without rewriting history.

This is not tidiness. The dashboard's "ended" count was once permanently zero because one comparison looked for a lowercase `'ended'` while the writer stored `'Ended'`; the Log action endpoint validated `action` as `string|max:255`, so a client could post any sentence it liked straight into a document's history. An enum makes the first a type error and `DocumentAction::postable()` makes the second impossible.

What `postable()` must keep out is the set the **system** observes: `Uploaded`, `Viewed`, `Downloaded`, `LinkShared`, `LinkRevoked`. A download is a record that bytes left this server, and a client claiming one does not make it true. `Received` is on the other side of that line — only the person can say a document reached them — which is why it is submittable and `Downloaded` never will be.

`DocumentStatus` has since been deleted outright with the End Action it described, which is the stronger version of the same lesson: the safest state is the one that does not exist.

### File responses are the one place Inertia gets out of the way

`accessFile` and `downloadQr` return `response()->file()` or `->download()`. An Inertia XHR visit cannot follow those, so they are reached with a plain `<a href>` or `window.location` — never `router.visit`.

There used to be a third, and removing it is worth recording. **"Receive & download" fused two facts that are not the same one.** It recorded that the person had taken delivery *and* handed back the bytes in a single request, which forced `documents/show.js` to post a real `<form>` nested inside a confirmation modal and read the CSRF token out of the meta tag, because it was not going through Inertia's handling at all.

But taking a copy is something the **server observes**, and a document reaching you is something only **you can assert** — and a document very often reaches somebody as paper, with nothing downloaded. Fusing them meant you could not say "this arrived" without also pulling the file, and a download by someone who already had the folder recorded a receipt they had not separately confirmed.

So `DocumentAction::Received` is a `postable()` action now, logged from the renamed **Log action** form, and Download is an ordinary link that already records itself in both `document_logs` and `document_downloads`. The route, `receiveFile()` and the nested form are gone.

**A logged action repeats, and nothing refuses a second one.** Two rules used to sit on that endpoint — Received once per person per document, and no two consecutive acknowledgements — both reasoned from the shape of the fact ("a document reaches you once", "a second acknowledgement says nothing the first did not"), and both wrong about the office. A document reaches the same person as paper on Monday and as a re-sent copy on Friday, and again after going out for signature and coming back; an acknowledgement repeats for the same reason. The two rows are not duplicates: they carry different timestamps and usually different remarks, which is exactly the information a hand-off trail exists to hold. And the cost was paid in the wrong place — refusing the entry never made the event un-happen, it made the trail *silent* about it, which is the worse failure for an audit trail.

What still cannot be posted is the set the **system** observes, enforced by `DocumentAction::postable()` through a `Rule::in`. That is the protection that matters: a client claiming a download does not make bytes have left the server. How often somebody records their own work is their account of it.

### The QR scanner owns the camera's lifetime

`resources/js/components/QrScanner.js` wraps `html5-qrcode` and stops the stream in `onBeforeUnmount`. That is the whole reason it is a component rather than inline page code: an Inertia visit unmounts the page without reloading the document, so nothing else would ever release the camera. The Blade version loaded the library twice from a CDN — once in the layout, again in the page — and leaked the camera on every navigation.

A scan may carry either a full tracking URL (our own labels) or a bare code (anything else), so `documents/scan.js` normalises both to the same visit.

### Tutorials are frontend-only, and leaner than the port they came from

`resources/js/tutorials/` is a driver.js runner, a registry, and one file per tour. There is no route, no table and no permission of its own: a tour declares the permission of the feature it teaches and `TutorialMenu` filters the list against the shared `auth.permissions` prop, so nobody is offered a walkthrough of a page they cannot open.

The mascot was ported from PROCMIS almost verbatim, assets included, so the two systems read as one product family. Three parts of PROCMIS's runner were deliberately left behind — sample-data pages, the second supplier audience and its costume, and nested overlay tracking — because DocTrack has no tour that uses any of them. Porting that machinery would have been dead code pretending to be capability, which is the same reason `App\Support\Modules` holds only permissions that gate something.

Two details are easy to get wrong. A step whose element is missing must either show a `fallback` and end the tour or be dropped — an empty folder has no first row to point at, and a tour that stops without saying why is worse than no tour. And **an anchor a tour references but no markup defines is a silent break**, because the step is simply dropped; the check is to scan the tour files for `[data-tour="…"]` and diff against the markup, which is how two missing anchors were caught here.

### A success is a toast, a failure is a modal

`HandleInertiaRequests` shares exactly two flash keys, `success` and `error`, and controllers that used to flash a third (`status`) were normalised to these two. What changed is that the two are no longer answered the same way.

Both used to open a `FeedbackModal` — a centred card over a scrim that had to be dismissed before the page could be used again. For a failure that is right. For a success it is not: "File uploaded." is the outcome the person just asked for, and blocking the screen to announce it interrupts the work it completed. So `success` now raises a `ToastStack` toast and `error` keeps the modal.

Three things about the toast are deliberate. It is **mounted in `app.js`, outside the Inertia page**, because a toast is usually raised by an action that then navigates — a stack inside the page component would be unmounted by the very visit carrying its message. Its **store is module-level**, so anything may raise one without a prop chain; that exists for the outcomes that never reach a flash at all, like a clipboard copy or a revoke refused with a 422, which previously had nowhere to be said. And it **watches the `flash` object rather than the message string**, because every Inertia response builds fresh props: saving the same form twice now raises two toasts, where watching the string left the second one silent — a bug the modal shared and that a toast makes far easier to hit.

The modal's title was also reworded. "Something went wrong" sat over every refusal in the app, including "you are not on this document's recipient list" — which is not a fault, it is an answer, and labelling it a malfunction told the reader to retry something that will never work. The title is a single honest string rather than a per-case one, because a per-case title means a third flash key, and two keys is worth more than a bespoke heading: the specifics belong in the message the controller already writes.

### Sign-in takes either identifier, in one field

`users.employee_id` is the credential the office issues. `users.email` is nullable and unique, and exists so Laravel's password broker has something to look an account up by — an account without one cannot self-serve a reset and needs an administrator.

**Both sign in.** The form posts one field, `login`, and `LoginRequest::credentials()` decides which column from the shape of the string: a valid email address is looked up as an email, everything else as an employee ID. Accepting only the employee ID meant somebody who had just recovered their account by email was then told to sign in with something else.

One field rather than two, because a pair of boxes or a toggle asks the person to classify their own identifier before they may type it, and the server already knows. Exactly one `Auth::attempt()` rather than one per column, because trying both in turn hashes the password twice and makes a wrong employee ID measurably slower to refuse than a wrong email. The refusal is `auth.failed` either way and names neither column, so it cannot be used to discover which identifiers exist — and the throttle stays keyed on the identifier **as typed**, which gives somebody who knows both of an account's identifiers two buckets of five rather than one. That is the honest trade: resolving the account before the throttle runs would turn the limiter itself into a probe for which identifiers exist.

Authentication goes through `App\Http\Requests\Auth\LoginRequest`, which is also where the throttling lives. The controller previously called `Auth::attempt()` itself and skipped the request class entirely, which meant there was no rate limiting on login at all.

There is no email verification: `users` has no `email_verified_at` column and `User` does not implement `MustVerifyEmail`, so there is nothing to verify. Reinstating it means adding both first.

### Access is role-based, and both halves land together

Permissions are named `{module}.{action}` and the whole vocabulary lives in one place, `App\Support\Modules` — the registry the roles screen, the seeder, the route middleware and the sidebar are all built from. It holds only what actually gates something: a permission that gates nothing is a checkbox granting the administrator nothing, and a name someone will later gate a route on believing it was already assignable.

`App\Support\Roles` names the two roles the *code* depends on. `SUPERADMIN` is short-circuited to allowed by the `Gate::before` hook in `AppServiceProvider` — returning `null` rather than `false` on a miss, so a non-superadmin still falls through to every other check. `Staff` is the least-privilege floor an administrator gives an ordinary account.

**There is no self-registration, and that is a removal rather than an omission.** `POST /register` created a working account on the spot: no approval, and nothing checking that the employee ID was real or that the person worked here, so the only thing between a stranger and an account was a unique string they chose themselves — and that account arrived holding `documents-all.read`, which lists every document the office has logged by title, type, code and uploader. The files stayed behind `AccessResolver`; the register is a disclosure of its own. The controller, the page, both routes and the login page's link are gone, and the login page says where an account comes from instead, because a form that refuses an unknown employee ID while saying nothing about how to get one is a dead end for the person who has just joined. The Users screen already did everything registration did, plus the thing it could not: choose the role. The tempting alternative is to keep the route behind an approval flag, and it is worse — a pending account is a second kind of account that every sign-in, every listing and every resolver then has to know about, which is the same shape as the lifecycle state the documents module removed.

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

### Realtime is a signal, and the channel is the resolver

Notifications and both audit trails update without a reload, over Laravel Reverb. Three decisions carry the design.

**A trail broadcasts that something changed, not what changed.** The obvious implementation pushes the new row and the page prepends it, and it is wrong here for three separate reasons. These lists are *paginated*, so splicing a pushed row into page two produces a list whose totals disagree with its contents — the same class of bug `FileListingTest` exists to catch on the listing side. A refetch goes back through the controller, so the row is re-authorized and re-serialized by the one code path that already gets it right, where a broadcast payload is a second serializer and a second serializer is how a field nobody meant to expose gets exposed. And *no audit row ever carries a live credential* is already an invariant: a signal cannot violate it, while a payload has to be re-checked every time it changes.

The events are dispatched from `DocumentLog::record()` and `ActivityLog::record()` — the same single factories that build the rows — so nothing can write to a trail without the page hearing about it. They are `ShouldBroadcastNow` rather than `ShouldBroadcast`, because queued they would reach no browser until a worker ran, which on an installation without a supervised `queue:work` is never; the payload is two values and the write is already inside a request.

**The client decides what to do with the signal, and usually it is "offer".** On page one with no filter it reloads that prop and the server re-paginates. Anywhere else it counts and shows a pill, because a viewer on page two must not have page-one rows injected under them and a filtered viewer must not be told a new row matched their filter when nobody has checked. `useLiveTrail` coalesces a burst into one reload and does nothing at all while the tab is hidden.

**`routes/channels.php` is the whole gate.** `/broadcasting/auth` carries `web` and no `permission:` middleware, so anything those callbacks forget is open. `documents.{document}` therefore asks `AccessResolver` — the same rule that decides whether the trail page opens — because a document's travel history is exactly as sensitive as the document. `ChannelAuthorizationTest` holds a differential test over a matrix of readers and levels asserting the channel answers precisely what the resolver answers, so the two cannot drift.

See `documentation/modules/realtime.md`.

The whole thing fails soft. `resources/js/echo.js` builds no connection without `VITE_REVERB_APP_KEY` and `listen()` hands back a no-op unsubscribe, so every page still works from its Inertia props on a deployment with no websocket. `REVERB_APP_SECRET` must never gain a `VITE_` twin — anything prefixed that way is compiled into the bundle.

### Access is asked for, not negotiated out of band

A reader refused a private or confidential document used to be told only that they were not on its list. True, and a dead end: the only way forward was to work out who filed it and ask by other means, which mostly means the work stops.

**Granting a request creates a `document_recipients` row and nothing else.** That is the test that this is built in the right place — `AccessResolver::forDocument()` already reads that table, so the feature introduces no new way into a document and the resolver did not change at all. `access_requests` records the asking and the answer; the recipient list stays the single statement of who may read what.

There is deliberately **no decide permission**. Whether somebody may answer is a per-record question, because the request is addressed to the document's uploader, and a module checkbox for it would create requests nobody holding it could answer — a dead letter rather than a protection. The decision route carries no `permission:` middleware for the same reason; the controller is the only thing that can know who the uploader is. It authorizes against the document's *live* `user_id` rather than the `owner_id` snapshot, so a transferred document's pending requests become answerable by whoever holds it now; a superadmin may also decide, so nothing stalls while somebody is on leave.

See `documentation/modules/access-requests.md`.

Two smaller things are load-bearing. Every refusal from the request endpoint answers **identically** — an unknown code and a forbidden one are indistinguishable, or this becomes a way to discover which tracking codes exist. And the refusal **is a page about the document**, not a bounce away from it: `view()` and `viewByCode()` render `documents/restricted` rather than returning `unauthorizedRedirect()`.

That is a correction to where the explanation was put, not to what it may say. The flash on the dashboard was right about the words and wrong about the place: the reader lost the code out of the address bar, so a reload, a bookmark or forwarding the URL to the person who *can* grant access no longer carried the one thing the exchange was about — and the ask was offered on a page they had just been moved to. The page carries the code, the level and `refusalMessage()`, which still distinguishes somebody never tagged from somebody the uploader removed, and it carries the ask itself. Never the title, the uploader or the type: `AccessRequestTest` asserts those as an *absence*, because telling somebody what a document they may not read is about is the disclosure this refusal exists to prevent.

`unauthorizedRedirect()` still answers everywhere else — editing, deleting, minting a link — where there is no document page to stand on and the dashboard genuinely is where the reader belongs.

### Access is taken away where you can see it, and a removal is a record

Granting access happens where the thing is made — the upload form and the New folder dialog. **Taking it away is the other half, and it has to be possible from wherever you can see it**, which is why the share panel kept its revoke button when it lost its picker. For a tracked document it was possible nowhere at all: nothing in the application detached a `document_recipients` row, so a document tagged with the wrong person stayed tagged with them for good.

`DocumentController::destroyRecipient()` is that half. The interesting decision is not that it exists but that **the row is stamped rather than deleted** — `document_recipients.revoked_at` and `revoked_by` — because a deleted row cannot answer the question that made this worth building.

**There are two refusals, and reporting them as one is a worse answer than either.** "You are not on this document's list" is an invitation to ask, and it is the right thing to tell somebody who was never tagged. Told to a reader the uploader *took off*, it describes a decision as an oversight: they go on asking the wrong person why a link that worked yesterday has stopped, and the system has made a positive statement that is false. So `refusalMessage()` asks `wasRemovedAsViewer()` and `DocumentLogController::index()` and `accessFile()` ask the same thing, because a person must not learn this on one screen and not the other. It discloses nothing: being told you were removed as a viewer is only ever told to somebody who was one.

The stamp buys two more things. A `DocumentAction::ViewerRemoved` entry on the document's own trail — a history that records every grant and no withdrawal overstates who can read the document — written by the system with the viewer's name in the remarks, so the row still reads after that account is gone. And re-tagging as an *update*: `(document_id, user_id)` is unique, so the row has to be revived rather than inserted.

Which fixes where the live/whole-table distinction has to live. **`Document::recipients()` is the live list** — it carries `wherePivotNull('revoked_at')`, and so does `User::receivedDocuments()` — so the resolver, the "Shared with me" tab, the recipient chips and the notification list are all correct without each remembering it; one of them forgetting would be a removed viewer who can still read the document. `allRecipients()` is the whole table and is what writes go through, including `DocumentRegistrar::tag()`, which clears the stamp. `AccessResolver::forDocument()` did not change at all, which is the test that this is built in the right place.

A revoked share **link** is deliberately *not* given this treatment and still answers 404, exactly as an unknown token does. The holder has no account and no identity this system ever verified, so there is nobody for a "you were removed" message to be about — and distinguishing "revoked" from "never existed" would confirm that a token was once real.

### The acceptance suite is the office's checklist, not a second copy of the tests

`tests/Feature/Acceptance/` holds one file per group of numbered acceptance items, each worked end to end through the screens: a mixed batch of uploads and what deleting a type or a group does to it, the three visibility levels put to four audiences, a removed viewer, the upload rules an administrator actually sets, one role swept against every module in `App\Support\Modules`, the file-manager menu and the trash, audit coverage over a whole working session, and what survives each deletion.

It is a different *kind* of test from the files beside it, and the distinction is worth keeping. The per-clause tests are the contract for the rules — `DocumentAuthorizationTest` is the contract for the resolver's clauses, `UploadPolicyTest` for the policy, `FileListingTest` for the listing. The acceptance files assert that those clauses **add up to the behaviour somebody asked for**, which is where a wrong answer actually surfaces: a type deleted a year after the documents were filed through it, a role that opens one screen too many, a tab whose grant somebody lacks.

Two of the stated items did not match what this system does, and both are recorded in the test files rather than quietly reinterpreted: a *public* document opens for somebody with no account on the public tracking page or through a **share link** — a look in the first case, a copy in the second (see "A tracking code opens one page"), and "visible to anyone who has an account" is what `public` means — `private` is the uploader plus the tagged list, so asserting otherwise would be asserting that two levels are one. Writing the mismatch into the test is the point: the next person to read it sees the office's expectation and the system's answer side by side.

One trap, and it silently passes rather than failing: `actingAs()` sets the user for **every later request in the test**, so an assertion about "a visitor with no account" made after a scene was built by an uploader is an assertion about that uploader. Every such test signs out first.

### A tracked upload has one implementation and two doors

A tracked document is a `files` row with a `documents` row pointing at it; that is the whole difference. Everything after the bytes are stored — issue a code, write the QR Tracker, create the document, open its trail, tag and notify — is the same work whichever screen the upload came from, so it lives in `App\Services\Documents\DocumentRegistrar` and both controllers call it. Copying it into the file manager would have been two statements of one rule, in exactly the shape that produced `AccessResolver` and `DocumentLog::record()`.

The registrar takes an **already stored** `File`, because the two callers should keep storing differently. My Files leaves the file where the person was browsing, which they chose by navigating there — moving it would read as a failed upload. The register asks: its form has a **Save to** field backed by the same `FolderPickerModal` Move and Copy use — which can create a folder from inside the dialog — and the default is the top of the uploader's own storage. Its two controls are gated on server-computed `can.chooseFolder` / `can.createFolder`, because the picker works through the file manager's endpoints and a role that files documents without browsing storage would otherwise meet a control that answers 403. Nothing downstream depends on the path, since `AccessResolver::forFile()` finds the document through `file_id`.

**Neither door invents a folder, and that is a correction.** The register used to file every upload into a `Tracked Documents {year}` folder it created on the spot, reasoning that the form had no folder field and needed *a* destination, and that one folder per year keeps a long office's root from becoming a flat list. The first half was true and the fix was to give the form the field; the second was the system arranging somebody else's storage for them. What it produced was a folder nobody made, holding files people could not find — they looked in My Files, where they had put everything else. A destination the uploader cannot write into is **refused**, not quietly swapped for their root, because an upload that lands somewhere other than where it was sent is the failure a picker exists to prevent.

`files:flatten-tracked-folders` is the other half of the removal: a default that has been taken out is only taken out once the rows it produced are gone, so the command moves what is in those folders to each owner's root — through `FileManager::move()`, so every move is ability-checked and on the activity trail — and removes the folder, keeping any that still holds something, trashed files included, because `files.folder_id` is `restrictOnDelete`.

One thing still asks for that folder by name: `files:purge --orphans` narrows to files under `Tracked Documents%`. It is collecting **historical** wreckage — the orphans the pre-versioning replacement left, which all lived in such a folder — and a sweep that stopped asking would start collecting ordinary files somebody uploaded and never tracked.

Tracking from My Files is **opt-in and takes exactly one file**. A tracking code names one document — `documents.file_id` is singular and the label is named after one code — so several files would mean either several sets of metadata in a dialog that cannot hold them, or one code over a set the schema cannot express. A batch that genuinely is one document is a zip, which `ArchiveService` already handles.

Finding it works both ways now: a tracked file carries its code as a badge into My Files, `File::scopeMatching()` searches tracking codes, and `Document::scopeMatching()` searches file names — the two halves of the same complaint, that somebody has one identifier and the screen only accepts the other.

## Documentation map

| File | Owns |
| --- | --- |
| `AGENTS.md` | The fast path: source map, working rules, invariants. Read first. |
| `CLAUDE.md` | This file — architectural "why" for cross-module work. |
| `documentation/project-stack.md` | Stack table and directory map. |
| `documentation/design-system.md` | Tokens, component inventory, page recipes. |
| `documentation/architecture.md` | Request lifecycle, shared props, data model, routes. |
| `documentation/migration-notes.md` | The Blade-to-Inertia conversion record. Append, do not rewrite. |
| `documentation/modules/*.md` | One doc per module — see `documentation/modules/README.md` for the index. |

Every section above describes code that exists today. When a decision here is reversed, rewrite the section rather than appending to it: two readings of one rule in this file is the same failure the file keeps warning about. `documentation/migration-notes.md` is the one place that is append-only, because it is a record of what happened rather than a statement of what is true.
