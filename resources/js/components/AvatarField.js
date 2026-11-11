import { router, useForm } from '@inertiajs/vue3';
import { computed, defineComponent, onBeforeUnmount, ref, watch } from 'vue';
import Button from './Button';
import ConfirmActionModal from './ConfirmActionModal';
import UserAvatar from './UserAvatar';
import { avatarChoices, generatedAvatarUrl } from '../avatar';

/**
 * Upload a photo, pick a generated avatar, or clear both.
 *
 * Three states rather than two, because "no photo" is not the same as "no
 * face": an account with neither renders an avatar generated from its name, so
 * clearing is a return to that default rather than a fall back to two grey
 * initials. The picker exists because a name-derived face cannot be changed
 * without changing the name — choosing one stores a seed instead.
 *
 *
 * It works two ways, because the two screens that need it are not in the same
 * situation. On an existing account — /account, and the Users form in edit —
 * `action` names an endpoint and the control posts there itself, so changing a
 * photo is one click and does not drag the rest of the form along with it. On
 * the create form there is no account to post to yet, so `action` is omitted
 * and the chosen File is handed to the parent through `v-model` to ride along
 * with the create request.
 *
 * The preview is an object URL over the chosen file, so the person sees the
 * photo they picked before anything is uploaded. Object URLs are revoked when
 * they are replaced and on unmount — they pin the file in memory until they
 * are, and an Inertia visit unmounts this without reloading the document, so
 * nothing else would ever release them.
 */
