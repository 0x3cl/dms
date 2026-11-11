<?php

use App\Http\Controllers\ActivityTrailController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DivisionController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentLogController;
use App\Http\Controllers\DocumentTypeController;
use App\Http\Controllers\FileActionController;
use App\Http\Controllers\FileManagerController;
use App\Http\Controllers\FileStreamController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\ShareLinkController;
use App\Http\Controllers\UploadSettingController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
 * Authentication routes (login, register, logout, password reset, password
 * confirmation) live in routes/auth.php, which bootstrap/app.php registers.
 * Do not redeclare them here — they were duplicated in both files before, and
 * whichever loaded last silently won.
 */

// Public
Route::get('/', [LandingController::class, 'index'])->name('home');

// A QR label resolves here. Public by design: the gate is the document's own
// visibility (DocumentController::isAuthorized()), not the route.
Route::get('/documents/success/{code}/{file_name}', [DocumentController::class, 'success'])->name('documents.success');
Route::get('/documents/qr/download/{file_name}', [DocumentController::class, 'downloadQr'])->name('documents.qrcode.download');
Route::get('/scan', [DocumentController::class, 'scan'])->name('scan.qr');

/*
 * How a document's file should be previewed, for the in-page viewer on
 * documents/show. Public for the same reason the page and the byte route are:
 * the gate is the document's own visibility, applied inside the controller, and
 * a signed-out reader holding a printed label must get the same preview as
 * anyone else. The literal `preview` segment keeps it clear of
 * `documents/view/{code}` below.
 */
Route::get('/documents/{document}/preview', [DocumentController::class, 'preview'])->name('documents.preview');

// Track-by-code. `/track` used to render a view that did not exist; it is now
// an alias of the real lookup rather than a guaranteed 500.
Route::get('/documents/track', [DocumentController::class, 'viewByCode'])->name('documents.viewByCode');
Route::get('/track', fn () => redirect()->route('documents.viewByCode'));

// Sections of a division, as JSON, for narrowing a section picker.
Route::get('/sections/by-division/{division}', [SectionController::class, 'byDivision'])->name('sections.byDivision');

