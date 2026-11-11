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

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
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
    })->create();
