# DocTrack

A document management system for **DOST-PTRI**. Staff upload an office document; DocTrack stamps it with a tracking code and a printable QR label, and every view, download, acknowledgement and hand-off from then on is written to an audit trail anyone holding the document can follow.

A document is either **public** — readable by anyone with its tracking code — or **private** to a named list of recipients, who are notified in the app when it is shared with them.

## Stack

Laravel 12 · Inertia 2 · Vue 3 · Tailwind 4 · MySQL 8 · Pest

The frontend is Vue 3 written as **plain `.js` components** with inline templates — there are no `.vue` files. See [`documentation/project-stack.md`](documentation/project-stack.md) for the full table and [`documentation/design-system.md`](documentation/design-system.md) for the visual contract.

## Local setup

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate
# set DB_DATABASE / DB_USERNAME / DB_PASSWORD in .env

php artisan migrate
php artisan db:seed

# Required: uploaded documents and QR labels 404 without it.
php artisan storage:link
```

Then run the app:

```bash
composer run dev     # serve + queue + vite together
# or separately:
php artisan serve
npm run dev
```

The seeded account is employee ID **`EMP-0001`**, password **`password`**.

## Tests

Tests run against their own MySQL database, not in-memory SQLite — the deployment target ships `pdo_mysql` but not `pdo_sqlite`. Create it once:

```sql
CREATE DATABASE dms_test;
```

```bash
php artisan test --compact                      # everything
php artisan test --compact --filter=testName    # one test
vendor/bin/pint                                 # format PHP
npm run build                                   # a Vue inline template only fails at build time
```

CI (`.github/workflows/tests.yml`) runs Pint, the frontend build and the test suite against a MySQL service container on every push and pull request to `main` and `develop`.

## A note on access

**This application has no roles or permissions.** Every authenticated user can reach every module, including the download-log report and the directory CRUD screens, and registration is self-service with no approval step.

The per-document rules are real and enforced — a private document is readable only by its uploader and the recipients named at upload, and only the uploader can edit, delete or end a document. But there is no module-level access model. This is a known, deliberate state rather than an oversight; it is written up in [`documentation/architecture.md`](documentation/architecture.md) → Open access, and should be read before deploying anywhere the account list is not trusted.

## Documentation

| File | Owns |
| --- | --- |
| [`AGENTS.md`](AGENTS.md) | The fast path for anyone (or anything) changing this code: source map, working rules, invariants |
| [`CLAUDE.md`](CLAUDE.md) | Architectural "why" for cross-module work |
| [`documentation/project-stack.md`](documentation/project-stack.md) | Stack and directory map |
| [`documentation/design-system.md`](documentation/design-system.md) | Tokens, component inventory, page recipes |
| [`documentation/architecture.md`](documentation/architecture.md) | Request lifecycle, shared props, data model, routes |
| [`documentation/migration-notes.md`](documentation/migration-notes.md) | The Blade-to-Inertia conversion record |
| [`documentation/modules/`](documentation/modules/README.md) | One doc per module |
