<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use App\Services\Files\OwnershipTransferService;
use App\Services\ProfileImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function __construct(
        private readonly OwnershipTransferService $transfers,
        private readonly ProfileImageService $images,
    ) {}

    /**
     * The account page: profile details, password change and account deletion
     * on one tabbed screen.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user()->load(['division:id,name', 'section:id,name']);

        return Inertia::render('account/index', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'profile_image' => $user->profile_image,
                'avatar_seed' => $user->avatar_seed,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'employee_id' => $user->employee_id,
                'email' => $user->email,
                'division' => $user->division?->name,
                'section' => $user->section?->name,
            ],
            // What closing this account would leave behind, so the screen can
            // ask about it before the fact rather than orphan it silently.
            'holdings' => $this->transfers->holdings($user),
            'transferTargets' => User::query()
                ->whereKeyNot($user->id)
                ->orderBy('name')
                ->get(['id', 'name', 'employee_id'])
                ->map(fn (User $candidate) => [
                    'id' => $candidate->id,
                    'name' => $candidate->name.' ('.$candidate->employee_id.')',
                ]),
        ]);
    }

    /**
     * Update the user's profile information.
     *
     * There is no email verification in this app — `users` has no
     * `email_verified_at` column and the address is only there so the password
     * broker has something to look an account up by.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());
        $request->user()->save();

        return to_route('profile.edit')->with('success', 'Profile updated.');
    }

    /**
     * Replace the signed-in user's own avatar.
     *
     * Its own endpoint rather than a field on the profile form, because an
     * image means a multipart body and the profile form is a plain PATCH. The
     * password is split off for a comparable reason: these are separate things
     * that should fail separately.
     */
    /**
     * Set the signed-in user's own avatar: an uploaded photo, or a seed.
     */
    public function updateAvatar(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updateAvatar', $this->avatarRules());

        if ($request->hasFile('profile_image')) {
            $this->images->store($request->user(), $request->file('profile_image'));

            return to_route('profile.edit')->with('success', 'Profile photo updated.');
        }

        $this->images->chooseSeed($request->user(), (string) $validated['avatar_seed']);

        return to_route('profile.edit')->with('success', 'Avatar updated.');
    }

    public function destroyAvatar(Request $request): RedirectResponse
    {
        $this->images->remove($request->user());

        // Not "removed": an account with no photo and no seed still has a face,
        // generated from its name. Saying otherwise would make the next screen
        // look broken.
        return to_route('profile.edit')->with('success', 'Back to the avatar generated from your name.');
    }

    /**
     * Either a photo or a generated avatar's seed, and at least one of them.
     *
     * One endpoint for both because they are two answers to one question: the
     * service clears whichever was not sent, so an account can never hold a
     * photo and a seed at once and render differently depending on which screen
     * read it first.
     *
     * @return array<string, mixed>
     */
    private function avatarRules(): array
    {
        return [
            'profile_image' => array_merge(['required_without:avatar_seed'], ProfileImageService::RULES),
            'avatar_seed' => ['required_without:profile_image', 'nullable', 'string', 'max:'.ProfileImageService::SEED_MAX],
        ];
    }

    /**
     * Close the signed-in user's own account.
     *
     * An account that still owns storage cannot simply be closed: its files
     * would point at an account nobody can sign into, editable by nobody, and
     * nothing would say so. So the request either names an account to hand
     * everything to, or it is refused with a count of what is in the way.
     *
     * The account is **soft**-deleted, so it cannot sign in while every audit
     * row and every remaining link stays intact.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
            'transfer_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $user = $request->user();
        $holdings = $this->transfers->holdings($user);

        if ($holdings['total'] > 0 && empty($validated['transfer_to'])) {
            return back()->withErrors([
                'transfer_to' => 'This account still holds '.$holdings['total'].' item(s). Choose someone to hand them to before closing it.',
            ], 'userDeletion');
        }

        if (! empty($validated['transfer_to'])) {
            $this->transfers->transfer(
                $user,
                User::query()->findOrFail($validated['transfer_to']),
                $user,
                $request,
            );
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
