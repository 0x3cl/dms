<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Division;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     *
     * Divisions carry their sections so the section picker filters client-side
     * without the extra round trip the old jQuery cascade made.
     */
    public function create(): Response
    {
        return Inertia::render('auth/register', [
            'divisions' => Division::query()
                ->with('sections:id,division_id,name')
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * A new account gets the least-privilege `Staff` role. Registration is
     * self-service and has no approval step, so whatever this role carries is
     * what anyone who can reach the sign-up page gets — which is why it is the
     * floor and not a convenience. See App\Support\Roles.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'employee_id' => ['required', 'string', 'max:50', 'unique:users'],
            'division_id' => ['required', 'exists:divisions,id'],
            'section_id' => ['required', 'exists:sections,id'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'employee_id' => $validated['employee_id'],
            'division_id' => $validated['division_id'],
            'section_id' => $validated['section_id'],
            'password' => Hash::make($validated['password']),
        ]);

        $user->assignRole(Roles::STAFF);

        return redirect()->route('login')
            ->with('success', 'Registration successful. You may now sign in.');
    }
}
