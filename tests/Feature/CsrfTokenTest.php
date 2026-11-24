<?php

namespace Tests\Feature;

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Which header carries which token.
 *
 * Inertia's visits go through axios, which handles this itself. A few places
 * cannot use a visit — the folder picker creating a folder, the delete
 * dialog, the PDF stamp posting its bytes back — and those use `fetch`, which
 * sends nothing automatically. They read the token from
 * `<meta name="csrf-token">`, which Blade renders **once** on the first full
 * page load; an Inertia visit never re-renders the layout, so the tag holds
 * whatever the token was when the tab was opened. Laravel rotates the token on
 * every session regeneration, which a sign-in does — so a tab left open
 * across a sign-in elsewhere sends a token that was correct hours ago, which
 * is the `CSRF token mismatch` somebody meets creating a folder in a tab they
 * have had open all afternoon. `resources/js/csrf.js` sends the `XSRF-TOKEN`
 * cookie instead, which is reissued on every response.
 *
 * **This asserts the resolution order rather than the refusal**, and that is
 * not a shortcut. `VerifyCsrfToken::handle()` short-circuits on
 * `runningUnitTests()`, so no feature test can ever observe a 419 — a test
 * written against the endpoint would pass whatever token it sent, including
 * none, and prove nothing. What the fix actually rests on is which header
 * Laravel reads and in what order, so that is what is pinned here.
 */
class CsrfTokenTest extends TestCase
{
    private function resolve(Request $request): ?string
    {
        $method = new ReflectionMethod(VerifyCsrfToken::class, 'getTokenFromRequest');

        return $method->invoke(app(VerifyCsrfToken::class), $request);
    }

    /** The raw token travels as `X-CSRF-TOKEN`. */
    public function test_the_raw_token_is_read_from_its_own_header(): void
    {
        $request = Request::create('/files/folders', 'POST');
        $request->headers->set('X-CSRF-TOKEN', 'the-raw-token');

        $this->assertSame('the-raw-token', $this->resolve($request));
    }

    /**
     * The cookie's value travels as `X-XSRF-TOKEN`, and is decrypted.
     *
     * This is the header `resources/js/csrf.js` sends whenever the cookie is
     * present, which is every response after the first.
     *
     * The value is built the way `EncryptCookies` builds it — a
     * `CookieValuePrefix` and then the token, encrypted — because
     * `getTokenFromRequest()` strips a fixed 41 characters after decrypting.
     * Encrypting the bare token therefore yields an empty string rather than
     * a failure, which is a quiet way for this test to prove nothing.
     */
    public function test_the_encrypted_cookie_is_read_and_decrypted(): void
    {
        $request = Request::create('/files/folders', 'POST');
        $request->headers->set('X-XSRF-TOKEN', $this->asCookie('the-raw-token'));

        $this->assertSame('the-raw-token', $this->resolve($request));
    }

    /** A token encrypted the way the `XSRF-TOKEN` cookie carries it. */
    private function asCookie(string $token): string
    {
        return Crypt::encrypt(
            CookieValuePrefix::create('XSRF-TOKEN', Crypt::getKey()).$token,
            false,
        );
    }

    /**
     * The bug this nearly shipped with: the encrypted value under the raw
     * header's name.
     *
     * `X-CSRF-TOKEN` is read **first** and `X-XSRF-TOKEN` is only consulted
     * when nothing came back, so sending the cookie's value under both names
     * fails on the first and never reaches the second — every write refused,
     * in the name of fixing refusals. It is why `csrfHeaders()` sends exactly
     * one header, chosen by which token it has.
     */
    public function test_the_encrypted_value_under_the_raw_header_does_not_resolve(): void
    {
        $encrypted = $this->asCookie('the-raw-token');

        $request = Request::create('/files/folders', 'POST');
        $request->headers->set('X-CSRF-TOKEN', $encrypted);
        $request->headers->set('X-XSRF-TOKEN', $encrypted);

        $this->assertNotSame(
            'the-raw-token',
            $this->resolve($request),
            'The raw header wins and carries ciphertext, so the decryptable one is never reached.',
        );
    }

    /** A `_token` field still wins over both, which is what a form post sends. */
    public function test_a_form_field_takes_precedence(): void
    {
        $request = Request::create('/files/folders', 'POST', ['_token' => 'from-the-form']);
        $request->headers->set('X-CSRF-TOKEN', 'from-the-header');

        $this->assertSame('from-the-form', $this->resolve($request));
    }
}
