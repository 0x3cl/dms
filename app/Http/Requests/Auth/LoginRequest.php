<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Signing in with **either** the employee ID or the email address.
 *
 * The employee ID is still the credential the office issues and the one
 * printed on a lanyard; email is the one people type without thinking, and it
 * was already on the account — nullable and unique — because the password
 * broker has nothing else to look an account up by. Accepting only the first
 * meant somebody who had just used their email to reset their password then
 * had to sign in with something else.
 *
 * One field, not two. A pair of boxes, or a toggle between them, asks the
 * person to classify their own identifier before they may type it, and the
 * form already knows: an identifier that is a valid email address is looked
 * up as an email, and everything else as an employee ID. There is exactly one
 * `Auth::attempt()` either way, because trying both in turn would hash the
 * password twice and make a wrong employee ID measurably slower to refuse
 * than a wrong email.
 *
 * This request is also what brings the rate limiting with it; the controller
 * must not call Auth::attempt() itself. It previously did, so there was no
 * throttling on sign-in at all.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * What the person typed, named as the thing it is rather than as one of
     * the two it might be.
     */
    public function attributes(): array
    {
        return ['login' => 'employee ID or email'];
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->credentials(), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'login' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Which column the identifier is looked up in.
     *
     * The test is the shape of the string, not a hint from the browser: a
     * `@` is what separates the two vocabularies, and an employee ID that is
     * also a valid email address is not a thing this office issues. The
     * refusal is identical either way — `auth.failed` names neither column —
     * so this cannot be used to discover which identifiers exist.
     *
     * @return array<string, string>
     */
    private function credentials(): array
    {
        $login = trim((string) $this->input('login'));
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'employee_id';

        return [$field => $login, 'password' => (string) $this->input('password')];
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Keyed on the identifier as typed, which is the same shape it has always
     * had. Somebody who knows both of an account's identifiers has two
     * buckets of five rather than one, and that is the honest trade for one
     * field accepting two vocabularies: the alternative is resolving the
     * account before the throttle runs, which turns the limiter into a probe
     * for which identifiers exist.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower(trim((string) $this->input('login'))).'|'.$this->ip());
    }
}
