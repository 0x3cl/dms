import { computed, defineComponent, ref, watch } from 'vue';
import { avatarUrl } from '../avatar';
import { initials } from '../initials';

/**
 * A person's face.
 *
 * Their uploaded photo if they have one, otherwise a generated avatar — see
 * resources/js/avatar.js, which owns the choice and the style. Initials are the
 * last resort rather than the default they used to be: two grey letters are
 * legible and completely interchangeable, and half a dozen "DC"s in a list of
 * staff say nothing about which Dela Cruz is which.
 *
 * A generated avatar is fetched from a third party, so it is allowed to fail:
 * `@error` falls back to initials, which is why this stays a component with
 * state rather than a bare `<img>`. The watcher resets that fallback when the
 * person changes, or a single failure would strand every subsequent avatar
 * rendered through the same element in a paginated list.
 *
 * `person` is any payload carrying `{ name, profile_image, avatar_seed }`. The
 * older `name` / `image` props still work, so call sites that predate generated
 * avatars render unchanged.
 */
export default defineComponent({
    name: 'UserAvatar',
    props: {
        name: { type: String, default: '' },
        /** The stored profile_image path, for callers that pass it separately. */
        image: { type: String, default: null },
        /** A chosen avatar seed, for callers that pass it separately. */
        seed: { type: String, default: null },
        /** Or the whole payload, which is what most callers have to hand. */
        person: { type: Object, default: null },
    },
    setup(props) {
        const failed = ref(false);

        const subject = computed(() => ({
            name: props.person?.name ?? props.name,
            profile_image: props.person?.profile_image ?? props.image,
            avatar_seed: props.person?.avatar_seed ?? props.seed,
        }));

        watch(subject, () => { failed.value = false; }, { deep: true });

        return {
            failed,
            src: computed(() => avatarUrl(subject.value)),
            label: computed(() => subject.value.name),
            text: computed(() => initials(subject.value.name)),
        };
    },
    template: `
    <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-[#07559e] bg-sky-100 text-sm font-bold text-[#07559e] shadow-sm" :title="label">
        <img v-if="!failed" :src="src" :alt="label" class="h-full w-full object-cover" loading="lazy" @error="failed = true" />
        <span v-else aria-hidden="true">{{ text }}</span>
    </span>
`,
});
