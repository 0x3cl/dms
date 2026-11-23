import { computed, defineComponent, nextTick, onMounted, ref, watch } from 'vue';
import UserAvatar from './UserAvatar';
import { formatTimestamp } from '../utils/format-date';
import PersonName from './PersonName';

/**
 * How many comments are drawn on first paint, and how many more each time
 * the reader reaches the top of the thread.
 */
const PAGE = 20;

/**
 * The discussion on one version of a document.
 *
 * Rendered in two places — the drawer beside the document and the drawer
 * inside the full-screen preview — so it is one component for the reason
 * `VersionList` is: two copies of "what does a comment look like" is how one
 * of them quietly stops showing something the other learned.
 *
 * **It owns no state.** The draft, the posting and the deleting all live in
 * the page, and this emits. That is not ceremony: the panel is on screen
 * twice in one page, and a draft held locally would mean typing a comment,
 * opening full screen and finding the box empty. One draft, one place.
 *
 * **It is scoped to a version, and says so.** A comment written about
 * version 1 is not advice about version 3, so the list is the version's own
 * and the count of what the rest of the history holds is stated rather than
 * hidden — a thread that looks empty when it is only elsewhere is worse than
 * one that says where it is.
 *
 * **It reads bottom-up, like every other thread anybody uses.** The server
 * sends the comments newest-first, which is the right order for a *record*
 * and the wrong one for a *conversation*: it puts the reply box under the
 * oldest remark and makes a thread read backwards. So the panel orders them
 * oldest-to-newest, opens pinned to the bottom where the latest is, and
 * reveals older ones as the reader scrolls up.
 *
 * The window is the component's own and not a server page, because the whole
 * thread already arrives as a prop — the controller says why. Paging it here
 * costs no request and still keeps a hundred-comment thread from painting all
 * at once. Two things make that scrolling honest: revealing older comments
 * holds the reader's place by adding back exactly the height that appeared
 * above them, and a new arrival only scrolls the view if the reader was
 * already at the bottom — yanking somebody out of the middle of an old
 * exchange because a reply landed is the thing that behaviour exists to avoid.
 */
