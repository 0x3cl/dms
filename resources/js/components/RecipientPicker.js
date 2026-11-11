import { computed, defineComponent, ref, watch } from 'vue';
import Button from './Button';
import UserAvatar from './UserAvatar';

/**
 * Picks people from a list that is too long to scroll.
 *
 * The recipient list used to be every account in the agency rendered as a
 * scrolling column of checkboxes. That is readable at a dozen people and
 * useless at three hundred: the ones already ticked scroll out of sight, so the
 * answer to "who have I added?" is to scroll back through everybody looking for
 * ticks. Nothing on screen was a summary.
 *
 * So the two questions are split. Who is picked is always visible at the top,
 * as a removable chip each. Finding somebody happens in a modal with a search
 * box, which is dismissed once they are picked.
 *
 * `modelValue` is the array of selected ids, so the parent keeps owning the
 * form state and this component never writes to it directly.
 */
export default defineComponent({
    name: 'RecipientPicker',
    components: { Button, UserAvatar },
    emits: ['update:modelValue'],
    props: {
        modelValue: { type: Array, default: () => [] },
        people: { type: Array, default: () => [] },
        invalid: { type: Boolean, default: false },
        addLabel: { type: String, default: 'Add' },
        emptyText: { type: String, default: 'Nobody added yet.' },
        searchPlaceholder: { type: String, default: 'Search name, ID or office' },
    },
    setup(props, { emit }) {
        const open = ref(false);
        const search = ref('');

        const byId = computed(() => new Map(props.people.map((person) => [person.id, person])));

        // Driven off `modelValue` rather than a list of its own, so a
        // recipient cleared by the parent — switching back to a public
        // document does exactly that — disappears from the chips too.
        const selected = computed(() => props.modelValue
            .map((id) => byId.value.get(id))
            .filter(Boolean));

        const haystack = (person) => [person.name, person.employee_id, person.division, person.section]
            .filter(Boolean).join(' ').toLowerCase();

        const matches = computed(() => {
            const term = search.value.trim().toLowerCase();

            if (term === '') return props.people;

            return props.people.filter((person) => haystack(person).includes(term));
        });

        const isPicked = (id) => props.modelValue.includes(id);

        const toggle = (id) => emit('update:modelValue', isPicked(id)
            ? props.modelValue.filter((candidate) => candidate !== id)
            : [...props.modelValue, id]);

        const remove = (id) => emit('update:modelValue', props.modelValue.filter((candidate) => candidate !== id));

        // A stale search term is the first thing you see on reopening, and it
        // hides everyone who does not match it.
        watch(open, (isOpen) => { if (!isOpen) search.value = ''; });

        const close = () => { open.value = false; };

        return { close, matches, open, remove, search, selected, isPicked, toggle };
    },
    template: `
    <div>
        <div
            class="mt-3 rounded-xl border p-3"
            :class="invalid ? 'border-rose-500' : 'border-slate-300'">
            <div v-if="selected.length" class="flex flex-wrap gap-2">
                <span
                    v-for="person in selected"
                    :key="person.id"
                    class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 py-1 pl-1 pr-1.5">
                    <UserAvatar :person="person" class="!h-7 !w-7 !border !text-[11px]" />
                    <span class="min-w-0">
                        <span class="block max-w-[14rem] truncate text-sm font-semibold text-slate-800">{{ person.name }}</span>
                        <span class="block text-[11px] text-slate-500">{{ person.employee_id }}</span>
                    </span>
                    <button
                        type="button"
                        class="flex h-6 w-6 items-center justify-center rounded-full text-slate-400 transition hover:bg-rose-50 hover:text-rose-600"
                        :aria-label="'Remove ' + person.name"
                        @click="remove(person.id)">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </span>
            </div>
            <p v-else class="py-3 text-center text-sm text-slate-500">{{ emptyText }}</p>

            <div class="mt-3 border-t border-slate-200 pt-3">
                <Button type="button" variant="secondary" @click="open = true">
                    <i class="fa-solid fa-user-plus" aria-hidden="true"></i>Add
                </Button>
            </div>
        </div>

        <!-- The picker itself. Its own overlay rather than ConfirmActionModal,
             which is built to ask a yes/no question and has no room for a
             searchable list. -->
        <teleport to="body">
            <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-slate-900/50" @click="close"></div>
                <div class="relative flex max-h-[80vh] w-full max-w-2xl flex-col overflow-hidden rounded-3xl bg-white shadow-xl">
                    <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-5">
                        <div>
                            <h3 class="text-lg font-bold uppercase tracking-wide text-slate-900">Add recipients</h3>
                            <p class="mt-1 text-sm text-slate-600">{{ selected.length }} selected</p>
                        </div>
                        <button type="button" class="text-slate-400 transition hover:text-slate-600" aria-label="Close" @click="close">
                            <i class="fa-solid fa-xmark text-lg" aria-hidden="true"></i>
                        </button>
                    </div>

                    <div class="border-b border-slate-200 px-6 py-4">
                        <label class="relative block">
                            <span class="sr-only">Search people</span>
                            <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" aria-hidden="true"></i>
                            <input
                                v-model="search"
                                class="h-[42px] w-full rounded-lg border border-slate-300 py-0 pl-11 pr-4 outline-none focus:border-[#00aeef] focus:ring-4 focus:ring-sky-100"
                                :placeholder="searchPlaceholder" />
                        </label>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto px-3 py-2">
                        <label
                            v-for="person in matches"
                            :key="person.id"
                            class="flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2 transition hover:bg-sky-50/50">
                            <input
                                type="checkbox"
                                :checked="isPicked(person.id)"
                                class="h-4 w-4 shrink-0 rounded border-slate-300 accent-[#00aeef]"
                                @change="toggle(person.id)" />
                            <UserAvatar :person="person" class="!h-8 !w-8 !text-xs" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-slate-800">{{ person.name }}</span>
                                <span class="block truncate text-xs text-slate-500">
                                    {{ person.employee_id }}
                                    <template v-if="person.section"> · {{ person.section }}</template>
                                    <template v-else-if="person.division"> · {{ person.division }}</template>
                                </span>
                            </span>
                        </label>

                        <p v-if="!matches.length && people.length" class="px-3 py-8 text-center text-sm text-slate-500">
                            Nobody matches that search.
                        </p>
                        <p v-if="!people.length" class="px-3 py-8 text-center text-sm text-slate-500">
                            There is nobody else registered yet.
                        </p>
                    </div>

                    <div class="flex justify-end border-t border-slate-200 px-6 py-4">
                        <Button type="button" variant="primary" @click="close">Done</Button>
                    </div>
                </div>
            </div>
        </teleport>
    </div>
`,
});
