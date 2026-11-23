# Auth

Session authentication on the employee ID **or** the email address, administrator-created accounts, and password reset.

## Key files

| File | Role |
| --- | --- |
| `routes/auth.php` | Every auth route. Registered by `bootstrap/app.php`'s `then:` callback |
| `app/Http/Requests/Auth/LoginRequest.php` | Resolves the identifier to a column **and** carries the login throttling |
| `app/Http/Controllers/Auth/` | Session, registration, password reset, password change, password confirmation |
| `resources/js/pages/auth/` | `login`, `forgot-password`, `reset-password`, `confirm-password` — there is no `register` |

## How sign-in works

`AuthenticatedSessionController@store` takes a `LoginRequest` and calls `$request->authenticate()`.

The form posts **one field, `login`**, and it accepts either identifier the account carries: `users.employee_id`, which the office issues and prints on a lanyard, or `users.email`, which is nullable, unique, and was already on the account because the password broker has nothing else to look one up by. Accepting only the first meant somebody who had just used their email to reset their password then had to sign in with something else.

`LoginRequest::credentials()` decides which column from the **shape of the string**: a valid email address is looked up as an email, everything else as an employee ID. One field rather than two, because a pair of boxes asks the person to classify their own identifier before they may type it; and exactly one `Auth::attempt()`, because trying both in turn would hash the password twice and make a wrong employee ID measurably slower to refuse than a wrong email. The refusal is `auth.failed` either way and names neither column, so this cannot be used to discover which identifiers exist.

An account with no address on file is simply unreachable by email and perfectly reachable by its employee ID — a null column never matches a typed one, which `AuthenticationTest` asserts.

> **Trap.** Authentication must go through `LoginRequest`, because that is where the rate limiting lives (5 attempts, keyed on the identifier **as typed** plus IP — somebody who knows both of an account's identifiers therefore gets two buckets of five, which is the honest trade for one field taking two vocabularies: resolving the account before the throttle runs would turn the limiter into a probe for which identifiers exist). The controller previously called `Auth::attempt()` inline and skipped the request class entirely, so there was **no rate limiting on login at all**. Covered by `tests/Feature/AuthenticationTest.php`.

`ConfirmablePasswordController` validates against `employee_id` too, not an email address.

## There is no registration

Accounts are created by an administrator on the **Users** screen (`users.write`), which already did everything self-registration did and the one thing it could not: choose the role.

`POST /register` was self-service and created a working account on the spot. There was no approval step and nothing checking that the employee ID was real or that the person worked here — so the only thing standing between a stranger and an account was a unique string they chose themselves, and that account arrived holding `documents-all.read`: every document the office has logged, listed by title, type, code and uploader. The files themselves stayed behind `AccessResolver`, but the register is a disclosure of its own.

`RegisteredUserController`, `pages/auth/register.js`, both routes and the login page's "Register here" link are **gone**, not hidden. The login page says where an account comes from instead, because a form that refuses an unknown employee ID while saying nothing about how to get one is a dead end for exactly the person who has just joined.

> **Do not reinstate this as a route with an approval flag.** A pending account is a second kind of account that every sign-in, every listing and every resolver then has to know about — the same class of thing as the lifecycle state the documents module removed. An administrator creating the account is the same gate with nothing to maintain.

## Password reset

Works by email address, which is the only thing Laravel's password broker can look a user up by. An account with no address on file cannot self-serve a reset and needs an administrator.

> **Trap.** This needed a schema change, not just a route. `users` had no `email` column and `password_reset_tokens` had been re-keyed to `employee_id`, which the broker cannot use — so the flow was dead even before its routes went unregistered. See `2026_11_10_000000_add_email_to_users_and_fix_password_reset_tokens`.

## Signing out lands on the sign-in page

`AuthenticatedSessionController::destroy()` redirects to `login`, not to `/`. Signing out happens at the end of a shift at a shared desk, and the next thing needed is the form; the landing page is a step away from it and reads as "you have been put outside" rather than "you are signed out". It is also the one answer rather than two — an expired session and `unauthorizedRedirect()` for a guest both already land there. The confirmation flashes `success`, so the sign-in page carries a toast saying what happened.

## No email verification

`users` has no `email_verified_at` column and `User` does not implement `MustVerifyEmail`, so there is nothing to verify. The three Breeze verification controllers were deleted and there are no verification routes.

Reinstating it means adding the column and the contract **first**; `routes/auth.php` carries that note.

## Traps for the whole module

> `routes/auth.php` went **unloaded** for the whole of the app's first life — `bootstrap/app.php` registered only `web.php` — which is why password reset never worked. Do not redeclare login/register/logout in `web.php`: they were duplicated in both files, and whichever loaded last silently won.
