import { defineComponent, Transition } from 'vue';

const DURATION = 250;

/**
 * Animates its single v-if child open and closed (height + fade), for accordions.
 * Skips the animation when the user prefers reduced motion.
 */
export default defineComponent({
    name: 'CollapseTransition',
    components: { Transition },
    setup() {
        const reduced = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        const reset = (el) => { el.style.height = ''; el.style.opacity = ''; el.style.overflow = ''; el.style.transition = ''; };
        const enter = (el, done) => {
            if (reduced()) { done(); return; }
            const height = el.scrollHeight;
            el.style.overflow = 'hidden';
            el.style.height = '0px';
            el.style.opacity = '0';
            void el.offsetHeight;
            el.style.transition = `height ${DURATION}ms ease, opacity ${DURATION}ms ease`;
            el.style.height = `${height}px`;
            el.style.opacity = '1';
            setTimeout(done, DURATION);
        };
        const leave = (el, done) => {
            if (reduced()) { done(); return; }
            el.style.overflow = 'hidden';
            el.style.height = `${el.scrollHeight}px`;
            void el.offsetHeight;
            el.style.transition = `height ${DURATION}ms ease, opacity ${DURATION}ms ease`;
            el.style.height = '0px';
            el.style.opacity = '0';
            setTimeout(done, DURATION);
        };

        return { enter, leave, reset };
    },
    template: `
    <Transition :css="false" @enter="enter" @after-enter="reset" @leave="leave" @after-leave="reset">
        <slot />
    </Transition>
`,
});
