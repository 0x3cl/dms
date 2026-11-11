# Project Stack and Structure

## Stack

| Layer | Technology |
| --- | --- |
| Backend | PHP 8.2+ (8.4 in development), Laravel 12 |
| Server-rendered application bridge | Inertia.js 2 for Laravel (`inertiajs/inertia-laravel`) — Inertia 3 requires Laravel 13 |
| Frontend | Vue 3 with JavaScript components (no `.vue` SFCs) |
| Styling | Tailwind CSS 4, configured CSS-first via `@theme` (no `tailwind.config.js`) |
| Build tool | Vite 5 with the Laravel Vite plugin and `@tailwindcss/vite` |
| Fonts | Poppins 400/500/600/700 via `@fontsource/poppins` |
| Icons | Font Awesome Free 6.7.2 as an npm package, imported in CSS — not a CDN |
| Database | MySQL 8 via Eloquent |
| Guided tours | `driver.js` 1.3, frontend-only; the mascot's 18 poses live in `public/images/mascot/staff/` |
| Thumbnails | GD only, **images only** — Imagick is absent (the same reason QR labels are SVG), so PDFs cannot be rasterised and show an icon tile |
| File storage | Private local disk `documents` (`storage/app/private/documents`), served only through a controller at an obfuscated URL. **Not** the `public` disk |
| Roles and permissions | `spatie/laravel-permission`. Permissions are `{module}.{action}`; the registry is `App\Support\Modules` and the built-in roles are `App\Support\Roles`. See `documentation/architecture.md` → Access model |
| Authentication | Session auth on `employee_id` (Laravel Breeze scaffolding, heavily reworked); no email verification |
| QR generation | `simplesoftwareio/simple-qrcode`, rendered as **SVG** (the PNG backend needs Imagick, which these servers lack) |
| QR scanning | `html5-qrcode` as an npm dependency, wrapped by `resources/js/components/QrScanner.js` |
| Notifications | Laravel database notifications (`notifications` table), surfaced by `resources/js/components/NotificationBell.js` |
| Queue | Database driver; nothing is queued today |
| Tests | Pest 3 on PHPUnit, against the MySQL database `dms_test` (see `phpunit.xml`) |
| Code formatting | Laravel Pint |
| CI | GitHub Actions `tests.yml`: Pint, frontend build and Pest against a MySQL service container, on push/PR to `main` and `develop` |

## Directory map

| Path | Purpose |
| --- | --- |
| `app/Http/Controllers` | `LandingController` (public page), `DashboardController`, `DocumentController` (the core of the system), `DocumentLogController`, `DivisionController`, `SectionController`, `EmployeeController`, `ProfileController`, `NotificationController`. |
| `app/Http/Controllers/Auth` | Login/logout, registration, password reset, password change and password confirmation. There are no email-verification controllers — see `routes/auth.php`. |
| `app/Http/Middleware` | `HandleInertiaRequests` — the only middleware this app adds, and the one place shared props are defined. |
| `app/Http/Requests` | `Auth\LoginRequest` (employee-ID credentials **and** the login throttling), `ProfileUpdateRequest`. |
| `app/Models` | `User`, `Division`, `Section`, `Employee` (a standalone directory, not accounts), `Document`, `DocumentLog`, `DocumentDownload`. |
| `app/Notifications` | `PrivateDocumentShared` — the in-app notice a recipient gets when a private document is shared with them. |
| `database/migrations` | Authoritative `create` migrations — the schema stated once rather than as an incremental history: organization (divisions, sections), users/sessions/password resets, cache, jobs, notifications, document types, document series, documents + recipients, document logs, document downloads. A deployment carrying data must dump and restore rather than migrate across. |
| `resources/css/app.css` | The entire design system: brand tokens, form base classes, table rules, and the dark theme. |
| `resources/js/app.js` | Inertia bootstrap. Wraps every page in the navbar/footer shell and installs the global validation-error scroll hook. |
| `resources/js/components` | Reusable Vue UI: `AdminShell` (the signed-in layout), `AppNavbar`/`AppFooter`, `Card`/`Button`/`Badge`/`VisibilityBadge`, `IndexControls`/`Pagination` (shared list controls), `SearchableSelect`, `ConfirmActionModal`/`FeedbackModal`, the file-manager set (`FileGrid`/`FileTable`/`FilePreviewModal`/`FolderPickerModal`/`FileFilterModal`/`ConfirmDeleteModal`/`ShareDrawer`), `UserAvatar`/`AvatarField`, `NotificationBell`, `ThemeToggle`, `QrScanner`, `CountUp`, `NavigationSkeleton`. |
| `resources/js/pages` | `index.js` (public landing) plus one directory per module: `auth/`, `dashboard/`, `documents/`, `divisions/`, `sections/`, `users/`, `account/`. Card, panel and modal titles are `uppercase tracking-wide`. |
| `resources/js/composables` | `useConfirmSubmit` (every form's confirm-then-submit gate), `useUrlTab` (tab state in the query string). |
| `resources/js/utils` | `format-date.js` — `formatDay`/`formatTimestamp` for serialized Carbon values. |
| `resources/js/organization.js` | The office's name, contact details and logo, used by the navbar and footer. One file instead of PROCMIS's editable Pages module. |
| `resources/views/app.blade.php` | The only Blade file left: the Inertia root template, including the pre-paint theme script. |
| `routes/web.php` | The public landing and tracking routes, then the authenticated group. |
| `routes/auth.php` | Authentication routes, registered by `bootstrap/app.php`'s `then:` callback. |
| `documentation` | This directory. See `CLAUDE.md` → Documentation map. |

## Conventions

Admin URLs live at the site root — there is no `/admin` prefix. The one `admin.`-prefixed route name (`admin.download.logs`) used to be a label rather than a gate; it is gated on `download-logs.read` now. Page components are resolved by path: `Inertia::render('documents/index')` loads `resources/js/pages/documents/index.js`. An index page is `index.js`, a create-and-edit form is `form.js`, a detail page is `show.js`.

See `AGENTS.md` for the working rules and the invariants, `documentation/design-system.md` for the visual contract, and `CLAUDE.md` for the architectural reasoning.
