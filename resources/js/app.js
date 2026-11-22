import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h, nextTick } from 'vue';
import AppFooter from './components/AppFooter';
import AppNavbar from './components/AppNavbar';
import NavigationSkeleton from './components/NavigationSkeleton';
import ToastStack from './components/ToastStack';
import './echo';
import './preferences';

/*
 * Every page and component in this app is a plain `.js` file exporting
 * defineComponent({ template: `...` }) — there are no .vue SFCs, and
 * vite.config.js aliases `vue` to the runtime+compiler build to make those
 * inline templates compile. See AGENTS.md before changing either.
 */

/**
 * Validation failures scroll to the field that caused them. `border-rose-500`
 * is the marker every form in the app puts on an invalid input, so this hook
 * is the reason that exact class name matters.
 */
router.on('error', () => {
    nextTick(() => {
        requestAnimationFrame(() => {
            const firstInvalidField = document.querySelector(
                'input.border-rose-500, select.border-rose-500, textarea.border-rose-500, [data-searchable-select] button.border-rose-500',
            );

            if (!firstInvalidField) {
                return;
            }

            firstInvalidField.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstInvalidField.focus({ preventScroll: true });
        });
    });
});

createInertiaApp({
    resolve: (name) => resolvePageComponent(
        `./pages/${name}.js`,
        import.meta.glob('./pages/**/*.js'),
    ),
    setup({ el, App, props, plugin }) {
        createApp({
            // The navbar and footer live outside the Inertia page so they persist
            // across every visit. A page's own <main> must carry `flex-1` itself,
            // because Inertia pages render <Head> and <main> as sibling roots and
            // Vue cannot fall a class through onto a fragment.
            // ToastStack sits out here with them, and for a sharper reason: a
            // toast is usually raised by an action that then navigates, so a
            // stack inside the page would be unmounted by the very visit
            // carrying the message it was about to show.
            render: () => h('div', { class: 'flex min-h-screen flex-col' }, [
                h(AppNavbar),
                h('div', { class: 'relative flex flex-1 flex-col' }, [h(App, props), h(NavigationSkeleton)]),
                h(AppFooter),
                h(ToastStack),
            ]),
        })
            .use(plugin)
            .mount(el);
    },
});
