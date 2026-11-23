# Project Stack and Structure

## Stack

| Layer | Technology |
| --- | --- |
| Backend | PHP 8.3+ (8.4 in development), Laravel 12 |
| Server-rendered application bridge | Inertia.js 2 for Laravel (`inertiajs/inertia-laravel`) — Inertia 3 requires Laravel 13 |
| Frontend | Vue 3 with JavaScript components (no `.vue` SFCs) |
| Styling | Tailwind CSS 4, configured CSS-first via `@theme` (no `tailwind.config.js`) |
| Build tool | Vite 5 with the Laravel Vite plugin and `@tailwindcss/vite` |
| Fonts | Poppins 400/500/600/700 via `@fontsource/poppins` |
| Icons | Font Awesome Free 6.7.2 as an npm package, imported in CSS — not a CDN |
| Tab icons | Generated from `public/ptri.png` by `php artisan brand:icons` (GD): a real multi-size `favicon.ico` plus `icon-{16,32,48,180,192,512}.png` |
| Database | MySQL 8 via Eloquent |
| Guided tours | `driver.js` 1.11, frontend-only; the mascot's 18 poses live in `public/images/mascot/staff/` |
| Thumbnails | GD only, **images only** — Imagick is absent, so PDFs cannot be rasterised and show an icon tile |
| File storage | Private local disk `documents` (`storage/app/private/documents`), served only through a controller at an obfuscated URL. **Not** the `public` disk |
| Office previews | `docx-preview` (Word) and SheetJS `xlsx` (Excel/CSV) render OOXML **in the browser** from our own stream, so nothing leaves the building. PowerPoint and legacy OLE2 `.doc`/`.xls` fall back to Microsoft's hosted viewer behind a short-lived signed URL; PDFs, images, video and audio are rendered by the browser from our stream |
| Realtime | Laravel Reverb (`laravel/reverb`) with `laravel-echo` + `pusher-js`. Both audit trails and the notification bell listen; it **fails soft** when `VITE_REVERB_APP_KEY` is absent. See `documentation/modules/realtime.md` |
| Roles and permissions | `spatie/laravel-permission`. Permissions are `{module}.{action}`; the registry is `App\Support\Modules` and the built-in roles are `App\Support\Roles`. See `documentation/architecture.md` → Access model |
| Authentication | Session auth on `employee_id` (Laravel Breeze scaffolding, heavily reworked); no email verification |
| QR generation | `endroid/qr-code` via `App\Services\Documents\QrLabelService` — **PNG through GD** with the DOST-PTRI seal embedded, plus SVG for inline rendering (`simple-qrcode` was dropped: both its PNG and logo paths need Imagick, which these servers lack) |
| QR scanning | `html5-qrcode` as an npm dependency, wrapped by `resources/js/components/QrScanner.js` |
| Notifications | Laravel database notifications (`notifications` table), surfaced by `resources/js/components/NotificationBell.js` and pushed over Reverb |
| Queue | Database driver. Nothing is queued today — the broadcast events are `ShouldBroadcastNow` on purpose, so an installation with no supervised worker still updates |
| Archives | `ext-zip`, through `App\Services\Files\ArchiveService` — entries are streamed out by index, never `extractTo()` |
| Tests | Pest 3 on PHPUnit, against the MySQL database `dms_test` (see `phpunit.xml`) |
| Code formatting | Laravel Pint |
| CI | GitHub Actions `tests.yml`: Pint, frontend build and Pest against a MySQL service container, on push/PR to `main` and `develop` |

## Directory map

