import { router } from '@inertiajs/vue3';
import { defineComponent, onBeforeUnmount, ref, watch } from 'vue';
import Badge from './Badge';
import useCloseOnTutorial from '../composables/useCloseOnTutorial';

/**
 * The links handed out for an item, and who already holds access to it.
 *
 * **Links only.** Naming a person, division, section or role happens where
 * somebody is actually deciding who something is for — the New folder dialog
 * and the document upload form, both of which show the tags as they are made.
 * A second place to grant access, opened later from a panel, is how an item
 * ends up shared with people nobody remembers choosing. What is left here is
 * the half that has to be reachable from everywhere: seeing who holds access,
 * and taking it away.
 *
 * A link's two parameters are enforced on the server, not merely displayed:
 * `ShareLink::isUsable()` is re-evaluated on every redemption, and
 * AccessResolver re-checks the item's visibility each time too — so a link to
 * something later marked confidential stops working at that moment rather than
 * whenever somebody thinks to revoke it.
 *
 * A slide-in panel. There was no drawer component in this design system — the
 * only off-canvas panel was baked into AdminShell's mobile sidebar — so this
 * extracts the pattern rather than duplicating it inline: a fixed right-hand
 * sheet over a scrim, `translate-x-full` → `translate-x-0`, with Escape and a
 * scrim click to close.
 *
 * State is fetched when the drawer opens rather than shipped as page props.
 * The file manager is the page people sit on, and sending the whole directory —
 * every account, division, section and role — with each folder navigation is a
 * cost paid constantly for a panel opened rarely.
 */
