# Directory

The office structure — divisions and sections — and the accounts of the people in it.

## Key files

| File | Role |
| --- | --- |
| `app/Http/Controllers/UserController.php` | Account CRUD, role assignment, password reset, closing |
| `app/Http/Controllers/DivisionController.php` | Division CRUD |
| `app/Http/Controllers/SectionController.php` | Section CRUD, plus the by-division JSON endpoint |
| `app/Models/{Division,Section}.php` | Each with a `matching()` search scope |
| `app/Models/User.php` | `matching()`, `fullName()`, and the `name` sync hook |
| `app/Services/Files/OwnershipTransferService.php` | What has to happen before an account can be closed |
| `resources/js/pages/{users,divisions,sections}/{index,form}.js` | |
| `tests/Feature/UserManagementTest.php` | The Users module |
| `tests/Feature/DirectoryTest.php` | The office structure's own rules |

## Shape

`divisions` ──< `sections` ──< `users`.

A division holds sections; a section holds accounts. Deleting a division or section with anything attached is refused with a flash error rather than cascading — `sections` cascades on delete and `users` falls back to null, so a silent delete would take records with it.

### There is no employee directory

There used to be two tables. `employees` listed who worked where; `users` listed who could sign in. Both carried an employee ID, a name and a section, and `employees.user_id` existed only to record that an entry and an account were the same human — a link that granted nothing and that somebody had to remember to make. So an office maintained every person twice and the two copies disagreed the moment one was edited.

They are one table now. `employees` was merged into `users` and dropped by `database/migrations/2026_11_10_000017_merge_employees_into_users.php`, which splits each existing `name`, lets a linked entry's own halves win over that split, turns every unlinked entry into a `Staff` account with a random password, and moves `employees.*` to `users.*` on the permission side.

> **A deployed install crosses that migration once and `down()` is empty.** The reverse of a merge is a split, and the information needed to perform one — which rows were directory entries and which were always sign-ins — is exactly what the merge discards.

### Codes

`divisions` and `sections` each carry a `code` as well as a `name`. The code is the short, stable handle: it survives a rename, which is what makes it safe to print on a report or match against another system. Both forms uppercase it on save.

A section's `code` **and** its `name` are unique *within its division*, not globally — two divisions may each run a "Records" section, but one may not run two.

> **Trap.** `sections` previously had no unique constraint at all, so one division could hold the same section twice, quietly splitting that office's staff and its audit rows between two rows. Covered by `tests/Feature/DirectoryTest.php`.

## The Users module

Two tabs over one account, held in the query string by `useUrlTab`:

| `?tab=` | Holds |
| --- | --- |
| `personal` | Employee ID, email, first and last name, division, section, role |
| `security` | The password |

They post to different endpoints — `PUT /users/{user}` and `PUT /users/{user}/password` — because correcting a surname and setting somebody's password are different acts of trust and fail differently: a rejected password must not discard a corrected name. Creating is the exception, since there is no account yet to set a password on separately, so both tabs feed the one create request.

> **This tab set passes `{ always: true }`**, so `?tab=personal` is in the address on arrival rather than only after a click. Every other tab set in the app keeps its default out of the URL; see the docblock on `resources/js/composables/useUrlTab.js` for why that is a flag and not the new rule.

### Three refusals, all enforced on the server

The form hides what it would refuse via a `can` prop, but the refusal itself is in `UserController` — a hidden button is not a gate.

- **Nobody changes their own role.** An administrator who demotes themselves locks the install out of the only screen that could promote anyone back.
- **The last `SUPERADMIN` is neither demoted nor closed.** Same reason, reached a different way.
- **Closing your own account happens on `/account`**, where it asks for your password. This screen has no business collecting it.

### Closing an account is not a plain delete

It goes through `OwnershipTransferService`, exactly as `ProfileController::destroy()` does. An account that still owns storage cannot simply go — its files would point at a sign-in nobody can use, editable by nobody, with nothing saying so — so the request either names someone to hand everything to or it is refused with a count of what is in the way.

The delete is **soft**: the account cannot sign in, and every audit row and remaining link stays intact.

> The actor recorded on the transfer is the administrator doing it, not the account being closed. That is the one difference from the self-service flow, and it is the difference the activity trail should show.

> **A transfer does not touch the audit trail.** `document_logs`, `document_downloads` and `activity_logs` keep the actor they recorded, and `documents.uploader_name` is not rewritten — the new owner can edit and end the document, but they did not file it. Ownership is a fact about now; an audit row is a fact about then.

## Seeded structure

`DivisionSeeder` and `SectionSeeder` seed the four DOST-PTRI divisions and their ten sections — the same structure PROCMIS seeds, so a person sits in the same office in both systems and `employees.user_id` can be matched up between them.

| Division | Sections |
| --- | --- |
| `OD` Office of the Director | `PICTS`, `TIPS` |
| `RDD` Research and Development Division | `NFUS`, `CDABPUS` |
| `TSD` Technical Services Division | `PCTS`, `MCPS` |
| `FAD` Finance and Administrative Division | `AS`, `BTS`, `HRRMS`, `PPGSS` |

> **Not sample data.** Registration asks for a division and a section, so an unseeded install cannot accept a sign-up at all — the same reason `DocumentTypeSeeder` is required. Both are called from `DatabaseSeeder`, sections after divisions.

