# Roles and permissions

The access model. A user holds exactly one role; a role holds permissions; every module route is gated on one.

Built on `spatie/laravel-permission`, which is also what PROCMIS uses — so the two systems read alike and the registry pattern ports between them.

## Key files

| File | Role |
| --- | --- |
| `app/Support/Modules.php` | The registry: modules, actions, groups, implications. **The single source of truth.** |
| `app/Support/Roles.php` | The two roles the *code* depends on, and `Staff`'s grant |
| `app/Providers/AppServiceProvider.php` | The `Gate::before` superadmin bypass |
| `bootstrap/app.php` | The `permission:` / `role:` middleware aliases, and the 403 rendering |
| `app/Http/Controllers/RoleController.php` | Roles CRUD; `save()` serves both store and update |
| `app/Http/Middleware/HandleInertiaRequests.php` | Shares `auth.role` and `auth.permissions` |
| `resources/js/components/AdminShell.js` | `MODULES` (each entry names its permission) and the `visibleModules` filter |
| `resources/js/pages/roles/{index,form}.js` | The list and the permission matrix |
| `resources/js/pages/errors/403.js` | What a refused page visit renders |
| `database/seeders/RolePermissionSeeder.php` | Creates every permission and both roles |
| `database/migrations/*_assign_roles_to_existing_accounts.php` | Gives already-deployed accounts a role |
| `tests/Feature/RolePermissionTest.php` | |

## Naming

`{module}.{action}`, lowercase kebab, dot-separated — `documents.download`, `divisions.write`, `download-logs.read`, `users.write`.

`Modules::ACTIONS` is the vocabulary (`read, create, write, edit, delete, share, download, manage`) and `MODULE_ACTIONS` says which ones each module actually offers.

> **Trap.** The registry holds only what actually gates something today. A permission that gates nothing is not harmless: it is a checkbox on the roles screen that grants the administrator nothing, and a name a developer will later gate a route on believing it was already assignable. `scan.share` does not exist.

## Adding a module

Four edits, and all four belong in the same change:

1. `MODULES` and `MODULE_ACTIONS` in `app/Support/Modules.php`
2. the sidebar entry in `resources/js/components/AdminShell.js`
3. the route group in `routes/web.php`
4. a migration granting the new permissions to the existing `SUPERADMIN` role

> **Trap.** (4) is not optional and a seeder will not do it. `database/seeders` only runs on a fresh install, so without the migration a deployed administrator silently cannot reach the module they just shipped. Copy the pattern from `2026_11_10_000007_assign_roles_to_existing_accounts.php`, and call `app(PermissionRegistrar::class)->forgetCachedPermissions()` at the end — the registrar caches the whole map for the life of the process.

> **Trap.** Skipping (2) or (3) is worse than doing neither. A hidden sidebar entry over an open route reads as protection that is not there.

## Enforcement, in four layers

1. **Route middleware** — `permission:divisions.write`. The outer gate.
2. **`Gate::before`** — `SUPERADMIN` short-circuits to `true`. It returns **`null`** on a miss, never `false`: `false` is a verdict and would stop every other gate, policy and permission check from being consulted.
3. **Per-record checks** — `DocumentController::isAuthorized()` / `::owns()`. Unchanged by any of this.
4. **The frontend** — the sidebar filter, and server-computed `can` props for buttons.

### A module permission is not a per-record permission

`documents.delete` says the capability exists for this user. `owns()` still decides which document it applies to.

This is why `Staff` holds `documents.write` and `documents.delete`. Withholding them would not protect anyone else's upload — it would stop a user revising or withdrawing the document they uploaded themselves.

### `auth.permissions` is for navigation, not authorization

It drives the sidebar and nothing else decides access by reading it. A button's visibility comes from a server-computed `can` prop on the page (see `DocumentController::showPayload()`); the real gate is the route middleware. It is shared eagerly rather than lazily because `AdminShell` renders on every authenticated page.

## The roles screen

`RoleController::save()` serves both store and update, because they are the same operation over a nullable role — the same reason one Vue component serves both forms.

Everything the screen posts is **re-derived against the registry**, never trusted:

- a module the registry does not know about is dropped;
- an action the module does not offer is dropped;
- a read-only module is narrowed to `read` whatever arrived;
- `write` expands to the permissions it implies (`Modules::MODULE_IMPLIED_PERMISSIONS`);
- anything other than `read` also grants `read`.

So a crafted request cannot grant a permission the matrix never showed. Covered by `test_a_read_only_module_cannot_be_granted_a_write` and `test_an_unknown_module_in_the_payload_is_ignored`.

