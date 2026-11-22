<?php

namespace App\Services\Files;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Who opened a share link, when nobody is signed in.
 *
 * A share link is deliberately unauthenticated — the token is the credential —
 * which left every row it wrote saying "Unknown". For a hand-off trail that is
 * the least useful thing it could say: the whole point of the trail is that
 * somebody can follow where a document went, and "Unknown opened this four
 * times" is indistinguishable from four different people.
 *
 * So a visitor with no account is asked once, on the landing page, and may
 * decline. Declining is a real answer rather than a dead end: they are given a
 * generated handle — "Anonymous Nightwatch" — which is stable for that device, so
 * the trail can still say *the same person* came back twice without ever
 * learning who they are.
 *
 * Three things about this are load-bearing.
 *
 * **It is not authentication and must never be read as any.** Nothing is
 * verified, so the name decides nothing — not access, not ability, not which
 * link resolves. It is a label on an audit row and that is all it may ever be,
 * which is why it is snapshotted as a name with a null `actor_id` rather than
 * being allowed anywhere near a `User`.
 *
 * **It is stored on the device, not against the link.** Two people sent the
 * same URL are two visitors and must read as two rows; one person opening it
 * on their phone and again on their laptop is, unavoidably, two as well. A
 * device is the closest thing to a person that exists here.
 *
 * **The cookie carries no token.** It holds a handle and a display name, so a
 * stolen cookie grants nothing: whoever has it still needs the link.
 */
class GuestIdentity
{
    public const COOKIE = 'dms_visitor';

    /** A year — long enough that a returning reader is recognised as the same one. */
    public const TTL_MINUTES = 525600;

    /**
     * The second half of a generated handle: "Anonymous {hero}".
     *
     * Hero *archetypes* rather than any publisher's characters — the office
     * prints and forwards these rows, and a trail that reads "Anonymous
     * Batman" borrows somebody's trademark to say something a made-up name
     * says just as well.
     *
     * The handle has to be sayable out loud by somebody asking a colleague
     * "was that you who opened it?", which is what rules out a hex string.
     *
     * @var list<string>
     */
    private const HEROES = [
        'Nightwatch', 'Ironclad', 'Starlance', 'Quickstep', 'Stormcaller',
        'Lightbringer', 'Shieldbearer', 'Skyward', 'Tideturner', 'Emberguard',
        'Sentinel', 'Pathfinder', 'Dawnbreaker', 'Stonewarden', 'Windrunner',
        'Thunderstride', 'Glasshawk', 'Silversmith', 'Longshadow', 'Trailblazer',
    ];

    /**
     * The visitor this request carries, or null when they have not answered yet.
     *
     * Shaped rather than returned raw, because the cookie is attacker-editable
     * by definition — it is on their own machine. A malformed or oversized one
     * is treated as absent rather than trusted into an audit row.
     *
     * @return array{handle: string, name: string, anonymous: bool}|null
     */
    public function current(Request $request): ?array
    {
        $raw = $request->cookie(self::COOKIE);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ! isset($decoded['handle'], $decoded['name'])) {
            return null;
        }

        $handle = (string) $decoded['handle'];
        $name = $this->clean((string) $decoded['name']);

        if ($handle === '' || $name === '' || ! preg_match('/^[A-Za-z0-9]{1,32}$/', $handle)) {
            return null;
        }

        return [
            'handle' => $handle,
            'name' => $name,
            'anonymous' => (bool) ($decoded['anonymous'] ?? false),
        ];
    }

    /**
     * Mint an identity for this device.
     *
     * A null or blank name is the "stay anonymous" answer, and produces a
     * generated handle instead of a refusal — somebody who does not want to
     * give their name should not be stopped at the door of a document that was
     * deliberately sent to them.
     *
     * @return array{handle: string, name: string, anonymous: bool}
     */
    public function issue(?string $name): array
    {
        $clean = $this->clean((string) $name);
        $handle = (string) Str::random(16);

        return [
            'handle' => $handle,
            'name' => $clean !== '' ? $clean : $this->generatedName(),
            'anonymous' => $clean === '',
        ];
    }

    /**
     * The cookie that carries it back.
     *
     * `httpOnly` — no page script has any reason to read it, and the less a
     * cross-site script can learn about who opened what, the better.
     */
    public function cookie(array $identity): Cookie
    {
        return cookie(
            self::COOKIE,
            (string) json_encode($identity),
            self::TTL_MINUTES,
            httpOnly: true,
        );
    }

    /**
     * How this visitor is written into an audit row.
     *
     * Always carries the word "visitor", because a self-asserted name sitting
     * unqualified beside a column of real accounts is a claim the trail cannot
     * support. A reader has to be able to tell at a glance which rows were
     * somebody signed in and which were somebody who typed a name into a box.
     */
    public function label(?array $identity): string
    {
        if ($identity === null) {
            return 'Link visitor';
        }

        return $identity['name'].' (link visitor)';
    }

    /** One line, no control characters, short enough to render in a trail row. */
    private function clean(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';

        return Str::limit(trim(preg_replace('/\s+/u', ' ', $value) ?? ''), 60, '');
    }

    private function generatedName(): string
    {
        return 'Anonymous '.self::HEROES[array_rand(self::HEROES)];
    }
}
