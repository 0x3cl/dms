# Documents

The core of the application. A staff member uploads an office document; it is stamped with a tracking code and a printable QR label, and every view, download, acknowledgement and hand-off from then on is recorded.

## Key files

| File | Role |
| --- | --- |
| `app/Http/Controllers/DocumentController.php` | Everything: upload, read, edit, file access, QR label, download report |
| `app/Http/Controllers/DocumentLogController.php` | Recording a hand-off, and the full-history page |
| `app/Services/TrackingCodeService.php` | Issues `ABBR-YYYYMMDD-NNNN`; the only thing that may |
| `app/Enums/Document{Visibility,Action}.php` | The two vocabularies, with their labels |
| `app/Models/Document.php` | `recipients()`, `logs()`, `downloads()`, `documentType()`, `uploaderName()` |
| `app/Models/DocumentType.php` | The type lookup: the form's list *and* the code prefixes |
| `app/Models/DocumentSeries.php` | A series' high-water mark, so a number is never reissued |
| `app/Models/DocumentLog.php` | One travel-history entry; `record()` is the only writer |
| `app/Models/DocumentDownload.php` | One file release; `register()` is the only writer |
| `database/seeders/DocumentTypeSeeder.php` | The nine types. **Required** — no type, no upload |
| `app/Notifications/PrivateDocumentShared.php` | The in-app notice a recipient gets |
| `resources/js/pages/documents/` | `index` (tabs, Deleted included), `form`, `show`, `logs`, `scan`, `track`, `upload-success`, `download-logs` |
| `resources/js/components/QrScanner.js` | The camera reader |
| `resources/js/components/VisibilityBadge.js` | The document pill — three levels, tone and icon derived from the value |

## Upload

`store()` validates the title, the type (an **active** `document_types` row, by id), the file (against the administrator-editable upload policy, never a literal), the visibility, and the recipients on either restricted level (each one distinct). It stores the file on the private `documents` disk, issues a tracking code, writes the QR label, creates the row — snapshotting the type's name into `type` and the uploader's into `uploader_name` — opens the trail with an `uploaded` entry, and attaches and notifies the tagged recipients.

> **The document type is a tag, not a condition.** It picks the tracking-code prefix and labels the row; it gates nothing. Access is decided entirely by the visibility below, so a document of type "Confidential" is not thereby confidential — somebody has to classify it.

## Who can open a document

Three levels, in `App\Enums\DocumentVisibility`, answering **two** questions rather than one:

| Level | Signed-in readers | A share link |
| --- | --- | --- |
| **Public** | anyone holding the tracking code, signed in or not | yes — opens without an account |
| **Private** | the uploader and the people tagged on it | yes — opens without an account |
| **Confidential** | the uploader and the people tagged on it | **never** |

> **Private and confidential grant identical reading.** They differ only in whether that reading can be handed to somebody outside the tagged list. That is the distinction, not a redundancy: a private document is restricted but still something you can send to a colleague who has no account; a confidential one is the named list and nothing else, so no URL exists that would open it for whoever holds it.

> **The recipient list is optional at every level, confidential included.** It was required there, on the reasoning that a confidential document with nobody tagged is readable by its uploader alone and by no link — filed where nobody can act on it. The reading was right and the conclusion was wrong: filing something before you know who it goes to is ordinary office work, and the refusal did not produce a tagged document, it produced an arbitrarily tagged one, because the way past the form was to name whoever came to mind. A tag somebody has to notice and undo is worse than an empty list. The form states the consequence instead, and tagging somebody is an edit.

This is why `AccessResolver::forDocument()` has **three** clauses and not four — confidential joins the recipient clause — while the link path asks `linkableNow()`. A fourth clause repeating the third would be a second statement of one rule. The public clause asks `restrictedToNamedPeople()` rather than `=== Public`, so a level added later cannot join the public side by being forgotten.

### Sharing a document by link

`POST /documents/{document}/share-links` (plus `PATCH .../{link}` for its terms, `POST .../{link}/regenerate` for a new address, and `DELETE .../{link}` to end it), gated on `documents.share`, with the uploader check and the confidential refusal in the controller — "may this person share" and "may this document be linked" are different questions, and a route can only ask the first.

> **The link is minted against the document, not against its `File` row.** The document is what somebody decided to share, what the trail is written about, and what carries the classification the link must obey. A link on the file would answer to the *file's* visibility and become exactly the looser second door to a tracked document that `AccessResolver::forFile()` exists to close. `AccessResolver::linkCovers()` is what lets a document's link reach that document's bytes and nothing else.

> **Reclassification is enforced on every redemption, not at creation.** A link handed out while a document was private stops working the moment the document becomes confidential — not when somebody remembers to revoke it. `test_a_link_stops_working_when_the_document_becomes_confidential`.

> **Opening a link writes to the document's own trail**, not only to the activity log. The two tables have different audiences, and "somebody opened this through the link you sent" belongs in the one the uploader reads. The row carries no actor, because whoever opened it is by construction not signed in.

