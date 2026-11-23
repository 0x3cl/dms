<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use Illuminate\Support\Facades\Route;

/*
 * Registered by bootstrap/app.php. This file went unloaded for the whole of
 * the app's first life, which is why password reset never worked.
 *
 * There are no email-verification routes: sign-in is by employee ID, `email`
 * is optional, and `users` has no `email_verified_at` column, so there is
 * nothing to verify. Reinstating verification means adding that column and
 * implementing MustVerifyEmail on the User model first.
 *
 * **There is no `register` route, and that is a removal rather than an
 * omission.** Self-registration created a working account on the spot, with
 * no approval and nothing checking that the employee ID was real or that the
 * person worked here — so the only thing between a stranger and the register
 * was a unique string they chose themselves. Accounts are created by an
 * administrator on the Users screen, which already does everything
 * registration did and one thing it could not: choose the role.
 *
 * Do not reinstate this as a route with an approval flag. A pending account
 * is a second kind of account that every sign-in, every listing and every
 * resolver then has to know about; an administrator creating the account is
 * the same gate with nothing to maintain.
 */

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
