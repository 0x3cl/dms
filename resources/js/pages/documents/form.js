import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, defineComponent, ref, watch } from 'vue';
import AdminShell from '../../components/AdminShell';
import Button from '../../components/Button';
import Card from '../../components/Card';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import FolderPickerModal from '../../components/FolderPickerModal';
import RecipientPicker from '../../components/RecipientPicker';
import SearchableSelect from '../../components/SearchableSelect';
import ToggleSwitch from '../../components/ToggleSwitch';
import UploadLimitsHint from '../../components/UploadLimitsHint';
import useConfirmSubmit from '../../composables/useConfirmSubmit';
import { registerHref } from '../../registerReturn';
import { csrfHeaders } from '../../csrf';
import { canCarryMark, stampable } from '../../stamp';
import { pushToast } from '../../components/ToastStack';

/**
 * Upload and edit in one component.
 *
 * **The level is part of the upload; the recipient list is not.** Who may open
 * a restricted document is editable here, because that is the part the office
 * corrects: a name left off, somebody who has moved on, or a document filed
 * before anybody knew who it was for — which this form invites explicitly by
 * making the list optional at every level. Tagging was once a one-shot
 * decision taken at upload, which meant the only way past a missing name was
 * for that person to request access to a document they had no reason to know
 * existed.
 *
 * Reclassifying stays out: that is a question about readers who already hold
 * the document, and the share panel answers the part of it that matters (the
 * link). So the three cards show on upload only, and the picker shows wherever
 * the level in force tags people — private and confidential.
 */