| Path | Purpose |
| --- | --- |
| `app/Http/Controllers` | `LandingController`, `DashboardController`, `DocumentController` (the core), `DocumentLogController`, `AccessRequestController`, `DocumentTypeController` + `DocumentTypeGroupController`, `FileManagerController` / `FileActionController` / `FileStreamController`, `ShareController` + `ShareLinkController`, `UploadSettingController`, `ActivityTrailController`, `UserController`, `DivisionController`, `SectionController`, `RoleController`, `ProfileController`, `NotificationController`. |
| `app/Http/Controllers/Auth` | Login/logout, registration, password reset, password change and password confirmation. There are no email-verification controllers — see `routes/auth.php`. |
| `app/Http/Middleware` | `HandleInertiaRequests` — the only middleware this app adds, and the one place shared props are defined. |
| `app/Http/Requests` | `Auth\LoginRequest` (employee-ID credentials **and** the login throttling), `ProfileUpdateRequest` (which refuses `name`, because it is derived). |
| `app/Models` | `User`, `Division`, `Section`, `Document`, `DocumentType`, `DocumentTypeGroup`, `DocumentSeries`, `DocumentLog`, `DocumentDownload`, `AccessRequest`, `File`, `Folder`, `ItemShare`, `ShareLink`, `ActivityLog`, `UploadSetting`. There is no `Employee` — the directory and accounts were merged into `users`. |
| `app/Services/Access` | `AccessResolver` (**the** single gate for a file, folder or document), `AccessGrant`, `AccessSource`. |
| `app/Services/Documents` | `DocumentRegistrar` (everything a tracked upload does after the bytes are stored, for both doors), `QrLabelService`. |
| `app/Services/Files` | `FileManager` (every structural operation), `FileStorage`, `FilePathFactory`, `FolderService`, `FilePreview`, `FileEventRecorder`, `SharingService`, `GuestIdentity`, `ArchiveService`, `UploadPolicy`, `DeletionConfirmation`, `ThumbnailGenerator`, `OwnershipTransferService`. |
| `app/Services` | `TrackingCodeService` (`ABBR-YYYYMMDD-NNNN`), `ProfileImageService`. |
| `app/Support` | `Modules` (the permission registry), `Roles`, `FileTypes` (the format catalogue: what each extension may sniff as, and the program/script floor an administrator cannot lift). |
| `app/Enums` | `DocumentAction`, `DocumentVisibility`, `AccessRequestStatus`, `ActivityAction`, `FileAbility`, `FileCategory`, `FileVisibility`, `GranteeType`, `ShareAbility`. There is no `DocumentStatus` — it went with the End Action. |
| `app/Events` | `DocumentTrailUpdated`, `ActivityTrailUpdated` — a **signal** that a trail changed, never the row. |
| `app/Notifications` | `PrivateDocumentShared`, `DocumentAccessRequested`, `DocumentAccessDecided`. |
| `app/Console/Commands` | `GenerateBrandIcons` (`brand:icons`), `ImportDocumentsIntoStorage` (`files:import-documents`), `PurgeTrashedFiles`. |
| `database/migrations` | An authoritative `create` baseline — the schema stated once rather than as an incremental history — plus ordinary forward migrations for everything since (the upload-policy merge, the actor's face on a trail row, the delete batch, access requests, document-type groups). A deployment carrying data from *before* the baseline must dump and restore. |
| `resources/css/app.css` | The entire design system: brand tokens, form base classes, table rules, and the dark theme. |
| `resources/js/app.js` | Inertia bootstrap. Wraps every page in the navbar/footer shell, mounts `ToastStack` **outside** the page, and installs the global validation-error scroll hook. |
| `resources/js/components` | Reusable Vue UI: `AdminShell` (the signed-in layout), `AppNavbar`/`AppFooter`, `Card`/`Button`/`Badge`/`VisibilityBadge`, `IndexControls`/`Pagination`/`RowActions`/`ActionMenu`/`ViewToggle`, `SearchableSelect`/`RecipientPicker`, `ConfirmActionModal`/`ConfirmDeleteModal`/`FeedbackModal`/`ToastStack`/`LoadingModal`/`LoadingFrame`, the file-manager set (`FileGrid`/`FileTable`/`FileBreadcrumbs`/`FileIcon`/`FilePreviewPane`/`FilePreviewModal`/`FolderPickerModal`/`FileFilterModal`/`ShareDrawer`/`OfficeRenderer`), `TrailFilters`, `TrackDocumentCard`, `AccessRequestsMenu`, `UploadLimitsHint`, `UserAvatar`/`AvatarField`, `NotificationBell`, `ThemeToggle`, `QrScanner`, `TutorialMenu`, `CountUp`, `CollapseTransition`, `ToggleSwitch`, `NavigationSkeleton`. |
| `resources/js/pages` | `index.js` (public landing) plus one directory per module: `auth/`, `dashboard/`, `documents/`, `document-types/`, `document-type-groups/`, `files/`, `share/`, `activity-trails/`, `upload-settings/`, `roles/`, `divisions/`, `sections/`, `users/`, `account/`, `errors/`. Card, panel and modal titles are `uppercase tracking-wide`. |
| `resources/js/composables` | `useConfirmSubmit` (every form's confirm-then-submit gate), `useUrlTab` (tab state in the query string), `useTabbedErrors` (a refusal switches to the tab holding the first error and focuses it), `useLiveTrail` (what to do with a trail signal), `usePreviewDescriptor` (fetching how a file should be previewed), `useCloseOnTutorial`. |
| `resources/js/utils` | `format-date.js` — `formatDay`/`formatTimestamp` for serialized Carbon values; `file-rows.js` — the shape both file listings render. |
| `resources/js/avatar.js`, `initials.js` | The generated-avatar URL and the initials fallback: the style and framing live here, never in `users.avatar_seed`. |
| `resources/js/echo.js` | The one Reverb connection, `listen()` — which returns a no-op unsubscribe when there is no socket — and `connection`, the `off`/`live`/`down` state the header wordmark is coloured by. |
| `resources/js/organization.js` | The office's name, contact details and logo, used by the navbar and footer. One file instead of PROCMIS's editable Pages module. |
| `resources/views/app.blade.php` | The only Blade file left: the Inertia root template, the icon links and the pre-paint theme script. |
| `routes/web.php` | The public landing, the authenticated group, then the token-and-opaque-id routes that are public by construction. |
| `routes/auth.php` | Authentication routes, registered by `bootstrap/app.php`'s `then:` callback. |
| `routes/channels.php` | Who may listen to a broadcast channel — and the entire gate, since `/broadcasting/auth` carries no `permission:` middleware. |
| `documentation` | This directory. See `CLAUDE.md` → Documentation map. |

## Conventions

Admin URLs live at the site root — there is no `/admin` prefix. The one `admin.`-prefixed route name (`admin.download.logs`) used to be a label rather than a gate; it is gated on `download-logs.read` now. Page components are resolved by path: `Inertia::render('documents/index')` loads `resources/js/pages/documents/index.js`. An index page is `index.js`, a create-and-edit form is `form.js`, a detail page is `show.js`.

See `AGENTS.md` for the working rules and the invariants, `documentation/design-system.md` for the visual contract, and `CLAUDE.md` for the architectural reasoning.
