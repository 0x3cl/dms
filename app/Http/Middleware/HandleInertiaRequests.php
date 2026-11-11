<?php

namespace App\Http\Middleware;

use App\Http\Controllers\NotificationController;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * `auth.permissions` is a flat list of permission names, and it is what
     * AdminShell filters its sidebar against. It is deliberately **not** lazy:
     * the shell renders on every authenticated page, so a lazy prop would just
     * be resolved every time anyway, and a page that forgot to request it
     * would render a sidebar with nothing in it.
     *
     * It is for *navigation and affordances* only. A button's visibility comes
     * from a server-computed `can` prop on the page (see
     * DocumentController::showPayload()), and the real gate is the route
     * middleware plus the policies — never this array.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    // The shell's header renders a face, so both halves of the
                    // avatar answer travel with every page.
                    'profile_image' => $user->profile_image,
                    'avatar_seed' => $user->avatar_seed,
                    'employee_id' => $user->employee_id,
                    'division' => $user->division?->name,
                    'section' => $user->section?->name,
                ] : null,
                // A user holds exactly one role; the first is the one.
                'role' => $user?->getRoleNames()->first(),
                'permissions' => $user ? $user->getAllPermissions()->pluck('name')->all() : [],
            ],
            // The header bell. Lazy so a page that never renders it pays nothing.
            'notifications' => fn (): ?array => $user
                ? NotificationController::feedFor($user)
                : null,
            // Every controller flashes exactly these two keys. Both shells watch
            // them and open a FeedbackModal; there is no toast component.
            'flash' => [
                'success' => fn (): ?string => $request->session()->get('success'),
                'error' => fn (): ?string => $request->session()->get('error'),
            ],
        ];
    }
}
