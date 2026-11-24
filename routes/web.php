<?php

use App\Http\Controllers\AccessRequestController;
use App\Http\Controllers\ActivityTrailController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DivisionController;
use App\Http\Controllers\DocumentCommentController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentLogController;
use App\Http\Controllers\DocumentTypeController;
use App\Http\Controllers\DocumentTypeGroupController;
use App\Http\Controllers\FileActionController;
use App\Http\Controllers\FileManagerController;
use App\Http\Controllers\FileStreamController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicTrackingController;
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

/*
 * The tracking surface — scan a label, type a code, open the document, preview
 * its file — now requires an account.
 *
 * It used to be open, on the reasoning that a QR label must resolve for
 * whoever is holding the folder. The office's answer to that is narrower and
 * better: a **tracking code is printed on the folder**, so treating it as a
 * credential means anyone who photographs a desk can read every public
 * document in the institute, and a code is short, sequential and guessable
 * besides. Somebody outside the office is given a **share link** instead —
 * one URL, with an ability, an expiry and a view cap, that can be revoked and
 * whose every redemption is recorded against it.
 *
 * So the two audiences are separated by the door they come through rather
 * than by what the document is classified as: staff sign in, everybody else
 * holds a link. `DocumentController::isAuthorized()` is unchanged and still
 * decides *which* documents a signed-in reader may open.
 */
Route::middleware('auth')->group(function () {
    Route::get('/documents/success/{code}/{file_name}', [DocumentController::class, 'success'])->name('documents.success');
    Route::get('/documents/qr/download/{file_name}', [DocumentController::class, 'downloadQr'])->name('documents.qrcode.download');

    /*
     * How a document's file should be previewed, for the in-page viewer on
     * documents/show. The literal `preview` segment keeps it clear of
     * `documents/view/{code}` further down.
     */
    Route::get('/documents/{document}/preview', [DocumentController::class, 'preview'])->name('documents.preview');

    /*
     * The QR label for one document, for the register's row action. Behind
     * the document's own read rule in the controller rather than a
     * `permission:` gate, and what it encodes depends on whether the asker
     * is the uploader — a reader gets a symbol for the tracking URL, never
     * for the share link.
     */
    Route::get('/documents/{document}/qr-label', [DocumentController::class, 'qrLabel'])->name('documents.qr-label');

    /*
     * The file's version history. Behind the document's own read rule inside
     * the controller, not a `permission:` gate — "revised four times, by whom,
     * and why" is a disclosure about the document, so it answers to the same
     * question the page does.
     */
    Route::get('/documents/{document}/versions', [DocumentController::class, 'versions'])
        ->name('documents.versions');

    /*
     * Comments, behind the document's own read rule inside the controller
     * rather than a `permission:` gate — for the same reason the trail and
     * the version history are: who may discuss a document is who may open
     * it, and `AccessResolver` already answers that. A module permission
     * would be a second statement of one rule, and one that could grant
     * somebody commenting on documents they cannot read.
     */
    Route::post('/documents/{document}/comments', [DocumentCommentController::class, 'store'])
        ->name('documents.comments.store');
    Route::delete('/documents/{document}/comments/{comment}', [DocumentCommentController::class, 'destroy'])
        ->name('documents.comments.destroy');

    // Track-by-code. `/track` used to render a view that did not exist; it is
    // now an alias of the real lookup rather than a guaranteed 500.
    Route::get('/documents/track', [DocumentController::class, 'viewByCode'])->name('documents.viewByCode');
});

/*
 * The public tracking surface.
 *
 * A label scanned, or a code typed, by somebody with no account. It is a
 * *narrowing* of the authenticated surface below and not a reopening of the
 * one that preceded it: a public document is readable without an account —
 * which is what `AccessResolver::forDocument()` has always said about it —
 * while a private or confidential one answers with its classification and
 * nothing else. See PublicTrackingController for the whole argument.
 *
 * Throttled, because a tracking code is a short per-type daily series and
 * this route answers whether one is live. Rate limiting does not make the
 * series unguessable; it makes walking it slow enough to be worth noticing.
 */
