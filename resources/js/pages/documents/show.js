import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, defineComponent, onMounted, reactive, ref, watch } from 'vue';
import AdminShell from '../../components/AdminShell';
import Badge from '../../components/Badge';
import Card from '../../components/Card';
import CommentPanel from '../../components/CommentPanel';
import ConfirmActionModal from '../../components/ConfirmActionModal';
import FilePreviewModal from '../../components/FilePreviewModal';
import FilePreviewPane from '../../components/FilePreviewPane';
import VersionList from '../../components/VersionList';
import VisibilityBadge from '../../components/VisibilityBadge';
import { pushToast } from '../../components/ToastStack';
import Pagination from '../../components/Pagination';
import UserAvatar from '../../components/UserAvatar';
import useLiveTrail from '../../composables/useLiveTrail';
import TrailFilters from '../../components/TrailFilters';
import TrailVersionTabs from '../../components/TrailVersionTabs';
import { formatTimestamp } from '../../utils/format-date';
import { registerHref } from '../../registerReturn';

/**
 * A document and its travel history — what a scanned QR Tracker label opens.
 *
 * The file actions that hand back bytes — Download and QR Tracker — are plain
 * <a href> links, not Inertia visits: they return file responses, which an XHR
 * visit cannot follow.
 *
 * **There is no "Receive & download" any more.** It acknowledged receipt and
 * handed back the file in one request, fusing two facts that are not the same:
 * the server observing that a copy left, and the person asserting the document
 * reached them — which may well have happened on paper, with nothing
 * downloaded. Taking delivery is now a `received` entry on the Log action
 * form, and Download is its own button, which already records the download in
 * both `document_logs` and `document_downloads`.
 *
 * **View file is not one of them any more.** It used to open the raw bytes in a
 * new tab, which is a download prompt for every format a browser cannot render
 * — which is most of what an office files. It now opens the same viewer the
 * file manager uses: the server says how this file should be shown
 * (`/documents/{id}/preview`), and a Word, Excel or PowerPoint document renders
 * through Microsoft's embedded Office viewer over a short-lived signed URL, as
 * it does in My Files. The decision is the server's; see
 * App\Services\Files\FilePreview.
 */
