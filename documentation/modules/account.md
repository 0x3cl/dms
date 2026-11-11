# Account

Profile details, password change, account deletion and the theme switch, on one tabbed page.

## Key files

| File | Role |
| --- | --- |
| `app/Http/Controllers/ProfileController.php` | Show, update, delete |
| `app/Http/Requests/ProfileUpdateRequest.php` | Name required, email optional |
| `app/Http/Controllers/Auth/PasswordController.php` | The password change |
| `resources/js/pages/account/index.js` | The page |
| `resources/js/components/ThemeToggle.js` | Light/dark |

## Tabs

`profile`, `password`, `danger`, held in the query string by `useUrlTab` so a tab can be linked and survives a reload. The default (`profile`) is kept out of the URL.

Employee ID, division and section are read-only here — they are administrator-managed, from the Users module.

The name is edited as **First name** and **Last name**. `users.name` is assembled from the two by the model and is not an input on this form; `ProfileUpdateRequest` refuses it for that reason.

## Traps

> **Email is optional.** Sign-in is by employee ID; `users.email` exists only so Laravel's password broker has something to look an account up by. `ProfileUpdateRequest` validates it as `nullable`. It originally required an `email` column that did not exist on the table at all.

> **There is no email verification.** `users` has no `email_verified_at` and `User` does not implement `MustVerifyEmail`, so changing an address verifies nothing. `ProfileController::update()` must not reintroduce an `email_verified_at = null` write.

> The password form posts to `PUT /password` and puts its errors in the `updatePassword` bag; deletion uses the `userDeletion` bag. Both are scoped so they cannot collide with the profile form on the same page.

> Deleting an account does **not** delete the documents that account uploaded — the office register keeps them and their tracking history stays intact. The page says so.

This whole page was unreachable before the port: `profile/edit.blade.php` used `<x-app-layout>` with a `$slot` the layout never rendered, called `route('password.update')` and `route('verification.send')` (neither registered), and read `$user->email`.

## The avatar

Three answers, in order: the uploaded photo, a generated avatar from the seed the person picked, or a generated avatar from **their name**. The third is the normal state and needs no choosing, which is the point — every account has a distinct, stable face from the moment it exists.

`AvatarField` on the Profile tab posts to `POST /profile/avatar` on its own, so changing a face does not resubmit a half-corrected surname; `DELETE /profile/avatar` returns to the name-derived default. The Users form uses the same component against `/users/{id}/avatar`, and on the *create* form the choice rides along with the create request because there is no account yet to post to.

> **Trap.** A photo and a seed are **exclusive**. `ProfileImageService` clears whichever was not sent and deletes the bytes the photo was using — an account holding both would render differently depending on which screen read it first, and an unreferenced file on disk is a leak no later change can reach.

> **Trap.** "Remove" is not "no avatar". The flash says so deliberately: with neither a photo nor a seed the face is generated from the name, so a message reading "photo removed" would make the next screen look broken.

> **Trap.** The generated image is fetched from DiceBear by the **browser**. Nothing but the seed leaves — a name at worst, never an identifier anyone could look an account up by — and `UserAvatar` falls back to initials when the request fails, so a blocked or offline CDN never leaves an empty circle. The style (`voxel-art`) and the framing (`scale=1.25`, DiceBear's own Close Up preset) live in `resources/js/avatar.js`; `users.avatar_seed` stores a *seed*, never a URL, so changing either is a change to that file rather than a rewrite of every row.
