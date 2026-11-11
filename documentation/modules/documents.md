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
| `resources/js/pages/documents/` | `index` (three tabs), `form`, `show`, `logs`, `scan`, `track`, `upload-success`, `download-logs` |
| `resources/js/components/QrScanner.js` | The camera reader |
| `resources/js/components/VisibilityBadge.js` | The document pill |

## Upload

`store()` validates the title, the type (an **active** `document_types` row, by id), the file (PDF/DOC/DOCX, max 10 MB), the visibility, and the recipients when private (each one distinct). It stores the file on the `public` disk under `documents/`, issues a tracking code, writes the QR label, creates the row — snapshotting the type's name into `type` and the uploader's into `uploader_name` — opens the trail with an `uploaded` entry, and for a private document attaches the recipients and notifies them.

### Tracking codes

`ABBR-YYYYMMDD-NNNN`, issued by `App\Services\TrackingCodeService::issue()` — the only thing that may issue one.

`document_types` is **one table serving two jobs**: the list of types the upload form offers, and the map from type to prefix. They cannot drift apart. Add a type in one place — and in the database, not in PHP, which is the point of the table. `code` is immutable in practice: it is stamped into every tracking code that type has already issued, and those are printed on paper. Retire a type with `is_active` rather than deleting or renaming it.

Seeded by `DocumentTypeSeeder` with the nine types and the codes they have always had:

| Type | Code | Type | Code |
| --- | --- | --- | --- |
| Confidential | `CONFID` | Contract of Service | `COS` |
| Administrative Order | `ADOR` | Memorandum of Understanding | `MOU` |
| Special Order | `SO` | JRU | `JRU` |
| Memo | `MEMO` | IPCR/DPCR | `IPDP` |
| TRACE docs | `TRACE` | | |

The counter is `max(highest code already on record, the document_series row's counter) + 1`, read under a row lock inside a transaction. **Both sources are load-bearing:**

- Reading it back off the codes in `documents` makes the series self-correcting, so importing an office's historical records is all it takes for it to continue from where their paper trail left off.
- The `document_series` row never goes backwards, so an issued number is never issued again — without it, deleting the day's last document hands its code to the next upload.

> **Trap.** The obvious implementation — count every document created today, add one — was the original, and it is wrong twice over: the second *type* uploaded on a given day gets a number the first series already used, and two simultaneous uploads both read the same count. All of this is covered by `tests/Feature/DocumentUploadTest.php`, including the deleted-document case and the import-continuity case.

> **Trap.** `DocumentTypeSeeder` is not sample data. A tracking code takes its abbreviation from the document's type, so an unseeded install cannot accept an upload at all — which is why `Tests\TestCase` seeds it for every test.

### QR labels

Rendered as **SVG** into `storage/app/public/qrcodes/`, encoding `route('documents.view', ['code' => $code])`.

> **Trap.** Not PNG. `simple-qrcode` renders PNG through BaconQrCode's Imagick backend, and these servers have GD but not Imagick, so `format('png')` threw on every upload.

> **Trap.** The filename is the tracking code (`{code}.svg`), never the document title. A title is arbitrary user input: it collided between documents of the same name, and `documents.qrcode.download` is a **public** route that takes the filename, so it was a path-traversal vector. `downloadQr()` matches the requested name against a stored `qr_code_path` before touching the disk — which also means rows from before the switch keep their `.png` names and keep working.

## Visibility and access

`isAuthorized()` is the single gate for reading:

```
public document                      → allowed
the signed-in user is the uploader   → allowed
the user is in document_recipients   → allowed
otherwise                            → redirect with an error
```

Applied by `view()`, `viewByCode()`, `accessFile()` and `receiveFile()`.

`owns()` is a separate question and gates writing — `edit`, `update`, `destroy`. A public document is readable by everyone and still editable only by its uploader.

> **Trap.** `DocumentLogController::canSee()` is a deliberate duplicate of `isAuthorized()`, because a log listing who received a confidential file is a disclosure of its own. Change one and change the other.

> **Trap.** `viewByCode()` (track-by-code) originally skipped the gate entirely, and `edit`/`update`/`destroy` had no ownership check at all. Both are covered by `tests/Feature/DocumentAuthorizationTest.php`; do not let either regress.

Visibility and recipients are set **at upload only**. Editing covers the title, the type and replacing the file — changing who a document was shared with after the fact would rewrite the record the notifications already went out about.

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
| `received` | Received and downloaded | `receiveFile`, once per user |
| `acknowledged` / `reviewed` / `returned` | Acknowledged / Forwarded, Reviewed, Returned | `DocumentLogController@store` |

### Every entry snapshots its actor

Both tables carry `actor_name` / `user_name`, `actor_employee_id`, `division_name` and `section_name` beside the foreign keys, and the pages render the **snapshots**. The keys stay for filtering and for following a row back to a live record, and every one of them is `nullOnDelete`.

Write rows only through `DocumentLog::record()` and `DocumentDownload::register()` — the one place the shape is built.

> **Trap.** Do not "simplify" this by eager-loading the actor and dropping the name columns. That was the old design: renaming a division silently rewrote where a document had been two years ago, deleting one blanked it, and deleting an account took the document with it (`documents.user_id` cascaded, and `/profile` lets a user delete their own account). Covered by `tests/Feature/DocumentTrailTest.php`.

> **Trap.** `document_downloads` existed from the start with nothing writing to it, and `DocumentDownload` was an empty model class the report still eager-loaded relations from — so the page was permanently blank and would have thrown the moment a row appeared.