> **Trap, found by this work.** `ShareLink::getRouteKeyName()` returned `token`, which reads as sensible and silently broke the only two routes that bind the model: both revoke endpoints are handed a link's **id** by the panel listing them, so every revoke resolved nothing, answered 404, and left the link working while the page reported success. The service was covered by tests and worked; the route in front of it was not. Removed, and now covered through HTTP by `test_revoking_a_link_actually_revokes_it`.

### Tracking codes

`ABBR-YYYYMMDD-NNNN`, issued by `App\Services\TrackingCodeService::issue()` — the only thing that may issue one.

`document_types` is **one table serving two jobs**: the list of types the upload form offers, and the map from type to prefix. They cannot drift apart. Add a type in one place — and in the database, not in PHP, which is the point of the table. `code` is immutable in practice: it is stamped into every tracking code that type has already issued, and those are printed on paper. Retire a type with `is_active` rather than deleting or renaming it.

Seeded by `DocumentTypeSeeder`, arranged into the four groups the picker offers:

| Group | Types |
| --- | --- |
| Documents | Confidential Document `CONFID`, Administrative Order `ADOR`, Special Order `SO`, Memorandum `MEMO`, Contract of Service `COS`, Memorandum of Understanding `MOU`, Memorandum of Agreement `MOA`, IPCR / DPCR `IPDP`, Letter `LETTER`, Report `REPORT`, Public Document `PUBDOC`, Private Document `PRIVDOC`, Others `OTHER` |
| Assets | Form or Template `FORM`, Dataset or Spreadsheet `DATA`, Presentation `PRES`, Design or Artwork `DESIGN` |
| Images | Photograph `PHOTO`, Scanned Copy `SCAN`, Certificate or Citation `CERT` |
| Videos | Video Recording `VIDEO`, Training Material `TRAIN`, Audio Recording `AUDIO` |

`TRACE` and `JRU` were dropped from the seeder because their names were placeholders rather than types anyone could identify. Dropping a line does **not** delete an existing row — the seeder only ever adds — because a type that has issued tracking codes must keep resolving.

> **Trap.** Three of these are named after access levels and none of them sets one. A document of type "Public Document" is not public and one of type "Confidential Document" is not confidential: the type is a tag that picks the tracking-code prefix, and `documents.visibility` — read by `AccessResolver::forDocument()` alone — is who may read it. Where the two disagree, the visibility is the answer.

> **Trap.** A new type takes `max(position) + 1` rather than a counter running from one. The counter is right exactly once, on a fresh install; on a database that already holds these rows, inserting into the middle of the list hands the new type a number another type already has, and two rows tied on `position` order arbitrarily. `MOA` and `LETTER` both came out as 8 the first time these were added.

The counter is `max(highest code already on record, the document_series row's counter) + 1`, read under a row lock inside a transaction. **Both sources are load-bearing:**

- Reading it back off the codes in `documents` makes the series self-correcting, so importing an office's historical records is all it takes for it to continue from where their paper trail left off.
- The `document_series` row never goes backwards, so an issued number is never issued again — without it, deleting the day's last document hands its code to the next upload.

> **Trap.** The obvious implementation — count every document created today, add one — was the original, and it is wrong twice over: the second *type* uploaded on a given day gets a number the first series already used, and two simultaneous uploads both read the same count. All of this is covered by `tests/Feature/DocumentUploadTest.php`, including the deleted-document case and the import-continuity case.

> **Trap.** `DocumentTypeSeeder` is not sample data. A tracking code takes its abbreviation from the document's type, so an unseeded install cannot accept an upload at all — which is why `Tests\TestCase` seeds it for every test.

### QR Trackers

Written as **PNG** into the private disk under `qrcodes/`, encoding the
document's **share link** — `QrLabelService::targetFor()`.

Not the tracking URL, and the reason is the section *A tracking code is not a
credential* below: once that URL required an account, a label encoding it sent
whoever scanned the folder to a sign-in page. A confidential document has no
link and never will, so its label falls back to the tracking URL and staff
sign in to read it.

`SharingService::ensureLinkFor()` mints the link with the document, so a label
always has a real target. The stored PNG is rewritten when a link is created,
regenerated or revoked — the three events that change the target — and
deliberately not when one is edited, because the point of an edit is that the
URL survives it.

> **Whether the label is a bearer credential is the link's term.** A document
> restricted to a named list gets its link on `ShareAbility::Restricted`,
> which resolves to the document's named audience: whoever photographs the
> folder learns that the document exists and that it is not theirs to open. A public document's link, and
> any link its uploader deliberately widens to view or download, *is* a bearer
> credential — better than a code, which could not be revoked at all, but a
> trade. So `downloadQr()` is restricted to whoever may share the document,
> and the page ships the *link's* symbol only to the uploader; every other
> reader gets a symbol for the tracking URL, which opens nothing without an
> account.

> **Trap.** Build labels only through `App\Services\Documents\QrLabelService`. It is PNG via `endroid/qr-code` (GD) with the seal embedded at High error correction — `simple-qrcode` could do neither, because its PNG and logo paths both need the Imagick extension these servers lack. Lower the correction level or widen the logo and the label stops scanning while still looking correct.

> **Trap.** The filename is the tracking code (`{code}.png`), never the document title. A title is arbitrary user input: it collided between documents of the same name, and `documents.qrcode.download` takes the filename, so it was a path-traversal vector. `downloadQr()` matches the requested name against a stored `qr_code_path` before touching the disk — which also means rows from before the switch keep their `.png` names and keep working.

