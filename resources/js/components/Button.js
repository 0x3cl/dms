import { Link } from '@inertiajs/vue3';
import { defineComponent } from 'vue';

const VARIANT_CLASSES = {
    primary: 'bg-[#00aeef] text-white hover:bg-[#009bd8]',
    secondary: 'border border-slate-300 text-slate-700 hover:bg-slate-50',
    danger: 'border border-rose-300 text-rose-600 hover:bg-rose-50',
};

/**
 * Shared button, standardized on rounded-lg and the padding already used by
 * IndexControls's Add button. Renders as an Inertia <Link> when `href`
 * is passed, otherwise as a native <button>.
 */
export default defineComponent({
    name: 'Button',
    components: { Link },
    props: {
        href: { type: String, default: '' },
        type: { type: String, default: 'button' },
        variant: { type: String, default: 'primary' },
    },
    computed: {
        variantClass() {
            return VARIANT_CLASSES[this.variant] ?? VARIANT_CLASSES.primary;
        },
    },
    // Branches explicitly between <Link> and <button> instead of a dynamic
    // <component :is="..."> — Vue's dynamic-component string resolution
    // falls back to a PascalCase lookup against registered components (so
    // ':is="\'button\'"' can, in the wrong context, resolve to this very
    // component's own registered name, "Button", instead of the native
    // element). Only one branch of a v-if/v-else pair ever renders, so this
    // still satisfies the single-root-element rule.
    template: `
    <Link v-if="href" :href="href" class="inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-bold transition" :class="variantClass">
        
        <slot />
    
    </Link>
    <button v-else :type="type" class="inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-bold transition" :class="variantClass">
        
        <slot />
    
    </button>
`,
});
