import { defineComponent } from 'vue';

/**
 * Shared card container matching the dominant admin-screen pattern
 * (border, white background, responsive padding, subtle shadow).
 * Pass extra classes on the component usage site — Vue merges them
 * onto this single root element automatically.
 */
export default defineComponent({
    name: 'Card',
    template: `
        <section class="border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <slot />
        </section>
    `,
});
