import { computed, defineComponent } from 'vue';

const tones = {
    primary: { icon: 'bg-sky-100 text-[#07559e]', button: 'bg-[#00aeef] hover:bg-[#008dcc]' },
    danger: { icon: 'bg-rose-100 text-rose-600', button: 'bg-rose-600 hover:bg-rose-700' },
};

export default defineComponent({
    name: 'ConfirmActionModal',
    // The two roots below make this a fragment, so a stray `onDismiss` would
    // warn about an attribute with nowhere to land.
    inheritAttrs: false,
    props: {
        // null drops the cancel button, for a modal that only reports
        // something and has nothing to decline.
        cancelLabel: { type: String, default: 'Cancel' },
        confirmLabel: { type: String, default: 'Confirm' },
        // Blocks confirming while the default slot's own input is incomplete
        // — e.g. the Backups page asking what to back up, where confirming
        // with nothing selected would queue nothing.
        confirmDisabled: { type: Boolean, default: false },
        details: { type: Array, default: () => [] },
        icon: { type: String, default: 'fa-solid fa-circle-question' },
        message: { type: String, default: '' },
        open: { type: Boolean, default: false },
        processing: { type: Boolean, default: false },
        // Upload percentage (0-100) while `processing` reflects a file
        // upload in progress — e.g. Inertia's form.progress?.percentage.
        // Null/omitted shows the plain processing label instead.
        progress: { type: Number, default: null },
        // Optional roll-call rendered as a Name | Signature table under the
        // message, for actions that may go ahead with signatures missing
        // (the AOC stage, the Notice to Proceed). Each entry is
        // { name, role?, signed, signed_at?, group? }; an unsigned one is
        // printed in rose so it reads at a glance. Entries carrying a
        // `group` are split under that heading, which is what the Notice to
        // Proceed needs to separate one purchase order from the next.
        signatories: { type: Array, default: () => [] },
        layerClass: { type: String, default: 'z-50' },
        title: { type: String, required: true },
        tone: { type: String, default: 'primary' },
        // Opt-in roomier body, for a long `details` list such as the Master
        // Editor's change summary. Other callers keep the narrow card.
        wide: { type: Boolean, default: false },
        /**
         * A third width, for a body that is a *layout* rather than a message:
         * the file filter's two-column grid of selects, where `wide` leaves
         * every label wrapping and every select truncating its own value.
         *
         * Kept as a separate opt-in rather than widening `wide`, because the
         * narrow card is the right shape for the dozen callers that are asking
         * a question — a dialog as wide as the page reads as a screen.
         */
        wider: { type: Boolean, default: false },
    },
    emits: ['close', 'confirm'],
    setup(props, { attrs, emit }) {

        /**
         * Backing out of the dialog entirely.
         *
         * For most callers that is just `close`, because their cancel button
         * is a cancel. It is not always: the signature prompt's cancel is
         * "Skip signature", and closing it goes ahead without one — so an X
         * wired to `close` there would submit the action while looking like
         * it abandoned it. Those callers pass `@dismiss` with a real abort,
         * and it takes precedence.
         */
        const dismiss = () => {
            if (props.processing) {
                return;
            }

            if (typeof attrs.onDismiss === 'function') {
                attrs.onDismiss();

                return;
            }

            emit('close');
        };
        const toneClasses = computed(() => tones[props.tone] ?? tones.primary);

        /*
         * Three widths, all a step wider than they were.
         *
         * The old default was `max-w-md` — 448px — which is a column of text,
         * not a dialog. Every caller that puts a *control* in the default slot
         * (and most of them do: a name, a file input, a radio list) was wrapping
         * labels and truncating selects inside it. The outer wrapper's `p-4`
         * and this card's `w-full` mean none of these widen anything on a
         * phone; they only stop a desktop dialog being narrower than it needs
         * to be.
         *
         * `wider` is for a body that is a *layout* rather than a message, and
         * is wide enough for a four-column grid of controls.
         */
        const widthClass = computed(() => {
            if (props.wider) return 'max-w-5xl';

            return (props.signatories.length || props.wide) ? 'max-w-3xl' : 'max-w-xl';
        });
        const signatureGroups = computed(() => {
            const groups = [];

            props.signatories.forEach((signatory) => {
                const label = signatory.group ?? null;
                const last = groups[groups.length - 1];

                if (last && last.label === label) {
                    last.rows.push(signatory);

                    return;
                }

                groups.push({ label, rows: [signatory] });
            });

            return groups;
        });
        const signedOn = (value) => (value ? new Date(value).toLocaleDateString('en-PH', { dateStyle: 'medium' }) : '');

        return { dismiss, signatureGroups, signedOn, toneClasses, widthClass };
    },
    template: `
    <Transition enter-active-class="transition-opacity duration-300 ease-out" enter-from-class="opacity-0" enter-to-class="opacity-100" leave-active-class="transition-opacity duration-200 ease-in" leave-from-class="opacity-100" leave-to-class="opacity-0">
        <div v-if="open" class="fixed inset-0 bg-slate-950/45" :class="layerClass"></div>
    </Transition>
    <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100" leave-active-class="transition duration-150 ease-in" leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 scale-95">
        <div v-if="open" class="pointer-events-none fixed inset-0 flex items-center justify-center p-4" :class="layerClass" role="dialog" aria-modal="true" aria-labelledby="confirm-action-title">
            <section class="pointer-events-auto max-h-full w-full overflow-y-auto rounded-3xl bg-white p-7 shadow-2xl sm:p-9" :class="widthClass">
                <div class="flex items-start justify-between gap-4">
                    <div class="flex h-14 w-14 items-center justify-center rounded-full text-2xl" :class="toneClasses.icon">
                        <i :class="icon" aria-hidden="true"></i>
                    </div>
                    <button type="button" class="-mr-2 -mt-2 rounded-full p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 disabled:cursor-not-allowed disabled:opacity-40" :disabled="processing" aria-label="Close" @click="dismiss">
                        <i class="fa-solid fa-xmark text-lg" aria-hidden="true"></i>
                    </button>
                </div>
                <h2 id="confirm-action-title" class="mt-5 text-2xl font-bold text-slate-900 uppercase tracking-wide">{{ title }}</h2>
                <p v-if="message" class="mt-2 leading-7 text-slate-600">{{ message }}</p>
                <!--
                    At the wider size, this list is two columns. A label/value
                    pair across a 1024px card is a short label, a long gap and a
                    short value, and the eye has to travel the whole width to
                    pair them up — which is exactly what the extra room was
                    meant to buy back. (No backticks in here: the comment is
                    inside a JavaScript template literal, and one would end it.)
                -->
                <dl v-if="details.length" class="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-4" :class="wider ? 'grid gap-x-8 sm:grid-cols-2' : 'divide-y divide-slate-100'">
                    <div v-for="detail in details" :key="detail.label" class="flex items-start justify-between gap-4 py-2.5 text-sm" :class="wider ? 'border-b border-slate-100 last:border-b-0' : ''">
                        <dt class="shrink-0 font-semibold text-slate-500">{{ detail.label }}</dt>
                        <dd class="min-w-0 break-words text-right font-bold text-slate-900">{{ detail.value }}</dd>
                    </div>
                </dl>
                <div v-if="signatories.length" class="mt-4 max-h-64 overflow-y-auto rounded-xl border border-slate-200">
                    <table class="w-full text-left text-sm">
                        <thead class="sticky top-0 border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-2 font-semibold">Name</th>
                                <th class="px-4 py-2 font-semibold">Signature</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="group in signatureGroups" :key="group.label ?? 'ungrouped'">
                                <tr v-if="group.label" class="border-b border-slate-100 bg-slate-50/70">
                                    <td colspan="2" class="px-4 py-1.5 text-xs font-bold uppercase tracking-wide text-slate-500">{{ group.label }}</td>
                                </tr>
                                <tr v-for="signatory in group.rows" :key="(group.label ?? '') + signatory.name + signatory.role" class="border-b border-slate-100 last:border-0">
                                    <td class="px-4 py-2" :class="signatory.signed ? 'text-slate-800' : 'font-bold text-rose-600'">
                                        {{ signatory.name }}
                                        <span v-if="signatory.role" class="block text-xs font-normal" :class="signatory.signed ? 'text-slate-500' : 'text-rose-500'">{{ signatory.role }}</span>
                                    </td>
                                    <td class="px-4 py-2" :class="signatory.signed ? 'text-slate-600' : 'font-bold text-rose-600'">
                                        <span v-if="signatory.signed">{{ signatory.signed_at ? 'Signed ' + signedOn(signatory.signed_at) : 'Signed' }}</span>
                                        <span v-else>Not signed</span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div v-if="$slots.default" class="mt-4"><slot /></div>
                <div class="mt-7 flex flex-wrap justify-end gap-3">
                    <button v-if="cancelLabel !== null" type="button" class="rounded-lg border border-slate-300 px-5 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50" :disabled="processing" @click="$emit('close')">  {{ cancelLabel }}  </button>
                    <button type="button" class="rounded-lg px-5 py-3 text-sm font-bold text-white disabled:cursor-not-allowed disabled:opacity-60" :class="toneClasses.button" :disabled="processing || confirmDisabled" @click="$emit('confirm')">  {{ processing ? (progress !== null ? 'Uploading… ' + progress + '%' : 'Please Wait…') : confirmLabel }}  </button>
                </div>
            </section>
        </div>
    </Transition>
`,
});