## Visibility and access

`isAuthorized()` is the single gate for reading:

```
public document                      → allowed
the signed-in user is the uploader   → allowed
the user is in document_recipients   → allowed
otherwise                            → redirect with an error
```

Applied by `view()`, `viewByCode()` and `accessFile()`.

`owns()` is a separate question and gates writing — `edit`, `update`, `destroy`. A public document is readable by everyone and still editable only by its uploader.

> `DocumentLogController::canSee()` asks the same question — a log listing who received a confidential file is a disclosure of its own — and both it and `isAuthorized()` delegate to `App\Services\Access\AccessResolver`. They used to be hand-copied duplicates carrying comments saying they must change together; one resolver is what stops that being a standing hazard.

> **Trap.** `viewByCode()` (track-by-code) originally skipped the gate entirely, and `edit`/`update`/`destroy` had no ownership check at all. Both are covered by `tests/Feature/DocumentAuthorizationTest.php`; do not let either regress.

### Where the file is saved

The upload form has a **Save to** field: the destination is shown as "My Files" until somebody chooses otherwise, a *Choose folder* button opens `FolderPickerModal` — the same picker Move and Copy use, which can **create a folder from inside the dialog** — and a reset link puts it back. The form posts `folder`, a folder's public id, and an empty value means the top of the uploader's own storage.

`DocumentController::destinationFor()` resolves it, asking `AccessResolver` for `FileAbility::Rename`: the same question the picker's own tree is built from, so every folder it offers is one an upload can land in. A destination the uploader cannot write into is **404, not silently swapped for their root** — an upload that lands somewhere other than where it was sent is the failure a picker exists to prevent.

The register used to create a `Tracked Documents {year}` folder and file everything there, reasoning that the form had no folder field and that one folder per year keeps a long office's root tidy. The first half was true and the fix was to give the form the field; the second was the system arranging somebody else's storage, and what it produced was a folder nobody made holding files people could not find — they looked in My Files, where they had put everything else.

The picker's two controls are gated on `can.chooseFolder` (`files.read`) and `can.createFolder` (`files.create`), computed by the server: they work through the file manager's own endpoints, and a role that can file a document but not browse storage would otherwise meet a control that answers 403. Without it the upload lands at the root, which is the default anyway.

### Changing who it is shared with

The **level** is set at upload and is not on the edit form: reclassifying is a question about readers who already hold the document, and the part of it the office needs is answered on the share panel, by the link.

The **tagged list is editable**, on the same form, through the same `RecipientPicker` the upload form uses, rendered wherever the level in force tags people — private and confidential. It had to be: the list is optional at every level by design, the form invites filing a document before anybody knows who it is for, and the only way past a name left off was for that person to request access to a document they had no reason to know existed. This is not a second place to grant access out of context — it is the form that made the list, reopened, with the whole list on screen while it is corrected.

`DocumentController::update()` validates `recipients` exactly as `store()` does and then **diffs** it against the live list:

- **Unchanged** people are touched by neither half, so correcting a title cannot re-notify everybody or re-stamp rows that did not change. A sync would.
- **Additions** go through `DocumentRegistrar::tag()` (notification deferred), because clearing `revoked_at` on a re-tag lives there and `(document_id, user_id)` is unique, plus a `DocumentAction::ViewerAdded` row per person.
- **Removals** go through the same `revokeViewer()` the chip's own button uses — one place for the stamp and its trail row.
- A level that tags nobody (**public**) ignores the field entirely; the level decides, and it is not editable here.
- Changing the list at all needs `documents.share`, the same grant `store()` asks for, checked only when something actually changes.

The rows and their trail entries commit **with** the rest of the edit; the notifications are sent **after** the commit, because a notification sent inside a transaction that then rolls back is a message about something that did not happen.

### Withdrawing a document, and the way back

`documents` is soft-deleted, so `DocumentController::destroy()` stamps `deleted_at` and nothing more. The document comes off every screen — the global scope hides it from the register, the dashboard, search and tracking-code lookup — while the row, the tracking code, the recipient rows and the whole trail stay exactly as they were. The **file is not touched at all**: a tracked document's bytes stay in My Files, and `files:purge` still refuses to collect them, because `reasonToKeep()` asks `$file->document()->withTrashed()->exists()`.

That half was always true and the other half was missing: nothing listed a withdrawn document and no route restored one, so Delete was a one-way door whose only undo was a hand-written `UPDATE`. A soft delete nobody can reach is just a row nobody can account for.

The register's **Deleted tab** is that other half — `Document::onlyTrashed()->uploadedBy($user)`, and `POST /documents/{document}/restore`:

