const SUFFIXES = new Set(['jr', 'sr', 'ii', 'iii', 'iv', 'v']);

/**
 * Two-letter avatar text for a name with no profile image: the first letter of
 * the first and last name ("Marnie B. Dones" → "MD", skipping suffixes like
 * "Jr."), or the first two letters of a single-word name.
 */
export const initials = (name) => {
    const words = String(name ?? '')
        .replace(/\(.*?\)/g, ' ')
        .split(/\s+/)
        .map((word) => word.replace(/[^\p{L}\p{N}]/gu, ''))
        .filter(Boolean);
    const significant = words.filter((word, index) => index === 0 || !SUFFIXES.has(word.toLowerCase()));

    if (significant.length === 0) return '?';
    if (significant.length === 1) return [...significant[0]].slice(0, 2).join('').toUpperCase();

    return ([...significant[0]][0] + [...significant.at(-1)][0]).toUpperCase();
};