export default defineComponent({
    name: 'ShareDrawer',
    components: { Badge },
    emits: ['close'],
    props: {
        open: { type: Boolean, default: false },
        /** { kind: 'file'|'folder', public_id, name } */
        item: { type: Object, default: null },
    },
    setup(props, { emit }) {
        const loading = ref(false);
        const state = ref(null);
        const error = ref('');

        const linkAbility = ref('view');
        const linkExpiresAt = ref('');
        const linkMaxViews = ref('');

        const close = () => emit('close');

        // A tour must never run behind an open drawer.
        useCloseOnTutorial(close);

        const load = async () => {
            if (!props.item) return;
            loading.value = true;
            error.value = '';
            try {
                const response = await fetch(
                    `/files/share-state?kind=${props.item.kind}&public_id=${props.item.public_id}`,
                    { headers: { Accept: 'application/json' }, credentials: 'same-origin' },
                );
                if (!response.ok) throw new Error('Unable to load sharing for this item.');
                state.value = await response.json();
            } catch (e) {
                error.value = e.message;
                state.value = null;
            } finally {
                loading.value = false;
            }
        };

        const onKeydown = (event) => {
            if (event.key === 'Escape' && props.open) close();
        };

        watch(() => props.open, (open) => {
            if (open) {
                document.addEventListener('keydown', onKeydown);
                load();
            } else {
                document.removeEventListener('keydown', onKeydown);
            }
        });

        onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));

        /*
         * These post through Inertia and then reload the panel, because the
         * server is the only thing that knows what the resulting grant
         * actually resolves to — an expiry may be refused, and a confidential
         * item's link is refused outright.
         */
        const revoke = (id) => router.delete(`/files/shares/${id}`, {
            preserveScroll: true, preserveState: true, onSuccess: load,
        });

        const createLink = () => router.post('/files/share-links', {
            kind: props.item.kind,
            public_id: props.item.public_id,
            ability: linkAbility.value,
            expires_at: linkExpiresAt.value || null,
            max_views: linkMaxViews.value || null,
        }, { preserveScroll: true, preserveState: true, onSuccess: load });

        const revokeLink = (id) => router.delete(`/files/share-links/${id}`, {
            preserveScroll: true, preserveState: true, onSuccess: load,
        });

        const copyLink = async (url) => {
            try { await navigator.clipboard.writeText(url); } catch { /* clipboard blocked; the URL is on screen */ }
        };

        const expiry = (value) => (value
            ? new Date(value).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
            : 'No expiry');

        return {
            close, copyLink, createLink, error, expiry, linkAbility, linkExpiresAt,
            linkMaxViews, loading, revoke, revokeLink, state,
        };
    },
    template: `
    <Transition
        enter-active-class="transition-opacity duration-200 ease-out" enter-from-class="opacity-0" enter-to-class="opacity-100"
        leave-active-class="transition-opacity duration-150 ease-in" leave-from-class="opacity-100" leave-to-class="opacity-0">
        <div v-if="open" class="fixed inset-0 z-40 bg-slate-950/50" @click="close"></div>
    </Transition>

    <Transition
        enter-active-class="transition-transform duration-300 ease-out" enter-from-class="translate-x-full" enter-to-class="translate-x-0"
        leave-active-class="transition-transform duration-200 ease-in" leave-from-class="translate-x-0" leave-to-class="translate-x-full">
        <aside v-if="open" class="fixed inset-y-0 right-0 z-50 flex w-full max-w-lg flex-col bg-white shadow-2xl" role="dialog" aria-modal="true" aria-label="Sharing">
            <header class="flex items-start justify-between gap-3 border-b border-slate-200 p-5">
                <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Sharing</p>
                    <h2 class="mt-1 truncate text-lg font-bold uppercase tracking-wide text-slate-900">{{ item?.name }}</h2>
                </div>
                <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100" aria-label="Close" @click="close">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </header>

            <div class="min-h-0 flex-1 overflow-y-auto p-5">
                <p v-if="loading" class="flex items-center gap-3 text-sm text-slate-500"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>Loading…</p>
                <p v-if="error" class="error">{{ error }}</p>

                <template v-if="state && !loading">
                    <section>
                        <h3 class="text-sm font-bold uppercase tracking-wide text-slate-700">Share links</h3>

                        <p v-if="!state.linkable" class="mt-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-700">
                            This item is confidential, so it cannot be shared through a link — not now, and not by a link made before it was classified. Only the people named on the item itself can reach it.
                        </p>

                        <template v-else>
                            <p class="mt-3 text-sm text-slate-600">Anyone holding the link can open this, with the access and for as long as you set here. Both are enforced on every use, not just shown.</p>

                            <div class="mt-3 space-y-3">
                                <label class="block">
                                    <span class="text-sm font-semibold text-slate-700">Access</span>
                                    <select v-model="linkAbility" class="field">
                                        <option value="view">View only — no copy leaves the server</option>
                                        <option value="download">View and download</option>
                                    </select>
                                </label>
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <label class="block">
                                        <span class="text-sm font-semibold text-slate-700">Expires</span>
                                        <input v-model="linkExpiresAt" type="datetime-local" class="field" />
                                    </label>
                                    <label class="block">
                                        <span class="text-sm font-semibold text-slate-700">Max opens</span>
                                        <input v-model="linkMaxViews" type="number" min="1" class="field" placeholder="Unlimited" />
                                    </label>
                                </div>
                                <button type="button" class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50" @click="createLink">
                                    <i class="fa-solid fa-link" aria-hidden="true"></i>Create link
                                </button>
                            </div>

                            <ul v-if="state.links.length" class="mt-4 space-y-2">
                                <li v-for="link in state.links" :key="link.id" class="rounded-xl border border-slate-200 p-3">
                                    <p class="break-all font-mono text-xs text-slate-600">{{ link.url }}</p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{ link.ability_label }} · {{ expiry(link.expires_at) }}
                                        <span v-if="link.max_views"> · {{ link.view_count }}/{{ link.max_views }} opens</span>
                                        <span v-else> · {{ link.view_count }} opens</span>
                                    </p>
                                    <Badge v-if="!link.usable" tone="slate" class="mt-1">Expired or used up</Badge>
                                    <div class="mt-2 flex gap-2">
                                        <button type="button" class="rounded-lg px-2 py-1 text-sm font-bold text-[#07559e] transition hover:bg-sky-100" @click="copyLink(link.url)">Copy</button>
                                        <button type="button" class="rounded-lg px-2 py-1 text-sm font-bold text-rose-600 transition hover:bg-rose-50" @click="revokeLink(link.id)">Revoke</button>
                                    </div>
                                </li>
                            </ul>
                        </template>
                    </section>

                    <!--
                        Who already holds access. Read-only on purpose: tags are
                        made where the thing is created, and this is where they
                        are seen and taken away.
                    -->
                    <section class="mt-8 border-t border-slate-200 pt-6">
                        <h3 class="text-sm font-bold uppercase tracking-wide text-slate-700">People with access</h3>
                        <ul v-if="state.shares.length" class="mt-3 space-y-2">
                            <li v-for="row in state.shares" :key="row.id" class="flex items-start justify-between gap-3 rounded-xl border border-slate-200 p-3">
                                <div class="min-w-0">
                                    <p class="truncate font-bold text-slate-800">{{ row.grantee }}</p>
                                    <p class="text-xs text-slate-500">{{ row.grantee_type_label }} · {{ row.ability_label }} · {{ expiry(row.expires_at) }}</p>
                                    <p v-if="row.granted_by" class="text-xs text-slate-400">Tagged by {{ row.granted_by }}</p>
                                    <Badge v-if="!row.active" tone="slate" class="mt-1">No longer active</Badge>
                                </div>
                                <button type="button" class="shrink-0 rounded-lg px-2 py-1 text-sm font-bold text-rose-600 transition hover:bg-rose-50" @click="revoke(row.id)">Revoke</button>
                            </li>
                        </ul>
                        <p v-else class="mt-3 text-sm text-slate-500">Nobody has been tagged on this. People are named when a folder is created or a document is uploaded.</p>
                    </section>
                </template>
            </div>
        </aside>
    </Transition>
`,
});
