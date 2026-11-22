# Activity trail

Everything that happens to a file or folder: uploads, previews, downloads, renames, moves, copies, deletions, extractions and shares.

Behind `activity-trails.read`, because it reaches across **every** account's storage — unlike a document's own trail, which is scoped to a document the viewer can already read.

## Three audit tables, and why

The reason they are separate is the **audience**, not the shape.

| Table | What it answers | Who sees it |
| --- | --- | --- |
| `document_logs` | a document's travel history | **everyone who can read that document** |
| `document_downloads` | "a copy left the server", with the IP it went to | `download-logs.read` |
| `activity_logs` | everything that happens to a file or folder | `activity-trails.read` |

> **This is the invariant the third table exists for.** `document_logs` is rendered to everyone who can read the document. A `copied`, `extracted` or `link_created` row appearing there would turn a document's travel history into a disclosure about the file manager — who took a copy into their private storage, what else is in the archive. Covered by `test_file_manager_events_do_not_leak_into_a_documents_trail`.

A sibling `file_logs` was the alternative and would have been a third copy of the same snapshot factory. One generic table with a morph subject is one.

## The single writer

**Write rows only through `ActivityLog::record()`** — the same invariant `DocumentLog::record()` and `DocumentDownload::register()` hold. It is the one place the shape is built, which is what makes every writer produce the same row. A second construction site is how an audit table ends up with rows missing the actor's office, and you find out when you need them.

Every row **snapshots its actor** — name, employee ID, division and section as they were — beside `nullOnDelete` keys, and the page renders the snapshot. Renaming a division must not rewrite where something happened two years ago, and deleting one must not blank it.

The **subject** is snapshotted the same way (`subject_label`, `subject_public_id`) and its morph carries **no foreign key**, because a row has to stay readable after the file it refers to has been purged.

> A row with no actor records `actor_name` as `System`, not null. A share link is opened by someone who is by construction not signed in, and a blank name reads as a bug rather than as a fact.

## The bridge

`App\Services\Files\FileEventRecorder` is the one place the activity log and a document's trail are written together, so the rule about which events cross over exists once.

Exactly two actions cross over, because they are the only two that already mean the same thing in a document's history:

| Activity action | Document action |
| --- | --- |
| `previewed` | `viewed` |
| `downloaded` | `downloaded` |

A download also writes `document_downloads`, because that register is what `/admin/download-logs` reads. Its `document_id` is not nullable, so an **untracked** file's download has no row there and does not need one.

## The preview debounce

`recordOnce()` collapses a repeat of the same action, by the same person, on the same subject, within five minutes.

> **Trap.** An embedded preview iframe re-requests the same file on a resize, a re-mount or a reload. One row per render is a *forged history*: it says the person opened the document nine times when they opened it once. The debounce is per person, so two people previewing is two events.

**A download is never debounced.** Each one is a separate copy leaving the server, which is the whole question the register answers.

> **Trap.** The disk-existence check runs **before** the record, so a file that has gone missing leaves no record of a release that never happened. The same rule `accessFile()` already followed. Covered by `test_a_missing_file_records_nothing`.

> **Trap.** A refused request records nothing. Covered by `test_a_refused_request_records_nothing`.

## What is deliberately not recorded

**A share link's token never appears in a row.** An audit row carrying a live credential makes the audit report a way to *obtain* that credential — a strictly worse disclosure than the one it was written to detect. The ability, the expiry and the view cap are recorded; the token is not. Covered by `test_a_link_is_recorded_without_its_token`.

`user_agent` is truncated to its column width, so a crafted header cannot fail the insert and thereby prevent the row being written at all.

## `context`

A JSON column holding whatever the action needs to be understood later: the folder a file moved out of, how many entries an archive held, who a share was granted to, the ability it carried.

A share records the grantee's **label** as well as its id, because `item_shares` grantee keys cascade — so after a division is deleted, this row is the only remaining record of who it was granted to.

