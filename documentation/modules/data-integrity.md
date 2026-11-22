# Data integrity

Requirement 10: deleting or changing one record must not destroy another.

The claim, stated once: **no deletion anywhere in the organisation chart removes a file, a folder, a document or an audit row.**

## The three delete strategies

Which one a foreign key uses is a statement about what the child *is*.

| Strategy | Used for | Why |
| --- | --- | --- |
| `nullOnDelete` | every audit row, and every ownership/office column | The row is a record of something that happened, or a live record that outlives its office. It must survive the thing it points at. |
| `cascadeOnDelete` | a share's grantee, a document's recipient | The child is meaningless without the parent. A share whose grantee is gone grants access to nobody. |
| `restrictOnDelete` | `folders.parent_id`, `files.folder_id` | Deleting a tree is a *service* operation that soft-deletes the subtree and writes an audit row per subject. A cascade would hard-delete descendants with no record and no way back. |

> **Trap.** `employees.section_id` used to cascade, so deleting a section silently deleted everyone recorded in it. (That table is gone — merged into `users` — and `users.section_id` nulls.) `documents.user_id` used to cascade, so deleting an account — which `/profile` lets a user do to their own — deleted every document that account had uploaded, including ones other offices were still tracking.

## Soft deletes, and the two guarantees

`users`, `divisions`, `sections`, `documents`, `folders` and `files` are soft-deleted.

**Two different guarantees, and the tests assert both separately:**

| | What it protects |
| --- | --- |
| `delete()` — soft | The **link** survives. A closed account cannot sign in (the auth provider applies the model's global scopes) while everything it owns still knows who owns it. |
| `forceDelete()` — hard | The **history** survives. `nullOnDelete` fires, and the name snapshot on each audit row is what the page renders. |

Soft-deleting `users` is what makes an ownership transfer possible rather than archaeology. Before it, a hard delete preserved the document row and destroyed its link, so a document survived its uploader and immediately had **no owner** — meaning nobody could edit or end it. That was the intended outcome of a worse earlier design, not a good one.

> **Trap.** Five existing tests asserted the old hard-delete semantics (`$user->delete()` nulling `documents.user_id`). They now use `forceDelete()` where they mean "the row is gone for good", and new tests cover the soft-delete behaviour. If you find yourself reaching for `delete()` in a test about audit survival, you want `forceDelete()`.

> **Trap.** Audit rows keep `nullOnDelete` and were **not** changed to soft deletes. An audit trail has to survive a permanent erasure too.

> **Worth knowing:** `users.employee_id` is unique and the constraint ignores soft deletes, so a closed account still holds its employee ID and it cannot be reused. That is arguably correct — the ID identifies a person the office has records about — but it is a surprise if you expect to re-register someone.

## Closing an account

`ProfileController::destroy()` refuses while the account still owns anything, and names the count. Otherwise its files would point at an account nobody can sign into, editable by nobody, with nothing saying so.

The request either names an account to hand everything to, or it is refused. The account page shows the holdings up front rather than at the moment of refusal.

## Ownership transfer

`App\Services\Files\OwnershipTransferService::transfer()` moves folders, files and documents from one account to another, in one transaction.

Three things it deliberately does **not** do:

- **It does not touch the audit trail.** Every `activity_logs`, `document_logs` and `document_downloads` row keeps the actor it recorded. Ownership is a fact about *now*; an audit row is a fact about *then*, and rewriting one to match the other is falsifying the record.
- **It does not rewrite `documents.uploader_name`.** That is the snapshot of who filed the document, printed alongside a tracking code. The successor can edit and end it; they did not upload it.
- **It does not move the source's root folder.** The children are reparented under the target's root and the source root stays. Moving it would give the target two roots, which breaks the one-root rule `unique(parent_id, name)` depends on — and because MySQL treats the NULL parent of every root as distinct, nothing at the database level would catch it.

It operates `withTrashed()` throughout: a soft-deleted file in the leaver's trash is still theirs, and leaving it behind would make it unrestorable by anyone.

## Purging

Two things purge, and they are for different situations.

**By hand, out of the trash.** `FileManager::purge()` erases a trashed item — a folder's whole subtree with it, deepest first, because `parent_id` restricts — and the bytes go with the row. Only a trashed item, only its owner or an administrator, and behind a four-digit confirmation code the server issued and spends (`App\Services\Files\DeletionConfirmation`, and its own purpose, so a code issued for the trash does not satisfy this). One activity row per subject, written **before** the delete while there is still a name to snapshot.

> **A purge takes every version's bytes.** `file_versions.file_id` cascades, so purging a file drops its version rows by itself — which is the danger, not the safety: without `FileStorage::delete()` unlinking each version's `storage_path` and thumbnail, the rows vanish while their bytes stay on disk, referenced by nothing that could ever find them again. A `files` row is the only thing that knows where its versions are. Covered by `test_a_purge_removes_every_versions_bytes`.

> **A transfer moves ownership, not the record of who did what.** `OwnershipTransferService` rewrites `user_id` and `owner_name` and pointedly leaves `files.version_created_by_*` and every `file_versions` row alone — the same distinction it already draws for `documents.uploader_name`. Ownership is a fact about now; who put a version there is a fact about then.

> **A replacement no longer orphans a row**, so `files:purge --orphans` has nothing new to sweep. It is kept for what the old behaviour left behind, which is unattributable by construction and is swept rather than adopted as version 1 — see `files.md` → Versioning.

> **The database is what makes a restore safe.** `files.folder_id` and `folders.parent_id` are `restrictOnDelete`, so nothing can be purged out from under a descendant — which is why `FileManager::restore()`'s "the original folder is gone" branch is a fail-safe rather than a routine path. Purging a folder works because the service walks the subtree in the right order, not because the constraint is lax.

**On a schedule, for everything nobody came back for:**

```bash
php artisan files:purge [--days=30] [--orphans] [--dry-run]
```

Scheduled weekly in `routes/console.php` — weekly rather than nightly because the point is to stop storage growing without bound, not to erase things promptly, and a longer gap gives a mistake longer to be noticed.

> Nothing is queued in this application, so this needs a real cron entry running `php artisan schedule:run` to fire at all.

**It keeps, whatever the age:**

- anything trashed more recently than `--days`;
- a file a tracked document still points at — a tracking code is printed on paper, and removing its bytes would leave the code pointing at nothing;
- a file that is shared, or that has a live share link.

Folders are purged last and only once empty, so a folder holding a kept file stays.

> The activity row is written **before** the delete, while there is still a name to snapshot. A purge that leaves no record of itself is the one audit gap a purge must not create.

### `--orphans` closes a standing leak

Replacing a document's file repointed `documents.file_id` at the replacement and left the old `files` row and its bytes in place **forever**, with nothing pruning them. Not web-reachable since storage moved to the private disk, so wasted space rather than an exposure — but unbounded.

The orphan sweep is narrow on purpose: only files under a `Tracked Documents%` folder, with `source` of `upload` or `import`, that no `documents` row points at. **A private file with no shares is not an orphan, it is a private file.**

## Key files

| File | Role |
| --- | --- |
| `app/Services/Files/OwnershipTransferService.php` | `transfer()` and `holdings()` |
| `app/Console/Commands/PurgeTrashedFiles.php` | The purge, and the orphan sweep |
| `app/Http/Controllers/ProfileController.php` | The closure guard |
| `routes/console.php` | The weekly schedule |
| `database/migrations/*_add_soft_deletes_for_integrity.php` | |
| `tests/Feature/DataIntegrityTest.php` | |
