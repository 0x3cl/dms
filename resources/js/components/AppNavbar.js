import { Link, usePage } from '@inertiajs/vue3';
import { computed, defineComponent, onBeforeUnmount, onMounted, ref } from 'vue';
import { organization } from '../organization';
import { connection } from '../echo';

/**
 * The site header, rendered outside the Inertia page so it persists across
 * visits (see resources/js/app.js).
 *
 * Its copy is hardcoded in `../organization.js` rather than loaded from the
 * database: DocTrack serves one office, so there is nothing to edit per
 * deployment and no reason to carry PROCMIS's Pages module across for it.
 *
 * It measures itself and publishes its height as `--app-navbar-h` on <html>,
 * which is what AdminShell's sticky header and fixed sidebar position against.
 */
export default defineComponent({
    name: 'AppNavbar',
    components: { Link },
    setup() {
        const page = usePage();
        const isAuthenticated = computed(() => Boolean(page.props?.auth?.user));

        const navbarEl = ref(null);
        const updateNavbarHeight = () => {
            if (navbarEl.value) {
                document.documentElement.style.setProperty('--app-navbar-h', `${navbarEl.value.offsetHeight}px`);
            }
        };
        let navbarResizeObserver = null;
        onMounted(() => {
            updateNavbarHeight();
            navbarResizeObserver = new ResizeObserver(updateNavbarHeight);
            if (navbarEl.value) navbarResizeObserver.observe(navbarEl.value);
        });
        onBeforeUnmount(() => navbarResizeObserver?.disconnect());

        /*
         * The wordmark doubles as the realtime lamp: green while the
         * websocket is connected, red while it is not.
         *
         * It reads the *connection*, never the configured key — a page that
         * said "live" because a key was compiled into the bundle would be
         * reporting its own configuration back to the reader, and the
         * ordinary failure is precisely a key with no `reverb:start`
         * listening behind it.
         *
         * A deployment with no key at all is left white. Realtime is
         * optional by design — every page works from its Inertia props
         * without it — so painting that red would be calling a deliberate
         * choice a fault, on every page, forever.
         */
        const live = computed(() => connection.value === 'live');
        const realtimeOff = computed(() => connection.value === 'off');

        const connectionLabel = computed(() => ({
            live: 'Live updates are connected.',
            down: 'Live updates are not connected — pages still work, they just do not refresh on their own.',
            off: 'Live updates are not configured on this deployment.',
        })[connection.value]);

        return { connectionLabel, isAuthenticated, live, navbarEl, org: organization, realtimeOff };
    },
    template: `
    <header ref="navbarEl" class="border-t-7 border-slate-800 bg-linear-to-r from-[#0d5ba6] via-[#07559e] to-[#043d78] py-4 text-white print:hidden" :class="isAuthenticated ? 'sticky top-0 z-30' : 'relative z-20 shadow-lg'">
        <nav class="mx-auto flex min-h-20 max-w-[1800px] flex-col items-center justify-between gap-5 px-5 py-3 sm:px-8 md:flex-row lg:px-12" aria-label="Site header">
            <Link :href="isAuthenticated ? '/dashboard' : '/'" class="flex min-w-0 items-center gap-3" aria-label="Home">
                <img :src="org.logo" :alt="org.logoAlt" class="h-14 w-14 shrink-0 object-contain" />
                <div class="min-w-0 leading-tight">
                    <p class="text-[11px] font-medium text-sky-100 sm:text-xs">{{ org.line1 }}</p>
                    <p class="text-[11px] font-medium text-sky-100 sm:text-xs">{{ org.line2 }}</p>
                    <p class="mt-1 text-sm font-bold tracking-tight sm:text-base">{{ org.line3 }}</p>
                </div>
            </Link>
            <div class="border-t border-white/20 pt-4 text-center md:border-t-0 md:border-l md:pl-8 md:pt-0 md:text-right">
                <p class="text-xs font-medium tracking-[0.18em] text-sky-200 uppercase">{{ org.label }}</p>
                <!--
                    The product name, coloured by the websocket's state, with
                    a dot beside it: colour alone is not a signal somebody
                    colour-blind can read, so the tooltip carries the
                    sentence and aria-label says it rather than leaving a
                    screen reader with a bare name whose meaning is in a CSS
                    class.
                -->
                <p class="mt-1 flex items-center justify-center gap-2 text-lg font-semibold tracking-tight sm:text-xl md:justify-end"
                    :title="connectionLabel"
                    :class="realtimeOff ? 'text-white' : (live ? 'text-emerald-300' : 'text-rose-300')">
                    <span>{{ org.title }}</span>
                    <span v-if="!realtimeOff"
                        class="inline-block h-2 w-2 shrink-0 rounded-full"
                        :class="live ? 'bg-emerald-400' : 'bg-rose-400'"
                        role="img"
                        :aria-label="connectionLabel"></span>
                </p>
            </div>
        </nav>
    </header>
`,
});
