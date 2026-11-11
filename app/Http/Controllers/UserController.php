<?php

namespace App\Http\Controllers;

use App\Models\Division;
use App\Models\Section;
use App\Models\User;
use App\Services\Files\OwnershipTransferService;
use App\Services\ProfileImageService;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Accounts: who can sign in, which office they sit in, and what role they hold.
 *
 * This replaced the staff directory, which was a second record of the same
 * people with no sign-in attached. There is one person per row now, and the
 * screen that lists them is the screen that grants their access — which is why
 * the module is SUPERADMIN's and not part of Staff's floor.
 *
 * The screen is two tabs over one account: Personal Information, which is the
 * office's record of them, and Security & Password, which is their access. They
 * post to different endpoints because they are different grants of trust and
 * fail differently — a rejected password does not discard a corrected surname.
 */
class UserController extends Controller
{
    public function __construct(
        private readonly OwnershipTransferService $transfers,
        private readonly ProfileImageService $images,
    ) {}

    public function index(Request $request): Response
    {
        $entries = $this->entries($request);
        $search = $request->string('search')->toString();
        $section = $request->integer('section') ?: null;
        $role = $request->string('role')->toString();

        return Inertia::render('users/index', [
            'users' => User::query()
                ->with(['division:id,name', 'section:id,name', 'roles:id,name'])
                ->matching($search)
                ->when($section, fn ($query) => $query->where('section_id', $section))
                ->when($role !== '', fn ($query) => $query->whereHas('roles', fn ($roles) => $roles->where('name', $role)))
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->paginate($entries)
                ->withQueryString(),
            'sections' => $this->sectionOptions(),
            'roles' => $this->roleNames(),
            'filters' => [
                'entries' => $entries,
                'search' => $search,
                'section' => $section,
                'role' => $role,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('users/form', $this->formPayload(null));
    }

    public function show(User $user): RedirectResponse
    {
        return to_route('users.edit', $user);
    }

    public function edit(User $user): Response
    {
        return Inertia::render('users/form', $this->formPayload($user));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            ...$this->rules(null),
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'profile_image' => ProfileImageService::RULES,
            'avatar_seed' => ['nullable', 'string', 'max:'.ProfileImageService::SEED_MAX],
        ]);

        $user = User::create([
            ...collect($validated)
                ->except(['role', 'password', 'password_confirmation', 'profile_image', 'avatar_seed'])
                ->all(),
            'password' => Hash::make($validated['password']),
        ]);

        // Taken in the same request as the rest of the account, because a
        // person and their photo arrive together; on an existing account it is
        // its own endpoint, since editing a surname should not re-upload a
        // picture that has not changed.
        if ($request->hasFile('profile_image')) {
            $this->images->store($user, $request->file('profile_image'));
        } elseif (($validated['avatar_seed'] ?? null) !== null) {
            $this->images->chooseSeed($user, (string) $validated['avatar_seed']);
        }

        $user->syncRoles([$validated['role']]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return to_route('users.index')->with('success', 'Account created.');
    }

    /**
     * The Personal Information tab, plus the role.
     *
     * `name` is not accepted and not written — the model assembles it from the
     * two halves on save, because every audit snapshot reads it.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate($this->rules($user));

        if ($validated['role'] !== $this->roleOf($user)) {
            if ($refusal = $this->refuseRoleChange($request, $user, $validated['role'])) {
                return $refusal;
            }

            $user->syncRoles([$validated['role']]);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        $user->update(collect($validated)->except('role')->all());

        return to_route('users.edit', $user)->with('success', 'Account updated.');
    }

    /**
     * Set someone else's password.
     *
     * No `current_password` rule, unlike Auth\PasswordController: an
     * administrator resetting an account does not know what it was, and that is
     * the whole reason this endpoint exists. The gate is `users.write` on the
     * route, not knowledge of the old secret.
     *
     * Errors go in the `updatePassword` bag so the Security tab shows them
     * without touching the Personal Information form on the same page.
     */
    public function updatePassword(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user->update(['password' => Hash::make($validated['password'])]);

        return to_route('users.edit', ['user' => $user, 'tab' => 'security'])
            ->with('success', 'Password set for '.$user->name.'.');
    }

    /**
     * Close an account.
     *
     * The same rule as a user closing their own on /account, and for the same
     * reason: an account that still owns storage cannot simply go, or its files
     * point at a sign-in nobody can use and nothing says so. The request either
     * names someone to hand everything to or it is refused with a count.
     *
     * The actor recorded on the transfer is the administrator doing it, not the
     * account being closed — that is the one difference from the self-service
     * flow, and it is the difference the activity trail should show.
     *
     * The delete is soft: the account cannot sign in, and every audit row and
     * remaining link stays intact.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'transfer_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if ($user->is($request->user())) {
            return back()->with('error', 'Close your own account from My Account, where it asks for your password.');
        }

        if ($this->isLastSuperadmin($user)) {
            return back()->with('error', 'This is the only '.Roles::SUPERADMIN.' account. Promote somebody else before closing it.');
        }

        $holdings = $this->transfers->holdings($user);

        if ($holdings['total'] > 0 && empty($validated['transfer_to'])) {
            return back()->with('error', 'That account still holds '.$holdings['total'].' item(s). Choose someone to hand them to first.');
        }

        if (! empty($validated['transfer_to'])) {
            $this->transfers->transfer(
                $user,
                User::query()->findOrFail($validated['transfer_to']),
                $request->user(),
                $request,
            );
        }

        $user->delete();

        return to_route('users.index')->with('success', 'Account closed.');
    }

    /**
     * Set or replace somebody's avatar.
     *
     * Gated on `users.write` like the rest of the Personal Information tab: a
     * photo is part of the office's record of a person, not of their access.
     */
    public function updateAvatar(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validateWithBag('updateAvatar', [
            'profile_image' => array_merge(['required_without:avatar_seed'], ProfileImageService::RULES),
            'avatar_seed' => ['required_without:profile_image', 'nullable', 'string', 'max:'.ProfileImageService::SEED_MAX],
        ]);

        if ($request->hasFile('profile_image')) {
            $this->images->store($user, $request->file('profile_image'));

            return to_route('users.edit', $user)->with('success', 'Profile photo updated.');
        }

        $this->images->chooseSeed($user, (string) $validated['avatar_seed']);

        return to_route('users.edit', $user)->with('success', 'Avatar updated.');
    }

    public function destroyAvatar(User $user): RedirectResponse
    {
        $this->images->remove($user);

        // An account with neither a photo nor a seed still has a face,
        // generated from its name.
        return to_route('users.edit', $user)->with('success', 'Back to the avatar generated from the name.');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(?User $user): array
    {
        $ignore = $user ? ",{$user->id}" : '';

        return [
            'employee_id' => ['required', 'string', 'max:50', "unique:users,employee_id{$ignore}"],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', "unique:users,email{$ignore}"],
            'division_id' => ['required', 'exists:divisions,id'],
            'section_id' => ['required', 'exists:sections,id'],
            'role' => ['required', 'string', 'exists:roles,name'],
        ];
    }

    /**
     * Two ways a role change locks the application out of its own access model,
     * both refused on the server rather than only hidden on the form.
     */
    private function refuseRoleChange(Request $request, User $user, string $to): ?RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot change your own role. Ask another '.Roles::SUPERADMIN.' to do it.');
        }

        if ($to !== Roles::SUPERADMIN && $this->isLastSuperadmin($user)) {
            return back()->with('error', 'This is the only '.Roles::SUPERADMIN.' account. Promote somebody else first.');
        }

        return null;
    }

    /**
     * An install with no SUPERADMIN cannot grant itself one back: the screen
     * that hands out roles is the screen only SUPERADMIN reaches.
     */
    private function isLastSuperadmin(User $user): bool
    {
        if ($this->roleOf($user) !== Roles::SUPERADMIN) {
            return false;
        }

        return User::query()
            ->whereKeyNot($user->id)
            ->whereHas('roles', fn ($roles) => $roles->where('name', Roles::SUPERADMIN))
            ->doesntExist();
    }

    /**
     * A user holds exactly one role, so the first is the one.
     */
    private function roleOf(User $user): ?string
    {
        return $user->getRoleNames()->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function formPayload(?User $user): array
    {
        return [
            'user' => $user ? [
                'id' => $user->id,
                'employee_id' => $user->employee_id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'name' => $user->name,
                'profile_image' => $user->profile_image,
                'avatar_seed' => $user->avatar_seed,
                'email' => $user->email,
                'division_id' => $user->division_id,
                'section_id' => $user->section_id,
                'role' => $this->roleOf($user),
            ] : null,
            // Divisions carry their sections, so the section picker narrows
            // client-side without the round trip the by-division endpoint
            // would cost on every change.
            'divisions' => Division::query()
                ->with('sections:id,division_id,name')
                ->orderBy('name')
                ->get(['id', 'name']),
            'roles' => $this->roleNames(),
            'defaultRole' => Roles::STAFF,
            // What closing this account would leave behind, so the screen can
            // ask before the fact rather than orphan it silently.
            'holdings' => $user ? $this->transfers->holdings($user) : null,
            'transferTargets' => $user ? $this->transferTargets($user) : [],
            'can' => [
                // Both of these are re-checked on the server; the flags only
                // keep the form from offering what it would refuse.
                'editRole' => $user === null || ! ($user->is(request()->user()) || $this->isLastSuperadmin($user)),
                'delete' => $user !== null
                    && ! $user->is(request()->user())
                    && ! $this->isLastSuperadmin($user)
                    && request()->user()->can('users.delete'),
            ],
        ];
    }

    /**
     * Every role an administrator may assign, taken from the roles table rather
     * than a constant — a role created on the roles screen is assignable here
     * the moment it exists, which is what makes that screen mean anything.
     *
     * @return array<int, string>
     */
    private function roleNames(): array
    {
        return Role::query()->orderBy('name')->pluck('name')->all();
    }

    /**
     * A section name is not unique across divisions, so each option carries its
     * division.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sectionOptions(): array
    {
        return Section::query()
            ->with('division:id,name')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'division_id'])
            ->map(fn ($section) => [
                'id' => $section->id,
                'name' => $section->name,
                'division' => $section->division?->name,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function transferTargets(User $user): array
    {
        return User::query()
            ->whereKeyNot($user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'employee_id'])
            ->map(fn (User $candidate) => [
                'id' => $candidate->id,
                'name' => $candidate->name.' ('.$candidate->employee_id.')',
            ])
            ->all();
    }

    private function entries(Request $request): int
    {
        $entries = $request->integer('entries', 10);

        return in_array($entries, [10, 25, 50], true) ? $entries : 10;
    }
}
