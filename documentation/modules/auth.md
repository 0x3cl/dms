# Auth

Session authentication on `employee_id`, self-service registration, and password reset.

## Key files

| File | Role |
| --- | --- |
| `routes/auth.php` | Every auth route. Registered by `bootstrap/app.php`'s `then:` callback |
| `app/Http/Requests/Auth/LoginRequest.php` | Employee-ID credentials **and** the login throttling |
| `app/Http/Controllers/Auth/` | Session, registration, password reset, password change, password confirmation |
| `resources/js/pages/auth/` | `login`, `register`, `forgot-password`, `reset-password`, `confirm-password` |

## How sign-in works

`users.employee_id` is the credential — there is no email sign-in. `AuthenticatedSessionController@store` takes a `LoginRequest` and calls `$request->authenticate()`.

> **Trap.** Authentication must go through `LoginRequest`, because that is where the rate limiting lives (5 attempts, keyed on employee ID plus IP). The controller previously called `Auth::attempt()` inline and skipped the request class entirely, so there was **no rate limiting on login at all**. Covered by `tests/Feature/AuthenticationTest.php`.

`ConfirmablePasswordController` validates against `employee_id` too, not an email address.

## Registration

`POST /register` is self-service: name, employee ID, division, section, password. There is still no approval step, so a new account is assigned the least-privilege `Staff` role (`App\Support\Roles`) — whatever that role carries is what anyone who can reach the sign-up page gets, which is why it is the floor rather than a convenience.

> **Open item.** Combined with the lack of an access model, this means anyone who can reach the app can create an account that can read the office register, open the download-log report and edit the directory. Recorded in `documentation/architecture.md` → Known open items.

The division→section picker filters client-side from nested props. The Blade page pulled jQuery from a CDN to hit `/sections/by-division/{id}` on every change.

## Password reset

Works by email address, which is the only thing Laravel's password broker can look a user up by. An account with no address on file cannot self-serve a reset and needs an administrator.

> **Trap.** This needed a schema change, not just a route. `users` had no `email` column and `password_reset_tokens` had been re-keyed to `employee_id`, which the broker cannot use — so the flow was dead even before its routes went unregistered. See `2026_11_10_000000_add_email_to_users_and_fix_password_reset_tokens`.

## No email verification

`users` has no `email_verified_at` column and `User` does not implement `MustVerifyEmail`, so there is nothing to verify. The three Breeze verification controllers were deleted and there are no verification routes.

Reinstating it means adding the column and the contract **first**; `routes/auth.php` carries that note.

## Traps for the whole module

> `routes/auth.php` went **unloaded** for the whole of the app's first life — `bootstrap/app.php` registered only `web.php` — which is why password reset never worked. Do not redeclare login/register/logout in `web.php`: they were duplicated in both files, and whichever loaded last silently won.