Both use `updateOrCreate` rather than `firstOrCreate`, keyed on the code: the code is the stable handle that survives a rename, so re-running corrects a name or description without creating a duplicate or disturbing the sections and staff attached to the original.

> **Trap, and the one real difference from the PROCMIS seeder this is modelled on.** PROCMIS matches a section on `['code' => $code]` alone, because its section codes are globally unique. DocTrack's are unique **per division**. Matching on the code alone would mean that the day somebody adds a second section sharing a code in another division, re-running the seeder silently **moves** the existing one across divisions instead of leaving it alone. `SectionSeeder` matches on `['division_id', 'code']` — the same pair the unique index enforces.

> `SectionSeeder` resolves its parent with `firstOrFail`, not `firstOrCreate`: a missing division means `DivisionSeeder` has not run, and inventing a placeholder would hide that rather than report it.

What is absent compared to PROCMIS is its `divisions.skipped_statuses` column, which maps a division onto its purchase-request clearance flow. DocTrack has no equivalent workflow.

## Traps

> **`name` is derived — never write it.** `users.first_name` and `users.last_name` are the input; `name` is assembled from them by the `saving` hook in `User::booted()`. A controller, seeder, importer or factory that sets `name` directly is writing a value the next save overwrites. It is a stored column rather than an accessor because audit snapshots and `files.owner_name` copy it and the download register searches it in SQL, where no accessor can be called. `ProfileUpdateRequest` and `UserController` both refuse `name` as input for this reason.

> **The module is SUPERADMIN's, and that narrowed Staff.** Staff used to hold `employees.read`, a people lookup. The screen that replaced it hands out roles and sets other people's passwords, so `users.read` is deliberately **not** in `Roles::STAFF_PERMISSIONS`. Staff keep `divisions.read` and `sections.read` — the office structure without the people.

> **The administrator's password reset does not ask for the current password**, unlike `Auth\PasswordController`. An administrator resetting an account does not know what it was; that is the reason the endpoint exists. The gate is `users.write` on the route. Its errors go in the `updatePassword` bag so the Security tab shows them without touching the form on the other tab.

> **Deleting a section does not delete the people in it.** `users.section_id` is `nullOnDelete`, and an account whose section is gone simply shows no section. Covered by `tests/Feature/DirectoryTest.php`.

> **A user's `email` is nullable.** Sign-in is by employee ID, so an account without an address is a normal account that simply cannot reset its own password and needs an administrator. The form's email label must not carry `required-label`.

> A section name is not unique across divisions, so anywhere a section is offered for selection the label carries its division (`UserController::sectionOptions()`).

> **The role picker reads the `roles` table, not a constant.** A role created on the roles screen is assignable here the moment it exists, which is what makes that screen mean anything. Staff is the default for a new account.

## The by-division endpoint

`GET /sections/by-division/{division}` returns a division's sections as JSON (`id`, `code`, `name`, `division_id`). It is public and unauthenticated.

Neither the registration page nor the Users form needs it — both receive divisions with their sections nested, which is what replaced the jQuery-from-a-CDN cascade — but the route stays for any caller that wants to narrow a section picker without a page load.

## The accounts table shows four columns

Employee ID, name (with the face and the office under it), role, actions.

Email, division and section used to be columns of their own. They were on every
row and read on almost none of them, and together they pushed the table past the
viewport — so the Actions column, the only part of a row anybody clicks, sat
behind a horizontal scroll. **View** opens a dialog with the whole account:
both halves of the name, the email, the office, the role and the created and
updated timestamps.

> The dialog costs no request. The listing already carries every field, so it is
> built from the row that is on the page.

> An account with no email is shown as "cannot self-serve a password reset"
> rather than as a blank, because that is the consequence somebody looking at
> this screen needs — `users.email` exists only so the password broker has
> something to look an account up by.

## StaffSeeder: ten colleagues, on purpose fixed

```bash
php artisan db:seed --class=StaffSeeder
```

Ten `Staff` accounts across all four divisions, two of them sharing a section. Document ownership, sharing, recipients, the file manager's access badges and every per-record rule need *other people* to be meaningful: with a single administrator, "shared with me" has nobody to share from and every access check passes for the wrong reason.

- **Fixed data, not `fake()`.** Written-down employee IDs (`1001`–`1010`), names and sections, so the same seeder produces the same ten people on every machine. "Log in as 1004 and check the Records folder" means something; "log in as whoever ended up fourth" does not.
- **Idempotent on `employee_id`** — the sign-in credential, and the one column that identifies a person here. A re-run corrects ten accounts rather than creating ten more.
- **The password is not rewritten on a re-run.** Somebody testing may have changed it, and a seeder that silently resets credentials is one nobody can run twice.
- **It calls `DivisionSeeder` and `SectionSeeder` itself**, because a section is what makes the data realistic — without them every seeded account lands with no office, which is the state these ten people exist to avoid.
- **Addresses are `@ptri.test.invalid`.** `.invalid` is reserved by RFC 2606: a seeded install must not be able to send mail to a stranger.

> **Trap.** It is deliberately **not** called from `DatabaseSeeder`. The document types, roles, divisions and sections are load-bearing — nothing can be uploaded or reached without them — and ten accounts that can sign in are not. They have no business appearing on a production install because somebody ran `db:seed`.

> **Trap.** `avatar_seed` is left null on all ten, which means "generate a face from the name". Seeding a chosen avatar would make these the only accounts in the system carrying a seed, and the default every real account uses would go untested.