export default defineComponent({
    name: 'DocumentShow',
    components: { AdminShell, Badge, Card, CommentPanel, ConfirmActionModal, FilePreviewModal, FilePreviewPane, Head, Link, Pagination, TrailFilters, TrailVersionTabs, UserAvatar, VersionList, VisibilityBadge },
    props: {
        document: { type: Object, required: true },
        /** A paginator now, not a bare array: the trail is 12 rows a page. */
        logs: { type: Object, default: () => ({ data: [], links: [] }) },
        logFilters: { type: Object, default: () => ({ search: '', action: '' }) },
        /** Only the offices this document's trail actually contains. */
        logOffices: { type: Array, default: () => [] },
        /** Only the file versions this trail has rows against. */
        logVersions: { type: Array, default: () => [] },
        /** The same split as `[{version, entries}]`, for the panel's tabs. */
        logVersionTabs: { type: Array, default: () => [] },
        logActions: { type: Array, default: () => [] },
        /** The whole thread; the panel narrows it to the version on screen. */
        comments: { type: Array, default: () => [] },
        commentsEnabled: { type: Boolean, default: true },
        actions: { type: Array, default: () => [] },
        /**
         * The document's access level as the server describes it —
         * `{value, label, description, tone, linkable}`. The page branches on
         * `linkable` rather than on the level's name, so a level added later is
         * not silently mishandled here.
         */
        visibility: { type: Object, default: null },
        /** Live share links. Sent to the uploader only — a link URL *is* the credential. */
        links: { type: Array, default: () => [] },
        /**
         * Viewers the uploader has taken off — `{id, name, removed_at}`, and to
         * the uploader alone. A chip that simply disappeared would leave them
         * no way to see whether they removed the right person.
         */
        removedViewers: { type: Array, default: () => [] },
        /** The QR Tracker as inline SVG markup, for the modal. */
        qrCodeSvg: { type: String, default: '' },
        trackingUrl: { type: String, default: '' },
        can: { type: Object, default: () => ({}) },
    },
    setup(props) {
        const page = usePage();
        const isAuthenticated = computed(() => Boolean(page.props.auth?.user));

        const logging = ref(false);
        const showingQr = ref(false);

        // ------------------------------------------------------------ trail
        /*
         * The trail's own search and filter, kept in the query string so a
         * narrowed history is a linkable thing. `only` restricts the reload to
         * the trail's props, so filtering does not re-ship the document, the
         * share links or the QR markup on every keystroke.
         */
        const trail = reactive({
            search: props.logFilters.search ?? '',
            action: props.logFilters.action ?? '',
            from: props.logFilters.from ?? '',
            to: props.logFilters.to ?? '',
            office: props.logFilters.office ?? '',
            version: props.logFilters.version ?? '',
            entries: props.logFilters.entries ?? 12,
        });
        let trailTimer;
        const loadTrail = () => router.get(window.location.pathname, { ...trail }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            only: ['logs', 'logFilters', 'logVersions', 'logVersionTabs'],
        });
        const searchTrail = () => {
            clearTimeout(trailTimer);
            trailTimer = setTimeout(loadTrail, 350);
        };

        /**
         * Paging the trail reloads only the trail.
         *
         * `only` matters here beyond tidiness: without it, turning a page
         * would re-ship the document, the share links and the QR markup, and
         * `preserveState` would still be holding the open modal.
         */
        const pageTrail = (url) => url && router.get(url, {}, {
            preserveScroll: true,
            preserveState: true,
            only: ['logs', 'logFilters', 'logVersions', 'logVersionTabs'],
        });

        /*
         * Live, when a websocket is available.
         *
         * Only reloads outright when the reader is on page one with no filter;
         * otherwise it counts and offers a pill. A viewer on page two must not
         * have page-one rows injected under them, and a filtered viewer must
         * not be told a new row matched their filter when nobody has checked.
         */
        const { pending: liveEntries, refresh: refreshTrail } = useLiveTrail(
            `documents.${props.document.id}`,
            {
                // `logVersions` too, or a version that has just appeared in
                // the trail is missing from the filter until a full reload.
                prop: ['logs', 'logFilters', 'logVersions', 'logVersionTabs'],
                // Unfiltered only. A viewer who has narrowed the trail must
                // not be told a new row matched their filter when nobody has
                // checked whether it does.
                isLive: () => (props.logs.current_page ?? 1) === 1
                    && !trail.search
                    && !trail.action
                    && !trail.from
                    && !trail.to
                    && !trail.office,
            },
        );

        /*
         * Everything but the search lives behind the Filter button, as a
         * draft: five controls that each reload the page is five reloads to
         * describe one question, and five selects standing permanently over a
         * twelve-row panel is a filter bar taller than what it filters.
         */
        const filteringTrail = ref(false);
        const applyTrailFilters = (values) => {
            Object.assign(trail, values);
            filteringTrail.value = false;
            loadTrail();
        };

        // The version is deliberately not counted here: it is the tab bar
        // in plain sight above the list, and a badge counting a choice the
        // reader can already read would say the trail is narrowed by
        // something hidden.
        const activeTrailFilters = computed(() => [
            trail.action, trail.office, trail.from, trail.to,
        ].filter(Boolean).length + (Number(trail.entries) !== 12 ? 1 : 0));

        /*
         * Switching version is the version filter, set from the tabs rather
         * than from the dialog. Back to page one, because the page in the
         * address bar belongs to the trail that was on screen: version 2's
         * page three is very often past the end of version 1.
         */
        const selectTrailVersion = (version) => {
            trail.version = version;
            router.get(window.location.pathname, { ...trail, logs: 1 }, {
                preserveScroll: true,
                preserveState: true,
                replace: true,
                only: ['logs', 'logFilters', 'logVersions', 'logVersionTabs'],
            });
        };

        /** The row a person clicked, rendered in full by the detail modal. */
        const inspecting = ref(null);
        const copiedTracking = ref(false);

        const copyTrackingUrl = async () => {
            try {
                await navigator.clipboard.writeText(props.trackingUrl);
                copiedTracking.value = true;
                setTimeout(() => { copiedTracking.value = false; }, 2000);
            } catch {
                pushToast({ title: 'Could not copy', message: props.trackingUrl, tone: 'warning' });
            }
        };

        // ------------------------------------------------------------ preview
        /*
         * The descriptor is fetched when the viewer is opened, never shipped
         * with the page: for an Office document it mints a URL that is live for
         * minutes, and a page that carried one would hand out a credential to
         * everybody who merely looked at the document.
         */
        const previewFile = ref(null);

        /*
         * Fetched once, when the page opens, because the file is now rendered
         * in the page rather than behind a button.
         *
         * Still a request rather than a page prop, and for the reason that
         * has not changed: for a PowerPoint or a legacy Office file the
         * descriptor mints a URL that is live for minutes, and a prop would
         * hand that credential to everybody who merely looked at the
         * document. The request is made by the person reading, after the
         * resolver has granted them `view`.
         *
         * It also writes the `previewed` trail entry — debounced per person
         * per five minutes by FileEventRecorder, which is what stops an
         * automatic render turning into a row every time somebody reloads.
         */
        const loadPreview = async (version = null) => {
            try {
                const query = version ? `?version=${version}` : '';
                const response = await fetch(`/documents/${props.document.id}/preview${query}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                previewFile.value = response.ok
                    ? await response.json()
                    : { mode: 'none', name: props.document.title, can_download: false };

                return response.ok;
            } catch {
                previewFile.value = { mode: 'none', name: props.document.title, can_download: false };

                return false;
            }
        };

        /**
         * Which version the URL asks for, if any.
         *
         * `?version=` survives a reload, a bookmark and a pasted link, which
         * is the whole reason it is in the URL rather than in component
         * state: somebody reading version 1 who refreshes was reading
         * version 1, and silently handing them the current file is the one
         * thing this feature exists to prevent.
         *
         * Read defensively — it is user input. A non-number, a zero or a
         * version this file does not have resolves to "the current file",
         * because the server answers 404 for a version that is not in the
         * history and the page must not sit on an empty frame over a
         * question mark in the address bar.
         */
        const versionFromUrl = () => {
            const asked = Number.parseInt(new URLSearchParams(window.location.search).get('version') ?? '', 10);

            return Number.isInteger(asked) && asked > 0 ? asked : null;
        };

        /**
         * The file's current version number, once anything has said so.
         *
         * Tracked separately from `previewFile.version`, which is the version
         * being *drawn* — while somebody reads version 1 those two are
         * different numbers, and the URL has to be able to name either.
         * Filled by the first load of the current file and corrected by the
         * history whenever the drawer fetches it.
         */
        const currentVersion = ref(null);

        /**
         * Put the version in the address bar without a visit.
         *
         * **The URL always names a version**, including the current one. The
         * first version of this wrote the parameter only for a superseded
         * version and deleted it otherwise, which makes a bare document URL
         * mean "whatever is current *when you open it*" — so a link shared
         * today quietly becomes a link to different bytes after somebody
         * replaces the file. Naming the version makes every URL say what it
         * resolves to, and a reader who wants "whatever is latest" can still
         * delete the parameter.
         *
         * `replaceState` and not an Inertia visit: the page's props do not
         * change when the preview does, so a visit would re-render the whole
         * document — the trail, the recipient list, everything — to alter a
         * query string. Inertia's own history state is passed through
         * untouched, so a later back or forward still finds the page data it
         * put there.
         */
        const writeParam = (key, value) => {
            const url = new URL(window.location.href);

            if (value === null) {
                url.searchParams.delete(key);
            } else {
                url.searchParams.set(key, String(value));
            }

            window.history.replaceState(window.history.state, '', url);
        };

        const rememberVersion = (version) => {
            // A document whose file is missing has no version to name.
            writeParam('version', version ?? currentVersion.value);
        };

        /*
         * The file's version history.
         *
         * Fetched when the panel is opened rather than shipped in the page's
         * props, for the same reason the descriptor above is: each entry
         * carries URLs that open bytes, and the panel is the least-read thing
         * on the page.
         *
         * Reading an older version in place reuses `loadPreview`, so the pane
         * that draws the current file draws an old one — and the trail records
         * the view against *that* version, because the request names it.
         */
        const versions = ref(null);
        const versionsOpen = ref(false);
        const versionsLoading = ref(false);
        const shownVersion = ref(versionFromUrl());

        /*
         * The first render honours the URL, so a reload shows what was being
         * read rather than quietly falling back to the current file — and
         * then the URL is written back, so a document opened without a
         * version ends up naming the one it is showing.
         *
         * When the URL names a version this file does not have — a
         * hand-edited number, a link from before a revert — it falls back to
         * the current file rather than leaving an empty frame, and the
         * parameter is corrected to the version actually on screen. The
         * server answers 404 there; this is what the page does with it.
         */
        onMounted(async () => {
            let served = await loadPreview(shownVersion.value);

            if (! served && shownVersion.value !== null) {
                shownVersion.value = null;
                served = await loadPreview();
            }

            /*
             * The current number comes from the descriptor, and only when
             * the descriptor is of the current file: while an older version
             * is being drawn, `previewFile.version` is that older number.
             */
            if (served && shownVersion.value === null) {
                currentVersion.value = previewFile.value?.version ?? null;
            }

            rememberVersion(shownVersion.value);

            // Arrived on a full-screen URL: the aside needs the history
            // that openFullscreen() would otherwise have fetched.
            if (previewing.value && versions.value === null) loadVersions();
        });

        const loadVersions = async () => {
            versionsLoading.value = true;

            try {
                const response = await fetch(`/documents/${props.document.id}/versions`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                versions.value = response.ok ? await response.json() : null;

                // The authoritative answer to "which is current", so the URL
                // can name it even for a reader who arrived on an old one.
                if (versions.value?.current) {
                    currentVersion.value = versions.value.current;
                }
            } catch {
                versions.value = null;
            } finally {
                versionsLoading.value = false;
            }
        };

        /**
         * The version, tiled across the preview as a watermark.
         *
         * A badge above the frame says which version is on screen; this says
         * it *on* the document. The difference matters for the thing that
         * actually goes wrong — somebody screenshots a page, or prints the
         * preview, or looks away and back — and a label outside the frame is
         * not carried by any of those. A superseded copy acted on as though
         * it were current is the failure versioning exists to prevent.
         *
         * Which is why **the current file carries no watermark**. A mark on
         * every document would make the whole register look like a draft,
         * and a mark that is on everything stops being read.
         *
         * **An SVG tile rather than repeated DOM.** One `background-image`
         * that repeats costs one node and stays crisp at any zoom; a grid of
         * a hundred rotated `<span>`s is a hundred nodes to lay out over a
         * frame that is already rendering a document. It is also the only
         * version that works over an `<iframe>` — nothing can be drawn
         * *inside* one from here, so the watermark has to be a layer over
         * the top, and a layer is `pointer-events-none` so the document
         * underneath still scrolls, selects and clicks.
         *
         * The colour is a mid slate at low alpha, which stays legible
         * against both a white page and the dark theme without either
         * drowning the document. It is deliberately not red for a
         * superseded version: the badge and the ring carry that alarm, and a
         * red wash over a page of text makes the text harder to read, which
         * is the opposite of what somebody checking an old version needs.
         */
        /**
         * Is the file on screen a superseded version?
         *
         * **From the descriptor, not from `shownVersion`.** Once every URL
         * names a version, `?version=3` on a file whose current version is 3
         * is the ordinary case — so "a version was asked for" stopped
         * meaning "an old version is being read", and the page warned about
         * and watermarked the current file. The descriptor carries both
         * numbers; comparing them is the only question that was ever being
         * asked.
         */
        /**
         * Where Download points, for the version actually on screen.
         *
         * It was a bare `/documents/{id}/file/download`, which always served
         * the *current* bytes — so somebody reading version 1, with the page
         * warned and watermarked to say so, pressed Download and silently
         * got version 3. That is the exact failure the warning exists to
         * prevent, handed out by the button next to it.
         *
         * Computed once and used by both Download buttons, because two
         * links to one thing is how one of them keeps a bug the other had
         * fixed.
         */
        const downloadUrl = computed(() => {
            const base = `/documents/${props.document.id}/file/download`;
            const version = previewFile.value?.version;
            const current = previewFile.value?.current_version;

            return version && current && version !== current ? `${base}?version=${version}` : base;
        });

        /*
         * Comments, narrowed to the version on screen.
         *
         * **A comment belongs to the bytes it was written about.** "This
         * needs the annex" said about version 1 is not advice about version
         * 3, and a thread that carries forward across a replacement tells
         * the next reader that a resolved objection is still open. So the
         * panel shows the version's own comments, and says how many the
         * rest of the history holds rather than hiding that they exist.
         *
         * Narrowed here rather than on the server because the whole thread
         * already arrived with the page: a comment carries no URL and no
         * credential, so there is nothing a fetch-per-version would
         * protect, and switching versions would cost a round trip.
         */
        const commentForm = useForm({ body: '', version: null });

        const versionComments = computed(() => {
            const version = previewFile.value?.version ?? null;

            return props.comments.filter((comment) => (comment.version ?? null) === version);
        });

        const otherComments = computed(() => props.comments.length - versionComments.value.length);

        const postComment = () => {
            if (commentForm.body.trim() === '') {
                pushToast({ tone: 'error', message: 'Write something before posting.' });

                return;
            }

            // The version the person was *looking at*, not whatever is
            // current by the time the request lands.
            commentForm.version = previewFile.value?.version ?? null;

            commentForm.post(`/documents/${props.document.id}/comments`, {
                preserveScroll: true,
                onSuccess: () => commentForm.reset('body'),
            });
        };

        /**
         * The comments drawer.
         *
         * Right-hand side, and **mutually exclusive with the other two**.
         * The trail already arrives from the right and the version history
         * from the left; three full-height overlays that can stack leave a
         * reader unsure which one the next Escape or click-outside closes,
         * so opening any of them closes the others.
         */
        const commentsOpen = ref(false);

        const openComments = () => {
            if (commentsOpen.value) {
                commentsOpen.value = false;

                return;
            }

            showingTrail.value = false;
            versionsOpen.value = false;
            commentsOpen.value = true;
        };

        const deletingComment = ref(null);

        const removeComment = () => {
            const comment = deletingComment.value;

            if (! comment) return;

            router.delete(`/documents/${props.document.id}/comments/${comment.id}`, {
                preserveScroll: true,
                onFinish: () => { deletingComment.value = null; },
            });
        };

        const isSuperseded = computed(() => {
            const drawn = previewFile.value?.version;
            const current = previewFile.value?.current_version;

            return Boolean(drawn && current && drawn !== current);
        });

        const watermark = computed(() => {
            /*
             * **Only a superseded version is watermarked.**
             *
             * Marking the current file too would make every document in the
             * register look like a draft, and a mark that is on everything
             * stops being read — which is exactly the muscle memory the
             * deletion code was introduced to interrupt, in a different
             * part of this application. A watermark earns its place by
             * being unusual.
             *
             * The condition is `isSuperseded`, which compares the version
             * drawn against the version current. It is deliberately not
             * `shownVersion === null`: that was true only while a bare URL
             * meant "current", and it marked the current file as soon as
             * the URL started naming it.
             */
            if (! isSuperseded.value) return null;

            const version = previewFile.value?.version;

            if (! version) return null;

            /*
             * Single-quoted attributes throughout, and the whole thing
             * URL-encoded. The tile sits inside a CSS `url("…")`, so a
             * double quote in the markup would close it early and a `#`
             * would be read as a fragment — both produce no background at
             * all rather than an error, which is the kind of breakage
             * nobody notices until a screenshot is missing its watermark.
             */
            const label = `VERSION ${version}`;
            // Two marks per tile, offset by half of each axis, so a plain
            // `repeat` lays them out staggered rather than in a hard grid —
            // the same brick pattern a stamped SAMPLE makes, and it reads as
            // a texture instead of as a table.
            const mark = (x, y) => "<text x='" + x + "' y='" + y + "'"
                + " fill='#475569' fill-opacity='0.13'"
                + " font-family='Helvetica,Arial,sans-serif' font-size='21' font-weight='700'"
                + " letter-spacing='2' text-anchor='middle'"
                + " transform='rotate(-24 " + x + " " + y + ")'>" + label + '</text>';
            const svg = "<svg xmlns='http://www.w3.org/2000/svg' width='280' height='170'>"
                + mark(70, 45) + mark(210, 130) + '</svg>';

            return {
                backgroundImage: `url("data:image/svg+xml,${encodeURIComponent(svg)}")`,
                backgroundRepeat: 'repeat',
            };
        });

        /**
         * Open the history drawer.
         *
         * A toggle, because the button is a control and pressing it twice
         * should not be a no-op.
         *
         * **Opening it closes the trail.** They are two full-height
         * overlays over the same page, and two scrims stacked on one
         * another leave a reader unsure which thing the next Escape or the
         * next click outside is going to close.
         */
        /*
         * The drawers, opened from the query string.
         *
         * `?comments=true` and `?versions=true` reproduce a screen the way
         * `fullscreen` and `version` already do, so a link can say "look at
         * version 14 and read what was said about it" rather than naming the
         * document and leaving the reader to find the rest.
         *
         * One name per surface, not one per place it appears: in full screen
         * `comments` opens the panel beside the file, and on the page it
         * opens the drawer. They are the same request — show me the comments
         * — and two names a letter apart would be the thing this codebase
         * keeps warning about.
         *
         * **The page drawers remain mutually exclusive**, which is a real
         * limit on asking for both at once: they are two full-height
         * overlays on the same edge, and the rule that opening one closes
         * the other is what keeps Escape unambiguous. So on the page,
         * `comments` wins and `versions` is ignored; in full screen both are
         * honoured, because there the version list is a column beside the
         * file rather than an overlay on top of it.
         */
        const openFromQuery = () => {
            const params = new URLSearchParams(window.location.search);

            // Full screen has its own two surfaces and resolves them itself.
            if (params.get('fullscreen') === 'true') {
                if (params.get('versions') === 'true' && versions.value === null) loadVersions();

                return;
            }

            if (params.get('comments') === 'true') {
                commentsOpen.value = true;

                return;
            }

            if (params.get('versions') === 'true') {
                versionsOpen.value = true;

                if (versions.value === null) loadVersions();
            }
        };

        onMounted(openFromQuery);

        const openVersions = () => {
            if (versionsOpen.value) {
                versionsOpen.value = false;

                return;
            }

            showingTrail.value = false;
            commentsOpen.value = false;
            versionsOpen.value = true;

            if (versions.value === null) loadVersions();
        };

        /**
         * Draw a version in the page.
         *
         * Closes the drawer, because what it changes is the preview *behind*
         * the drawer — leaving it open would hide the only visible effect of
         * pressing the button.
         */
        /**
         * Draw a version in the page.
         *
         * Closes the drawer, because what it changes is the preview
         * *behind* the drawer — leaving it open would hide the only visible
         * effect of pressing the button. The full-screen aside is the
         * opposite case: there the file fills the screen, so the swap is
         * the visible result and closing would throw it away, which is what
         * `keepFullscreen` is for.
         */
        const showVersion = (entry, { keepFullscreen = false } = {}) => {
            shownVersion.value = entry.is_current ? null : entry.version;
            previewFile.value = null;
            versionsOpen.value = false;

            if (! keepFullscreen) closeFullscreen();

            rememberVersion(shownVersion.value);
            loadPreview(shownVersion.value);
        };

        /*
         * The same descriptor, filling the screen.
         *
         * The inline pane is a panel on a page that also carries the
         * document's details and its actions, so it is deliberately a window
         * onto the file rather than the file at full size — which is the
         * right default and the wrong thing for reading a scanned page of
         * small print. No second fetch: the modal renders the descriptor the
         * page already holds, through the same FilePreviewPane.
         */
        /*
         * Full screen is in the URL too, for the reason the version is: it
         * is part of what somebody is looking at, so a reload, a bookmark
         * or a pasted link should reproduce it rather than drop the reader
         * back to the page with the frame closed.
         *
         * Only `true` is written. `?fullscreen=false` on every other URL
         * would be a parameter that says nothing, and the absence of one
         * already means closed.
         */
        const previewing = ref(new URLSearchParams(window.location.search).get('fullscreen') === 'true');

        /*
         * The full-screen comments panel, in the URL for the same reason the
         * other two are: it is part of what somebody is looking at, so
         * `?fullscreen=true&version=3&comments=true` reproduces the screen
         * they meant to send rather than the file with the discussion shut.
         *
         * A separate ref from `commentsOpen`, which is the *page* drawer.
         * They are two surfaces for one thread and the one draft is already
         * shared between them, but their open-ness is not the same fact: the
         * drawer closes when the other two overlays open, and the full-screen
         * panel sits beside the file with nothing to be exclusive with.
         * Writing both from one ref made closing the drawer shut the panel
         * behind it.
         *
         * Only `true` is written, as with `fullscreen` — the absence of the
         * parameter already means closed, and `comment=false` on every other
         * URL would be a parameter that says nothing.
         */
        const fullscreenComments = ref(new URLSearchParams(window.location.search).get('comments') === 'true');

        const setFullscreenComments = (open) => { fullscreenComments.value = open; };

        /*
         * The query string follows the drawers, rather than each thing that
         * opens one remembering to write it.
         *
         * There are seven ways these two open and close — the two toggle
         * buttons, the X on each drawer, the scrim behind it, Escape, the
         * Audit Trail button that closes both, and drawing a version, which
         * closes the version list because the result is behind it. Writing
         * the parameter at each of those is seven places to forget, and the
         * one that forgets produces a URL that does not reproduce the screen
         * it was copied from — which is the whole point of putting it there.
         *
         * So the state is the single source and these two effects mirror it.
         * `comments` covers both surfaces, because the drawer on the page and
         * the panel in full screen are one request — show me the comments —
         * and the parameter says which of the two to open by whether
         * `fullscreen` is beside it.
         *
         * Only `true` is ever written. The absence of the parameter already
         * means closed, so `comments=false` would be a parameter that says
         * nothing, and `replaceState` means none of this adds a history
         * entry: Back still leaves the document rather than closing a drawer.
         */
        watch([commentsOpen, fullscreenComments], ([drawer, panel]) => {
            writeParam('comments', drawer || panel ? 'true' : null);
        });

        watch(versionsOpen, (open) => {
            writeParam('versions', open ? 'true' : null);
        });

        const openFullscreen = () => {
            previewing.value = true;
            writeParam('fullscreen', 'true');

            // The aside needs the history, and somebody may never have
            // opened the drawer. Fetched here rather than with the page for
            // the reason it always was: the entries carry URLs that open
            // bytes, so they are minted when something is going to show
            // them.
            if (versions.value === null) loadVersions();
        };

        const closeFullscreen = () => {
            previewing.value = false;
            writeParam('fullscreen', null);

            // The panel only exists inside the overlay, so leaving it open in
            // state would put `comments=true` on a closed one — a parameter
            // describing nothing, which would then reopen the panel on the
            // next full screen somebody opened for an unrelated reason. The
            // watcher above clears the parameter once this is false.
            fullscreenComments.value = false;
        };

        /** The trail, in its own slide-over. */
        const showingTrail = ref(false);

        // ------------------------------------------------------------ sharing
        /*
         * A link that opens this document without an account. Offered only when
         * the server says both halves are true — this person may share, and
         * *this document* may be linked — because they are different questions
         * and a confidential document refuses the second however senior the
         * person is.
         */
        const sharing = ref(false);

        /*
         * **One link at a time.**
         *
         * Several links to one document is several credentials with separate
         * expiries and caps, and no screen can say which of them the message
         * somebody sent last week carried — so revoking the wrong one is the
         * normal outcome. The server enforces it (SharingService::createLink
         * refuses a second); this is the same rule on screen, which is why
         * the panel offers Change rather than New once one exists.
         */
        const link = computed(() => props.links[0] ?? null);

        /*
         * `datetime-local` wants `YYYY-MM-DDTHH:mm` and the server sends ISO
         * with a zone. Sliced rather than parsed: a Date round-trip would
         * shift the value into the browser's zone and silently move somebody's
         * expiry by eight hours.
         */
        const localDateTime = (value) => (value ? String(value).slice(0, 16) : '');

        /*
         * What a link starts on, which is not the same question for every
         * document.
         *
         * `restricted` on anything restricted to a named list — the term that
         * makes the URL an address rather than a key, so a link made in
         * passing, or minted automatically behind a printed QR label, cannot
         * hand the document to whoever photographs the folder. Widening it is
         * then something the uploader chose, which is the point.
         *
         * The server mints on the same rule; this is what the form shows
         * *before* there is a link to read it off.
         */
        const defaultAbility = computed(() => (props.visibility?.restricted ? 'restricted' : 'view'));

        const linkForm = useForm({
            ability: link.value?.ability ?? defaultAbility.value,
            expires_at: localDateTime(link.value?.expires_at),
            max_views: link.value?.max_views ?? null,
        });

        // Reopening the panel shows the link's *current* terms, not whatever
        // was last typed into the form.
        const openSharing = () => {
            linkForm.clearErrors();
            linkForm.ability = link.value?.ability ?? defaultAbility.value;
            linkForm.expires_at = localDateTime(link.value?.expires_at);
            linkForm.max_views = link.value?.max_views ?? null;
            sharing.value = true;
        };

        const copied = ref(null);

        /*
         * Create, or change the one that exists — and changing keeps the URL.
         * Revoke-and-recreate would break every message the old URL was
         * pasted into, which is the opposite of what somebody narrowing a
         * link from download to view is asking for.
         */
        const submitLink = () => {
            const options = {
                preserveScroll: true,
                onSuccess: () => { sharing.value = false; },
            };

            if (link.value) {
                linkForm.patch(`/documents/${props.document.id}/share-links/${link.value.id}`, options);
                return;
            }

            linkForm.post(`/documents/${props.document.id}/share-links`, options);
        };

        /*
         * Removing a viewer is confirmed, and the confirmation names them.
         *
         * It is not reversible from here — re-tagging somebody is the upload
         * form's job — and the person loses access the moment it is done, so
         * it gets the same small dialog the other access changes get rather
         * than being one stray click on a chip.
         */
        const removing = ref(null);

        const removeViewer = () => {
            const viewer = removing.value;

            if (! viewer) {
                return;
            }

            router.delete(`/documents/${props.document.id}/recipients/${viewer.id}`, {
                preserveScroll: true,
                onFinish: () => { removing.value = null; },
            });
        };

        /*
         * A heading whenever the version changes, and on the first row of a
         * page so a boundary cannot hide one. Client-side, because the rows
         * already arrive newest-first carrying their own version — grouping on
         * the server would be a second ordering to keep in step with the
         * paginator's — and it degrades to nothing when every row is null,
         * which is every trail written before versioning.
         */
        const startsVersion = (log, index) => {
            if (!log.version || props.logVersions.length < 2 || trail.version) return false;

            return index === 0 || props.logs.data[index - 1]?.version !== log.version;
        };

        /*
         * Both controls on the link end something for people who are not on
         * screen, so both are confirmed and neither confirmation asks "are
         * you sure" — each says what stops working.
         *
         * Revoking ends the link outright: the document is left with none,
         * and a public one's printed label falls back to a tracking URL that
         * asks a scanner to sign in. That consequence is not visible from the
         * dialog, which is exactly why the confirmation states it.
         */
        const revoking = ref(false);

        const revokeLink = () => {
            if (! link.value) {
                return;
            }

            router.delete(
                `/documents/${props.document.id}/share-links/${link.value.id}`,
                {
                    preserveScroll: true,
                    onFinish: () => { revoking.value = false; },
                    // Unlike a regeneration, there is nothing left to copy.
                    onSuccess: () => { sharing.value = false; },
                },
            );
        };

        /*
         * A new address for the same link.
         *
         * Confirmed, and the confirmation says what it costs: this is the one
         * control in the dialog that cannot be undone and that reaches people
         * who are not on screen — every copy of the URL already sent out
         * stops working. The terms survive, so what somebody decided about
         * the link is not quietly reset along with its address.
         *
         * The dialog stays open afterwards, unlike revoke: the whole reason
         * to regenerate is to copy the new URL and send it on, and closing
         * would take it away at that moment.
         */
        const regenerating = ref(false);

        const regenerateLink = () => {
            if (! link.value) {
                return;
            }

            router.post(
                `/documents/${props.document.id}/share-links/${link.value.id}/regenerate`,
                {},
                {
                    preserveScroll: true,
                    onFinish: () => { regenerating.value = false; },
                },
            );
        };

        /*
         * `navigator.clipboard` is unavailable on an insecure origin, which an
         * office installation served over plain HTTP is — so the fallback is
         * not defensive dressing, it is the path that will actually run there.
         */
        const copyLink = async (link) => {
            try {
                await navigator.clipboard.writeText(link.url);
            } catch {
                const field = document.createElement('textarea');
                field.value = link.url;
                document.body.appendChild(field);
                field.select();
                document.execCommand('copy');
                field.remove();
            }

            copied.value = link.id;
            setTimeout(() => { copied.value = null; }, 2000);
        };

        // The action is an App\Enums\DocumentAction value, and the options
        // come from the server — the page no longer carries its own list of
        // action strings for the controller to compare against.
        const logForm = useForm({
            document_id: props.document.id,
            action: props.actions[0]?.value ?? 'received',
            remarks: '',
        });

        const submitLog = () => logForm.post('/document/log', {
            preserveScroll: true,
            onSuccess: () => {
                logForm.reset('remarks');
                logging.value = false;
            },
        });

        return {
            backHref: registerHref(),
            copied,
            copyLink,
            defaultAbility,
            link,
            linkForm,
            openSharing,
            removeViewer,
            removing,
            regenerateLink,
            regenerating,
            revokeLink,
            revoking,
            sharing,
            submitLink,
            previewFile,
            previewing,
            openFullscreen,
            closeFullscreen,
            commentForm,
            commentsOpen,
            fullscreenComments,
            setFullscreenComments,
            openComments,
            deletingComment,
            downloadUrl,
            otherComments,
            postComment,
            removeComment,
            versionComments,
            isSuperseded,
            openVersions,
            watermark,
            showVersion,
            shownVersion,
            selectTrailVersion,
            startsVersion,
            versions,
            versionsLoading,
            versionsOpen,
            showingTrail,
            formatTimestamp,
            isAuthenticated,
            logForm,
            logging,
            submitLog,
            showingQr,
            copiedTracking,
            copyTrackingUrl,
            activeTrailFilters,
            applyTrailFilters,
            filteringTrail,
            trail,
            loadTrail,
            searchTrail,
            inspecting,
            pageTrail,
            liveEntries,
            refreshTrail,
        };
    },
    template: `
    <Head :title="document.title" />
    <AdminShell active="documents" :title="document.title">
        <div class="space-y-5">
            <Card>
                <!--
                    A flex-1 heading column with a zero minimum, which is what
                    stops a long title taking the page apart. Without the
                    minimum a flex item is at least as wide as its content,
                    so one unbroken title pushed the controls beside it onto
                    their own line and left them floating in the middle of
                    the card — the layout in the screenshot that prompted
                    this. The column can now be narrower than its text, and
                    the text is what gives.
                -->
                <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-5">
                    <div class="min-w-0 flex-1 basis-[600px]">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#07559e]">{{ document.type }}</p>
                        <!-- One line with an ellipsis, scrollable on hover,
                             and the full string in a tooltip for everybody
                             who does not hover. See .hover-scroll in
                             app.css. -->
                        <!-- py-1 is not spacing for its own sake: an
                             overflow container clips to its content box, so
                             without it a descender and the focus ring are cut
                             off flat along the top and bottom edges. -->
                        <h2
                            class="hover-scroll mt-0.5 w-full max-w-[600px] py-1 text-2xl font-bold uppercase tracking-wide text-slate-900"
                            :title="document.title"
                            tabindex="0">{{ document.title }}</h2>
                        <p class="mt-2 font-mono text-sm text-slate-600">{{ document.code }}</p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-2">
                        <!--
                            Where Back goes depends on who is reading. A signed-in
                            member of staff came from the register — on the tab and
                            under the filters they left it on, which is what
                            registerReturn.js remembers; somebody holding
                            a printed label has no history here at all and no reach
                            into the register, so they are sent to the one public
                            screen that can do something for them.
                        -->
                        <Link :href="isAuthenticated ? backHref : '/documents/track'" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 transition hover:border-[#07559e] hover:text-[#07559e]">
                            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>{{ isAuthenticated ? 'Back to documents' : 'Track another' }}
                        </Link>
                        <VisibilityBadge :visibility="document.visibility" />
                        <!-- The level's own sentence, from the enum. A badge alone says
                             which level; it does not say what the level does, and
                             "confidential" is exactly the word people assume they
                             already understand. -->
                        <p v-if="visibility" class="max-w-xs text-right text-xs text-slate-500">{{ visibility.description }}</p>
                    </div>
                </div>

                <dl class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Uploaded by</dt>
                        <dd class="mt-1 font-semibold text-slate-800">{{ document.user?.name ?? document.uploader_name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Uploaded</dt>
                        <dd class="mt-1 font-semibold text-slate-800">{{ formatTimestamp(document.created_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Last updated</dt>
                        <dd class="mt-1 font-semibold text-slate-800">{{ formatTimestamp(document.updated_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Actions logged</dt>
                        <dd class="mt-1 font-semibold text-slate-800">{{ logs.total }}</dd>
                    </div>
                </dl>

                <div v-if="document.visibility !== 'public' && (document.recipients?.length || removedViewers.length)" class="mt-6 border-t border-slate-200 pt-5">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Tagged — these people can open it</p>
                    <div v-if="document.recipients?.length" class="mt-2 flex flex-wrap gap-2">
                        <!-- The uploader's chip carries a remove control. Who a
                             document is for is chosen on the upload form, but
                             taking access away has to be possible from wherever
                             you can see it. -->
                        <span v-for="recipient in document.recipients" :key="recipient.id" class="inline-flex items-center gap-2 rounded-full bg-sky-100 py-1 pl-3 text-xs font-bold text-[#07559e]" :class="can.manage ? 'pr-1' : 'pr-3'">
                            <i class="fa-solid fa-user" aria-hidden="true"></i>{{ recipient.name }}
                            <button v-if="can.manage" type="button" class="inline-flex h-5 w-5 items-center justify-center rounded-full text-[#07559e] transition hover:bg-white/70 hover:text-rose-600" :title="'Remove ' + recipient.name" :aria-label="'Remove ' + recipient.name + ' as a viewer'" @click="removing = recipient">
                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                            </button>
                        </span>
                    </div>
                    <p v-else class="mt-2 text-sm text-slate-500">Nobody is tagged on this document.</p>

                    <!-- Only the uploader sees this: who *used* to be able to
                         open a document is more than reading it discloses. -->
                    <div v-if="can.manage && removedViewers.length" class="mt-4">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Removed — no longer able to open it</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <span v-for="viewer in removedViewers" :key="viewer.id" class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-500"><i class="fa-solid fa-user-slash" aria-hidden="true"></i>{{ viewer.name }}<span class="font-semibold text-slate-400">{{ formatTimestamp(viewer.removed_at) }}</span></span>
                        </div>
                    </div>
                </div>

                <!-- File actions are plain links: these routes return the file itself. -->
                <div class="mt-7 flex flex-wrap gap-3 border-t border-slate-200 pt-5">
                    <!-- The file is rendered below rather than behind a
                         button; what needs one is the trail, which is long and
                         read far less often. -->
                    <button type="button" class="inline-flex items-center justify-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#009bd8]" @click="versionsOpen = false; commentsOpen = false; showingTrail = true">
                        <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>Audit Trail
                        <span class="ml-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-white/20 px-1.5 text-xs font-bold">{{ logs.total }}</span>
                    </button>
                    <a v-if="can.download" :href="downloadUrl" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"><i class="fa-solid fa-download" aria-hidden="true"></i>Download</a>
                    <button v-if="isAuthenticated" type="button" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50" @click="logging = true"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>Log action</button>
                    <button type="button" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50" @click="showingQr = true"><i class="fa-solid fa-qrcode" aria-hidden="true"></i>QR Tracker</button>
                    <button v-if="can.link" type="button" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50" @click="openSharing"><i class="fa-solid fa-link" aria-hidden="true"></i>{{ link ? 'Manage link' : 'Share by link' }}</button>
                    <template v-if="can.manage">
                        <Link :href="'/documents/' + document.id + '/edit'" class="inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50"><i class="fa-solid fa-pen" aria-hidden="true"></i>Edit</Link>
                    </template>
                </div>

                <!-- ------------------------------------------------- share links -->
                <!-- Only the uploader sees these: a link's URL *is* the credential, so
                     listing one to somebody who merely has read access would hand them
                     a way to pass that access on. -->
                <!-- A line, not a list: there is one link, and the panel
                     behind "Manage link" is where it is read and changed. -->
                <div v-if="can.manage && link" class="mt-6 flex flex-wrap items-center gap-3 border-t border-slate-200 pt-5">
                    <span class="inline-flex items-center gap-2 rounded-full bg-sky-100 px-3 py-1 text-xs font-bold uppercase tracking-wide text-[#07559e]">
                        <!-- The label is a sentence fragment about a person
                             ("Can view and download"), so the words after it
                             have to complete that sentence. "… link is live"
                             did not: it read as "Can view and download link
                             is live". Reworded here and not in
                             ShareAbility::label(), because that label also
                             stands on its own in the share drawer, where it
                             is correct as it is. -->
                        <i class="fa-solid fa-link" aria-hidden="true"></i>{{ link.ability_label }} through the link
                    </span>
                    <span class="text-xs text-slate-500">
                        {{ link.view_count }} {{ link.view_count === 1 ? 'open' : 'opens' }}<span v-if="link.max_views"> of {{ link.max_views }}</span>
                        <span v-if="link.expires_at"> · expires {{ formatTimestamp(link.expires_at) }}</span>
                    </span>
                    <button type="button" class="rounded-lg px-2.5 py-1.5 text-xs font-bold text-[#07559e] hover:bg-sky-50" :title="copied === link.id ? 'Copied' : 'Copy link'" @click="copyLink(link)">
                        <i :class="copied === link.id ? 'fa-solid fa-check' : 'fa-solid fa-copy'" aria-hidden="true"></i>
                    </button>
                </div>

                <!-- A confidential document says why there is no share button, rather
                     than simply not having one. An absent control reads as a bug. -->
                <p v-else-if="can.manage && visibility && !visibility.linkable" class="mt-6 rounded-xl bg-rose-50 px-4 py-3 text-xs font-semibold text-rose-700">
                    <i class="fa-solid fa-link-slash mr-1.5" aria-hidden="true"></i>
                    A confidential document cannot be shared by link. Only the people tagged on it can open it.
                </p>
            </Card>

            <!--
                The file itself, rendered in the page.

                There was a "View file" button here, which is a button in
                front of the one thing somebody opening a document came to do.
                The viewer is the same one the file manager uses and the
                server still chooses it — see App\Services\Files\FilePreview —
                so a Word or Excel document draws in the browser, a PDF in a
                frame, and a format with no preview says so plainly rather
                than showing an empty box.
            -->
            <Card class="flex min-h-[70vh] flex-col">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 pb-4">
                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Your file</p>
                        <h3 class="mt-1 truncate text-xl font-bold uppercase tracking-wide text-slate-900">{{ document.file_name ?? document.title }}</h3>
                        <!--
                            Which bytes are on screen, as a badge.

                            It was a line of small grey text, which is the
                            wrong weight for the thing it says: somebody
                            reading a superseded copy and acting on it is the
                            failure this whole feature exists to prevent, and
                            a grey sentence under a filename is exactly what
                            a reader skims past.

                            Two tones, and the difference is the point.
                            Emerald is "this is the live file"; rose with a
                            warning is "this is not", which is the state that
                            has to be read rather than skimmed. The pairing
                            matches the Current pill in the version drawer,
                            so one state is not two colours in one app.

                            Shown whenever there is a version at all. It
                            used to be gated on version > 1, on the
                            reasoning that a document with one version has no
                            version question — which was wrong twice. A
                            file's first version is still a fact worth
                            stating, and, far worse, the gate read the
                            version *being drawn*: open version 1 of a file
                            that has three, and 1 > 1 is false, so the one
                            case the rose badge exists for — you are reading
                            a superseded copy — showed nothing at all.
                        -->
                        <div v-if="previewFile && previewFile.version" class="mt-2 flex flex-wrap items-center gap-2">
                            <template v-if="isSuperseded">
                                <Badge tone="rose">
                                    <i class="fa-solid fa-triangle-exclamation mr-1.5" aria-hidden="true"></i>Version {{ previewFile.version }} — superseded
                                </Badge>
                                <!-- The way back. Without it a reader who
                                     opened an old version has to reopen the
                                     drawer to undo it. -->
                                <button type="button" class="text-xs font-bold text-[#07559e] underline-offset-2 transition hover:underline" @click="showVersion({ is_current: true })">
                                    Back to the current file
                                </button>
                            </template>
                            <Badge v-else tone="emerald">
                                <i class="fa-solid fa-circle-check mr-1.5" aria-hidden="true"></i>Version {{ previewFile.version }} — current
                            </Badge>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-3">
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-bold transition"
                            :class="versionsOpen ? 'border-[#07559e] bg-sky-50 text-[#07559e]' : 'border-slate-300 text-slate-700 hover:bg-slate-50'"
                            :aria-expanded="versionsOpen ? 'true' : 'false'"
                            aria-controls="document-version-history"
                            @click="openVersions">
                            <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>Versions
                        </button>
                        <!-- Beside Versions, because the two answer the same
                             kind of question about the file on screen: what
                             it has been, and what people have said about it. -->
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-bold transition"
                            :class="commentsOpen ? 'border-[#07559e] bg-sky-50 text-[#07559e]' : 'border-slate-300 text-slate-700 hover:bg-slate-50'"
                            :aria-expanded="commentsOpen ? 'true' : 'false'"
                            aria-controls="document-comments"
                            @click="openComments">
                            <i class="fa-solid fa-comment" aria-hidden="true"></i>Comments
                            <span v-if="versionComments.length" class="rounded-full bg-slate-100 px-1.5 text-[11px] font-bold text-slate-600">{{ versionComments.length }}</span>
                        </button>
                        <a v-if="can.download" :href="downloadUrl" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                            <i class="fa-solid fa-download" aria-hidden="true"></i>Download
                        </a>
                        <!-- The same descriptor at full size, for a scan of
                             small print that the panel below is too short to
                             read. Nothing is re-fetched. -->
                        <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50" @click="openFullscreen">
                            <i class="fa-solid fa-expand" aria-hidden="true"></i>Full Screen
                        </button>
                    </div>
                </div>

                <!--
                    A window onto the file, not the file at full height.

                    The surface is the same slate the full-screen preview
                    uses, so a document does not change background between
                    the two places it is drawn — and an image or a PDF has
                    something to sit on rather than floating on the card.

                    A definite height is what makes the scroll work at all:
                    OfficeRenderer and an iframe both fill their container, so
                    without one a long docx would grow the page to its own
                    length and push the trail button and everything else out
                    of reach. The floor stops a one-line text file collapsing
                    the panel to nothing.
                -->
                <div class="relative mt-4 flex h-[70vh] min-h-[26rem] flex-col overflow-hidden rounded-xl border border-slate-200 bg-slate-100">
                    <FilePreviewPane :file="previewFile" />
                    <!-- Over the frame, never inside it: an iframe's content
                         is another document and nothing here can draw on it.
                         pointer-events-none so the file underneath still
                         scrolls, selects and clicks through the layer. -->
                    <div v-if="watermark" class="pointer-events-none absolute inset-0 z-10" :style="watermark" aria-hidden="true"></div>
                </div>

            </Card>
        </div>

        <!--
            The discussion, in a drawer on the **right**.

            It was a section under the preview, which put the thread a
            screen below the file it is about and made the page longer than
            the thing somebody opened it for. A drawer is the same shape the
            trail and the version history already use, and the three are
            mutually exclusive so only one scrim is ever up.

            Left, with the version history, because both are panels *about*
            the file on screen; the trail stays on the right. They are
            mutually exclusive, so two things sharing an edge costs nothing
            — only one is ever up.

            45% rather than a fixed width: a thread is prose in bubbles,
            and a column sized to the viewport keeps a sensible measure on
            a laptop and on a wide monitor alike. Floored at 22rem so it
            does not become unusable on a small screen, and full width
            below the breakpoint.
        -->
        <Transition
            enter-active-class="transition-opacity duration-200 ease-out" enter-from-class="opacity-0" enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-150 ease-in" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="commentsOpen" class="fixed inset-0 z-40 bg-slate-950/50" @click="commentsOpen = false"></div>
        </Transition>

        <Transition
            enter-active-class="transition-transform duration-300 ease-out" enter-from-class="-translate-x-full" enter-to-class="translate-x-0"
            leave-active-class="transition-transform duration-200 ease-in" leave-from-class="translate-x-0" leave-to-class="-translate-x-full">
            <aside v-if="commentsOpen" id="document-comments" class="fixed inset-y-0 left-0 z-50 flex w-full min-w-[22rem] flex-col bg-white shadow-2xl sm:w-[45%]" role="dialog" aria-modal="true" aria-label="Comments">
                <header class="flex shrink-0 items-start justify-between gap-3 border-b border-slate-200 px-5 pb-4 pt-5">
                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">This file</p>
                        <h2 class="mt-1 truncate text-lg font-bold uppercase tracking-wide text-slate-900">
                            Comments<template v-if="previewFile && previewFile.version"> on version {{ previewFile.version }}</template>
                        </h2>
                    </div>
                    <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100" aria-label="Close" @click="commentsOpen = false">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>

                <CommentPanel
                    v-model="commentForm.body"
                    :comments="versionComments"
                    :other-count="otherComments"
                    :version="previewFile ? previewFile.version : null"
                    :enabled="commentsEnabled"
                    :processing="commentForm.processing"
                    :error="commentForm.errors.body"
                    :viewer-id="$page.props.auth.user ? $page.props.auth.user.id : null"
                    @post="postComment"
                    @remove="deletingComment = $event" />
            </aside>
        </Transition>

        <!--
            The file's history, in a drawer on the **left**.

            It was an inline panel under the preview, which put it the better
            part of a screen below the button that opened it — the file is
            rendered in a 70vh frame — so the button read as doing nothing at
            all. Scrolling to it fixed the symptom; a drawer fixes the shape.
            What the file has been is a reference you read *against* the
            document, so it belongs beside it rather than under it.

            Left, because the trail already comes in from the right. Two
            overlays arriving from the same edge are two things that look
            like the same thing, and only one of them can be open at a time
            — which is why opening either closes the other rather than
            stacking a second scrim over the first.

            Narrower than the trail at 30rem: these are a handful of rows
            with two buttons each, not a paginated history with filters.
        -->
        <Transition
            enter-active-class="transition-opacity duration-200 ease-out" enter-from-class="opacity-0" enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-150 ease-in" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="versionsOpen" class="fixed inset-0 z-40 bg-slate-950/50" @click="versionsOpen = false"></div>
        </Transition>

        <Transition
            enter-active-class="transition-transform duration-300 ease-out" enter-from-class="-translate-x-full" enter-to-class="translate-x-0"
            leave-active-class="transition-transform duration-200 ease-in" leave-from-class="translate-x-0" leave-to-class="-translate-x-full">
            <aside v-if="versionsOpen" id="document-version-history" class="fixed inset-y-0 left-0 z-50 flex w-full flex-col bg-white shadow-2xl sm:w-[30rem]" role="dialog" aria-modal="true" aria-label="Version history">
                <header class="flex shrink-0 items-start justify-between gap-3 px-5 pb-3 pt-5">
                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">This file</p>
                        <h2 class="mt-1 truncate text-lg font-bold uppercase tracking-wide text-slate-900">Version history</h2>
                    </div>
                    <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100" aria-label="Close" @click="versionsOpen = false">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>

                <!-- Outside the scroll area: it describes the list rather
                     than belonging to it, so scrolling to version 1 should
                     not scroll the sentence explaining what version 1 is
                     off the top of the panel. -->
                <p class="shrink-0 border-b border-slate-200 px-5 pb-4 text-sm text-slate-600">Every version of this file, newest first. Showing one replaces what is drawn in the page behind this.</p>

                <div class="min-h-0 flex-1 overflow-y-auto px-5 pb-5">
                    <VersionList
                        :entries="versions ? versions.versions : []"
                        :loading="versionsLoading"
                        :shown="shownVersion"
                        @show="showVersion" />
                </div>
            </aside>
        </Transition>

        <!--
            The trail, in a drawer rather than a second card.

            It is the longest thing on this page and the least often read: a
            paginated history standing permanently below the document pushed
            the file itself — the thing somebody opened this page for — off
            the screen entirely. Half the viewport, because a trail row is a
            line of text and a document is a page; neither reads well in a
            column the width of a phone.

            Its own slide-over rather than ShareDrawer's: that one is a 32rem
            panel built around a form, and widening it for this would make
            every share panel in the app half the screen.
        -->
        <Transition
            enter-active-class="transition-opacity duration-200 ease-out" enter-from-class="opacity-0" enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-150 ease-in" leave-from-class="opacity-100" leave-to-class="opacity-0">
            <div v-if="showingTrail" class="fixed inset-0 z-40 bg-slate-950/50" @click="showingTrail = false"></div>
        </Transition>

        <Transition
            enter-active-class="transition-transform duration-300 ease-out" enter-from-class="translate-x-full" enter-to-class="translate-x-0"
            leave-active-class="transition-transform duration-200 ease-in" leave-from-class="translate-x-0" leave-to-class="translate-x-full">
            <aside v-if="showingTrail" class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-white shadow-2xl lg:w-1/2" role="dialog" aria-modal="true" aria-label="Audit trail">
                <header class="flex items-start justify-between gap-3 border-b border-slate-200 p-5">
                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase tracking-[.18em] text-[#07559e]">Audit trail</p>
                        <h2 class="mt-1 truncate text-lg font-bold uppercase tracking-wide text-slate-900">Tracking history</h2>
                    </div>
                    <button type="button" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100" aria-label="Close" @click="showingTrail = false">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto p-5">
                    <!-- The drawer's own header says what this is; what
                         belongs here is the way out of it. -->
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-sm text-slate-600">Every view, download, receipt, acknowledgement and hand-off, newest first.</p>
                        <Link :href="'/documents/' + document.id + '/logs'" class="shrink-0 text-sm font-bold text-[#07559e] hover:text-[#043d78]">Full history</Link>
                    </div>
                    <!-- Search in plain sight, everything else behind the button —
                         the same division of labour as My Files, and the same
                         component the full-history page uses. -->
                    <div class="mt-5 grid gap-3 sm:grid-cols-[1fr_auto]">
                        <label class="block">
                            <span class="sr-only">Search the trail</span>
                            <input v-model="trail.search" class="field !mt-0" placeholder="Search by person, employee ID or remarks" @input="searchTrail" />
                        </label>
                        <button type="button" class="relative inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50" @click="filteringTrail = true">
                            <i class="fa-solid fa-filter" aria-hidden="true"></i>Filter
                            <span v-if="activeTrailFilters" class="ml-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-[#00aeef] px-1.5 text-xs font-bold text-white">{{ activeTrailFilters }}</span>
                        </button>
                    </div>

                    <!-- The trail read version by version, and nothing at
                         all until a file has been replaced: one version is
                         not a choice. -->
                    <TrailVersionTabs :tabs="logVersionTabs" :current="trail.version" @select="selectTrailVersion" />

                    <!--
                        A row is a summary and a button, not the whole record.

                        Division and section moved into the detail modal: they are
                        the same two values on nearly every row of a given
                        document's trail, so repeating them crowded out the parts
                        that differ — who, what, when. Remarks are truncated here
                        for the same reason and shown in full there.
                    -->
                    <!-- Something arrived while you were reading. Offered, not
                         applied: splicing a new row into page two would make the
                         paginator's totals disagree with its contents. -->
                    <button
                        v-if="liveEntries"
                        type="button"
                        class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-sky-50 px-4 py-2.5 text-sm font-bold text-[#07559e] transition hover:bg-sky-100"
                        @click="refreshTrail">
                        <i class="fa-solid fa-arrow-rotate-right" aria-hidden="true"></i>
                        {{ liveEntries }} new {{ liveEntries === 1 ? 'entry' : 'entries' }} — refresh
                    </button>

                    <ol class="mt-6 space-y-2">
                        <template v-for="(log, index) in logs.data" :key="log.id">
                        <!-- The trail, read version by version. A heading
                             only where the version actually changes, and
                             nothing at all for a document whose file has
                             never been replaced. -->
                        <li v-if="startsVersion(log, index)" class="px-3 pt-4 pb-1 text-[11px] font-bold uppercase tracking-wide text-slate-500">
                            <i class="fa-solid fa-clock-rotate-left mr-1.5" aria-hidden="true"></i>File version {{ log.version }}
                        </li>
                        <li>
                            <button type="button" class="flex w-full gap-4 rounded-xl px-3 py-3 text-left transition hover:bg-sky-50/60" @click="inspecting = log">
                                <span class="relative mt-0.5 shrink-0">
                                    <UserAvatar
                                        :person="{ name: log.actor_name, profile_image: log.actor_profile_image, avatar_seed: log.actor_avatar_seed }"
                                        class="!h-10 !w-10 !text-xs" />
                                    <!-- The action, overlaid on the face: one glance
                                         answers both "who" and "what". -->
                                    <span class="absolute -bottom-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full bg-[#ffffff] text-[10px] shadow" :class="log.action_tone">
                                        <i :class="log.action_icon" aria-hidden="true"></i>
                                    </span>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-bold text-slate-800">{{ log.action_label }}</span>
                                    <span class="block text-xs text-slate-500">{{ log.actor_name }}</span>
                                    <span class="block text-xs text-slate-400">
                                        {{ formatTimestamp(log.occurred_at ?? log.created_at) }}
                                        <!-- Which bytes the entry was about.
                                             The version headings above group
                                             the list, but a row read on its
                                             own — found by a search, or the
                                             one row a filter left — carried
                                             no answer at all, and "downloaded
                                             this document" is not a complete
                                             sentence once a file has a
                                             history. Null is meaningful: a
                                             row written before the column
                                             existed has no version to name,
                                             and inventing one would be a
                                             fabricated snapshot. -->
                                        <span v-if="log.version" class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 font-bold text-slate-500">v{{ log.version }}</span>
                                    </span>
                                    <span v-if="log.remarks" class="mt-1.5 block truncate text-xs italic text-slate-600">{{ log.remarks }}</span>
                                </span>
                                <i class="fa-solid fa-chevron-right mt-1 shrink-0 text-xs text-slate-300" aria-hidden="true"></i>
                            </button>
                        </li>
                        </template>
                        <li v-if="logs.data.length === 0" class="py-10 text-center text-sm text-slate-500">
                            <span v-if="trail.version && !(trail.search || trail.action || trail.from || trail.to || trail.office)">Nothing was recorded against {{ trail.version === 'none' ? 'the unversioned file' : 'file version ' + trail.version }}.</span>
                            <span v-else-if="trail.search || trail.action || trail.from || trail.to || trail.office || trail.version">No entries match that search.</span>
                            <span v-else>Nothing has been logged against this document yet.</span>
                        </li>
                    </ol>
                    <Pagination :pagination="logs" @page="pageTrail" />
                </div>
            </aside>
        </Transition>

        <!--
            Removing a viewer, confirmed and named.

            A small dialog rather than a heavy one: it is a single, stated
            consequence, and the friction belongs where nothing can undo the
            outcome. Re-tagging somebody is still possible from the upload
            form, which is what the message says rather than implying this is
            final.
        -->
        <ConfirmActionModal
            :open="Boolean(removing)"
            title="Remove this viewer?"
            :message="removing ? removing.name + ' will no longer be able to open this document or read its history. They will be told they were removed rather than that they were never tagged.' : ''"
            confirm-label="Remove viewer"
            tone="danger"
            icon="fa-solid fa-user-slash"
            @close="removing = null"
            @confirm="removeViewer" />

        <!--
            One trail entry, in full.

            The row shows who, what and when; everything that is usually the
            same across a document's entries — the office the actor sat in, the
            exact timestamp, the complete remarks — lives here, so the list
            stays readable and nothing is lost. The office and the employee id
            are the row's **snapshots**, not the person's record today: an
            audit entry is a fact about then.
        -->
        <ConfirmActionModal
            :open="Boolean(inspecting)"
            :title="inspecting ? inspecting.action_label : 'Trail entry'"
            :cancel-label="null"
            confirm-label="Close"
            :icon="inspecting ? inspecting.action_icon : 'fa-solid fa-clock-rotate-left'"
            wide
            @close="inspecting = null"
            @confirm="inspecting = null">
            <div v-if="inspecting" class="text-left">
                <div class="flex items-center gap-4 rounded-xl bg-slate-50 px-4 py-3">
                    <UserAvatar
                        :person="{ name: inspecting.actor_name, profile_image: inspecting.actor_profile_image, avatar_seed: inspecting.actor_avatar_seed }"
                        class="!h-14 !w-14 !text-sm" />
                    <span class="min-w-0">
                        <span class="block truncate font-bold text-slate-800">{{ inspecting.actor_name }}</span>
                        <span v-if="inspecting.actor_employee_id" class="block truncate text-sm text-slate-600">{{ inspecting.actor_employee_id }}</span>
                    </span>
                </div>

                <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">When</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-800">{{ formatTimestamp(inspecting.occurred_at ?? inspecting.created_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Action</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-800">{{ inspecting.action_label }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Division</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-800">{{ inspecting.division_name || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Section</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-800">{{ inspecting.section_name || '—' }}</dd>
                    </div>
                    <!-- A snapshot like every other field here: it says which
                         bytes the event concerned, not which are current. -->
                    <div v-if="inspecting.version">
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">File version</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-800">Version {{ inspecting.version }}</dd>
                    </div>
                </dl>

                <div class="mt-5">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Remarks</p>
                    <p v-if="inspecting.remarks" class="mt-2 whitespace-pre-line break-words rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-700">{{ inspecting.remarks }}</p>
                    <p v-else class="mt-2 text-sm text-slate-500">None recorded.</p>
                </div>
            </div>
        </ConfirmActionModal>

        <!--
            The QR Tracker.

            Shown before it is downloaded, because the common thing somebody
            wants here is to *look* at the label — to check it is the right
            document, or to let a colleague scan it off the screen. A bare
            download link made that a two-step round trip through the file
            system.

            The symbol renders from inline SVG so it is sharp at any size; the
            Download button fetches the stored PNG, which is a file response
            and therefore a plain <a href>, never an Inertia visit.
        -->
        <ConfirmActionModal
            :open="showingQr"
            title="QR Tracker"
            :message="can.manage
                ? 'Print this and stick it on the physical folder. Scanning it opens this document through its share link — no account needed, and you can revoke that link at any time.'
                : 'Print this and stick it on the physical folder. Scanning it opens this document for anyone signed in.'"
            :cancel-label="null"
            confirm-label="Close"
            icon="fa-solid fa-qrcode"
            @close="showingQr = false"
            @confirm="showingQr = false">
            <div class="mt-5 text-center">
                <div class="mx-auto w-fit rounded-2xl border border-slate-200 bg-[#ffffff] p-4 [&>svg]:h-56 [&>svg]:w-56" v-html="qrCodeSvg"></div>
                <p class="mt-4 font-mono text-sm font-bold text-slate-800">{{ document.code }}</p>

                <!-- What the symbol above actually resolves to, spelled out.
                     For the uploader that is the share link — a credential —
                     so it is shown rather than implied: somebody about to
                     print a label onto a folder in a corridor should be able
                     to read what they are putting there. -->
                <p class="mx-auto mt-3 max-w-md break-all rounded-xl bg-slate-50 px-3 py-2 font-mono text-[11px] text-slate-500">{{ trackingUrl }}</p>
                <p v-if="can.manage && !link" class="mt-2 text-xs text-slate-500">
                    This document is confidential, so it has no share link. The label opens it for signed-in staff only.
                </p>

                <div class="mt-5 flex flex-wrap items-center justify-center gap-3">
                    <a :href="'/documents/qr/download/' + document.qr_code_path" class="inline-flex items-center gap-2 rounded-lg bg-[#00aeef] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#009bd8]">
                        <i class="fa-solid fa-download" aria-hidden="true"></i>Download PNG
                    </a>
                    <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50" @click="copyTrackingUrl">
                        <i :class="copiedTracking ? 'fa-solid fa-check' : 'fa-solid fa-copy'" aria-hidden="true"></i>{{ copiedTracking ? 'Copied' : 'Copy link' }}
                    </button>
                </div>
            </div>
        </ConfirmActionModal>

        <ConfirmActionModal
            :open="logging"
            :processing="logForm.processing"
            title="Log action"
            message="Record what you did with this document — that it reached you, that you reviewed it, or that you passed it on."
            confirm-label="Log action"
            icon="fa-solid fa-pen-to-square"
            @close="logging = false"
            @confirm="submitLog">
            <div class="mt-5 space-y-4 text-left">
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Action</span>
                    <select v-model="logForm.action" class="field">
                        <option v-for="option in actions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Remarks (optional)</span>
                    <textarea v-model="logForm.remarks" rows="3" class="field" :class="{ 'border-rose-500': logForm.errors.remarks }"></textarea>
                    <p v-if="logForm.errors.remarks" class="error">{{ logForm.errors.remarks }}</p>
                </label>
            </div>
        </ConfirmActionModal>

        <!--
            The document's one share link, in the same shape as the QR Tracker
            above: the credential shown plainly with a Copy button, and the
            controls that change it underneath.

            One link, not a list. Several links to one document is several
            credentials with separate expiries and caps, and nothing on screen
            can say which of them the message somebody sent last week carried.
            So the terms are *edited* — the URL already handed out keeps
            working, which is what somebody narrowing a link from download to
            view is actually asking for — and Revoke is the deliberate act
            that ends it.
        -->
        <ConfirmActionModal
            :open="sharing"
            :processing="linkForm.processing"
            title="Share by link"
            :message="linkForm.ability === 'restricted'
                ? 'One link, whose terms you choose. On the restricted setting below it opens only for the people this document is already for; widen it and anyone holding it can open the document without signing in.'
                : 'Anyone holding this link can open the document without signing in. Its terms can be changed, its address replaced and the link revoked at any time.'"
            :confirm-label="link ? 'Save changes' : 'Create link'"
            icon="fa-solid fa-link"
            @close="sharing = false"
            @confirm="submitLink">
            <div v-if="link" class="mt-5 text-left">
                <p class="text-xs font-bold uppercase tracking-wide text-slate-500">The link</p>
                <div class="mt-2 flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <i class="fa-solid fa-link text-slate-400" aria-hidden="true"></i>
                    <span class="min-w-0 flex-1 break-all font-mono text-xs text-slate-600">{{ link.url }}</span>
                    <button type="button" class="inline-flex items-center gap-2 rounded-lg bg-[#00aeef] px-3 py-1.5 text-xs font-bold text-white transition hover:bg-[#009bd8]" @click="copyLink(link)">
                        <i :class="copied === link.id ? 'fa-solid fa-check' : 'fa-solid fa-copy'" aria-hidden="true"></i>{{ copied === link.id ? 'Copied' : 'Copy link' }}
                    </button>
                </div>
                <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-slate-500">
                        Opened {{ link.view_count }} {{ link.view_count === 1 ? 'time' : 'times' }}<span v-if="link.max_views"> of {{ link.max_views }}</span>.
                        Each visitor is asked who they are, and the answer is recorded in the history below.
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Regenerate, not revoke, as the control in reach.
                             "This URL has gone further than I meant it to" is
                             the thing that actually happens, and revoking
                             answers it by leaving the document with no link
                             at all — which for a public one is also the
                             target of every label already printed. A new
                             address keeps the terms, keeps the label working
                             once it is redrawn, and cuts off the old URL. -->
                        <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-amber-200 px-3 py-1.5 text-xs font-bold text-amber-700 transition hover:bg-amber-50" @click="regenerating = true">
                            <i class="fa-solid fa-rotate" aria-hidden="true"></i>Regenerate link
                        </button>
                        <!-- Still here, quieter: ending the link entirely is a
                             different act from re-addressing it, and it has to
                             stay possible from where you can see the link. -->
                        <button type="button" class="rounded-lg px-2 py-1.5 text-xs font-bold text-rose-600 transition hover:bg-rose-50" @click="revoking = true">
                            Revoke instead
                        </button>
                    </div>
                </div>
            </div>

            <div class="mt-5 space-y-4 text-left">
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">What the link allows</span>
                    <select v-model="linkForm.ability" class="field">
                        <!--
                            Offered only where it means something. On a
                            restricted document "people who already have
                            access" is the tagged list; on a public one it is
                            everybody, so the option would be a second name
                            for View only.
                        -->
                        <option v-if="visibility && visibility.restricted" value="restricted">Restricted — people with access only</option>
                        <option value="view">View only</option>
                        <option value="download">View and download</option>
                    </select>
                    <!--
                        What the choice actually does, stated at the moment it
                        is made rather than in a paragraph underneath.

                        The difference between these is not how much a holder
                        may do — it is whether the URL is a key at all. Getting
                        that wrong on a restricted document hands it to whoever
                        photographs the folder it is printed on, so the sentence
                        says so in those words.
                    -->
                    <p v-if="linkForm.ability === 'restricted'" class="mt-1 text-xs text-slate-500">
                        The link opens only for you and the people tagged on this document. Anyone else is told it exists and is restricted — so a copy of the URL, or a photograph of the printed label, gives nothing away.
                    </p>
                    <p v-else class="mt-1 text-xs text-slate-500">
                        <span v-if="visibility && visibility.restricted" class="font-semibold text-amber-700">Anyone holding this URL can open the document without an account, even though it is {{ visibility.label.toLowerCase() }}.</span>
                        <span v-else>Anyone holding this URL can open the document without an account.</span>
                        {{ linkForm.ability === 'download' ? 'They can also take a copy.' : 'No copy leaves the server.' }}
                        A link can never edit, share or delete the document.
                    </p>
                </label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-semibold text-slate-700">Expires (optional)</span>
                        <input v-model="linkForm.expires_at" type="datetime-local" class="field" :class="{ 'border-rose-500': linkForm.errors.expires_at }" />
                        <p v-if="linkForm.errors.expires_at" class="error">{{ linkForm.errors.expires_at }}</p>
                    </label>
                    <label class="block">
                        <span class="text-sm font-semibold text-slate-700">Maximum opens (optional)</span>
                        <input v-model.number="linkForm.max_views" type="number" min="1" class="field" :class="{ 'border-rose-500': linkForm.errors.max_views }" placeholder="Unlimited" />
                        <p v-if="linkForm.errors.max_views" class="error">{{ linkForm.errors.max_views }}</p>
                    </label>
                </div>
                <p class="rounded-xl bg-slate-50 px-4 py-3 text-xs text-slate-500">
                    Both limits are checked on every open, not only when the link is made — and the link stops working
                    immediately if this document is ever reclassified as confidential.
                    <span v-if="link">Changing them here keeps the same URL, so nobody you have already sent it to is cut off.</span>
                </p>
            </div>
        </ConfirmActionModal>

        <!-- The one control in the share dialog that reaches people who are
             not on screen: everybody already holding the URL loses it. So it
             is confirmed, and the confirmation says that rather than asking
             "are you sure". -->
        <ConfirmActionModal
            :open="regenerating"
            layer-class="z-[60]"
            title="Give this link a new address?"
            message="The current URL stops working immediately, for everyone you have already sent it to. What the link allows, when it expires and how many opens it has left are all kept, and the QR label is redrawn to match."
            icon="fa-solid fa-rotate"
            tone="danger"
            confirm-label="Regenerate link"
            @close="regenerating = false"
            @confirm="regenerateLink" />

        <ConfirmActionModal
            :open="revoking"
            layer-class="z-[60]"
            title="Revoke this link?"
            :message="'The URL stops working for everyone you have sent it to, and this document is left with no link at all'
                + (document.visibility === 'public' ? ' — its printed QR label will fall back to the tracking page, which asks whoever scans it to sign in.' : '.')
                + ' Making a new one later gives a different address.'"
            icon="fa-solid fa-link-slash"
            tone="danger"
            confirm-label="Revoke link"
            @close="revoking = false"
            @confirm="revokeLink" />

        <!--
            Full screen, with the version history beside the file.

            The aside is the same VersionList the drawer renders, so a row
            cannot gain a badge in one place and not the other. Showing a
            version from here keeps the overlay open: at full size the file
            *is* the screen, so swapping the bytes is the visible result and
            closing would hide it — the opposite of the drawer, where the
            preview sits behind the panel.
        -->
        <!-- A comment is somebody's words, so removing one is confirmed
             rather than done on a single click.

             layer-class, because this is reachable from inside the
             full-screen preview (z-[70]) as well as from the page drawer:
             at the default z-50 the confirmation would open behind the
             thing it was opened from. -->
        <ConfirmActionModal
            :open="deletingComment !== null"
            layer-class="z-[80]"
            title="Delete this comment?"
            :message="deletingComment ? 'Written by ' + deletingComment.author + '. This cannot be undone.' : ''"
            icon="fa-solid fa-trash"
            tone="danger"
            confirm-label="Delete comment"
            @close="deletingComment = null"
            @confirm="removeComment" />

        <FilePreviewModal
            :open="previewing"
            :file="previewFile"
            :watermark="watermark"
            aside-label="Version history"
            panel-label="Comments"
            :panel-count="versionComments.length"
            :panel-open="fullscreenComments"
            @update:panel-open="setFullscreenComments"
            @close="closeFullscreen">
            <!-- The same CommentPanel the page drawer renders, sharing the
                 one draft: typing a comment, going full screen and finding
                 the box empty would be the cost of a second instance owning
                 its own state. -->
            <template #panel>
                <CommentPanel
                    v-model="commentForm.body"
                    :comments="versionComments"
                    :other-count="otherComments"
                    :version="previewFile ? previewFile.version : null"
                    :enabled="commentsEnabled"
                    :processing="commentForm.processing"
                    :error="commentForm.errors.body"
                    :viewer-id="$page.props.auth.user ? $page.props.auth.user.id : null"
                    @post="postComment"
                    @remove="deletingComment = $event" />
            </template>

            <template v-if="versions && versions.versions.length > 1" #aside>
                <!-- No heading here: the modal draws aside-label as the
                     panel's own fixed header, so the title stays put while
                     the list scrolls. -->
                <VersionList :entries="versions.versions" :shown="shownVersion" @show="showVersion($event, { keepFullscreen: true })" />
            </template>
        </FilePreviewModal>

        <TrailFilters
            :open="filteringTrail"
            :filters="trail"
            :actions="logActions"
            :offices="logOffices"
            @close="filteringTrail = false"
            @apply="applyTrailFilters" />

    </AdminShell>
`,
});
