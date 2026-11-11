import { Link, usePage } from '@inertiajs/vue3';
import { computed, defineComponent, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * The header bell. Reads the `notifications` prop shared by
 * HandleInertiaRequests, so no page has to pass it.
 *
 * Both actions are plain <Link> navigations to the existing notification
 * routes — `notifications.read` marks one read and forwards to the document it
 * is about, which is why following the link is the whole interaction.
 */
export default defineComponent({
    name: 'NotificationBell',
    components: { Link },
    setup() {
        const page = usePage();
        const feed = computed(() => page.props?.notifications ?? { items: [], unread: 0 });
        const unread = computed(() => feed.value.unread ?? 0);
        const items = computed(() => feed.value.items ?? []);

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

        <div v-show="open" class="absolute right-0 z-30 mt-2 w-80 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">
            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                <h4 class="text-xs font-bold uppercase tracking-wide text-[#07559e]">Notifications</h4>
                <Link v-if="unread > 0" href="/notifications/mark-all-read" class="text-xs font-semibold text-slate-500 hover:text-[#07559e]" @click="open = false">Mark all read</Link>
            </div>
            <ul class="max-h-80 overflow-y-auto">
                <li v-for="item in items" :key="item.id" class="border-b border-slate-100 last:border-b-0">
                    <Link :href="'/notifications/' + item.id + '/read'" class="block px-4 py-3 transition hover:bg-sky-50" @click="open = false">
                        <p class="text-sm leading-snug" :class="item.read ? 'text-slate-500' : 'font-semibold text-slate-800'">{{ item.message }}</p>
                        <p class="mt-1 text-xs text-slate-400">{{ item.created_at }}</p>
                    </Link>
                </li>
                <li v-if="items.length === 0" class="px-4 py-8 text-center text-sm text-slate-500">Nothing to catch up on.</li>
            </ul>
        </div>
    </div>
`,
});
