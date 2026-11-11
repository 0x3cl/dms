<?php

namespace App\Services\Files;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The four-digit code a destructive file operation has to be confirmed with.
 *
 * A typed code is a speed bump, not an authorization: whoever asks for one is
 * already allowed to delete the thing. What it defends against is the muscle
 * memory that clicks the second button of a dialog without reading the first
 * line — which, on a recursive folder delete, is the difference between losing
 * one file and losing a year of them.
 *
 * It is **issued and verified by the server**, not generated in the browser.
 * A code the page invents and checks itself is a label on a button: the request
 * underneath it still deletes on its own, so anything that posts directly — a
 * replayed form, a script, a stale tab — bypasses the whole thing. The code
 * lives in the session, is spent on use, and expires, so a confirmation cannot
 * be harvested once and reused.
 *
 * Two purposes rather than one, because they are two different acts: moving
 * something to the trash is reversible and permanent deletion is not, and a
 * code issued for the first must not satisfy the second.
 */
class DeletionConfirmation
{
    public const PURPOSE_DELETE = 'delete';

    public const PURPOSE_PURGE = 'purge';

    /** How long an issued code stays good. */
    private const TTL_SECONDS = 600;

    /**
     * Mint a code for this purpose and remember it.
     *
     * Issuing again replaces the previous one, so the code on screen is always
     * the only one that works — two live codes would mean a dialog opened,
     * abandoned and reopened could be confirmed with the number the person
     * happened to still have written down.
     */
    public function issue(Request $request, string $purpose): string
    {
        $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        $request->session()->put($this->key($purpose), [
            'code' => $code,
            'issued_at' => now()->timestamp,
        ]);

        return $code;
    }

    /**
     * Spend the code, or refuse.
     *
     * Spent on success as well as on expiry: a confirmation is for one
     * operation, and leaving it in the session would let the next request
     * through without the person seeing a dialog at all.
     */
    public function assert(Request $request, string $purpose, ?string $given): void
    {
        $stored = $request->session()->get($this->key($purpose));
        $request->session()->forget($this->key($purpose));

        if (! is_array($stored) || ! isset($stored['code'], $stored['issued_at'])) {
            $this->refuse('Ask for a new confirmation code and try again.');
        }

        if (now()->timestamp - (int) $stored['issued_at'] > self::TTL_SECONDS) {
            $this->refuse('That confirmation code has expired. Ask for a new one.');
        }

        // hash_equals rather than ===: there are only ten thousand codes, so
        // they are guessable by anyone who can replay the request. Comparing in
        // constant time costs nothing and keeps the dialog from leaking which
        // digits were right.
        if (! is_string($given) || ! hash_equals((string) $stored['code'], $given)) {
            $this->refuse('That confirmation code does not match the one shown.');
        }
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['confirmation' => $message]);
    }

    private function key(string $purpose): string
    {
        return "files.confirmation.{$purpose}";
    }
}