- **The tab appears only when it has contents.** A permanent fourth tab reading "(0)" on every register in the office is chrome about an absence.
- **Your own uploads only, administrator included.** Restoring mirrors deleting and `destroy()` is uploader-only; a screen listing other people's withdrawn documents would also disclose what they decided to take off the register.
- **The route binds `withTrashed()`.** Its subject is by definition soft-deleted, and without it the model binding applies the global scope and the one route that exists to reach a trashed document 404s on all of them. It is gated on `documents.delete` — the grant that put the document there — because a second permission would be a role holding half of one act.
- **A row on that tab offers View details and Restore, and the row itself is inert.** Open, Copy link and the label all point at routes that apply the scope and would 404; an action that cannot work is worse than a missing one, because the reader concludes the document is broken rather than withdrawn.
- **Restoring writes no trail entry**, and the symmetry is what decides it: withdrawing writes none either, so a restore that wrote a row would leave the history saying a document came back from somewhere it is never recorded as having gone.

**The Trash lists them too, and that is not a second trash.** "I deleted something and want it back" is answered by the screen named after the question, so a soft-deleted thing that appears nowhere on it is a thing people hunt for — which is exactly what happened. Both screens read `Document::withdrawnBy()`, one statement of what somebody has withdrawn, because two hand-built `onlyTrashed()` queries is how one of them quietly starts showing a document the other does not. On the Trash they are **rows in the same grid and table the files use**, not a list of their own. They began as a panel above the table, which read as two trashes on one screen and left the table beneath announcing "nothing here" while the trash plainly held five things: what a *row* is differs between a document and a file, which is what the columns are for, but what a *trash* is does not. A document's row says "The register" where a file says which folder it restores to, and "File kept" where a file gives its size — withdrawing a document never deleted its bytes, and the person deciding whether to restore it is exactly who needs telling. A document has no folder, so it is listed at the top level only and never inside a deleted folder, where it would claim a containment that does not exist. `can_restore` rides on each row and is `documents.delete`, not a file grant — the Trash lists them, it does not own them.

The delete confirmation says all of this now. It used to say "cannot be undone", which was true of the screens and false of the data.

### Taking a viewer off

The other door onto the same act, and the one that has to be possible from wherever you can see it: `DELETE /documents/{document}/recipients/{user}` (`documents.recipients.destroy`, gated `documents.share` plus `owns()`) removes a named viewer. Until this existed, nothing in the application could detach a recipient, so a document tagged with the wrong person stayed tagged with them.

The row is **stamped, not deleted**. `document_recipients.revoked_at` and `revoked_by` keep the record, which buys three things a deleted row cannot:

- **Two refusals instead of one.** `DocumentController::refusalMessage()` tells a removed reader they were removed, where somebody who was never tagged is told the document is restricted and invited to ask. The second invites a question; the first answers one that has already been decided. The same split is applied by `DocumentLogController::index()` and `accessFile()`, so the person learns it wherever they hit the wall.
- **A trail entry.** `DocumentAction::ViewerRemoved` — not postable, written by the system, with the viewer's name in the remarks so the row still reads after that account is gone. A trail that records every grant and no withdrawal overstates who can read the document, and `DocumentAction::ViewerAdded` is the same argument read the other way: one that records every withdrawal and no later grant understates it. Tagging at upload writes neither — the `uploaded` row already says the document was filed with the list it carries.
- **Re-tagging as an update.** `(document_id, user_id)` is unique, so the row has to be revived rather than inserted; `DocumentRegistrar::tag()` goes through `Document::allRecipients()` and clears the stamp.

