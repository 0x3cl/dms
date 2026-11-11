/**
 * A face for every account.
 *
 * Three answers, in order: the photo the person uploaded, a generated avatar
 * from the seed they picked, or a generated avatar from their name. The third
 * is why nobody has to do anything — an account has a recognisable face from
 * the moment it is created.
 *
 * Generated faces come from DiceBear's `voxel-art` style, framed close up
 * (`scale=1.25`, which is what DiceBear's own Close Up preset sets). The style
 * and the framing live here and nowhere else: `users.avatar_seed` stores a
 * *seed*, never a URL, so changing either is a change to this file rather than
 * a rewrite of every row.
 *
 * Two things to know before touching it.
 *
 * **The seed is the identity.** The same seed always renders the same face, so
 * a person's avatar is stable across every screen and across sessions, and two
 * people are near-certain to differ. Falling back to the name means a renamed
 * account gets a new face, which is exactly why the picker stores a seed
 * instead of relying on the name.
 *
 * **It is a third-party request, so it must be allowed to fail.** The image is
 * fetched by the browser from DiceBear, which can be blocked, offline or slow.
 * `UserAvatar` renders initials on error, so an avatar never becomes an empty
 * box — and nothing but the seed is ever sent, which is a name at worst and
 * never an identifier anyone could look an account up by.
 */

/** DiceBear's HTTP API. The major version is pinned: `voxel-art` is 10.x only. */
const DICEBEAR = 'https://api.dicebear.com/10.x/voxel-art/svg';

/** DiceBear's Close Up preset for this style, which is a scale and nothing else. */
const CLOSE_UP_SCALE = '1.25';

/** The seed an account's generated avatar is drawn from. */
export function avatarSeed(person) {
    const seed = person?.avatar_seed ?? '';

    if (seed !== '') return seed;

    // A name is a perfectly good seed and needs no storing. An account with
    // neither still gets a face rather than a blank.
    return (person?.name ?? '').trim() || 'DocTrack';
}

/** The URL of a generated avatar for this seed. */
export function generatedAvatarUrl(seed) {
    const params = new URLSearchParams({ seed, scale: CLOSE_UP_SCALE });

    return `${DICEBEAR}?${params.toString()}`;
}

/**
 * What to render for this person: their uploaded photo if they have one,
 * otherwise a generated face.
 *
 * `person` is any payload carrying `{ name, profile_image, avatar_seed }`,
 * which is the shape every controller that lists people already sends.
 */
export function avatarUrl(person) {
    if (person?.profile_image) return `/storage/${person.profile_image}`;

    return generatedAvatarUrl(avatarSeed(person));
}

/**
 * A spread of seeds to choose between, stable for one person.
 *
 * Their name first, so the default they already have is the first option and
 * choosing it is a no-op rather than a surprise. The rest are the name with a
 * suffix, which keeps the whole set reproducible: reopening the picker offers
 * the same faces in the same order, so "the third one" stays the third one.
 */
export function avatarChoices(name, count = 11) {
    const base = (name ?? '').trim() || 'DocTrack';

    return [base, ...Array.from({ length: count }, (_, index) => `${base}-${index + 1}`)];
}