`DocumentLogController@store` accepts only `DocumentAction::postable()` (`acknowledged`, `reviewed`, `returned`) and refuses two consecutive `acknowledged` entries by the same person, since the second says nothing the first did not.

> **Trap.** That endpoint used to validate `action` as `string|max:255`, so a client could post any text it liked — including a forged `received` entry it had not earned.

## Viewing a document in place

**View file** opens the same viewer the file manager uses, not a new browser
tab. `GET /documents/{document}/preview` returns the descriptor from
`App\Services\Files\FilePreview`, so a PDF, an image or a video renders in the
page and a Word, Excel or PowerPoint document renders through Microsoft's
embedded Office viewer over a short-lived signed URL — see `file-manager.md` for
how narrow that URL is, and for why *that* half cannot work on a `localhost`
install.

It used to be `<a href=".../file/view" target="_blank">`, which handed the raw
bytes to a tab: fine for a PDF, a download prompt for every other format an
office files.

> **Public, like the page it serves.** `documents/show` renders for a signed-out
> reader of a public document, and the bytes behind the preview come from the
> file-stream route, which is public for the same reason. Gating the descriptor
> on a session would mean the preview worked for everybody except the person
> holding the printed label. The gate is `isAuthorized()`, applied before
> anything else, and a refusal is a **404** — the caller is a fetch behind a
> button and has nothing to render a flash with.

> **Trap.** The descriptor is fetched when the viewer opens, never shipped with
> the page. For an Office document it mints a URL that is live for minutes, and
> a page prop would hand that credential to everybody who merely looked at the
> document.

> **Trap.** Opening the viewer writes the `viewed` entry, debounced through
> `FileEventRecorder::recordOnce()`. `accessFile` is not involved, which matters
> because that route writes a trail entry on *every* response — an embedded
> frame there would claim the document was opened once per re-render. For an
> Office document the bytes are fetched by Microsoft rather than by the person,
> so the descriptor request is the only one that carries an actor at all.

## File responses

`accessFile`, `receiveFile` and `downloadQr` return file responses.

> **Trap.** An Inertia XHR visit cannot follow those. Reach them with a plain `<a href>`, a real form post, or `window.location` — never `router.visit`. `documents/show.js` posts the receive form directly for this reason, and reads the CSRF token from the meta tag because it is not going through Inertia.

Both `accessFile` and `receiveFile` check the file exists on disk before writing any log, so a missing file does not leave a download recorded that never happened.

## One page, three tabs

"My Documents" and "All Documents" were two sidebar entries over two controller methods and two Vue pages listing the same table through different filters. They are **one** page now — All, Uploaded by me, Shared with me — because which of those somebody wants is a filter, not a destination.

The All tab is still a different *grant*: "every document in the office" is not "the documents I am party to", so it is rendered only when the server says `can.all`.

> **Trap.** `documents-all.read` is resolved **in the controller**, not by route middleware, and `DocumentController::tab()` degrades to "Uploaded by me" rather than refusing. A tab is a filter on one screen, so middleware would turn a stale link into a 403 for the whole page. `tests/Feature/ModuleRegistryTest.php` names it in `$resolvedInCode` for exactly this reason — it gates something real, just not a route.

`/documents/all` survives as a redirect to `/documents?tab=all`, carrying `documents.read`, so a bookmark or a pasted link still lands somewhere.

The upload screen moved to **`/documents/upload`**. The route *name* is still `documents.create`, so every `to_route()` call and `<Link>` kept working.

> **Trap.** Every literal `documents/<word>` segment must be declared before `documents/{document}` in `routes/web.php`, or it is captured as a document id. `documents/upload` is now one of them.

## Tracking is a modal, not two menu entries

The sidebar's "Scan QR Code" entry is gone. Tracking is a **Track document** button beside Upload, opening a modal that offers the two things a person actually has in front of them: the code printed beside the label, or the label itself.

Both routes stay, and stay public — `/documents/track` and `/scan` — because a QR label has to resolve for whoever is holding the folder, including a signed-out reader of a public document. What changed is the sidebar, not the gate.

> **Trap.** The scanner is mounted only while that method is chosen in the modal, so `QrScanner`'s `onBeforeUnmount` releases the camera when the modal closes. Rendering it eagerly behind a `v-show` would hold the camera open for as long as the page was.

## Scanning

`QrScanner.js` wraps `html5-qrcode` and **stops the stream in `onBeforeUnmount`**.

> **Trap.** That teardown is the reason it is a component. An Inertia visit unmounts the page without reloading the document, so nothing else would release the camera. The Blade version loaded the library twice from a CDN — once in the layout, again in the page — and leaked the camera on every navigation.

A scan may carry a full tracking URL (our own labels) or a bare code; `documents/scan.js` normalises both to the same visit.

## Pages

| Page | Route | Notes |
| --- | --- | --- |
| `index.js` | `/documents` | Three tabs — all, uploaded by me, shared with me — plus Upload and the Track document modal. The tab is in the query string. |
| `form.js` | `/documents/upload`, `/documents/{id}/edit` | One component for both |
| `show.js` | `/documents/view/{code}` | What a QR label opens |
| `track.js` | `/documents/track` | Only rendered on a miss or an empty code — a hit renders `show` instead |
| `logs.js` | `/documents/{id}/logs` | Paginated full history |
| `scan.js` | `/scan` | Public, and no longer in the sidebar — the Track document modal is the way in |
| `upload-success.js` | `/documents/success/{code}/{file_name}` | The printable label |
| `download-logs.js` | `/admin/download-logs` | Gated on `download-logs.read`; the report carries the IP every copy went to, which is why it has a permission of its own |