> **Write implies read, in both directions.** A role that may change a record it cannot open is not a coherent grant. The form enforces it on the checkboxes too, so the UI cannot show a state the server would refuse.

> **Trap.** `SUPERADMIN` cannot be renamed or deleted, and the server refuses rather than relying on the hidden buttons. `Gate::before` short-circuits on the *name*, so renaming it would silently strip the bypass from everyone holding it.

A role still held by an account is not deleted either — those accounts would be left holding nothing.

## The two built-in roles

**`SUPERADMIN`** holds every permission the registry can generate, and is short-circuited by `Gate::before` regardless.

**`Staff`** is the least-privilege floor assigned to every new and self-registered account: see the office structure, and create, revise, withdraw, download and share documents. **Not** the download register — it carries the IP address every copy went to — and no directory write.

`users.read` is deliberately absent, and it is the one grant that *narrowed* when the staff directory became the Users module. The old `employees.read` opened a people lookup. The screen that replaced it is where roles are handed out and other people's passwords are set, so reaching it is reaching the access model itself; the first grant does not imply the second. Staff keep `divisions.read` and `sections.read` — the office structure without the people.

Registration is self-service with no approval step, so whatever `Staff` carries is what anyone who can reach the sign-up page gets. Treat `Roles::STAFF_PERMISSIONS` as a security boundary, not a convenience list.

## Two modules deliberately do not have the permission you would expect

**`access-requests` has `read` and `create`, and no decide.** Whether somebody may *answer* a request is a per-record question — it is addressed to the document's uploader — so a module checkbox for it would create requests nobody holding it could answer: a dead letter rather than a protection. `PATCH /access-requests/{accessRequest}` therefore carries no `permission:` middleware at all, and the controller checks the document's **live** `user_id` (or SUPERADMIN). Same distinction as `documents.delete` versus `owns()`. See `documentation/modules/access-requests.md`.

**There is no document-type-*groups* module.** A group and a type are one piece of office vocabulary, so `document-types.{read,write,delete}` gate both screens. Two sets of permission names for one grant is how a role ends up holding half of it.

## Routes

The four CRUD modules are declared action by action rather than through `Route::resource()`: a resource cannot carry per-action middleware, and reading a division is not the same grant as deleting one.

> **Trap.** Every route *name* is exactly the one `Route::resource()` generated, so no `to_route()` call or `<Link>` had to change. Diff `php artisan route:list --json` before and after if you touch that block — a silently renamed route breaks the frontend without failing a test.

> **Trap.** Ordering is load-bearing. Every literal `documents/<word>` segment (`create`, `all`) must be declared **before** `documents/{document}`, or it is captured as a document id. `Route::resource()` handled `create` for you; splitting it means you handle it.

## Testing

`UserFactory` assigns `Staff` on creation, and `->superadmin()` is the state for a test that needs more. That one line is what kept the pre-RBAC suite meaningful: those tests act as "some authenticated user", and after gating that had to mean something concrete.

`Tests\TestCase` seeds `TestSupportSeeder` (document types **and** roles) and calls `forgetCachedPermissions()` in `setUp()` — without it, the second test in a file resolves roles against the first test's rolled-back rows.

## Three permissions have been retired

`files-shared.read`, `files-browse.read` and `files.write` are gone — the rows are deleted by `database/migrations/2026_11_11_000001_retire_merged_module_permissions.php`, which also revokes them from every role holding them.

| Retired | Why |
| --- | --- |
| `files-shared.read` | Shared with Me is a filter on My Files now, and that listing is `files.read` like the rest of it |
| `files-browse.read` | Browse Storage is the reach across other people's storage, which is exactly what `files.manage` has always meant. Two names for one grant is how a role ends up holding half of it |
| `files.write` | It gated no route and never had. `files.edit` is the coarse toggle the roles screen offers |

Two gained a gate instead of being retired, which is the other way to resolve the same problem:

- **`documents.share`** now gates naming recipients on a private upload. It previously gated nothing at all — a checkbox with no effect — while the act it is named for went unchecked.
- **`documents-all.read`** gates the All tab on the Documents page, resolved in the controller rather than by middleware. `ModuleRegistryTest` names both in `$resolvedInCode`.

> **Trap.** `ModuleRegistryTest::test_every_registry_permission_gates_something` is the thing that keeps this honest in both directions: a permission no route gates and no code reads fails the suite, and a route gating a permission the registry cannot generate fails it too. If you add an entry to `App\Support\Modules`, that test tells you which of the four edits you forgot.