## The report

`ActivityTrailController` + `resources/js/pages/activity-trails/index.js`. Filters: search, action, and a date range.

Because the rows carry their own snapshots, the report needs **no eager loading at all** and the search reads one table rather than running a `whereHas` against `users` and another against `files` on every keystroke — and it keeps answering after either is deleted. Covered by `test_the_report_renders_after_its_actors_are_deleted`.

## Versions

Two actions: `ActivityAction::VersionReplaced` ("Replaced the file") and `VersionReverted` ("Restored an earlier version"). Neither is in `releases()` — no copy left the server — and both reach the report's filter automatically, because `values()` and `options()` derive from `cases()`.

`activity_logs.subject_version` records which version of the subject a row was about, derived from the subject when it is not passed, so every existing caller stayed correct. A folder has no version and keeps null, as does every row written before the column existed.

> **Trap, and a real bug avoided.** `recordOnce()`'s debounce key **includes the version**. Without it, previewing version 1 and then version 2 inside the five-minute window would record only the first — a forged history in the opposite direction from the one the debounce exists to prevent. Covered by `test_previewing_two_versions_inside_the_debounce_window_records_both`.

> **No version filter on this report, deliberately.** `/activity-trails` is cross-subject, and "version 2" without a subject would match version 2 of every file in the agency. The version is rendered on the row; filtering by it belongs on a *document's* trail, where one subject is already named.

Only a **tracked document's** replacement crosses into `document_logs`, as `DocumentAction::FileReplaced`, and it is written directly by `FileManager::replace()` rather than through `DOCUMENT_EQUIVALENTS` — which stays at two entries. See `documents.md` for the audience argument.

## Key files

| File | Role |
| --- | --- |
| `app/Models/ActivityLog.php` | The model, and `record()` — **the only writer** |
| `app/Enums/ActivityAction.php` | The vocabulary; stored value is a slug, sentence is `label()` |
| `app/Services/Files/FileEventRecorder.php` | The bridge to `document_logs`, and the debounce |
| `app/Http/Controllers/ActivityTrailController.php` | The report |
| `database/migrations/*_grant_activity_trails_to_superadmin_role.php` | Edit 4 of 4 for a new module |
| `tests/Feature/ActivityTrailTest.php` | |

## What the file manager now records

Every structural operation writes its own row, and the context carries the **previous and the new** value wherever there is one:

| Action | Context |
| --- | --- |
| `moved` | `from`, `to`, and `from_id` / `to_id` so the row still identifies the folders after either is renamed |
| `copied` | `from_owner`, `from`, `to`; for a folder, `folders`, `files` and `skipped` counts |
| `deleted` | `via: 'folder'` on each subject swept up by a folder delete |
| `restored` | `to`, `reattached` (the original folder could not be rebuilt), `renamed_to` (the name was taken) |
| `purged` | `size`, `owner`, `trashed_at`; one row per subject, written **before** the delete while there is still a name to snapshot |
| `visibility_changed` | `from`, `to` |
| `shared` / `link_created` | the grantee or the ability and expiry — **never the token** |

### A failure is a row too

`FileManager::attempt()` wraps the transaction behind move, copy, delete, restore and purge. On failure it writes a row carrying `outcome: 'failed'` and the exception's class, then re-throws untouched.

> **Trap.** That row is written **outside** the transaction, after the rollback. Inside, it would be rolled back with the work it was recording — which is the trap that makes "log the error in the same transaction" useless exactly when it matters. Covered by `test_a_failed_operation_leaves_a_row_saying_so_and_still_fails`.

> **Trap.** Authorization refusals are deliberately *not* recorded here. They resolve before the transaction opens, and recording them would let anyone holding an identifier fill the trail with rows about items they cannot touch.

> **Trap.** The class, not the message. A trail is read by people, and an exception message can carry a path or a query; the application log has the rest.