export default defineComponent({
    name: 'CommentPanel',
    components: { PersonName, UserAvatar },
    emits: ['update:modelValue', 'post', 'remove'],
    props: {
        /** The comments for the version on screen, newest-first. */
        comments: { type: Array, default: () => [] },
        /** How many the other versions hold between them. */
        otherCount: { type: Number, default: 0 },
        /** The version being discussed, or null when the file is gone. */
        version: { type: Number, default: null },
        /** False when the uploader has closed the thread. */
        enabled: { type: Boolean, default: true },
        /** The shared draft. */
        modelValue: { type: String, default: '' },
        processing: { type: Boolean, default: false },
        error: { type: String, default: '' },
        /** Whose comments are "mine", so the thread has two sides. */
        viewerId: { type: Number, default: null },
    },
    setup(props) {
        const scroller = ref(null);
        const visibleCount = ref(PAGE);

        /**
         * Oldest first. The prop is reversed rather than re-sorted, because
         * the server has already ordered it and sorting a second time here
         * would be a second answer to "what order is this thread in".
         */
        const ordered = computed(() => [...props.comments].reverse());

        /** The window, always anchored to the newest end. */
        const shown = computed(() => ordered.value.slice(-visibleCount.value));

        const hasOlder = computed(() => ordered.value.length > shown.value.length);

        const scrollToBottom = () => {
            const el = scroller.value;

            if (el) {
                el.scrollTop = el.scrollHeight;
            }
        };

        /** Within a comment's height of the end counts as "at the bottom". */
        const atBottom = () => {
            const el = scroller.value;

            return !el || el.scrollHeight - el.scrollTop - el.clientHeight < 120;
        };

        /*
         * Reveal older comments without moving what the reader is looking
         * at: whatever height the new rows added above is added back to the
         * scroll offset, so the thread grows upward out of view.
         */
        const revealOlder = async () => {
            const el = scroller.value;

            if (!hasOlder.value || !el) {
                return;
            }

            const before = el.scrollHeight;
            visibleCount.value += PAGE;

            await nextTick();
            el.scrollTop += el.scrollHeight - before;
        };

        const onScroll = () => {
            if (scroller.value && scroller.value.scrollTop < 80) {
                revealOlder();
            }
        };

        onMounted(() => nextTick(scrollToBottom));

        // A different version is a different thread: start it at its own
        // newest comment rather than wherever the last one was scrolled to.
        watch(() => props.version, () => {
            visibleCount.value = PAGE;
            nextTick(scrollToBottom);
        });

        /*
         * Runs before the DOM updates, which is the only moment the
         * *pre-arrival* scroll position can still be read — asking afterwards
         * would measure a list that had already grown.
         */
        watch(() => props.comments.length, (now, was) => {
            if (now <= was) {
                return;
            }

            const follow = atBottom();
            visibleCount.value += now - was;

            if (follow) {
                nextTick(scrollToBottom);
            }
        });

        return { formatTimestamp, hasOlder, onScroll, revealOlder, scroller, shown };
    },
    template: `
    <div class="flex min-h-0 flex-1 flex-col">
        <div ref="scroller" class="min-h-0 flex-1 overflow-y-auto px-5 py-4" @scroll.passive="onScroll">
            <p v-if="otherCount > 0" class="mb-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
                {{ otherCount }} more comment{{ otherCount === 1 ? '' : 's' }} on other versions of this file.
            </p>

            <!-- Scrolling to the top loads the older ones; the button is the
                 same act for a reader who got there by keyboard. -->
            <div v-if="hasOlder" class="mb-3 flex justify-center">
                <button
                    type="button"
                    class="rounded-full border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-500 transition hover:bg-slate-50"
                    @click="revealOlder">
                    <i class="fa-solid fa-arrow-up mr-1.5" aria-hidden="true"></i>Load earlier comments
                </button>
            </div>

            <!--
                A conversation, not a list of records.

                Each entry is a face and a bubble, and the viewer's own
                comments sit on the right in the brand blue — the shape
                everybody already reads as a thread. The face is
                UserAvatar, so a comment uses the same identity rendering
                as the trail, the recipient chips and the navbar: one
                person, one face, everywhere.

                The delete control appears on hover for the pointer and is
                always reachable by keyboard, so it is not a control that only
                exists for people using a mouse.
            -->
            <ul v-if="shown.length" class="space-y-4">
                <li
                    v-for="comment in shown"
                    :key="comment.id"
                    class="group flex items-start gap-2.5"
                    :class="comment.author_id && comment.author_id === viewerId ? 'flex-row-reverse' : ''">
                    <UserAvatar
                        :person="{ name: comment.author, profile_image: comment.author_image, avatar_seed: comment.author_seed }"
                        class="!h-9 !w-9 shrink-0 !text-[11px]" />

                    <div class="min-w-0 max-w-[85%]">
                        <!-- Name above, time below. A bubble is read before
                             either of them, so the name leads into it and
                             the timestamp settles after — putting both on
                             top made the line above the message longer than
                             most messages. -->
                        <div
                            class="flex items-baseline"
                            :class="comment.author_id && comment.author_id === viewerId ? 'justify-end' : ''">
                            <PersonName :id="comment.author_id" :name="comment.author" class="text-xs font-bold text-slate-700" />
                        </div>

                        <div
                            class="mt-1 flex items-start gap-1.5"
                            :class="comment.author_id && comment.author_id === viewerId ? 'flex-row-reverse' : ''">
                            <p
                                class="whitespace-pre-line break-words rounded-2xl px-3.5 py-2.5 text-sm"
                                :class="comment.author_id && comment.author_id === viewerId
                                    ? 'rounded-tr-sm bg-[#07559e] text-white'
                                    : 'rounded-tl-sm bg-slate-100 text-slate-700'">{{ comment.body }}</p>

                            <button
                                v-if="comment.can_delete"
                                type="button"
                                class="mt-1.5 shrink-0 rounded p-1 text-xs text-slate-300 opacity-0 transition hover:text-rose-600 focus:opacity-100 group-hover:opacity-100"
                                title="Delete this comment"
                                aria-label="Delete this comment"
                                @click="$emit('remove', comment)">
                                <i class="fa-solid fa-trash" aria-hidden="true"></i>
                            </button>
                        </div>

                        <p
                            class="mt-1 text-[11px] text-slate-400"
                            :class="comment.author_id && comment.author_id === viewerId ? 'text-right' : ''">
                            {{ formatTimestamp(comment.created_at) }}
                        </p>
                    </div>
                </li>
            </ul>

            <p v-else class="rounded-xl border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500">
                Nothing has been said about<template v-if="version"> version {{ version }} of</template> this file yet.
            </p>
        </div>

        <!-- Pinned below the list rather than scrolling with it: the box is
             how you reply, and a reply control that scrolls away under a
             long thread is one you have to hunt for. -->
        <div v-if="enabled" class="shrink-0 border-t border-slate-200 px-5 py-4">
            <form @submit.prevent="$emit('post')">
                <label class="block">
                    <span class="sr-only">Write a comment</span>
                    <textarea
                        :value="modelValue"
                        rows="3"
                        maxlength="2000"
                        class="field !mt-0"
                        :class="{ 'border-rose-500': error }"
                        placeholder="Add a note for anyone who can read this document."
                        @input="$emit('update:modelValue', $event.target.value)"></textarea>
                </label>
                <p v-if="error" class="error">{{ error }}</p>
                <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs text-slate-500">
                        <template v-if="version">Kept against version {{ version }}.</template>
                        <template v-else>Kept against this document.</template>
                    </p>
                    <button type="submit" :disabled="processing" class="inline-flex items-center justify-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#009bd8] disabled:opacity-50">
                        <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>Post
                    </button>
                </div>
            </form>
        </div>

        <p v-else class="shrink-0 border-t border-slate-200 px-5 py-4 text-sm text-slate-500">
            <i class="fa-solid fa-comment-slash mr-1.5" aria-hidden="true"></i>
            Comments are turned off for this document. What was already written stays.
        </p>
    </div>
`,
});