export default defineComponent({
    name: 'DocumentForm',
    components: { AdminShell, Button, Card, ConfirmActionModal, FolderPickerModal, Head, Link, RecipientPicker, SearchableSelect, ToggleSwitch, UploadLimitsHint },
    props: {
        document: { type: Object, default: null },
        /** Who the document is shared with now — the live list, ids only. */
        recipients: { type: Array, default: () => [] },
        types: { type: Array, default: () => [] },
        visibilities: { type: Array, default: () => [] },
        users: { type: Array, default: () => [] },
        /*
         * What the server will actually accept, from `UploadPolicy::limits()`.
         * The picker's `accept` comes from here rather than from a literal: a
         * hard-coded `.pdf,.doc,.docx` outlived the policy it was copied from
         * and hid every permitted spreadsheet from the browser's own dialog.
         */
        limits: { type: Object, default: null },
        /**
         * What the destination picker may do, computed by the server.
         *
         * It reads and writes through the file manager's own endpoints, so a
         * role that can file a document but cannot browse storage is not
         * offered a control that would answer 403. The upload then lands at
         * the top of their storage, which is the default regardless.
         */
        can: { type: Object, default: () => ({}) },
    },
    setup(props) {
        const isEditing = computed(() => Boolean(props.document));

        const form = useForm({
            title: props.document?.title ?? '',
            // The type is a row in `document_types` now, not a name string, so
            // the form posts its id. Renaming a type no longer changes what
            // this field has to send.
            document_type_id: props.document?.document_type_id ?? '',
            file: null,
            // Only ever sent with a replacement — see the field's own comment.
            note: '',
            /*
             * Where in My Files the bytes land, as a folder's public id.
             *
             * Empty is the top of the person's own storage. The register used
             * to file every upload into a `Tracked Documents {year}` folder it
             * created itself — a folder nobody made, holding files people then
             * could not find, because they went looking where they had put
             * everything else.
             */
            folder: '',
            /*
             * The document's own level when editing, not the default. The
             * picker is rendered off `selected`, which is looked up by this
             * value — seeded as 'public' it would have hidden the picker on
             * every private document in the register.
             */
            visibility: props.document?.visibility ?? 'public',
            // Pre-ticked from the live list, so saving an unrelated edit
            // leaves the tagging exactly as it was. A removed viewer is
            // deliberately absent: see DocumentController::edit().
            recipients: [...props.recipients],
            /*
             * Whether readers may discuss this document.
             *
             * Defaults to on: a feature that has to be switched on per
             * document is one nobody finds. Turning it off closes the
             * thread and never deletes what was already written — erasing
             * other people's words as a side effect of a setting is not
             * something a toggle should do, so the panel stays readable.
             */
            comments_enabled: props.document?.comments_enabled ?? true,
            /*
             * Whether to draw the code into the file's own bytes.
             *
             * On by default: the office asked for every file to carry its
             * code, and a default of off would make that a thing somebody
             * has to remember on every upload. It is a choice rather than a
             * rule because a stamp is irreversible — an image is re-encoded
             * and a PDF gains a cover page — and there are documents nobody
             * wants altered.
             */
            /*
             * Whether this file carries its tracking code.
             *
             * **A state, not an action**, which is the correction: it opened
             * *off* on a file that was already stamped, because it was read
             * as "draw it now" and there was nothing to draw. Somebody
             * looking at a stamped document therefore saw a switch saying
             * the file was unmarked, directly above a note saying it was
             * marked. It now shows what `files.stamp` holds, and the form
             * only ever turns it into an action for bytes that do not carry
             * a mark yet.
             *
             * A new upload defaults to on, because every file is meant to
             * carry its code and a default of off would make that something
             * to remember on every upload.
             */
            stamp: props.document
                ? Boolean(props.document.carries_the_code)
                : true,
        });

        /*
         * Grouped by the server — `[{ label, options: [...] }]` — and passed
         * through untouched. The arrangement is an office decision in
         * `document_type_groups`, not a shape this page invents, so the file
         * manager's tracking dialog offers exactly the same list.
         */
        const typeOptions = computed(() => props.types.map((group) => ({
            label: group.label,
            options: group.options.map((type) => ({
                value: type.value,
                label: `${type.label} (${type.code})`,
            })),
        })));

        /*
         * Which level does what is the server's answer, carried on each option
         * as `taggable` and `linkable`. The template used to test
         * `visibility === 'private'` directly, which is why adding
         * Confidential would have left the recipient picker hidden on a level
         * that requires it — correct on the server, invisible in the browser.
         */
        const selected = computed(
            () => props.visibilities.find((option) => option.value === form.visibility) ?? null,
        );

        /*
         * Switching to a level that tags nobody drops the recipients already
         * picked, so an upload can never carry a list the server would ignore.
         *
         * Only while uploading: the level is not editable afterwards, so on an
         * edit this watcher has nothing to react to — and were it to fire, it
         * would be clearing a list somebody is in the middle of correcting.
         */
        watch(() => form.visibility, () => {
            if (!isEditing.value && !selected.value?.taggable) {
                form.recipients = [];
            }
        });

        /** What the tagging section is about to do, stated before it is saved. */
        const tagChanges = computed(() => {
            const before = props.recipients.map(Number);
            const after = form.recipients.map(Number);

            return {
                added: after.filter((id) => !before.includes(id)).length,
                removed: before.filter((id) => !after.includes(id)).length,
            };
        });

        /*
         * Drawing the code into a PDF, after the document exists.
         *
         * An image is stamped on the server. A PDF cannot be — there is no
         * PDF library on these servers — so the browser draws it and posts
         * the result back, which is the second half of the same upload.
         *
         * The bytes are held in this closure and the code arrives on the
         * success page Inertia hands to `onSuccess`, so the pair is only
         * ever together here, in the moment between the two. The call is not
         * awaited and the component is already unmounting: a plain async
         * function keeps running, and nothing on screen depends on it.
         *
         * **A failure says so**, and that is a correction rather than an
         * extra. It was silent, on the reasoning that a stamp is not worth
         * an error message over a finished upload — which is true of the
         * *upload*, and wrong about the person: they cannot tell a file that
         * carries its code from one that does not by looking at it, so a
         * silent failure means printing a document and discovering at the
         * counter that there is nothing to scan. The toast names the step
         * that failed, because "stamping failed" is not something anybody
         * can act on.
         */
        const stampAfterUpload = async (file, documentId, code) => {
            if (! file || ! code || ! documentId) return;

            try {
                const { stampable, stampPdf } = await import('../../stamp');

                if (! stampable(file)) return;

                /*
                 * The *stamp* label, not the printable one.
                 *
                 * They encode different things on purpose: the printable
                 * label points at the share link and can be reprinted when
                 * that link is regenerated, while a stamp is in every copy
                 * already handed out and so must never carry a token. See
                 * `stamp_png` on this endpoint.
                 */
                const label = await fetch(`/documents/${documentId}/qr-label`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                if (! label.ok) {
                    pushToast({
                        title: 'The code was not stamped onto the file',
                        message: `${code} is filed, but its label could not be fetched. Open the document and use Download PNG to attach it by hand.`,
                        tone: 'error',
                    });

                    return;
                }

                const { stamp_png: png, cover } = await label.json();

                if (! png) return;

                const stamped = await stampPdf(file, { labelPng: png, code, cover });

                if (! stamped) {
                    /*
                     * `stampPdf` refuses an encrypted or signed PDF, and one
                     * too large to rewrite in a tab. Each is a deliberate
                     * refusal rather than a fault — rewriting a signed PDF
                     * would invalidate its signature — so the message says
                     * the document is filed and what to do instead.
                     */
                    pushToast({
                        title: 'This PDF could not be stamped',
                        message: `${code} is filed. A signed, encrypted or very large PDF is left untouched — open the document and use Download PNG to attach its label.`,
                        tone: 'error',
                    });

                    return;
                }

                const body = new FormData();
                body.append('code', code);
                body.append('stamped', stamped, file.name);

                const saved = await fetch('/files/stamp', {
                    method: 'POST',
                    body,
                    headers: csrfHeaders(),
                    credentials: 'same-origin',
                });

                if (! saved.ok) {
                    pushToast({
                        title: 'The stamped file could not be saved',
                        message: `${code} is filed, but the stamped copy was refused (${saved.status}). Its bytes are unchanged.`,
                        tone: 'error',
                    });

                    return;
                }

                pushToast({
                    title: 'Tracking code stamped',
                    message: `${code} is on every page of this document.`,
                    tone: 'success',
                });

                /*
                 * The screen was rendered before these bytes carried
                 * anything, so it still says "not stamped" about a file
                 * that now is — and the switch it says it under is the one
                 * the person just used. The props are re-read rather than
                 * patched, because what changed is the file's own row.
                 *
                 * Only on the edit form: the upload flow lands on a success
                 * page that says nothing about the stamp, and reloading it
                 * would be a visit nobody asked for.
                 */
                if (isEditing.value) {
                    router.reload({ only: ['document'] });
                }
            } catch (error) {
                // The document is filed either way; only the stamp is lost —
                // and the person is told, because they cannot see which.
                pushToast({
                    title: 'The code was not stamped onto the file',
                    message: `${code} is filed. ${error?.message ?? 'The stamp could not be drawn.'}`,
                    tone: 'error',
                });
            }
        };

        /*
         * Whether the file in hand can carry a mark, and what it would do.
         *
         * Asked of the file rather than of the document, because on the edit
         * form the question is about the *replacement*: a spreadsheet
         * replaced by a photograph can be stamped where the spreadsheet
         * could not.
         */
        /*
         * Two subjects, and which one is in play depends on the form.
         *
         * With a file chosen the question is about **that** file — a
         * spreadsheet replaced by a photograph can be stamped where the
         * spreadsheet could not. With none chosen, on the edit form, the
         * question is about the file the document already holds, which is
         * what lets a document filed before stamping existed be marked
         * without re-uploading anything.
         */
        const markable = computed(() => (form.file
            ? canCarryMark(form.file)
            : Boolean(props.document?.can_stamp)));

        /** Is the subject a PDF, which only the browser can draw into? */
        const markIsPdf = computed(() => (form.file
            ? stampable(form.file)
            : Boolean(props.document?.needs_browser_stamp)));

        /**
         * Does the file already carry its code?
         *
         * Asked of the server's own answer rather than worked out from the
         * stamp's value. The list here was `pdf` and `image`, the two
         * formats stamping started with, so a Word, Excel or PowerPoint
         * document that already carries a cover read as unstamped: the
         * switch sat off above a note offering to add one, and saving did
         * nothing because the server will not draw a second. `carries_the_code`
         * is `DocumentStamp::carriesTheCode()`, which is where that
         * question belongs and where a format added next is handled
         * without this page being told.
         */
        const alreadyMarked = computed(() => ! form.file
            && Boolean(props.document?.carries_the_code));

        const markNote = computed(() => {
            if (alreadyMarked.value) {
                // What it carries, by what was actually drawn — a cover
                // page, a sheet and a slide are three different things and
                // "this image carries its code in the corner" was said of
                // all of them.
                return {
                    image: 'This image carries its code in the corner. Replacing the file draws it again.',
                    pdf: 'This file carries its code on a cover page. Replacing the file draws a new one.',
                    docx: 'This document carries its code on a cover page. Replacing the file draws a new one.',
                    xlsx: 'This workbook carries its code on a cover sheet. Replacing the file adds a new one.',
                    pptx: 'This deck carries its code on a cover slide. Replacing the file adds a new one.',
                }[props.document?.stamp] ?? 'This file carries its code. Replacing it draws a new one.';
            }

            const name = form.file?.name ?? '';

            if (/\.(xlsx|xlsm)$/i.test(name)) {
                return 'A cover sheet is added as the first tab, carrying the QR code, the tracking code, the title, the type and the classification.';
            }

            if (/\.(pptx|pptm|ppsx)$/i.test(name)) {
                return 'A cover slide is added as the first slide, carrying the QR code, the tracking code, the title, the type and the classification.';
            }

            if (markIsPdf.value || /\.(docx|docm)$/i.test(name)) {
                return 'A cover page is added as page one, carrying the QR code, the tracking code, the title, the type and the classification.';
            }

            return 'A small QR code is drawn into the bottom-right corner of the image.';
        });

        /*
         * Choosing a replacement turns it back on.
         *
         * The switch shows the state of the file **in storage**, and a new
         * file makes that stale: these are different bytes and they carry
         * nothing yet. Without this, somebody replacing a stamped file saw
         * the switch already on and never thought about it — which is
         * correct — while somebody replacing an *unstamped* one saw it off
         * and would have uploaded unmarked bytes without meaning to. New
         * bytes default to being marked, exactly as on the upload form.
         */
        watch(() => form.file, (chosen, previous) => {
            if (chosen && ! previous) {
                form.stamp = true;
            }
        });

        /**
         * Fetch this document's own file, stamp it, and hand it back.
         *
         * The one path where the browser has to *get* the bytes rather than
         * being handed them: a document filed before stamping existed has
         * its file in storage and nothing in the form. It is the same three
         * steps as an upload's second half, with a download in front.
         */
        const stampStoredFile = async ({ id, code, name }) => {
            try {
                const current = await fetch(`/documents/${id}/file/view`, {
                    credentials: 'same-origin',
                });

                if (! current.ok) {
                    pushToast({
                        title: 'The code was not stamped onto the file',
                        message: `${code} is unchanged — its file could not be read back (${current.status}).`,
                        tone: 'error',
                    });

                    return;
                }

                const blob = await current.blob();
                const file = new File([blob], name ?? 'document.pdf', {
                    type: 'application/pdf',
                });

                await stampAfterUpload(file, id, code);
            } catch (error) {
                pushToast({
                    title: 'The code was not stamped onto the file',
                    message: `${code} is unchanged. ${error?.message ?? 'Its file could not be read back.'}`,
                    tone: 'error',
                });
            }
        };

        const submitForm = (options) => {
            if (isEditing.value) {
                /*
                 * An edit that carries a replacement file needs the same
                 * second half as an upload.
                 *
                 * A replacement is a new version of the same document: the
                 * tracking code is the one already printed on paper, so the
                 * new bytes carry the *same* code — but they carry none of
                 * the old version's mark, because that was drawn into the
                 * bytes this just superseded. The server resets the row to
                 * `none` and re-stamps an image itself; a PDF is the
                 * browser's, here as on the way in.
                 */
                const replacement = form.file;
                /*
                 * Held before the post, for the reason the upload branch
                 * holds its bytes: `onSuccess` runs against whatever props
                 * the redirect brought back, and a stamp that depends on
                 * reading them afterwards is a stamp that stops happening
                 * the day one of them is renamed or arrives later.
                 */
                const subject = {
                    id: props.document.id,
                    code: props.document.code,
                    name: props.document.file_name,
                    browserDraws: Boolean(props.document.needs_browser_stamp),
                };

                // A file input needs multipart, and Laravel needs the method
                // spoofed for it.
                form.transform((data) => ({ ...data, _method: 'put' }))
                    .post(`/documents/${props.document.id}`, {
                        ...options,
                        forceFormData: true,
                        onSuccess: (page) => {
                            options?.onSuccess?.(page);
                            if (! form.stamp) return;

                            if (replacement) {
                                stampAfterUpload(replacement, subject.id, subject.code);
                            } else if (subject.browserDraws) {
                                /*
                                 * No replacement: the bytes to draw on are
                                 * the ones already in storage, so they are
                                 * fetched back, stamped and returned. The
                                 * server has just touched the file, which is
                                 * what opens the window `files.stamp` checks.
                                 */
                                stampStoredFile(subject);
                            }
                        },
                    });
            } else {
                // Held before the post, because a successful one resets the
                // form and the bytes are what the stamp needs.
                const pending = form.file;
                const wanted = form.stamp;

                form.post('/documents', {
                    ...options,
                    forceFormData: true,
                    onSuccess: (page) => {
                        options?.onSuccess?.(page);
                        // Declined, so the browser draws nothing either —
                        // the server has already recorded that these bytes
                        // carry no code.
                        if (wanted) {
                            stampAfterUpload(pending, page?.props?.documentId, page?.props?.code);
                        }
                    },
                });
            }
        };

        const { confirm, request, cancel, proceed } = useConfirmSubmit(submitForm);

        /*
         * `selected` is returned, and that is not a tidying-up.
         *
         * It is what the whole tagging section is rendered on
         * (`v-if="selected && selected.taggable"`), and leaving it out of
         * setup's return meant the template read an undefined property,
         * evaluated it as falsey and drew nothing. So choosing Private or
         * Confidential offered no recipient picker at all: a private
         * document could only ever be uploaded with an empty list, and a
         * confidential one could only be addressed by hand-posting the form.
         *
         * It failed silently in both directions, which is why it survived:
         * Vue logs a warning and renders nothing, and every test that tags
         * somebody posts to the endpoint directly, where the field works.
         */
        /*
         * The last gate says what is about to happen to people who are not on
         * screen. "Are you sure you want to proceed?" is a question with no
         * information in it, and a tagging change is exactly the kind of edit
         * somebody makes by mistake while fixing a title.
         */
        const confirmMessage = computed(() => {
            if (!isEditing.value) {
                return 'A tracking code and QR Tracker will be generated once it is uploaded.';
            }

            const parts = [];

            if (tagChanges.value.added) {
                parts.push(`${tagChanges.value.added} ${tagChanges.value.added === 1 ? 'person' : 'people'} will be given this document and notified`);
            }

            if (tagChanges.value.removed) {
                parts.push(`${tagChanges.value.removed} ${tagChanges.value.removed === 1 ? 'person' : 'people'} will lose access to it`);
            }

            return parts.length ? `${parts.join(', and ')}.` : 'Are you sure you want to proceed?';
        });

        /*
         * The destination, chosen in the same picker Move and Copy use.
         *
         * Reused rather than rebuilt: it already fetches the tree the server
         * says this person may write into, and a second folder chooser is a
         * second answer to "where may this go".
         */
        const choosingFolder = ref(false);
        const folderLabel = ref('My Files');
        const chooseFolder = (publicId, name) => {
            // Reset passes nothing, and the server reads an empty string as
            // "the top of your storage" — so this control and the default
            // agree on what choosing no folder means.
            form.folder = publicId ?? '';
            folderLabel.value = name ?? 'My Files';
            choosingFolder.value = false;
        };

        return {
            backHref: registerHref(), cancel, chooseFolder, choosingFolder, confirm, confirmMessage, folderLabel,
            alreadyMarked, form, isEditing, markable, markNote, proceed, request, selected, tagChanges, typeOptions,
        };
    },
    template: `
    <Head :title="isEditing ? 'Edit document' : 'Upload document'" />
    <AdminShell :active="isEditing ? 'documents' : 'documents-create'" :title="isEditing ? 'Edit document' : 'Upload document'">
        <Card class="w-full">
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-5">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#07559e]">Documents</p>
                    <h2 class="mt-1 text-2xl font-bold uppercase tracking-wide text-slate-900">{{ isEditing ? 'Update document' : 'New document' }}</h2>
                    <p class="mt-1 text-slate-600">Fields marked with <span class="font-bold text-rose-600">*</span> are required.</p>
                </div>
                <Link :href="isEditing ? '/documents/view/' + document.code : backHref" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 hover:border-[#07559e] hover:text-[#07559e]"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back</Link>
            </div>

            <form class="mt-7 grid gap-5 md:grid-cols-2" @submit.prevent="request">
                <label class="md:col-span-2">
                    <span class="required-label text-sm font-semibold text-slate-700">Document title</span>
                    <input data-tour="document-title" v-model="form.title" class="field" :class="{ 'border-rose-500': form.errors.title }" />
                    <p v-if="form.errors.title" class="error">{{ form.errors.title }}</p>
                </label>

                <div>
                    <span class="required-label text-sm font-semibold text-slate-700">Document type</span>
                    <div class="mt-2">
                        <SearchableSelect v-model="form.document_type_id" :options="typeOptions" placeholder="Select a type" :invalid="Boolean(form.errors.document_type_id)" />
                    </div>
                    <p v-if="form.errors.document_type_id" class="error">{{ form.errors.document_type_id }}</p>
                    <p class="mt-1 text-xs text-slate-500">The type decides the prefix of the tracking code.</p>
                </div>

                <label>
                    <span class="text-sm font-semibold text-slate-700" :class="{ 'required-label': !isEditing }">{{ isEditing ? 'Replace file (optional)' : 'File' }}</span>
                    <input data-tour="document-file" type="file" :accept="limits ? limits.accept : undefined" class="field file:mr-3 file:rounded-lg file:border-0 file:bg-sky-100 file:px-3 file:py-1.5 file:text-sm file:font-bold file:text-[#07559e]" :class="{ 'border-rose-500': form.errors.file }" @input="form.file = $event.target.files[0]" />
                    <p v-if="form.errors.file" class="error">{{ form.errors.file }}</p>
                    <UploadLimitsHint :limits="limits" />
                    <p v-if="isEditing" class="mt-1 text-xs text-slate-500">
                        Leave empty to keep the current file. A new file is saved as the
                        <strong>next version</strong>: the tracking code, the QR Tracker and the share link stay
                        as they are, and the file it replaces is kept in the document's version history.
                    </p>
                </label>

                <!--
                    Where the file goes in My Files.

                    Upload only: on an edit the file already has a home, and a
                    replacement is a new version of it rather than a new file
                    to place. Optional, and the default is the top of the
                    person's own storage — the register used to invent a
                    "Tracked Documents {year}" folder instead, which is the
                    system arranging somebody else's filing for them.
                -->
                <div v-if="!isEditing && can.chooseFolder">
                    <span class="text-sm font-semibold text-slate-700">Save to <span class="font-normal text-slate-500">(optional)</span></span>
                    <div class="mt-2 flex flex-wrap items-center gap-3">
                        <span class="inline-flex min-w-0 items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700">
                            <i class="fa-solid fa-folder text-amber-500" aria-hidden="true"></i>
                            <span class="max-w-[300px] truncate" :title="folderLabel">{{ folderLabel }}</span>
                        </span>
                        <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-bold text-slate-700 transition hover:border-[#07559e] hover:text-[#07559e]" @click="choosingFolder = true">
                            <i class="fa-solid fa-folder-tree" aria-hidden="true"></i>Choose folder
                        </button>
                        <button v-if="form.folder" type="button" class="text-sm font-semibold text-slate-500 transition hover:text-[#07559e]" @click="chooseFolder()">
                            Reset to My Files
                        </button>
                    </div>
                    <p v-if="form.errors.folder" class="error">{{ form.errors.folder }}</p>
                    <p class="mt-1 text-xs text-slate-500">The document is tracked either way — this is only where its file sits in your storage.</p>
                </div>

                <!-- Only on edit, and only alongside a replacement: a note
                     about a file nobody is replacing has nothing to attach to.
                     Optional, because a required reason is one more thing to
                     get past on a correction somebody is in a hurry to make. -->
                <!-- The one setting on this form that is about what happens
                     to the document *after* it is filed, so it sits on its
                     own row rather than beside the type. -->
                <div class="md:col-span-2">
                    <div class="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-slate-700">Allow comments</p>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Anyone who can read this document can leave a note on it. Comments are kept against the file version they were written about.
                            </p>
                        </div>
                        <ToggleSwitch v-model="form.comments_enabled" label="Allow comments on this document" />
                    </div>
                    <p v-if="isEditing" class="mt-1 text-xs text-slate-500">Turning this off closes the thread. Nothing already written is deleted.</p>
                </div>

                <!--
                    Offered only where the bytes can actually carry a mark.
                    A spreadsheet, a video or a zip has no surface to draw
                    on, so a tickbox over one would be a control that does
                    nothing — canCarryMark() is the same test the server
                    uses, asked of the file in hand.
                -->
                <div v-if="markable" class="md:col-span-2">
                    <div class="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3.5">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-slate-700">Stamp the tracking code into the file</p>
                            <p class="mt-0.5 text-xs text-slate-500">{{ markNote }}</p>
                        </div>
                        <ToggleSwitch v-model="form.stamp" :disabled="alreadyMarked" label="Stamp the tracking code into the file" />
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        <template v-if="alreadyMarked">A stamp cannot be removed — the bytes were rewritten when it was drawn. Replace the file to change what it carries.</template>
                        <template v-else>This changes the file itself and cannot be undone — an image is re-encoded, and a PDF gains a cover page. The code is on the document either way.</template>
                    </p>
                </div>

                <label v-if="isEditing && form.file" class="md:col-span-2">
                    <span class="text-sm font-semibold text-slate-700">What changed? (optional)</span>
                    <!-- Full width and tall, because this is prose rather than
                         a value: it sits across both columns like the other
                         full-width fields on this form, and the height is set
                         rather than left to the rows attribute so it does not change with
                         the font. border-rose-500 is the marker app.js scrolls
                         to on a validation failure, so this field behaves like
                         the rest of them when the server refuses it. -->
                    <textarea v-model="form.note" maxlength="255" class="field h-[350px]" :class="{ 'border-rose-500': form.errors.note }" placeholder="e.g. Signed copy, corrected annexes"></textarea>
                    <p v-if="form.errors.note" class="error">{{ form.errors.note }}</p>
                    <p class="mt-1 text-xs text-slate-500">Recorded in the version history and on the document's audit trail.</p>
                </label>

                <!-- The level is set at upload and not here: reclassifying
                     is a question about the readers who already hold the
                     document. Who is tagged *is* editable, below. -->
                <fieldset v-if="!isEditing" data-tour="document-visibility" class="md:col-span-2">
                    <legend class="text-sm font-semibold text-slate-700">Who can open this document?</legend>
                    <!-- The cards come from App\Enums\DocumentVisibility, so the
                         wording lives with the rule it describes, and the row is
                         a three-column grid rather than a wrapping two-column one: the
                         three levels are one choice and are read by comparing them,
                         which a card orphaned onto a second line defeats. -->
                    <div class="mt-3 grid gap-3 md:grid-cols-3">
                        <label v-for="option in visibilities" :key="option.value" class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition" :class="form.visibility === option.value ? 'border-[#00aeef] bg-sky-50' : 'border-slate-300 hover:border-sky-300'">
                            <input v-model="form.visibility" type="radio" :value="option.value" class="mt-1 h-4 w-4 shrink-0 accent-[#00aeef]" />
                            <span class="min-w-0">
                                <span class="flex items-center gap-2">
                                    <span class="text-sm font-bold uppercase tracking-wide text-slate-800">{{ option.label }}</span>
                                    <i v-if="!option.linkable" class="fa-solid fa-link-slash text-xs text-rose-500" aria-hidden="true" title="Cannot be shared by link"></i>
                                </span>
                                <span class="mt-0.5 block text-xs text-slate-500">{{ option.description }}</span>
                            </span>
                        </label>
                    </div>
                    <p v-if="form.errors.visibility" class="error">{{ form.errors.visibility }}</p>
                </fieldset>

                <!--
                    Tagging, at upload and afterwards.

                    Rendered wherever the level in force tags people — Private
                    and Confidential — which on an edit is the document's own
                    level rather than a card somebody just clicked. It is the
                    same section answering the same question later, so the
                    control is identical and only the wording says which of the
                    two moments this is.
                -->
                <div v-if="selected && selected.taggable" class="md:col-span-2">
                    <!--
                        Optional at every level, confidential included,
                        and the label says so rather than carrying an
                        asterisk: the marker is a promise about what the
                        form will refuse, so one over a field nothing
                        refuses is a lie the reader acts on.
                    -->
                    <span class="text-sm font-semibold text-slate-700">
                        {{ isEditing ? 'Who may open this document' : 'Tag the people who may open it' }}
                        <span class="font-normal text-slate-500">(optional)</span>
                    </span>
                    <p class="mt-1 text-xs text-slate-500">
                        <span v-if="selected.linkable">Everyone tagged can open this document, and they will be notified. A link can be sent as well — for a public document it opens for anyone, and for a private one only for the people tagged here.</span>
                        <span v-else>Only the people tagged here can ever open this document — a confidential document cannot be shared by link. You can leave it empty and tag somebody once you know who it is for.</span>
                    </p>
                    <RecipientPicker
                        v-model="form.recipients"
                        :people="users"
                        :invalid="Boolean(form.errors.recipients)"
                        :empty-text="isEditing ? 'Nobody is tagged. Use Add to choose who can open this.' : 'Nobody added yet. Use Add to choose who can open this.'" />

                    <!--
                        What saving will do, stated before it is saved.

                        On an edit the list is a *change*, and the two halves of
                        it are not the same kind of act: somebody added gets a
                        notification, somebody taken off loses access and is
                        recorded on the trail as removed. A count of the chips
                        would say neither, and both are things the uploader is
                        about to do to people who are not on screen.
                    -->
                    <p v-if="isEditing && (tagChanges.added || tagChanges.removed)" class="mt-2 rounded-xl bg-amber-50 px-4 py-2.5 text-sm font-semibold text-amber-800">
                        <i class="fa-solid fa-triangle-exclamation mr-1.5" aria-hidden="true"></i>
                        <span v-if="tagChanges.added">{{ tagChanges.added }} {{ tagChanges.added === 1 ? 'person' : 'people' }} will be given this document and notified.</span>
                        <span v-if="tagChanges.added && tagChanges.removed"> </span>
                        <span v-if="tagChanges.removed">{{ tagChanges.removed }} {{ tagChanges.removed === 1 ? 'person' : 'people' }} will lose access, which is recorded on the audit trail.</span>
                    </p>
                    <!--
                        Who has been tagged, stated in one line rather than
                        left to be counted off the chips.
                    -->
                    <p v-else-if="form.recipients.length" class="mt-2 rounded-xl bg-emerald-50 px-4 py-2.5 text-sm font-semibold text-emerald-700">
                        <i class="fa-solid fa-user-check mr-1.5" aria-hidden="true"></i>{{ form.recipients.length }} {{ form.recipients.length === 1 ? 'person' : 'people' }} tagged — <span v-if="isEditing">they can open this document.</span><span v-else>they will be notified and can open this document.</span>
                    </p>
                    <!--
                        A statement, not a warning.

                        This used to be an amber caution beside a field
                        the server refused to accept empty, which made
                        people tag whoever came to mind to get past the
                        form — a tag somebody then has to notice and undo,
                        which is worse than an empty list. Filing a
                        confidential document before you know who it goes
                        to is ordinary, so the form says what the
                        consequence is and lets it through.
                    -->
                    <p v-else-if="!selected.linkable" class="mt-2 rounded-xl bg-slate-50 px-4 py-2.5 text-sm text-slate-600">
                        <i class="fa-solid fa-circle-info mr-1.5 text-[#07559e]" aria-hidden="true"></i>Nobody tagged — only you can open this, and a confidential document has no link. Tag somebody here whenever you know who it is for.
                    </p>
                    <p v-else class="mt-2 rounded-xl bg-slate-50 px-4 py-2.5 text-sm text-slate-600">
                        <i class="fa-solid fa-circle-info mr-1.5 text-[#07559e]" aria-hidden="true"></i>Nobody tagged — only you can open this. Tag somebody here, or send a share link.
                    </p>
                    <p v-if="!selected.linkable" class="mt-2 rounded-xl bg-rose-50 px-4 py-2.5 text-xs font-semibold text-rose-700">
                        <i class="fa-solid fa-link-slash mr-1.5" aria-hidden="true"></i>No share link can be created for a confidential document, and any link already issued stops working the moment it is classified this way.
                    </p>
                    <p v-if="form.errors.recipients" class="error">{{ form.errors.recipients }}</p>
                </div>

                <div class="flex justify-end gap-3 border-t border-slate-200 pt-5 md:col-span-2">
                    <Button variant="secondary" href="/documents">Cancel</Button>
                    <Button data-tour="document-submit" type="submit" variant="primary" :disabled="form.processing">
                        <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                        <span v-if="form.processing && form.progress">Uploading… {{ form.progress.percentage }}%</span>
                        <span v-else-if="form.processing">Saving…</span>
                        <span v-else>{{ isEditing ? 'Save changes' : 'Upload document' }}</span>
                    </Button>
                </div>
            </form>
        </Card>

        <FolderPickerModal
            :open="choosingFolder"
            action="upload"
            :can-create="Boolean(can.createFolder)"
            :items="[{ kind: 'file', public_id: 'new', name: form.file ? form.file.name : 'this document' }]"
            @close="choosingFolder = false"
            @confirm="chooseFolder" />

        <ConfirmActionModal
            :open="confirm.open"
            :processing="confirm.processing"
            :progress="form.progress ? form.progress.percentage : null"
            :title="isEditing ? 'Save these changes?' : 'Upload this document?'"
            :message="confirmMessage"
            @close="cancel"
            @confirm="proceed" />
    </AdminShell>
`,
});
