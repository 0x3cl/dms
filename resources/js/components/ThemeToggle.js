import { computed, defineComponent } from 'vue';
import { preferences } from '../preferences';

/**
 * Light/dark switch in the header, in the admin header. It writes the same per-device
 * preference the My Account → Preferences tab does, so the two always agree.
 *
 * The icon shows the theme it switches *to*, which is the convention people expect from a single
 * toggle: a moon while the page is light, a sun while it is dark.
 */
export default defineComponent({
    name: 'ThemeToggle',
    setup() {
        const isDark = computed(() => preferences.theme === 'dark');
        const toggle = () => {
            preferences.theme = isDark.value ? 'light' : 'dark';
        };

        return { isDark, toggle };
    },
    template: `
    <button
        type="button"
        class="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-[#07559e]"
        :aria-label="isDark ? 'Switch to light theme' : 'Switch to dark theme'"
        :title="isDark ? 'Switch to light theme' : 'Switch to dark theme'"
        @click="toggle"
    >
        <!--
            Both icons are always present and stacked, so the swap is a rotate-and-fade between
            them rather than one glyph being replaced by another with nothing to animate.
            On a browser that swaps instantly this is the only motion; where the page uses a
            View Transition, the cross-fade carries the icon across with everything else.
        -->
        <span class="relative flex h-4 w-4 items-center justify-center">
            <i class="fa-solid fa-moon absolute transition duration-300 ease-out" :class="isDark ? 'rotate-90 scale-0 opacity-0' : 'rotate-0 scale-100 opacity-100'" aria-hidden="true"></i>
            <i class="fa-solid fa-sun absolute transition duration-300 ease-out" :class="isDark ? 'rotate-0 scale-100 opacity-100' : '-rotate-90 scale-0 opacity-0'" aria-hidden="true"></i>
        </span>
    </button>`,
});
