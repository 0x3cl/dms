import { Link, router, usePage } from '@inertiajs/vue3';
import { listen } from '../echo';
import { computed, defineComponent, onBeforeUnmount, onMounted, ref } from 'vue';
import UserAvatar from './UserAvatar';

/**
 * The header bell. Reads the `notifications` prop shared by
 * HandleInertiaRequests, so no page has to pass it.
 *
 * Both actions are plain <Link> navigations to the existing notification
 * routes — `notifications.read` marks one read and forwards to the document it
 * is about, which is why following the link is the whole interaction.
 *
 * **A row says who and what kind before it says anything else.** It was a
 * sentence and a timestamp, so a document shared with you, somebody asking to
 * open one of yours and an answer to something you asked for were three
 * different events rendered identically — a reader with nine of them had to
 * read all nine to find whether any needed an answer. Each row now carries the
 * actor's face with the kind badged on it, the same shape the audit trail
 * uses, and both come from `NotificationKind` on the server rather than from
 * a list of kinds written into this template.
 *
 * **The message is clamped, not wrapped.** A document title is free text and
 * can be a paragraph; left to wrap, one notification filled the panel and
 * pushed the rest out of sight. Two lines and an ellipsis, with the full
 * sentence in the `title` attribute, and the list itself scrolls.
 */
export default defineComponent({
    name: 'NotificationBell',
    components: { Link, UserAvatar },
    setup() {
        const page = usePage();
        const feed = computed(() => page.props?.notifications ?? { items: [], unread: 0 });
        const unread = computed(() => feed.value.unread ?? 0);
        const items = computed(() => feed.value.items ?? []);

        /*
         * Live, when a websocket is available.
         *
         * Laravel broadcasts a notification on the recipient's own private
         * channel, so this is a reload of two lazy props rather than a splice:
         * the server decides what the feed contains and what the unread count
         * is, and a client-side increment would drift the moment a
         * notification was read in another tab.
         *
         * `listen` returns a no-op unsubscribe when there is no socket, so
         * nothing here has to branch on whether realtime is configured.
         */
        const stopListening = listen(
            `users.${page.props?.auth?.user?.id}`,
            'Illuminate\\Notifications\\Events\\BroadcastNotificationCreated',
            () => router.reload({ only: ['notifications', 'accessRequests'] }),
        );
        onBeforeUnmount(stopListening);

        const open = ref(false);
        const root = ref(null);
        const closeOnOutsideClick = (event) => {
            if (!root.value?.contains(event.target)) open.value = false;
        };
        onMounted(() => document.addEventListener('click', closeOnOutsideClick));
        onBeforeUnmount(() => document.removeEventListener('click', closeOnOutsideClick));

        return { items, open, root, unread };
    },
    template: `
    <div class="relative" ref="root">
        <button
            type="button"
            class="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-[#07559e]"
            :aria-label="unread > 0 ? unread + ' unread notifications' : 'Notifications'"
            @click="open = !open">
            <i class="fa-solid fa-bell" aria-hidden="true"></i>
            <span v-if="unread > 0" class="absolute right-1 top-1 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-600 px-1 text-[10px] font-bold leading-none text-white">{{ unread > 9 ? '9+' : unread }}</span>
        </button>

        <!-- Wider than the button it hangs from, and capped at the viewport:
             these rows carry a face, a kind and somebody's free-text document
             title, which 20rem turned into four lines of wrapped sentence. -->
        <div v-show="open" class="absolute right-0 z-30 mt-2 w-[min(26rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">
            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                <h4 class="text-xs font-bold uppercase tracking-wide text-[#07559e]">Notifications</h4>
                <Link v-if="unread > 0" href="/notifications/mark-all-read" class="text-xs font-semibold text-slate-500 hover:text-[#07559e]" @click="open = false">Mark all read</Link>
            </div>
            <ul class="max-h-[26rem] overflow-y-auto">
                <li v-for="item in items" :key="item.id" class="border-b border-slate-100 last:border-b-0">
                    <Link :href="'/notifications/' + item.id + '/read'" class="flex gap-3 px-4 py-3 transition hover:bg-sky-50" :class="item.read ? '' : 'bg-sky-50/60'" @click="open = false">
                        <!-- The face with the kind badged on it, exactly as the
                             audit trail draws an actor: one glance answers both
                             "who" and "what sort of thing". A row with no actor
                             — every row written before the face was recorded —
                             shows the badge alone rather than initials derived
                             from a sentence. -->
                        <span class="relative mt-0.5 shrink-0">
                            <UserAvatar v-if="item.actor" :person="item.actor" class="!h-10 !w-10 !text-xs" />
                            <span v-else class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-400"><i class="fa-solid fa-bell" aria-hidden="true"></i></span>
                            <span class="absolute -bottom-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full bg-[#ffffff] text-[10px] shadow" :class="item.tone">
                                <i :class="item.icon" aria-hidden="true"></i>
                            </span>
                        </span>
                        <span class="min-w-0 flex-1">
                            <!-- Who, then what kind. The name is the thing a
                                 reader recognises and the sentence buries it
                                 mid-clause, so it leads the row; it comes from
                                 the notification's own stored name, never from
                                 reading the sentence. -->
                            <span v-if="item.actor_name || item.kind_label" class="flex items-baseline gap-1.5 text-[11px] font-bold uppercase tracking-wide">
                                <span v-if="item.actor_name" class="truncate text-slate-600">{{ item.actor_name }}</span>
                                <span v-if="item.actor_name && item.kind_label" class="text-slate-300" aria-hidden="true">·</span>
                                <span v-if="item.kind_label" class="shrink-0 text-slate-400">{{ item.kind_label }}</span>
                            </span>
                            <!-- Two lines and an ellipsis. A document title is
                                 free text, and one long enough to wrap four
                                 times pushed every other notification out of
                                 the panel. -->
                            <span class="mt-0.5 block overflow-hidden text-ellipsis text-sm leading-snug [display:-webkit-box] [-webkit-box-orient:vertical] [-webkit-line-clamp:2]" :class="item.read ? 'text-slate-500' : 'font-semibold text-slate-800'" :title="item.message">{{ item.message }}</span>
                            <span class="mt-1 block text-xs text-slate-400">{{ item.created_at }}</span>
                        </span>
                        <!-- Unread, stated rather than only implied by the
                             weight of the text: a dot is readable at a glance
                             down the edge of the list. -->
                        <span v-if="!item.read" class="mt-2 h-2 w-2 shrink-0 rounded-full bg-[#00aeef]" aria-label="Unread"></span>
                    </Link>
                </li>
                <li v-if="items.length === 0" class="px-4 py-8 text-center text-sm text-slate-500">Nothing to catch up on.</li>
            </ul>
        </div>
    </div>
`,
});
