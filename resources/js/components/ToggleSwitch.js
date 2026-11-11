import { defineComponent } from 'vue';

/**
 * An on/off switch for a setting that applies the moment it is flipped, with no Save behind it.
 * `role="switch"` plus `aria-checked` is what makes a styled button announce itself as a switch.
 */
export default defineComponent({
    name: 'ToggleSwitch',
    props: {
        disabled: { type: Boolean, default: false },
        label: { type: String, default: '' },
        modelValue: { type: Boolean, default: false },
    },
    emits: ['update:modelValue'],
    template: `
    <button
        type="button"
        role="switch"
        :aria-checked="modelValue ? 'true' : 'false'"
        :aria-label="label || null"
        :disabled="disabled"
        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition focus:outline-none focus:ring-4 focus:ring-sky-100 disabled:cursor-not-allowed disabled:opacity-60"
        :class="modelValue ? 'bg-[#00aeef]' : 'bg-slate-300'"
        @click="$emit('update:modelValue', !modelValue)"
    >
        <!--
            The knob is bg-[#ffffff], not bg-white: the dark theme rewrites .bg-white into a dark
            card surface, which would hide the knob against the track.
        -->
        <span class="inline-block h-5 w-5 rounded-full bg-[#ffffff] shadow transition" :class="modelValue ? 'translate-x-5' : 'translate-x-0.5'"></span>
    </button>`,
});
