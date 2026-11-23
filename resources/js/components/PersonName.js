import { usePage } from '@inertiajs/vue3';
import { computed, defineComponent } from 'vue';

/**
 * A person's name, marked when that person is the one reading.
 *
 * "Janna Rinon" and "Janna Rinon (You)" are the same fact, and the second is
 * the one somebody can act on: on a register that is mostly other people's
 * work, the first question asked of a name is whether it is yours. The page
 * knows the answer and was making every reader work it out.
 *
 * Three decisions carry it.
 *
 * **Matched on the id, never on the name.** Two people in an office share a
 * surname and sometimes a whole name, and an audit row's `actor_name` is a
 * *snapshot* — what they were called when it happened. Comparing text would
 * label a colleague's row "(You)" and, after somebody changed their own name,
 * stop labelling theirs. An id is the only thing that means the same in both
 * places. It follows that a row whose actor is gone — `actor_id` null, which
 * every snapshot survives into — is never you, which is correct: the row
 * outlived the account, and a closed account is not the person reading.
 *
 * **A suffix, not a replacement.** Rendering "You" in place of the name reads
 * well and destroys the row: a trail is printed, screenshotted and read back
 * by somebody else, and "You downloaded this" in an audit report is a sentence
 * with no subject. The name stays and the marker is added beside it.
 *
 * **It is never a grant.** This decides nothing — not access, not an ability,
 * not which controls render. It is a label, computed from the shared
 * `auth.user` prop, and every real gate stays on the server.
 */
export default defineComponent({
    name: 'PersonName',
    props: {
        /** Any payload carrying `{ id, name }` — a user, a recipient, a row. */
        person: { type: Object, default: null },
        /** Or the two halves separately, for a row that snapshots its actor. */
        name: { type: String, default: '' },
        id: { type: [Number, String], default: null },
        /** What to render when there is no name at all. */
        fallback: { type: String, default: '—' },
        /**
         * Whether to mark the viewer's own name at all.
         *
         * False where the surrounding screen has already said it — the
         * register's "Uploaded by me" tab, where every row is yours, so a
         * marker on each one is noise rather than information. The same
         * reasoning the "Shared with you" badge uses: a badge on every row
         * of a list that is entirely one thing says nothing.
         */
        mark: { type: Boolean, default: true },
    },
    setup(props) {
        const page = usePage();

        const label = computed(() => props.person?.name ?? props.name ?? '');
        const subject = computed(() => props.id ?? props.person?.id ?? null);

        const isYou = computed(() => {
            if (! props.mark) return false;

            const viewer = page.props?.auth?.user ?? null;

            // Both halves must exist. A guest is nobody, and a row with no
            // actor is nobody — neither is "you", and `null == null` would
            // make both of them you.
            if (! viewer || subject.value === null || subject.value === undefined) return false;

            // Loose by value, not by type: an id arrives as a number from one
            // payload and as a string from another.
            return String(subject.value) === String(viewer.id);
        });

        return { isYou, label };
    },
    template: `
    <span class="inline-flex min-w-0 items-baseline gap-1.5">
        <span class="truncate">{{ label || fallback }}</span>
        <!-- Quiet and of a piece with the name rather than a badge: it is a
             qualifier on what is already there, and a coloured pill beside
             every one of your own rows would read as the loudest thing on a
             page that is mostly rows. -->
        <span v-if="isYou" class="shrink-0 text-xs font-semibold text-slate-500">(You)</span>
    </span>
`,
});
