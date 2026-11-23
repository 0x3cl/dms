<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        // No props. `canRegister` went with self-registration: there is no
        // sign-up page to offer, and a flag whose only value is `true` is a
        // switch nothing can turn.
        return Inertia::render('auth/login');
    }

    /**
     * Handle an incoming authentication request.
     *
     * Authentication goes through LoginRequest so the attempt is rate limited;
     * do not inline Auth::attempt() here again.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     *
     * To the **sign-in page**, not the public landing page. Signing out is
     * something somebody does at the end of a shift at a shared desk, and the
     * next thing they or the next person needs is the form — the landing page
     * is a step away from it, and it reads as "you have been put outside"
     * rather than "you are signed out". It is also where every other way out
     * of a session already lands: an expired session and
     * `unauthorizedRedirect()` for a guest both go to `login`.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'You have been signed out.');
    }
}
