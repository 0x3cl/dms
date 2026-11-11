import { usePage } from '@inertiajs/vue3';
import { computed, defineComponent, onBeforeUnmount, onMounted, ref } from 'vue';
import { isTutorialAvailable, openTutorial, tutorials } from '../tutorials';

/**
 * The lightbulb in the header, and the list of tours behind it.
 *
 * Tours are filtered against the shared `auth.permissions` prop, so nobody is
 * offered a walkthrough of a page they cannot open. Those whose `paths` match
 * the current URL are grouped first under "On this page", because the tour
 * someone wants is almost always the one for what they are looking at.
 *
 * The button hides itself entirely when no tour is available, rather than
 * opening an empty menu.
 */
export default defineComponent({
    name: 'TutorialMenu',
    setup() {
        const page = usePage();
        const open = ref(false);
        const root = ref(null);

        const available = computed(() => tutorials.filter(
            (tutorial) => isTutorialAvailable(tutorial, page.props.auth),
        ));

        const groups = computed(() => {
            const path = window.location.pathname;
            const here = [];
            const byModule = new Map();

            available.value.forEach((tutorial) => {
                const matches = (tutorial.paths ?? []).some((prefix) => path.startsWith(prefix));

                if (matches) {
                    here.push(tutorial);

                    return;
                }

                const module = tutorial.module ?? 'Other';
                byModule.set(module, [...(byModule.get(module) ?? []), tutorial]);
            });

            return [
                ...(here.length ? [{ label: 'On this page', items: here }] : []),
                ...[...byModule.entries()].map(([label, items]) => ({ label, items })),
            ];
        });

        const start = (tutorial) => {
            open.value = false;
            openTutorial(tutorial);
        };

        const closeOnOutside = (event) => {
            if (!root.value?.contains(event.target)) open.value = false;
        };

        onMounted(() => document.addEventListener('click', closeOnOutside));
        onBeforeUnmount(() => document.removeEventListener('click', closeOnOutside));

        return { available, groups, open, root, start };
    },
    template: `
    <div v-if="available.length" ref="root" class="relative">
        <button
            type="button"
            data-tour="tutorials"
            class="inline-flex h-10 w-10 items-center justify-center rounded-full text-slate-500 transition hover:bg-sky-100 hover:text-[#07559e]"
            :aria-expanded="open"
            aria-haspopup="true"
            title="Tutorials"
            @click.stop="open = !open">
            <span class="sr-only">Tutorials</span>
            <i class="fa-solid fa-lightbulb text-lg" aria-hidden="true"></i>
        </button>

        <Transition
            enter-active-class="transition duration-150 ease-out" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100"
            leave-active-class="transition duration-100 ease-in" leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 scale-95">
            <div v-if="open" class="absolute right-0 z-50 mt-2 w-80 origin-top-right overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
                <div class="border-b border-slate-100 px-4 py-3">
                    <p class="text-sm font-bold uppercase tracking-wide text-slate-800">Tutorials</p>
                    <p class="mt-0.5 text-xs text-slate-500">A guided walkthrough, right here on the page.</p>
                </div>
                <div class="max-h-96 overflow-y-auto py-1">
                    <div v-for="group in groups" :key="group.label">
                        <p class="px-4 pt-3 pb-1 text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">{{ group.label }}</p>
                        <button
                            v-for="tutorial in group.items"
                            :key="tutorial.key"
                            type="button"
                            class="flex w-full items-start gap-3 px-4 py-2.5 text-left transition hover:bg-sky-50"
                            @click="start(tutorial)">
                            <i :class="tutorial.icon" class="mt-0.5 w-4 shrink-0 text-center text-slate-400" aria-hidden="true"></i>
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold text-slate-800">{{ tutorial.label }}</span>
                                <span v-if="tutorial.hint" class="mt-0.5 block text-xs text-slate-500">{{ tutorial.hint }}</span>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </div>
`,
});
