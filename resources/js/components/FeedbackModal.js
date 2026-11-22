import { Link } from '@inertiajs/vue3';
import { computed, defineComponent } from 'vue';

const tones = {
    success: 'bg-emerald-100 text-emerald-600',
    info: 'bg-sky-100 text-[#07559e]',
    warning: 'bg-amber-100 text-amber-600',
    error: 'bg-rose-100 text-rose-600',
};

export default defineComponent({
    name: 'FeedbackModal',
    components: { Link },
    props: {
        actionHref: { type: String, default: '' },
        actionIcon: { type: String, default: 'fa-solid fa-arrow-right' },
        actionLabel: { type: String, default: '' },
        closeLabel: { type: String, default: 'Close' },
        icon: { type: String, default: 'fa-solid fa-circle-check' },
        message: { type: String, required: true },
        open: { type: Boolean, default: false },
        title: { type: String, required: true },
        tone: { type: String, default: 'success' },
    },
    emits: ['action', 'close'],
    setup(props, { emit }) {
        const toneClass = computed(() => tones[props.tone] ?? tones.info);

        return { toneClass };
    },
    template: `
    <Transition appear enter-active-class="transition-opacity duration-300 ease-out" enter-from-class="opacity-0" enter-to-class="opacity-100" leave-active-class="transition-opacity duration-200 ease-in" leave-from-class="opacity-100" leave-to-class="opacity-0">
        <!--
            z-[90], not z-50. This is the application's one blocking failure
            surface and it is rendered from AdminShell, inside the page — so
            at z-50 it opened *behind* the full-screen preview (z-[70]) and
            the dialogs lifted over it (z-[80]). A refusal raised while the
            preview was open was painted under the scrim and never read,
            which is the worst thing that can happen to the surface whose
            entire job is to be impossible to miss. Below ToastStack's
            z-[100] deliberately: a transient, non-blocking message may sit
            over a modal, never under it.
        -->
        <div v-if="open" class="fixed inset-0 z-[90] bg-slate-950/45"></div>
    </Transition>
    <Transition appear enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100" leave-active-class="transition duration-150 ease-in" leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 scale-95">
        <div v-if="open" class="pointer-events-none fixed inset-0 z-[90] flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="feedback-modal-title">
            <section class="pointer-events-auto w-full max-w-2xl rounded-3xl bg-white p-8 text-center shadow-2xl sm:p-10">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full text-3xl" :class="toneClass" aria-hidden="true">
                    <i :class="icon"></i>
                </div>
                <h2 id="feedback-modal-title" class="mt-6 text-2xl font-bold text-slate-900 sm:text-3xl uppercase tracking-wide">{{ title }}</h2>
                <p class="mt-3 whitespace-pre-line break-words text-base leading-7 text-slate-600">{{ message }}</p>
                <Link v-if="actionHref && actionLabel" :href="actionHref" class="mt-8 inline-flex items-center gap-2 rounded-xl bg-[#00aeef] px-6 py-3 text-sm font-bold uppercase tracking-wide text-white transition hover:bg-[#008dcc]"><i :class="actionIcon" aria-hidden="true"></i>{{ actionLabel }}</Link>
                <!-- A label with no href is an action this page handles, not a
                     place to go: the refusal modal uses it to offer "Request
                     access" without navigating away from the explanation. -->
                <div v-else-if="actionLabel" class="mt-8 flex flex-wrap items-center justify-center gap-3">
                    <button type="button" class="inline-flex items-center gap-2 rounded-xl bg-[#00aeef] px-6 py-3 text-sm font-bold uppercase tracking-wide text-white transition hover:bg-[#008dcc]" @click="$emit('action')"><i :class="actionIcon" aria-hidden="true"></i>{{ actionLabel }}</button>
                    <button type="button" class="inline-flex items-center gap-2 rounded-xl border border-slate-300 px-6 py-3 text-sm font-bold uppercase tracking-wide text-slate-700 transition hover:bg-slate-50" @click="$emit('close')">{{ closeLabel }}</button>
                </div>
                <button v-else type="button" class="mt-8 inline-flex items-center gap-2 rounded-xl bg-[#00aeef] px-6 py-3 text-sm font-bold uppercase tracking-wide text-white transition hover:bg-[#008dcc]" @click="$emit('close')">{{ closeLabel }}</button>
            </section>
        </div>
    </Transition>
`,
});