Route::get('/track', [PublicTrackingController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('tracking.public');

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

    /*
     * Sections of a division, as JSON, for narrowing a section picker.
     *
     * It sat **outside** this group, which was right while there was a
     * registration form: a guest choosing their own office had to be able to
     * narrow the list. That form is gone, and the route stayed — a public
     * endpoint anyone could walk division by division to map the office's
     * structure, gating nothing and called by nothing, since the forms that
     * narrow a section picker do it client-side from props they already
     * hold. Declared before `sections/{section}` so `by-division` is not
     * captured as a section id.
     */
    Route::middleware('permission:sections.read')
        ->get('sections/by-division/{division}', [SectionController::class, 'byDivision'])->name('sections.byDivision');

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
     * Putting a withdrawn document back.
     *
     * `withTrashed()` on the binding, because the subject of this route is by
     * definition soft-deleted — without it the model binding applies the
     * global scope and the one route that exists to reach a trashed document
     * 404s on every one of them.
     *
     * Gated on `documents.delete`, which is the grant that put it there:
     * restoring is the mirror of deleting, not a separate capability, and a
     * second permission would be a role holding half of one act. The
     * controller still asks `owns()`, so the grant says the capability exists
     * and ownership says which document.
     */
    Route::middleware('permission:documents.delete')
        ->post('documents/{document}/restore', [DocumentController::class, 'restore'])
        ->withTrashed()
        ->name('documents.restore');

    /*
     * File responses. These must be reached by a plain link or window.location
     * — an Inertia XHR visit cannot follow a file download.
     *
     * The module permission is the outer gate; which *particular* document this
     * user may read is still DocumentController::isAuthorized(). Holding
     * `documents.download` does not make anyone a recipient.
     */
    Route::middleware('permission:documents.download')->group(function () {
        /*
         * There is no `receive` route any more. "Receive & download" fused two
         * different facts into one request — the server observing that a copy
         * left, and the person asserting the document reached them. Taking
         * delivery is now a `received` entry on the Log action form, and
         * downloading is this route, which already records the download in
         * both `document_logs` and `document_downloads`.
         */
        Route::get('documents/{document}/file/{action}', [DocumentController::class, 'accessFile'])
            ->where('action', 'view|download')
            ->name('documents.accessFile');
    });

    /*
     * A document's own share links.
     *
     * Separate from the file manager's `files.links.*` because the subject is
     * the document: that is what carries the classification a link has to obey,
     * and a link minted against the document's `File` row would answer to the
     * file's visibility instead — the looser second door AccessResolver exists
     * to close.
     *
     * `documents.share` is the module gate; the uploader check and the
     * confidential refusal are both in the controller, because "may this person
     * share" and "may this document be linked" are different questions and a
     * route can only ask the first.
     */
    Route::middleware('permission:documents.share')->group(function () {
        Route::post('documents/{document}/share-links', [DocumentController::class, 'storeLink'])
            ->name('documents.links.store');
        // An item has one live link, so its terms are edited rather than
        // superseded — the URL somebody was already sent keeps working.
        Route::match(['put', 'patch'], 'documents/{document}/share-links/{link}', [DocumentController::class, 'updateLink'])
            ->name('documents.links.update');
        /*
         * A new address for the one link, keeping its terms.
         *
         * Not an edit, so not the PATCH above: the terms are untouched and the
         * credential is what changes, which cuts off every copy of the old URL.
         */
        Route::post('documents/{document}/share-links/{link}/regenerate', [DocumentController::class, 'regenerateLink'])
            ->name('documents.links.regenerate');
        Route::delete('documents/{document}/share-links/{link}', [DocumentController::class, 'destroyLink'])
            ->name('documents.links.destroy');
        /*
         * Taking a named viewer off a restricted document.
         *
         * Under `documents.share`, because deciding who a document is for is
         * one grant whichever direction it moves in — a role that may tag
         * somebody is the role that may untag them, and a separate permission
         * for the second half is how an office ends up able to grant access it
         * cannot withdraw. Per-document ownership is still checked in the
         * controller, as it is for every other write here.
         */
        Route::delete('documents/{document}/recipients/{user}', [DocumentController::class, 'destroyRecipient'])
            ->name('documents.recipients.destroy');
    });

    /*
     * Asking to be let into a document, and answering.
     *
     * Granting is a `document_recipients` row — the same list the resolver
     * already reads — so this adds no new way into a document.
     *
     * The decision route carries **no** `permission:` middleware, deliberately:
     * whether somebody may answer is a per-record question (it is addressed to
     * the document's uploader), and a module permission for it would create
     * requests nobody holding the checkbox could answer. The controller is the
     * only thing that can know who the uploader is.
     */
    Route::middleware('permission:access-requests.read')
        ->get('/access-requests', [AccessRequestController::class, 'index'])->name('access-requests.index');
    Route::middleware('permission:access-requests.create')
        ->post('/access-requests', [AccessRequestController::class, 'store'])->name('access-requests.store');
    Route::patch('/access-requests/{accessRequest}', [AccessRequestController::class, 'update'])
        ->name('access-requests.update');
    /*
     * Clearing one's own request. No `permission:` middleware, for the same
     * reason the decision route has none: whether somebody may clear a row is
     * a per-record question — is it theirs — and the controller is the only
     * thing that can answer it. A module checkbox would be a permission that
     * grants nothing it could not already do.
     */
    Route::delete('/access-requests/{accessRequest}', [AccessRequestController::class, 'destroy'])
        ->name('access-requests.destroy');

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
        /*
         * The second half of an upload, for the one format this server
         * cannot draw into: the browser stamps a PDF and posts it back. Same
         * grant as the upload it completes, because that is what it is —
         * see FileActionController::stamp() for why it is not a general
         * write.
         */
        Route::post('/files/stamp', [FileActionController::class, 'stamp'])->name('files.stamp');
        Route::post('/files/copy', [FileActionController::class, 'copy'])->name('files.copy');
    });
    Route::middleware('permission:files.edit')->group(function () {
        Route::post('/files/rename', [FileActionController::class, 'rename'])->name('files.rename');
        /*
         * `files.edit`, not `files.create`, and the distinction is the subject
         * rather than the bytes: what is gated here is altering an item that
         * already exists. Somebody holding `files.create` without this can
         * still upload the thing as a new file, which is the honest fallback.
         * The per-item rule — and the refusal to let anyone but a tracked
         * document's uploader swap its bytes — lives in FileManager::replace().
         */
        Route::post('/files/replace', [FileActionController::class, 'replace'])->name('files.replace');
        Route::post('/files/revert', [FileActionController::class, 'revert'])->name('files.revert');
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
        // Fetched like the descriptor, and for the same reason: every entry
        // carries URLs that open bytes.
        Route::get('/files/versions', [FileStreamController::class, 'versions'])->name('files.versions');
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
        Route::match(['put', 'patch'], '/files/share-links/{link}', [ShareController::class, 'updateLink'])->name('files.links.update');
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
    /*
     * The module opens on the **groups**, and a group is opened to see the
     * types inside it. A flat table of every type answered the wrong
     * question first — an office wants to know what kinds of thing it files
     * before which exact type.
     *
     * The literal `group` segment is declared before `document-types/{documentType}`
     * for the rule this file keeps everywhere: a literal that comes after a
     * wildcard is captured by it.
     */
    Route::middleware('permission:document-types.read')->group(function () {
        Route::get('document-types', [DocumentTypeController::class, 'index'])->name('document-types.index');
        // `{group}` is a group id, `other` for the ungrouped, or `all`.
        Route::get('document-types/group/{group}', [DocumentTypeController::class, 'group'])
            ->name('document-types.group');
    });
    Route::middleware('permission:document-types.write')->group(function () {
        Route::get('document-types/create', [DocumentTypeController::class, 'create'])->name('document-types.create');
        Route::post('document-types', [DocumentTypeController::class, 'store'])->name('document-types.store');
        Route::get('document-types/{documentType}/edit', [DocumentTypeController::class, 'edit'])->name('document-types.edit');
        Route::match(['put', 'patch'], 'document-types/{documentType}', [DocumentTypeController::class, 'update'])->name('document-types.update');
        Route::post('document-types/{documentType}/restore', [DocumentTypeController::class, 'restore'])
            ->withTrashed()->name('document-types.restore');

        /*
         * The headings types are arranged under — a parent of a type, managed
         * on the same screen and behind the same grant. A group and a type
         * are one piece of office vocabulary, and two sets of permission
         * names is how a role ends up holding half of one.
         *
         * Declared before `document-types/{documentType}` would matter if the
         * paths collided; they do not, but the literal-before-wildcard rule
         * in this file is worth keeping visible.
         */
        Route::get('document-type-groups/create', [DocumentTypeGroupController::class, 'create'])
            ->name('document-type-groups.create');
        Route::post('document-type-groups', [DocumentTypeGroupController::class, 'store'])
            ->name('document-type-groups.store');
        Route::get('document-type-groups/{documentTypeGroup}/edit', [DocumentTypeGroupController::class, 'edit'])
            ->name('document-type-groups.edit');
        Route::match(['put', 'patch'], 'document-type-groups/{documentTypeGroup}', [DocumentTypeGroupController::class, 'update'])
            ->name('document-type-groups.update');
    });
    Route::middleware('permission:document-types.delete')->group(function () {
        Route::delete('document-types/{documentType}', [DocumentTypeController::class, 'destroy'])->name('document-types.destroy');
        Route::delete('document-type-groups/{documentTypeGroup}', [DocumentTypeGroupController::class, 'destroy'])
            ->name('document-type-groups.destroy');
    });

    // The upload policy. Its own module, because loosening what the office
    // accepts is an administrative act, not a deploy.
    Route::middleware('permission:upload-settings.read')
        ->get('/upload-settings', [UploadSettingController::class, 'edit'])->name('upload-settings.edit');
    Route::middleware('permission:upload-settings.write')
        // No `{setting}`: there is one policy, so the URL has nothing to name.
        ->match(['put', 'patch'], '/upload-settings', [UploadSettingController::class, 'update'])->name('upload-settings.update');

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
Route::post('/s/{token}/identify', [ShareLinkController::class, 'identify'])
    ->where('token', '[A-Za-z0-9]{64}')->name('share.identify');
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
 * The tracking-code view sits last on purpose: `documents/{code}` would
 * otherwise be shadowed by the authenticated `documents.show` route above.
 *
 * **One link, which answers whoever opens it.** It used to carry `auth`, so a
 * signed-out reader was handed a sign-in form — no answer at all to somebody
 * holding the folder, and the reason `/scan` already forwards a guest to the
 * public lookup instead. The middleware is gone and `view()` forwards a guest
 * to that same public door itself, before it touches the database.
 *
 * Nothing is disclosed that `/track` did not already disclose by code, and the
 * forward lands on the throttled route rather than widening this one: a
 * tracking code is still not a credential. What changed is only that the three
 * readers — the tagged, the untagged and the signed-out — each get an answer
 * about *this* document instead of one of them getting a login form.
 */
Route::get('/documents/view/{code}', [DocumentController::class, 'view'])
    ->name('documents.view');
