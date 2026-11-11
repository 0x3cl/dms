<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * An account's avatar: an uploaded photo, or a generated one.
 *
 * Two kinds, and the pair is deliberately exclusive — `profile_image` holds an
 * uploaded file and `avatar_seed` holds the seed of a generated face, and
 * setting either clears the other. An account carrying both would render
 * differently depending on which screen read it first.
 *
 * Neither is required. A null pair means "generate a face from this person's
 * name", which is what gives every account a recognisable avatar from the
 * moment it exists without anybody being asked to pick one — and why removing
 * an avatar is not the same as having none.
 *
 *
 * Two screens upload one: a user changing their own on /account, and an
 * administrator setting somebody's on the Users form. Put the rule in one place
 * rather than two, because the part that is easy to forget is not the write —
 * it is deleting the file the account was using before. Without that, every
 * change leaves its predecessor on disk forever, and an avatar that is replaced
 * a few times a year across an office is a slow leak nobody ever looks for.
 *
 * The validation rules live here too, as a constant both call sites use, so the
 * two forms cannot drift into accepting different things.
 *
 * This deliberately does **not** go through `UploadPolicy`. That policy exists
 * so an administrator can widen what the office *files* — tracked documents and
 * the file manager — and it has two scopes because those are two different
 * kinds of thing. An avatar is a third thing and not an office record: it is
 * displayed inline in a page as an `<img>`, so the set of formats that is safe
 * is fixed by what a browser will render as an image, not by office policy.
 * Making it configurable would mean offering an administrator a switch whose
 * only effect is to let something other than an image be served from a public
 * URL.
 */
class ProfileImageService
{
    /**
     * Where avatars live on the `public` disk.
     */
    public const DIRECTORY = 'profile-images';

    /**
     * The one definition of an acceptable avatar.
     *
     * `image` makes the server sniff the contents rather than trust the
     * extension, and the explicit `mimes` list narrows that to the formats
     * every browser renders — `image` alone would also pass an SVG, which is a
     * document that executes script, served from our own origin.
     *
     * @var list<string>
     */
    public const RULES = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'];

    /**
     * The longest seed worth storing.
     *
     * A seed is an opaque string that only ever reaches an avatar URL, so the
     * limit is about the column rather than about meaning.
     */
    public const SEED_MAX = 64;

    /**
     * Store a new avatar for `$user` and remove the one it replaces.
     *
     * Returns the stored path, relative to the `public` disk.
     */
    public function store(User $user, UploadedFile $image): string
    {
        $previous = $user->profile_image;

        // Hashed name, not the uploaded one: two people uploading `me.jpg`
        // must not collide, and the name a user typed has no business
        // appearing in a URL this application serves.
        $path = $image->store(self::DIRECTORY, 'public');

        // The seed goes with it: a photo and a generated face are two answers
        // to one question, and an account holding both renders differently
        // depending on which screen reads it first.
        $user->forceFill(['profile_image' => $path, 'avatar_seed' => null])->save();

        $this->forget($previous);

        return $path;
    }

    /**
     * Pick a generated avatar.
     *
     * The uploaded photo is deleted, not merely unlinked from the row: keeping
     * it would leave a file on disk that nothing points at and that no later
     * change can reach, which is the same slow leak `store()` exists to avoid.
     */
    public function chooseSeed(User $user, string $seed): void
    {
        $previous = $user->profile_image;

        $user->forceFill([
            'profile_image' => null,
            'avatar_seed' => mb_substr(trim($seed), 0, self::SEED_MAX),
        ])->save();

        $this->forget($previous);
    }

    /**
     * Drop whatever the account chose, falling back to a face generated from
     * its name.
     */
    public function remove(User $user): void
    {
        $previous = $user->profile_image;

        $user->forceFill(['profile_image' => null, 'avatar_seed' => null])->save();

        $this->forget($previous);
    }

    /**
     * Delete a stored file, if there is one.
     *
     * The row is updated before this runs, so a failure to unlink leaves an
     * orphaned file rather than a row pointing at bytes that are gone — the
     * harmless direction of the two.
     */
    private function forget(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
