import { computed, defineComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const formatters = {
    number: (value, decimals) => value.toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals }),
    'compact-money': (value) => `₱${value.toLocaleString(undefined, { notation: 'compact', maximumFractionDigits: 1 })}`,
};

/**
 * Counts a number up from zero to `value` when it first appears and again
 * whenever `value` changes, never dipping below zero on the way. Skips the animation
 * for people who prefer reduced motion.
 */
export default defineComponent({
    name: 'CountUp',
    props: {
        value: { type: Number, default: 0 },
        format: { type: String, default: 'number' },
        duration: { type: Number, default: 900 },
    },
    setup(props) {
        const current = ref(0);
        let frame = null;
        const decimals = computed(() => (Number.isInteger(props.value) ? 0 : Math.min(2, (String(props.value).split('.')[1] ?? '').length)));
        const display = computed(() => (formatters[props.format] ?? formatters.number)(current.value, decimals.value));

        const animateTo = (target) => {
            cancelAnimationFrame(frame);
            if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches || props.duration <= 0) {
                current.value = target;

                return;
            }
            const from = 0;
            const start = performance.now();
            const step = (now) => {
                const progress = Math.min((now - start) / props.duration, 1);
                const value = from + (target - from) * (1 - (1 - progress) ** 3);
                current.value = target >= 0 ? Math.max(0, value) : value;
                if (progress < 1) { frame = requestAnimationFrame(step); } else { current.value = target; }
            };
            frame = requestAnimationFrame(step);
        };

        onMounted(() => animateTo(props.value));
        watch(() => props.value, animateTo);
        onBeforeUnmount(() => cancelAnimationFrame(frame));

        return { display };
    },
    template: '<span class="tabular-nums">{{ display }}</span>',
});