export default defineComponent({
    name: 'AvatarField',
    components: { Button, ConfirmActionModal, UserAvatar },
    emits: ['update:modelValue', 'update:seed'],
    props: {
        // The stored path on the public disk, or null for a generated avatar.
        image: { type: String, default: null },
        // The chosen avatar seed, or null to generate one from the name.
        seed: { type: String, default: null },
        name: { type: String, default: '' },
        // Self-posting mode: where to POST the new photo.
        action: { type: String, default: '' },
        // Self-posting mode: where to DELETE the current one.
        removeAction: { type: String, default: '' },
        modelValue: { type: File, default: null },
        label: { type: String, default: 'Profile photo' },
        hint: { type: String, default: 'JPG, PNG or WebP, up to 2 MB.' },
    },
    setup(props, { emit }) {
        const input = ref(null);
        const preview = ref(null);
        const error = ref('');
        const form = useForm({ profile_image: null });
        const seedForm = useForm({ avatar_seed: '' });

        // ----------------------------------------------------------- the picker
        const choosing = ref(false);
        const draftSeed = ref('');
        const choices = computed(() => avatarChoices(props.name));
        const openPicker = () => {
            draftSeed.value = props.seed ?? choices.value[0];
            choosing.value = true;
        };

        /**
         * Commit the chosen seed.
         *
         * On an existing account this posts to the same endpoint the photo
         * does, which is what keeps "a photo and a generated face are two
         * answers to one question" true on the server: setting either clears
         * the other. On the create form there is nothing to post to yet, so the
         * seed rides along with the create request through `v-model:seed`.
         */
        const chooseAvatar = () => {
            releasePreview();

            if (!props.action) {
                emit('update:seed', draftSeed.value);
                emit('update:modelValue', null);
                choosing.value = false;

                return;
            }

            seedForm.avatar_seed = draftSeed.value;
            seedForm.post(props.action, {
                preserveScroll: true,
                errorBag: 'updateAvatar',
                onSuccess: () => { choosing.value = false; },
                onError: (errors) => { error.value = errors.avatar_seed ?? 'That avatar could not be saved.'; },
            });
        };

        const releasePreview = () => {
            if (preview.value) {
                URL.revokeObjectURL(preview.value);
                preview.value = null;
            }
        };

        // Shown instead of the stored avatar once something is picked.
        const shown = computed(() => preview.value ?? (props.image ? `/storage/${props.image}` : null));

        const choose = () => input.value?.click();

        const picked = (event) => {
            const file = event.target.files?.[0] ?? null;

            // Clear the input's value so picking the same file twice in a row
            // still fires `change` — the browser suppresses it otherwise, and
            // a failed upload retried with the same photo would do nothing.
            event.target.value = '';

            if (!file) return;

            error.value = '';
            releasePreview();
            preview.value = URL.createObjectURL(file);

            if (!props.action) {
                emit('update:modelValue', file);

                return;
            }

            form.profile_image = file;
            form.post(props.action, {
                preserveScroll: true,
                errorBag: 'updateAvatar',
                forceFormData: true,
                onSuccess: () => {
                    // The server now serves the stored photo, so the local
                    // preview is redundant and would otherwise mask a later
                    // change made somewhere else.
                    releasePreview();
                    form.reset();
                },
                onError: (errors) => {
                    releasePreview();
                    error.value = errors.profile_image ?? 'That photo could not be uploaded.';
                },
            });
        };

        const clear = () => {
            error.value = '';
            releasePreview();

            if (!props.action) {
                emit('update:modelValue', null);
                emit('update:seed', null);

                return;
            }

            router.delete(props.removeAction, { preserveScroll: true });
        };

        // A photo cleared by the parent must drop the preview with it.
        watch(() => props.modelValue, (file) => { if (!file && !props.action) releasePreview(); });

        onBeforeUnmount(releasePreview);

        const busy = computed(() => form.processing || seedForm.processing);
        const hasPhoto = computed(() => Boolean(shown.value));
        // Anything other than the name-derived default is something the person
        // chose, so there is something to clear.
        const hasChoice = computed(() => Boolean(shown.value) || Boolean(props.seed));

        return {
            busy, choose, chooseAvatar, choices, choosing, clear, draftSeed, error,
            generatedAvatarUrl, hasChoice, hasPhoto, input, openPicker, picked, shown,
        };
    },
    template: `
    <div>
        <span class="text-sm font-semibold text-slate-700">{{ label }}</span>
        <div class="mt-2 flex flex-wrap items-center gap-4">
            <span class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-[#07559e] bg-sky-100 shadow-sm">
                <img v-if="shown" :src="shown" :alt="name" class="h-full w-full object-cover" />
                <UserAvatar v-else :name="name" :seed="seed" class="!h-full !w-full !border-0 !text-xl !shadow-none" />
            </span>

            <div class="flex flex-wrap items-center gap-2">
                <Button type="button" variant="secondary" :disabled="busy" @click="openPicker">
                    <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>Choose avatar
                </Button>
                <Button type="button" variant="secondary" :disabled="busy" @click="choose">
                    <i class="fa-solid fa-camera" aria-hidden="true"></i>{{ busy ? 'Saving…' : (hasPhoto ? 'Change photo' : 'Upload photo') }}
                </Button>
                <Button v-if="hasChoice" type="button" variant="danger" :disabled="busy" @click="clear">
                    <i class="fa-solid fa-trash" aria-hidden="true"></i>Remove
                </Button>
            </div>

            <input ref="input" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="picked" />
        </div>
        <p v-if="error" class="error">{{ error }}</p>
        <p v-else class="mt-2 text-xs text-slate-500">{{ hint }}</p>

        <ConfirmActionModal
            :open="choosing"
            :processing="busy"
            title="Choose your avatar"
            message="Pick one of these, or type anything you like to generate a different face. The same text always makes the same avatar."
            confirm-label="Use this avatar"
            icon="fa-solid fa-wand-magic-sparkles"
            wide
            @close="choosing = false"
            @confirm="chooseAvatar">
            <div class="text-left">
                <div class="grid grid-cols-4 gap-3 sm:grid-cols-6">
                    <button
                        v-for="choice in choices"
                        :key="choice"
                        type="button"
                        class="overflow-hidden rounded-xl border-2 bg-sky-50 p-1 transition"
                        :class="draftSeed === choice ? 'border-[#00aeef] ring-4 ring-sky-100' : 'border-slate-200 hover:border-sky-300'"
                        :aria-pressed="draftSeed === choice"
                        @click="draftSeed = choice">
                        <span class="sr-only">Avatar {{ choice }}</span>
                        <img :src="generatedAvatarUrl(choice)" alt="" class="h-full w-full" loading="lazy" />
                    </button>
                </div>

                <label class="mt-4 block">
                    <span class="text-sm font-semibold text-slate-700">Or make your own</span>
                    <input v-model="draftSeed" maxlength="64" class="field" placeholder="Anything — a nickname, a word, a number" />
                </label>

                <div class="mt-3 flex items-center gap-3 rounded-xl bg-slate-50 px-4 py-3">
                    <img :src="generatedAvatarUrl(draftSeed || name)" alt="" class="h-14 w-14 rounded-full bg-sky-100" />
                    <p class="text-sm text-slate-600">This is what will appear beside your name.</p>
                </div>
            </div>
        </ConfirmActionModal>
    </div>
`,
});