// Authenticated
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    /*
     * The four CRUD modules are declared action by action rather than through
     * Route::resource(), because a resource cannot carry per-action middleware
     * and each action needs a different permission — reading a division and
     * deleting one are not the same grant.
     *
     * Every route name here is exactly the one Route::resource() generated, so
     * every to_route() call and every <Link> keeps working. If you touch this
     * block, diff `php artisan route:list --json` before and after: a silently
     * renamed route breaks the frontend without failing a test.
     */

    // Divisions (no `show` — the index is the detail view)
    Route::middleware('permission:divisions.read')
        ->get('divisions', [DivisionController::class, 'index'])->name('divisions.index');
    Route::middleware('permission:divisions.write')->group(function () {
        Route::get('divisions/create', [DivisionController::class, 'create'])->name('divisions.create');
        Route::post('divisions', [DivisionController::class, 'store'])->name('divisions.store');
        Route::get('divisions/{division}/edit', [DivisionController::class, 'edit'])->name('divisions.edit');
        Route::match(['put', 'patch'], 'divisions/{division}', [DivisionController::class, 'update'])->name('divisions.update');
    });
    Route::middleware('permission:divisions.delete')
        ->delete('divisions/{division}', [DivisionController::class, 'destroy'])->name('divisions.destroy');

    // Sections. `sections/create` is declared before `sections/{section}` so
    // the literal segment is not captured as a section id.
    Route::middleware('permission:sections.read')
        ->get('sections', [SectionController::class, 'index'])->name('sections.index');
    Route::middleware('permission:sections.write')->group(function () {
        Route::get('sections/create', [SectionController::class, 'create'])->name('sections.create');
        Route::post('sections', [SectionController::class, 'store'])->name('sections.store');
    });
    Route::middleware('permission:sections.read')
        ->get('sections/{section}', [SectionController::class, 'show'])->name('sections.show');
    Route::middleware('permission:sections.write')->group(function () {
        Route::get('sections/{section}/edit', [SectionController::class, 'edit'])->name('sections.edit');
        Route::match(['put', 'patch'], 'sections/{section}', [SectionController::class, 'update'])->name('sections.update');
    });
    Route::middleware('permission:sections.delete')
        ->delete('sections/{section}', [SectionController::class, 'destroy'])->name('sections.destroy');

    /*
     * User accounts.
     *
     * This replaced the employee directory: one row per person, and the screen
     * that lists them is the screen that grants their access. `users/create`
     * must stay ahead of `users/{user}`, or it is captured as an account id.
     *
     * The password endpoint is its own route rather than part of `update`
     * because setting someone else's password is a different act from
     * correcting their surname, and the two tabs that post them fail
     * separately.
     */
    Route::middleware('permission:users.read')
        ->get('users', [UserController::class, 'index'])->name('users.index');
    Route::middleware('permission:users.write')->group(function () {
        Route::get('users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
    });
    Route::middleware('permission:users.read')
        ->get('users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::middleware('permission:users.write')->group(function () {
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::match(['put', 'patch'], 'users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::put('users/{user}/password', [UserController::class, 'updatePassword'])->name('users.password.update');
        Route::post('users/{user}/avatar', [UserController::class, 'updateAvatar'])->name('users.avatar.update');
        Route::delete('users/{user}/avatar', [UserController::class, 'destroyAvatar'])->name('users.avatar.destroy');
    });
    Route::middleware('permission:users.delete')
        ->delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

    /*
     * Documents.
     *
     * Ordering is load-bearing: every literal `documents/<word>` segment must
     * be declared before `documents/{document}`, or it is captured as a
     * document id. Route::resource() got this right for `create` on its own;
     * `documents/all` always had to be hoisted by hand, and splitting the
     * resource means `create` now needs the same care.
     */
    Route::middleware('permission:documents.read')
        ->get('documents', [DocumentController::class, 'index'])->name('documents.index');

    /*
     * The upload screen lives at `documents/upload`, not `documents/create`.
     * The route *name* is still `documents.create`, so every to_route() call
     * and <Link> keeps working — the path changed because the office asked for
     * it and a name is not a URL.
     */
    Route::middleware('permission:documents.create')->group(function () {
        Route::get('documents/upload', [DocumentController::class, 'create'])->name('documents.create');
        Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
    });

    /*
     * The office-wide register is a *tab* on the Documents page now, not a
     * screen of its own. This path survives as a redirect so a bookmark still
     * lands somewhere, and it carries `documents.read` rather than
     * `documents-all.read`: the All tab's own grant is applied inside
     * DocumentController, which degrades to "Uploaded by me" instead of
     * refusing the whole page.
     */
    Route::middleware('permission:documents.read')
        ->get('documents/all', [DocumentController::class, 'all'])->name('documents.all');

    Route::middleware('permission:documents.read')
        ->get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');

    Route::middleware('permission:documents.write')->group(function () {
        Route::get('documents/{document}/edit', [DocumentController::class, 'edit'])->name('documents.edit');
        Route::match(['put', 'patch'], 'documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
    });

    Route::middleware('permission:documents.delete')
        ->delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

    /*
     * File responses. These must be reached by a plain link or window.location
     * — an Inertia XHR visit cannot follow a file download.
     *
     * The module permission is the outer gate; which *particular* document this
     * user may read is still DocumentController::isAuthorized(). Holding
     * `documents.download` does not make anyone a recipient.
     */
    Route::middleware('permission:documents.download')->group(function () {
        Route::get('documents/{document}/file/{action}', [DocumentController::class, 'accessFile'])
            ->where('action', 'view|download')
            ->name('documents.accessFile');
        Route::post('documents/{document}/receive', [DocumentController::class, 'receiveFile'])->name('documents.receiveFile');
    });

    /*
     * The download register carries the IP address every copy went to, which is
     * why it is the one report with a permission of its own. The `admin.` route
     * name used to be a label over an open route; it is a real gate now.
     */
    Route::middleware('permission:download-logs.read')
        ->get('/admin/download-logs', [DocumentController::class, 'downloadLogs'])->name('admin.download.logs');

    /*
     * The file manager.
     *
     * These do not collide with the byte-serving `/files/{owner}/{file}` near
     * the bottom of this file, and two independent things keep it that way:
     * that route takes **three** segments where every page and action here
     * takes one or two, and both of its segments are constrained to exactly 26
     * alphanumeric characters. Adding a *three*-segment literal under `/files`
     * would need declaring before it — which is the hazard `documents/all`
     * actually has, where the shapes really do overlap.
     */
    Route::middleware('permission:files.read')->group(function () {
        Route::get('/files', [FileManagerController::class, 'index'])->name('files.index');
        Route::get('/files/trash', [FileManagerController::class, 'trash'])->name('files.trash');
    });
    /*
     * Browse Storage: other people's. Gated on `files.manage`, which is the one
     * name for "the reach across everyone else's storage" — it is what
     * AccessResolver already resolves that reach by, so the screen and the rule
     * cannot be granted apart. `files-browse.read` was a second name for the
     * same thing and has been retired.
     *
     * There is no `/files/shared` any more: what has been shared with this
     * person is a filter on My Files, listed by the same rule as everything
     * else there.
     */
    Route::middleware('permission:files.manage')
        ->get('/files/browse', [FileManagerController::class, 'browse'])->name('files.browse');

    /*
     * Operations. Each one carries the module permission as its outer gate;
     * which *particular* item it applies to is resolved by AccessResolver
     * inside App\Services\Files\FileManager, in the same transaction that
     * writes.
     */
    Route::middleware('permission:files.create')->group(function () {
        Route::post('/files/folders', [FileActionController::class, 'storeFolder'])->name('files.folders.store');
        Route::post('/files/upload', [FileActionController::class, 'upload'])->name('files.upload');
        Route::post('/files/copy', [FileActionController::class, 'copy'])->name('files.copy');
    });
    Route::middleware('permission:files.edit')->group(function () {
        Route::post('/files/rename', [FileActionController::class, 'rename'])->name('files.rename');
        Route::post('/files/move', [FileActionController::class, 'move'])->name('files.move');
        Route::post('/files/restore', [FileActionController::class, 'restore'])->name('files.restore');
    });
    /*
     * Deleting, and the two things that have to happen around it.
     *
     * `files.confirm-code` mints the four-digit code the dialog shows and the
     * delete then has to carry; `files.delete-preview` counts what a recursive
     * folder delete would actually take with it. Both are gated with the delete
     * itself: a count of what is inside a folder is a disclosure about that
     * folder, and a code handed to someone who cannot delete is noise.
     *
     * `files.purge` is permanent and has its own confirmation purpose, so a
     * code issued for the reversible one cannot satisfy it.
     */
    Route::middleware('permission:files.delete')->group(function () {
        Route::post('/files/delete', [FileActionController::class, 'destroy'])->name('files.delete');
        Route::post('/files/purge', [FileActionController::class, 'purge'])->name('files.purge');
        Route::post('/files/confirm-code', [FileActionController::class, 'confirmCode'])->name('files.confirmCode');
        Route::get('/files/delete-preview', [FileActionController::class, 'deletePreview'])->name('files.deletePreview');
    });

    /*
     * Two read-side JSON endpoints for the file manager's dialogs.
     *
     * `files.destinations` is the folder list the Move and Copy pickers walk,
     * scoped to folders this person may actually write into. `files.preview`
     * says how a file should be previewed and mints the short-lived URL when
     * that means Microsoft's Office viewer — which is why it is a request of its
     * own rather than a prop on the listing: forty files in a folder would
     * otherwise mint forty live credentials per navigation.
     */
    Route::middleware('permission:files.read')->group(function () {
        Route::get('/files/destinations', [FileActionController::class, 'destinations'])->name('files.destinations');
        Route::get('/files/preview', [FileStreamController::class, 'previewDescriptor'])->name('files.preview');
    });

    /*
     * Archives. Zipping is a download, so it needs `files.download`;
     * extracting writes new files, so it needs `files.create`.
     *
     * `files.zip` returns a file response — reach it with a real form post or
     * window.location, never router.visit.
     */
    Route::middleware('permission:files.download')
        ->post('/files/zip', [FileActionController::class, 'zip'])->name('files.zip');
    Route::middleware('permission:files.create')
        ->post('/files/extract', [FileActionController::class, 'extract'])->name('files.extract');
    Route::middleware('permission:files.share')->group(function () {
        Route::post('/files/visibility', [FileActionController::class, 'visibility'])->name('files.visibility');
        /*
         * Sharing is link-based from the drawer. Naming people happens where
         * the thing is created — the New folder dialog and the document upload
         * form — so there is no `shares.store` route any more: a second place
         * to grant access is how an item ends up shared with people nobody
         * remembers choosing. Revoking stays, because taking access away has
         * to be possible from wherever you can see it.
         */
        Route::get('/files/share-state', [ShareController::class, 'show'])->name('files.shares.show');
        Route::delete('/files/shares/{share}', [ShareController::class, 'destroy'])->name('files.shares.destroy');
        Route::post('/files/share-links', [ShareController::class, 'storeLink'])->name('files.links.store');
        Route::delete('/files/share-links/{link}', [ShareController::class, 'destroyLink'])->name('files.links.destroy');
    });

    /*
     * Document types — the list the upload form offers, and the map to tracking
     * code prefixes. Editable by the office: adding a type or correcting an
     * abbreviation used to be a deploy.
     *
     * `restore` and `destroy` bind `withTrashed()`, because a soft-deleted type
     * would not resolve otherwise and restoring one is the whole point.
     */
    Route::middleware('permission:document-types.read')
        ->get('document-types', [DocumentTypeController::class, 'index'])->name('document-types.index');
    Route::middleware('permission:document-types.write')->group(function () {
        Route::get('document-types/create', [DocumentTypeController::class, 'create'])->name('document-types.create');
        Route::post('document-types', [DocumentTypeController::class, 'store'])->name('document-types.store');
        Route::get('document-types/{documentType}/edit', [DocumentTypeController::class, 'edit'])->name('document-types.edit');
        Route::match(['put', 'patch'], 'document-types/{documentType}', [DocumentTypeController::class, 'update'])->name('document-types.update');
        Route::post('document-types/{documentType}/restore', [DocumentTypeController::class, 'restore'])
            ->withTrashed()->name('document-types.restore');
    });
    Route::middleware('permission:document-types.delete')
        ->delete('document-types/{documentType}', [DocumentTypeController::class, 'destroy'])->name('document-types.destroy');

    // The upload policy. Its own module, because loosening what the office
    // accepts is an administrative act, not a deploy.
    Route::middleware('permission:upload-settings.read')
        ->get('/upload-settings', [UploadSettingController::class, 'edit'])->name('upload-settings.edit');
    Route::middleware('permission:upload-settings.write')
        ->match(['put', 'patch'], '/upload-settings/{setting}', [UploadSettingController::class, 'update'])->name('upload-settings.update');

    /*
     * Everything that has happened to a file or folder. Its own permission,
     * because it reaches across every account's storage — unlike a document's
     * own trail, which is scoped to a document the viewer can already read.
     */
    Route::middleware('permission:activity-trails.read')
        ->get('/activity-trails', [ActivityTrailController::class, 'index'])->name('activity-trails.index');

    // Document trail. Gated on reading documents at the module level; whether
    // this user may see *this* document's history is DocumentLogController::canSee().
    Route::middleware('permission:documents.read')->group(function () {
        Route::post('/document/log', [DocumentLogController::class, 'store'])->name('document.log');
        Route::get('documents/{document}/logs', [DocumentLogController::class, 'index'])->name('documents.logs');
    });

    // Roles and permissions
    Route::middleware('permission:roles.read')
        ->get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::middleware('permission:roles.write')->group(function () {
        Route::get('roles/create', [RoleController::class, 'create'])->name('roles.create');
        Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
        Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
        Route::match(['put', 'patch'], 'roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    });
    Route::middleware('permission:roles.delete')
        ->delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

    // Account
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/', [ProfileController::class, 'update'])->name('profile.update');
        Route::post('avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar.update');
        Route::delete('avatar', [ProfileController::class, 'destroyAvatar'])->name('profile.avatar.destroy');
        Route::delete('/', [ProfileController::class, 'destroy'])->name('profile.destroy');
    });

    // Notifications
    Route::get('/notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::get('/notifications/mark-all-read', [NotificationController::class, 'markAllRead'])->name('notifications.markAllRead');
});

/*
 * Share links.
 *
 * Unauthenticated: the token is the credential. Short path because these get
 * pasted into messages, and 64 characters of token is already the long part.
 * Every request re-resolves the link — see ShareLinkController.
 */
Route::get('/s/{token}', [ShareLinkController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{64}')->name('share.show');
Route::get('/s/{token}/file', [ShareLinkController::class, 'stream'])
    ->where('token', '[A-Za-z0-9]{64}')->name('share.stream');

/*
 * A file's bytes, at an obfuscated URL.
 *
 * Outside the authenticated group for the same reason `documents.view` is: a
 * QR label has to resolve for a signed-out reader of a public document, and
 * the gate is the item's own access rule, not the route. AccessResolver
 * decides, in FileStreamController.
 *
 * The `where()` constraints are load-bearing. A path segment that must be
 * exactly 26 alphanumeric characters cannot carry `..`, a slash or a null
 * byte, so traversal is refused by the router before any controller code runs.
 */
Route::get('/files/{owner}/{file}', [FileStreamController::class, 'show'])
    ->where(['owner' => '[A-Za-z0-9]{26}', 'file' => '[A-Za-z0-9]{26}'])
    ->name('files.stream');

/*
 * The one door the private disk opens without a session.
 *
 * Microsoft's Office viewer renders Word, Excel and PowerPoint on their servers
 * and fetches the file itself, so the URL it is given has to work without our
 * cookie. (A PDF never comes through here: the browser renders those, and the
 * Office viewer cannot.) `signed` is the credential, the signature carries a few minutes'
 * expiry, and FileStreamController::viewer() re-resolves the item's visibility
 * on every fetch — a confidential item never gets a URL and stops working
 * immediately if it is reclassified after one was issued.
 *
 * No URL is minted at all when `app.url` names a host the internet cannot
 * resolve, because a frame pointing at `localhost` can only ever stay empty.
 *
 * Four segments, so it cannot collide with the three-segment byte route above.
 */
Route::get('/files/viewer/{owner}/{file}', [FileStreamController::class, 'viewer'])
    ->middleware('signed')
    ->where(['owner' => '[A-Za-z0-9]{26}', 'file' => '[A-Za-z0-9]{26}'])
    ->name('files.viewer');

/*
 * The public tracking-code view sits last on purpose: `documents/{code}` would
 * otherwise be shadowed by the authenticated `documents.show` route above, and
 * a QR label has to work for a signed-out reader of a public document.
 */
Route::get('/documents/view/{code}', [DocumentController::class, 'view'])->name('documents.view');
