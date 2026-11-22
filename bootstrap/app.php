<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Inertia\Inertia;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            // Breeze's auth routes (password reset, email verification, password
            // confirmation) were never registered before, which left those pages
            // unreachable. They are part of the app now.
            Illuminate\Support\Facades\Route::middleware('web')
                ->group(base_path('routes/auth.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Route-level permission gates. Every module route carries one; see
        // App\Support\Modules for the vocabulary and routes/web.php for the
        // groups.
        $middleware->alias([
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // A permission failure has to answer in the idiom of whatever asked.
        // A page visit gets a rendered 403; anything else — a form post, a
        // delete — goes back where it came from with the error flash the shell
        // already watches for, because dumping a raw 403 body into an Inertia
        // XHR shows the user nothing at all.
        $exceptions->render(function (UnauthorizedException $e, $request) {
            if ($request->isMethod('GET')) {
                return Inertia::render('errors/403', [
                    'message' => 'You do not have permission to open that page.',
                ])->toResponse($request)->setStatusCode(403);
            }

            return back()->with('error', 'You do not have permission to do that.');
        });

        // A 404 is the most common refusal in this application and it used to
        // be answered by Laravel's default body, which says "404 | NOT FOUND"
        // and nothing else. The page a reader most often reaches it from is a
        // share link, and that reader has no account, no sidebar and no way
        // back — so the answer has to carry the doors with it.
        //
        // Only a page visit renders. An XHR expecting JSON, and anything that
        // is not a GET, falls through to the framework untouched by returning
        // null, because a JSON client asking for a missing record wants a
        // status and not a rendered shell.
        //
        // The wording never distinguishes "revoked" from "never existed" —
        // see resources/js/pages/errors/404.js.
        $exceptions->render(function (NotFoundHttpException $e, $request) {
            if (! $request->isMethod('GET') || $request->expectsJson()) {
                return null;
            }

            return Inertia::render('errors/404', [
                'subject' => match (true) {
                    $request->is('s/*') => 'share',
                    $request->is('files/*', 'documents/*') => 'file',
                    default => 'page',
                },
            ])->toResponse($request)->setStatusCode(404);
        });
    })->create();
