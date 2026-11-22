# Access requests

Asking the uploader to be let into a document, and answering.

A reader refused a private or confidential document used to be told only that they were not on its list. True, and a dead end: the only way forward was to work out who filed it and ask by other means, which mostly means the work stops.

## Key files

| Path | What it holds |
| --- | --- |
| `app/Http/Controllers/AccessRequestController.php` | The worklist (JSON), asking by tracking code, and the decision |
| `app/Models/AccessRequest.php` | `open()`, the `pending()` scope, the snapshots |
| `app/Enums/AccessRequestStatus.php` | `pending`, `granted`, `denied` |
| `app/Notifications/DocumentAccessRequested.php` | To the uploader, when somebody asks |
| `app/Notifications/DocumentAccessDecided.php` | To the requester, on either answer |
| `resources/js/components/AccessRequestsMenu.js` | The header menu, its badge and the three tabs |
| `tests/Feature/AccessRequestTest.php` | The whole flow, including every refusal |

## Granting creates a recipient row and nothing else

That is the test that this is built in the right place. `AccessResolver::forDocument()` already reads `document_recipients`, so **this feature introduces no new way into a document and the resolver did not change at all.** `access_requests` records the asking and the answer; the recipient list stays the single statement of who may read what. The grant goes through `DocumentRegistrar::tag()` — the same helper the upload form uses — with `notify: false`, because the decision notification is the message and `PrivateDocumentShared` would be a second one saying the same thing.

## There is no decide permission

`access-requests` offers `read` and `create`, and deliberately nothing else. Whether somebody may *answer* is a per-record question — the request is addressed to the document's uploader — and a module checkbox for it would create requests nobody holding it could answer: a dead letter rather than a protection. The same distinction as `documents.delete` versus `DocumentController::owns()`.

So `PATCH /access-requests/{accessRequest}` carries **no `permission:` middleware**. The controller is the only thing that can know who the uploader is, and `mayDecide()` names SUPERADMIN explicitly rather than leaning on `Gate::before`, so nothing stalls while somebody is on leave and no reader is sent looking for an `access-requests.decide` that does not exist.

> **Trap.** The decision authorizes against the document's **live `user_id`**, not the `owner_id` snapshot on the row. `OwnershipTransferService` moves documents when an account is closed, and a transferred document's pending requests must become answerable by whoever holds it now rather than stalling forever. The snapshot is the record of who was *asked*.

## Asking is by code, and every refusal is identical

`POST /access-requests` takes a tracking **code**, not an id: somebody who was refused never saw the row's id — what they have is the code, from a printed label or a pasted link.

Four conditions refuse, and they all return the **same** message: no such code, you are the uploader, the document is public (so ask for nothing), and you can already open it. Distinguishing them would make this endpoint a way to discover which tracking codes exist, which is the one thing a short, sequential, printed code must not leak.

> **Trap.** One open request per person per document. A refresh must not queue five, so a pending row short-circuits with its own message — the only refusal here that is allowed to be specific, because it discloses nothing the asker did not already do.

## The refusal that offers the ask

`DocumentController::unauthorizedRedirect()` is unchanged: the same redirect and the same `error` flash that `DocumentAuthorizationTest` asserts. The tracking code travels alongside in its own session key, surfaced as the `accessRequest` shared prop — **not** a third flash key, and never the title, which would disclose the subject of a document to somebody who has just been told they may not read it.

## The worklist is fetched, not shipped

`GET /access-requests` returns three tabs as JSON — `incoming` (pending, addressed to you), `granted` (what you have allowed) and `outgoing` (what you asked for) — the way the share drawer fetches its own state. This is something somebody opens occasionally; only the pending **count** rides along on every page, for the badge.
