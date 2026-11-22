import { usePage } from '@inertiajs/vue3';
import { defineComponent, onBeforeUnmount, reactive, watch } from 'vue';

/**
 * Transient confirmations, stacked at the top right.
 *
 * Flash messages used to open a `FeedbackModal` — a centred card over a scrim
 * that had to be dismissed before the page could be used again. For a failure
 * that is right: a refusal the reader scrolls past is a refusal they will act
 * as though never happened, which is why `error` still opens the modal. For a
 * success it is not: "File uploaded." is the outcome the person just asked for,
 * and blocking the screen to announce it interrupts the work it completed.
 *
 * Three things about the shape are deliberate.
 *
 * **It is mounted once, in `app.js`, outside the Inertia page.** A toast is
 * most often raised by an action that then navigates — upload, save, delete —
 * so a stack living inside the page component would be unmounted by the very
 * visit carrying the message it was about to show. The navbar and footer sit
 * outside the page for the same reason.
 *
 * **The store is module-level, so anything can raise one** without a prop
 * chain: `import { pushToast } from '…/ToastStack'`. That matters for the
 * outcomes that never reach a flash at all — a clipboard copy, a refused
 * revoke answered with a 422 — which previously had nowhere to be said.
 *
 * **The flash watcher keys on the `flash` object, not the message string.**
 * Every Inertia response builds a fresh props object, so saving the same form
 * twice produces two toasts. Watching the string meant the second save was
 * silent, because the value had not changed — a bug the modal shared and that
 * a toast makes far easier to hit.
 *
 * **It sits above every layer in the application, and that is load-bearing
 * rather than a styling choice.** It was `z-[60]`, which is *below* the
 * full-screen preview at `z-[70]` and the dialogs lifted over that at
 * `z-[80]`. The toast was still raised, still announced to a screen reader and
 * still timed out — it was simply painted behind the scrim, so posting an
 * empty comment from the preview, copying a link there, or having a revoke
 * refused with a 422 all looked like a button that did nothing. A toast is the
 * one surface that is never *inside* anything: it reports the outcome of what
 * the person just did, and whatever is open when they do it is exactly when
 * they need to be told. So it is raised to `z-[100]` — above the highest layer
 * anything else may claim — rather than fixed per caller the way
 * `ConfirmActionModal`'s `layer-class` is, because a global surface that each
 * caller has to remember to lift is one that will be invisible wherever
 * somebody forgets.
 *
 * The container stays `pointer-events-none` with only the toasts themselves
 * accepting clicks, so sitting on top of a dialog costs that dialog nothing:
 * the corner a toast occupies is still clickable through to whatever is
 * underneath it, except on the toast's own dismiss button.
 */

let nextId = 0;

const state = reactive({ toasts: [] });

const tones = {
    success: { icon: 'fa-solid fa-circle-check', ring: 'border-emerald-200', badge: 'bg-emerald-100 text-emerald-600' },
    info: { icon: 'fa-solid fa-circle-info', ring: 'border-sky-200', badge: 'bg-sky-100 text-[#07559e]' },
    warning: { icon: 'fa-solid fa-triangle-exclamation', ring: 'border-amber-200', badge: 'bg-amber-100 text-amber-600' },
    error: { icon: 'fa-solid fa-circle-exclamation', ring: 'border-rose-200', badge: 'bg-rose-100 text-rose-600' },
};

/** How long a toast stays up. An error lingers; it is the one worth re-reading. */
const lifetimes = { success: 4500, info: 5000, warning: 6500, error: 8000 };

export const dismissToast = (id) => {
    const at = state.toasts.findIndex((toast) => toast.id === id);

    if (at === -1) return;

    clearTimeout(state.toasts[at].timer);
    state.toasts.splice(at, 1);
};

/**
 * Raise a toast. `message` is the only required part — a title is a label on
 * the tone and most callers do not need one.
 *
 * @param {{ message: string, title?: string, tone?: 'success'|'info'|'warning'|'error', timeout?: number }} toast
 */
export const pushToast = ({ message, title = '', tone = 'success', timeout = null } = {}) => {
    if (!message) return null;

    const id = ++nextId;
    const life = timeout ?? lifetimes[tone] ?? lifetimes.info;

    state.toasts.push({
        id,
        message,
        title,
        tone: tones[tone] ? tone : 'info',
        timer: life > 0 ? setTimeout(() => dismissToast(id), life) : null,
    });

    // Oldest first out, so a burst of them cannot bury the page.
    while (state.toasts.length > 4) dismissToast(state.toasts[0].id);

    return id;
};

export default defineComponent({
    name: 'ToastStack',
    setup() {
        const page = usePage();

        watch(
            () => page.props.flash,
            (flash) => pushToast({ message: flash?.success, title: 'Done', tone: 'success' }),
            { immediate: true },
        );

        onBeforeUnmount(() => state.toasts.forEach((toast) => clearTimeout(toast.timer)));

        return { state, tones, dismissToast };
    },
    template: `
    <div class="pointer-events-none fixed inset-x-0 top-0 z-[100] flex flex-col items-end gap-3 p-4 sm:left-auto sm:right-0 sm:w-[26rem]" role="status" aria-live="polite">
        <TransitionGroup
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="translate-x-6 opacity-0"
            enter-to-class="translate-x-0 opacity-100"
            leave-active-class="transition duration-200 ease-in absolute"
            leave-from-class="translate-x-0 opacity-100"
            leave-to-class="translate-x-6 opacity-0"
            move-class="transition duration-200"
        >
            <div
                v-for="toast in state.toasts"
                :key="toast.id"
                class="pointer-events-auto flex w-full items-start gap-3 rounded-2xl border bg-white p-4 shadow-lg"
                :class="tones[toast.tone].ring"
            >
                <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm" :class="tones[toast.tone].badge" aria-hidden="true">
                    <i :class="tones[toast.tone].icon"></i>
                </span>
                <span class="min-w-0 flex-1">
                    <span v-if="toast.title" class="block text-xs font-bold uppercase tracking-wide text-slate-900">{{ toast.title }}</span>
                    <span class="mt-0.5 block whitespace-pre-line break-words text-sm leading-6 text-slate-600">{{ toast.message }}</span>
                </span>
                <button type="button" class="-m-1 shrink-0 rounded-lg p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" title="Dismiss" aria-label="Dismiss" @click="dismissToast(toast.id)">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>
        </TransitionGroup>
    </div>
`,
});