> **Trap.** `Document::recipients()` is the **live** list — it carries `wherePivotNull('revoked_at')`, and so does `User::receivedDocuments()`. Every read goes through those; only `allRecipients()` (writes) and `removedViewers()` (the uploader's own record of it) see the whole table. A read that went to the whole table would leave a removed viewer still able to open the document.

A revoked share **link** is a different case and answers 404, the same as an unknown token. The holder has no account and no identity this system verified, so there is nobody for a "you were removed" message to be about, and distinguishing "revoked" from "never existed" would confirm a token was once real.

## Replacing the file: versions

The edit form's "Replace file" input saves the new file as the **next version** of the same `files` row. `DocumentController::update()` hands it to `FileManager::replace()`.

**What survives a replacement**, and this is the headline:

- `documents.code` — the tracking code, and `TrackingCodeService` is never called (the series counter never goes backwards, so issuing a code would burn a number *and* change the string printed on paper);
- `qr_code_path` and the stored PNG, because the label encodes a URL;
- the share link, **with its token and its view count**;
- every `document_recipients` row, and the whole `document_logs` trail;
- the file's `public_id`, so `/files/{owner}/{file}` keeps working;
- `documents.file_id` itself — the document points at the same row forever, which is what makes all of the above true.

`documents.file_name` does follow the new bytes, because it is printed beside the tracking code.

> **What this replaced.** `update()` used to call `FileStorage::store()`, creating a *second* `files` row and repointing `file_id` at it — leaving the first orphaned with no link back to the document, nothing in either trail, no transaction and no thumbnail. `files:purge --orphans` exists to sweep up after that, and now has nothing to sweep.

> **Trap.** Only the uploader may replace a tracked document's file — `FileManager::mayReplace()` enforces it, even against somebody holding a manage share on the `files` row, because a tracking code printed on a folder must not come to name content the uploader never approved.

A legacy or imported document with no `files` row at all (`file_id` is `nullOnDelete`) gets version 1 through the ordinary upload path, so it also gets a thumbnail and an `uploaded` activity row — both of which the old code skipped.

### The version list on the document page

`GET /documents/{document}/versions` returns the history, behind the document's own read rule and answering 404 on a refusal, exactly as `preview()` does. It is **fetched** when the Versions panel is opened, never a page prop, because each entry carries URLs that open bytes.

Showing an older version reuses the same `FilePreviewPane` — `GET /documents/{document}/preview?version=N` — so an old `.docx` is drawn as the docx it is even if the current file is now a PDF. Downloading one goes through `documents.accessFile` with `?version=N`, which records the view or download **against that version** and still writes `document_downloads`.

### A replacement is in the trail

`DocumentAction::FileReplaced` — "File replaced" — is written for every replacement, so **everyone who can read the document sees it**. For a tracked document the file *is* the document: a reader who downloaded version 1 and acted on it must learn that version 2 exists, and the row discloses nothing they could not see by opening the file.

A **revert** writes the same action with "Reverted to version 1" in the remarks. It gets no case of its own, because to a reader it is a replacement — the code now names different bytes — and "these bytes are a copy of version 1" is storage mechanics, which belongs in `activity_logs`.

Neither is `postable()`: a client claiming the file changed does not change it.

> **Trap.** This crossover is written **directly** by `FileManager::replace()`, not through `FileEventRecorder::DOCUMENT_EQUIVALENTS`. That map exists for actions fired from four different doors which all need one mirroring point; a replacement has exactly one writer. Routing it through the map would put every future action added beside those two entries one typo away from appearing in every document's public trail.

### The trail reads version by version

`document_logs.version` is stamped on every row — **derived** by `DocumentLog::record()` from `$document->file?->version` when no version is passed, which is what keeps every existing writer correct untouched: a view, a download, a receipt and a link share each concern whatever version was current at that moment. Only the path serving a *superseded* version passes it explicitly.

`TrailVersionTabs` reads the trail one version at a time: `All versions (n)` and a tab per version the history actually holds, newest first, with the unversioned rows (`version=none`) last. The tabs come from `DocumentLog::versionTabsFor()`, grouped on the server under every filter in force **except** the version itself, so each count states what choosing that tab would show; `All versions` is the sum rather than a second query. It renders nothing until a file has been replaced — one version is not a choice — and switching tabs returns to page one.

It *is* the version filter: a tab sets the same `version` query parameter `DocumentLog::scopeTrail()` has always read, so a narrowed trail stays linkable. The select is therefore gone from `TrailFilters` (two controls over one filter contradict each other), and the Filter button's badge does not count the version, because the tabs say so in plain sight. `Clear all filters` still clears it. Both the panel and the full-history page also group rows under a "File version N" heading on the `All versions` tab, repeated on the first row of every page so a page boundary cannot hide one, and `DocumentLog::versionsFor()` still decides whether there is more than one version to group by.

Null stays meaningful: a document with no stored file has no version, and neither does any row written before the column existed. Nothing was backfilled.

## There is no lifecycle

A document is simply on record. **The End Action has been removed** at the office's request, and removed completely rather than hidden: the `documents.end` route, `DocumentController::end()`, `App\Enums\DocumentStatus`, `DocumentAction::Ended`, the `StatusBadge` component and the `documents.status` / `documents.ended_at` columns are all gone.

What that changed, and what it did not:

- `AccessResolver::READER_ABILITIES` is now a flat `[View, Download]`. Ending a document used to take `download` away from everybody but the uploader, and that was the only state that narrowed a reader's grant.
- The dashboard counts visibility instead of status. Its "ended" tile could only ever be zero or a number nobody acted on; it now reports what the person shared with named staff and what is readable office-wide.
- An uploader who wants a document out of circulation **withdraws it**, which is a delete. `documents` is soft-deleted and the trail is `nullOnDelete` beside a name snapshot, so the history survives.

> **Trap.** Do not reinstate `status` as a column "for later". A lifecycle state that nothing writes is the condition that produced the original bug: the dashboard's "ended" count sat at zero for months because one comparison looked for `'ended'` while the writer stored `'Ended'`, and nobody noticed because nothing depended on the answer.

The dead columns from earlier designs — `documents.is_ended`, `documents.target_division_id`, `documents.target_section_id` and `document_logs.status` — were dropped before this and stay dropped.

## The two audit tables

`document_logs` is the travel history, shown to anyone who can read the document. `document_downloads` is the narrower "whose hands a copy ended up in" register behind `/admin/download-logs`; it carries the IP a copy went to, which has no business on a document page. A download writes a row to **both**, and the second points at the first through `document_log_id`.

`action` is an `App\Enums\DocumentAction` — the stored value is a slug, the sentence is `label()`, so the wording can be corrected without rewriting history.

| Action | `label()` | Written by |
| --- | --- | --- |
| `uploaded` | Uploaded | `store` |
| `viewed` | Viewed file | `accessFile` with `view` |
| `downloaded` | Downloaded file | `accessFile` with `download` |
| `received` | Received | posted from the Log action form, once per user |
| `acknowledged` / `reviewed` / `returned` | Acknowledged / Forwarded, Reviewed, Returned | `DocumentLogController@store` |

### Every entry snapshots its actor

Both tables carry `actor_name` / `user_name`, `actor_employee_id`, `division_name` and `section_name` beside the foreign keys, and the pages render the **snapshots**. The keys stay for filtering and for following a row back to a live record, and every one of them is `nullOnDelete`.

`document_logs` snapshots the actor's **face** the same way: `actor_avatar_seed` and `actor_profile_image` are the two inputs `resources/js/avatar.js` needs, so a trail renders a recognisable person without joining a live account. Neither is a credential — a seed is an arbitrary token the person picked, the path is already served to anybody who can see them in a list — and both are nullable with no backfill, because `avatar.js` derives a stable face from `actor_name` alone for rows written before the columns existed.

Write rows only through `DocumentLog::record()` and `DocumentDownload::register()` — the one place the shape is built.

> **Trap.** Do not "simplify" this by eager-loading the actor and dropping the name columns. That was the old design: renaming a division silently rewrote where a document had been two years ago, deleting one blanked it, and deleting an account took the document with it (`documents.user_id` cascaded, and `/profile` lets a user delete their own account). Covered by `tests/Feature/DocumentTrailTest.php`.

> **Trap.** `document_downloads` existed from the start with nothing writing to it, and `DocumentDownload` was an empty model class the report still eager-loaded relations from — so the page was permanently blank and would have thrown the moment a row appeared.

`DocumentLogController@store` accepts only `DocumentAction::postable()` — `received`, `acknowledged`, `reviewed`, `returned` — and refuses two consecutive `acknowledged` entries by the same person, since the second says nothing the first did not. `received` may be logged **once** per person, a rule that lives in the controller beside the fact rather than on any one endpoint: it belongs to "a document reaches you once", not to the door it was posted through.

> **Trap.** `downloaded` is deliberately *not* postable, and `received` deliberately is. Taking a copy is something the **server observes**; a document reaching you is something only **you can assert**, and very often it arrives as paper with nothing downloaded. The old "Receive & download" button fused the two, so you could not say "this arrived" without pulling the file, and a download by somebody who already held the folder recorded a receipt they never confirmed.

> **Trap.** That endpoint used to validate `action` as `string|max:255`, so a client could post any text it liked — including a forged `received` entry it had not earned.

## Viewing a document in place

**The file is rendered in the page**, under the details card, by the same
`FilePreviewPane` the file manager's preview modal uses. There was a **View
file** button, which is a button in front of the one thing somebody opening a
document came to do; the trail went the other way, into a half-width
**Audit Trail** drawer, because it is the longest thing on the page and the
least often read.

`GET /documents/{document}/preview` returns the descriptor from
`App\Services\Files\FilePreview`, so a PDF, an image or a video renders in the
page and a Word, Excel or PowerPoint document renders through Microsoft's
embedded Office viewer over a short-lived signed URL — see `file-manager.md` for
how narrow that URL is, and for why *that* half cannot work on a `localhost`
install.

It used to be `<a href=".../file/view" target="_blank">`, which handed the raw
bytes to a tab: fine for a PDF, a download prompt for every other format an
office files.

> **Behind `auth`, like the page it serves.** Both used to be public, so a
> signed-out reader of a public document could open the page and its preview
> from the printed code. They require an account now — see *A tracking code is
> not a credential* below — and somebody outside the office is served by a
> share link, whose landing page renders this same descriptor resolved through
> the token. The gate inside the controller is still `isAuthorized()`, applied
> before anything else, and a refusal is still a **404**: the caller is a fetch
> behind a button and has nothing to render a flash with.

> **Trap.** The descriptor is fetched when the page opens, never shipped with
> it. For an Office document it mints a URL that is live for minutes, and
> a page prop would hand that credential to everybody who merely looked at the
> document.

> **Trap.** Opening the viewer writes the `viewed` entry, debounced through
> `FileEventRecorder::recordOnce()`. `accessFile` is not involved, which matters
> because that route writes a trail entry on *every* response — an embedded
> frame there would claim the document was opened once per re-render. For an
> Office document the bytes are fetched by Microsoft rather than by the person,
> so the descriptor request is the only one that carries an actor at all.

## File responses

`accessFile` and `downloadQr` return file responses.

> **Trap.** An Inertia XHR visit cannot follow those. Reach them with a plain `<a href>`, a real form post, or `window.location` — never `router.visit`. There is no nested form on `documents/show.js` any more: the combined "Receive & download" is gone, receipt is a `received` entry on the Log action form, and Download is an ordinary link.

`accessFile` checks the file exists on disk before writing any log, so a missing file does not leave a download recorded that never happened.

## One page, and tabs

"My Documents" and "All Documents" were two sidebar entries over two controller methods and two Vue pages listing the same table through different filters. They are **one** page now — All, Uploaded by me, Shared with me — because which of those somebody wants is a filter, not a destination.

On **All**, a row the reader was tagged on carries a "Shared with you" badge beside the classification: the level says what the document is, the badge says what it is to them. It is `withExists(['recipients as shared_with_me' => …])` — one boolean per row, asked of the *live* recipient relation, so removing a viewer removes the badge with the access.

The All tab is still a different *grant*: "every document in the office" is not "the documents I am party to", so it is rendered only when the server says `can.all`.

> **Trap.** `documents-all.read` is resolved **in the controller**, not by route middleware, and `DocumentController::tab()` degrades to "Uploaded by me" rather than refusing. A tab is a filter on one screen, so middleware would turn a stale link into a 403 for the whole page. `tests/Feature/ModuleRegistryTest.php` names it in `$resolvedInCode` for exactly this reason — it gates something real, just not a route.

**A register row opens on click, and carries its classification as a stripe.** The whole row is the open affordance — guarded on what was clicked, so a control in the Actions cell is that control's click and not the row's — and a 3px left border repeats the level in the badge's own three colours, with the badge's own fallback: an unknown level is drawn as the most restrictive, since a row that defaults to green on a value it does not know will eventually paint a restricted document as open. The stripe is an addition to the badge and never a replacement: colour alone is not an answer for a reader who cannot tell these three apart.

**The QR label is a row action, fetched when it is asked for.** `GET /documents/{document}/qr-label` returns the symbol as SVG, what it resolves to, and whether the stored PNG may be downloaded. Not a prop on the listing, for the reason `FilePreview` is not one: a page of ten rows would otherwise carry ten symbols — each an SVG with the seal embedded, and for the uploader each one a live credential — on every navigation, nine never looked at.

> **Trap.** What the symbol encodes depends on who asked, exactly as it does on the document page. The uploader gets the **share link's** label, which is what makes a printed label work for a courier and what makes it a credential; everybody else gets the tracking URL, which opens nothing without an account. `can_download` follows ownership too, because `downloadQr()` answers 404 to anybody but the uploader — offering a reader the button would be offering them a 404.

**Every date on screen carries its time.** `formatDay` is no longer called anywhere: two documents filed the same afternoon are ordered by a time the screen was not showing, so "Nov 21, 2026" twice read as a tie the list had already broken.

**The register's Uploaded column is a face, a name and a date.** The uploader was once a column of its own, which is part of what pushed Actions off the right of a laptop screen; folded into the date's column it costs no width. The **name** is the row's own `uploader_name` snapshot and the **face** comes from the live account's `profile_image` / `avatar_seed`, which is the same split the audit trail makes — a document filed by an account since closed still says who filed it, and `avatar.js` derives a stable face from the name alone when there is no account left to read.

**Back comes back to the tab you left.** The tab, the filters and the sort all live in the query string, but the Back button on a document page wrote a bare `/documents`, which threw that URL away and dropped the reader on the default tab — so somebody working through "Shared with me", or through a filter they had just set up in the dialog, rebuilt it after every document they opened. `resources/js/registerReturn.js` records the register's current query in `sessionStorage` and `show`, `restricted`, `form` (create) and `upload-success` read it back as their Back target.

Three things about it are deliberate. It records the **server's** `filters` prop rather than the page's local copy, because the server is what resolved the tab — a reader without `documents-all.read` who asked for All is shown "Uploaded by me", and storing the ask would send them back to a tab they were never on. It is `sessionStorage` and not `localStorage`, because this is where you were a moment ago and not a preference: a tab opened tomorrow starts on the default, and two windows do not fight over one value. And it is only ever a **link target** — nothing navigates on its own, the register still resolves its own tab, so a stale or hand-edited value degrades there exactly as a stale link always did, and a store that cannot be read answers with the plain `/documents` it replaced. The sidebar's own Documents entry is untouched: that is a deliberate navigation, not a return.

`/documents/all` survives as a redirect to `/documents?tab=all`, carrying `documents.read`, so a bookmark or a pasted link still lands somewhere.

The upload screen moved to **`/documents/upload`**. The route *name* is still `documents.create`, so every `to_route()` call and `<Link>` kept working.

> **Trap.** Every literal `documents/<word>` segment must be declared before `documents/{document}` in `routes/web.php`, or it is captured as a document id. `documents/upload` is now one of them.

## Tracking is a modal, not two menu entries

The sidebar's "Scan QR Code" entry is gone. Tracking is a **Track document** button beside Upload, opening a modal that offers the two things a person actually has in front of them: the code printed beside the label, or the label itself.

Both routes stay — `/documents/track` and `/scan`. The first requires an account; the second does not, and forwards a visitor to the public lookup.

### A tracking code opens one page, and that page is not the document

A tracking code is **not a credential**, and that is the rule the whole surface was closed to enforce: it is printed on the folder and it is a per-type daily series, so it is short, sequential and guessable. Anyone who photographed a desk — or simply counted — could walk it.

What that closure got wrong was the **answer**, not the rule. Every scan by somebody without an account landed on a sign-in form, which says nothing about the document and nothing about why — and the courier holding the folder, the entire reason the label is printed on it, is precisely the person with no account.

So `/track` is public, served by `PublicTrackingController`, and `/scan` forwards a guest to it. `AccessResolver` is untouched and the page asks it rather than deciding anything:

| What the visitor holds | What `/track` answers |
| --- | --- |
| A code for a **public** document | Title, type, uploader, date, and a preview through the same `FilePreviewPane` the document page uses. No download — `FilePreview` reports `can_download` false for a signed-out reader. |
| A code for a **private** or **confidential** document | The code and the level. No title, no uploader, no type, no file, no trail. Sign in, or ask for a share link. |
| A code, while signed in and granted `view` | A redirect to `documents.view`, so the public door is never the worse page. |
| A code that matches nothing | "No document carries that code." — check the label. |
| Something that is not a code at all | "That is not a tracking code." — check what you typed. |

`/documents/view/{code}`, `/documents/track`, `/documents/success/...`, the QR download and `documents.preview` still carry `auth`, as does `documents.accessFile`.

Somebody who needs a *copy* gets a **share link**, and the difference is not formality: a link is an object with an ability, an optional expiry, an optional view cap, a recorded count of its redemptions and a revoke button. A printed code has none of those and never can. A code is a look; a link is a copy.

### Three answers to a lookup, not two

`TrackingCodeService::MASK` is the shape of a tracking code — `ABBR-YYYYMMDD-NNNN` — and `looksLikeCode()` is asked **before the database is**, by both `PublicTrackingController::show()` and `DocumentController::viewByCode()`, so the two doors cannot disagree about what a code is.

The reason it is a third answer and not a fold into "not found" is where each one sends somebody. "No document carries that code" sends them back to the printed label. "That is not a tracking code" sends them back to what they typed. A scanned vCard, a wifi label, a poster QR and a pasted paragraph all land in the second, and reporting them as the first has people hunting the register for a code they never had.

The mask lives beside the code that composes one, and **is not restated in the frontend**. `TrackDocumentCard` validates only what is about the *input* rather than about the format — it is empty, it has whitespace in it, it is longer than any code could be, or a scan produced a URL belonging to another site — and everything else is the server's answer. A second copy of the mask in JavaScript is a second thing to update when a type code's rules change, and the copy that drifts is always the one that refuses a code that is in fact valid.

Input is bounded at 64 characters and **truncated rather than refused with a 422**: this is a GET with no form behind it, so a validation redirect has nowhere to render its message.

> **Trap.** Both server messages are about a lookup that was **submitted**, so `TrackDocumentCard` hides them the moment the box is edited and quotes `attempted` — the value the server answered about — rather than the live input. `code` is a local ref shadowing the prop of the same name, so the first version read the box: clearing it left *"“” is not a tracking code"* on screen, a complaint about a value nobody searched for. A submission marks itself attempted rather than waiting on the prop watcher, because the same wrong code submitted twice produces identical props and would otherwise never show its message again.

> **Trap.** A camera scan stops the stream *before* handing the result up, so a symbol that turns out not to be a label leaves a dead region with nothing to point at the folder again. `TrackDocumentCard` bumps a `:key` to remount `QrScanner`, which restarts through the `onBeforeUnmount` teardown that is already tested rather than adding a second way to stop a camera.

> **Trap.** `accept="image/*"` filters the file picker and nothing else — a drag or a paste delivers whatever was chosen. `QrScanner` checks the type and the size itself, because the library's failure for a PDF reads as "no QR code could be read", which sends somebody looking for a better photo of a file that was never a photo.

> **Trap.** The page discloses that a code is live and how it is classified. That is the cost of answering the courier at all, and it is blunted rather than removed — `/track` is throttled, and a restricted document discloses nothing beyond its level. Answering "no such document" for a confidential one was rejected: it is a lie told to the uploader's own colleagues as often as to anyone else, and what is being protected is the contents, which this page does not hold.

> **Trap.** A visitor's preview is recorded against nobody — `files.stream` writes the row with a null actor, so it reads "Unknown". That is the gap `GuestIdentity` closed for share links and that is not closed here.

> **Trap.** The scanner is mounted only while that method is chosen in the modal, so `QrScanner`'s `onBeforeUnmount` releases the camera when the modal closes. Rendering it eagerly behind a `v-show` would hold the camera open for as long as the page was.

## Scanning

`QrScanner.js` wraps `html5-qrcode` and **stops the stream in `onBeforeUnmount`**.

> **Trap.** That teardown is the reason it is a component. An Inertia visit unmounts the page without reloading the document, so nothing else would release the camera. The Blade version loaded the library twice from a CDN — once in the layout, again in the page — and leaked the camera on every navigation.

A scan may carry a share link (our own labels), a full tracking URL (labels
printed before the switch) or a bare code; `documents/scan.js` normalises the
last two to the same visit and follows the first as an ordinary URL.

## Pages

| Page | Route | Notes |
| --- | --- | --- |
| `index.js` | `/documents` | Three tabs — all, uploaded by me, shared with me — plus Upload and the Track document modal. The tab is in the query string. |
| `form.js` | `/documents/upload`, `/documents/{id}/edit` | One component for both |
| `show.js` | `/documents/view/{code}` | What a QR label opens |
| `track.js` | `/documents/track` | Signed in. Only rendered on a miss or an empty code — a hit renders `show` instead |
| `track/index.js` | `/track` | Public. The lookup, and what a visitor with no grant is told about a hit |
| `logs.js` | `/documents/{id}/logs` | Paginated full history |
| `scan.js` | `/scan` | Signed in only; a guest is forwarded to `/track`. No longer in the sidebar — the Track document modal is the way in |
| `upload-success.js` | `/documents/success/{code}/{file_name}` | The printable label |
| `download-logs.js` | `/admin/download-logs` | Gated on `download-logs.read`; the report carries the IP every copy went to, which is why it has a permission of its own |
