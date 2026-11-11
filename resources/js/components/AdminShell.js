import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, defineComponent, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ConfirmActionModal from './ConfirmActionModal';
import FeedbackModal from './FeedbackModal';
import NotificationBell from './NotificationBell';
import ThemeToggle from './ThemeToggle';
import TutorialMenu from './TutorialMenu';
import UserAvatar from './UserAvatar';
import { navigationState } from '../navigationState';
import useCloseOnTutorial from '../composables/useCloseOnTutorial';

/**
 * The layout every signed-in page uses: sticky sub-header, fixed sidebar,
 * content slot.
 *
 * The module list is filtered against the shared `auth.permissions` prop, and
 * every entry below names the permission it needs. An entry with
 * `permission: null` is one whose route is genuinely open to any authenticated
 * user — the dashboard, the account page — or deliberately public, like the QR
 * scanner and track-by-code, which must resolve for a signed-out reader of a
 * public document.
 *
 * The filter and the route middleware in routes/web.php are one mechanism in
 * two halves. Add an entry here and you add its `permission:` gate there, in
 * the same change: a sidebar entry hidden over an open route reads as
 * protection that is not there. See documentation/architecture.md.
 *
 * Note that an entry names the permission for *reaching the page*, which is not
 * always `{key}.read`.
 *
 * Four entries have been folded away rather than hidden, and the distinction
 * matters: "All Documents" is a tab on Documents, "Upload Document" is a button
 * on it, "Scan QR Code" is one half of its tracking modal, and "Shared with Me"
 * is part of My Files. None of their routes were left gating nothing — each one
 * either moved into the page that absorbed it or stayed as a redirect.
 */
const MODULES = [
    { key: 'dashboard', group: 'Overview', label: 'Dashboard', href: '/dashboard', icon: 'fa-solid fa-chart-line', permission: null },
    { key: 'documents', group: 'Documents', label: 'Documents', href: '/documents', icon: 'fa-solid fa-folder', permission: 'documents.read' },
    { key: 'track', group: 'Documents', label: 'Track Document', href: '/documents/track', icon: 'fa-solid fa-magnifying-glass-location', permission: null },
    { key: 'files', group: 'Storage', label: 'My Files', href: '/files', icon: 'fa-solid fa-folder-tree', permission: 'files.read' },
    { key: 'files-browse', group: 'Storage', label: 'Browse Storage', href: '/files/browse', icon: 'fa-solid fa-magnifying-glass', permission: 'files.manage' },
    { key: 'files-trash', group: 'Storage', label: 'Trash', href: '/files/trash', icon: 'fa-solid fa-trash-can', permission: 'files.delete' },
    { key: 'download-logs', group: 'Reports', label: 'Download Logs', href: '/admin/download-logs', icon: 'fa-solid fa-clock-rotate-left', permission: 'download-logs.read' },
    { key: 'activity-trails', group: 'Reports', label: 'Activity Trail', href: '/activity-trails', icon: 'fa-solid fa-list-check', permission: 'activity-trails.read' },
    { key: 'users', group: 'Directory', label: 'Users', href: '/users', icon: 'fa-solid fa-users', permission: 'users.read' },
    { key: 'divisions', group: 'Directory', label: 'Divisions', href: '/divisions', icon: 'fa-solid fa-sitemap', permission: 'divisions.read' },
    { key: 'sections', group: 'Directory', label: 'Sections', href: '/sections', icon: 'fa-solid fa-diagram-project', permission: 'sections.read' },
    { key: 'document-types', group: 'Settings', label: 'Document Types', href: '/document-types', icon: 'fa-solid fa-tags', permission: 'document-types.read' },
    { key: 'upload-settings', group: 'Settings', label: 'Upload Rules', href: '/upload-settings', icon: 'fa-solid fa-sliders', permission: 'upload-settings.read' },
    { key: 'roles', group: 'Settings', label: 'Roles and Permissions', href: '/roles', icon: 'fa-solid fa-user-shield', permission: 'roles.read' },
    { key: 'account', group: 'Account', label: 'My Account', href: '/profile', icon: 'fa-solid fa-circle-user', permission: null },
];

const GROUP_ORDER = ['Overview', 'Documents', 'Storage', 'Reports', 'Directory', 'Settings', 'Account'];

export default defineComponent({
    name: 'AdminShell',
    components: { ConfirmActionModal, FeedbackModal, Link, NotificationBell, ThemeToggle, TutorialMenu, UserAvatar },
    props: {
        active: { type: String, required: true },
        title: { type: String, required: true },
    },
    setup() {
        const page = usePage();

        const confirmingLogout = ref(false);
        const loggingOut = ref(false);
        const logout = () => {
            loggingOut.value = true;
            router.post('/logout', {}, {
                onFinish: () => {
                    loggingOut.value = false;
                    confirmingLogout.value = false;
                },
            });
        };

        // Flash messages surface as a modal, not a toast — there is no toast
        // component in this design system.
        const feedback = ref({ message: '', open: false, title: '', tone: 'success' });
        const showFeedback = (title, message, tone) => {
            if (!message) return;
            feedback.value = { message, open: true, title, tone };
        };
        watch(() => page.props.flash?.success, (message) => showFeedback('Saved successfully', message, 'success'), { immediate: true });
        watch(() => page.props.flash?.error, (message) => showFeedback('Something went wrong', message, 'error'), { immediate: true });

        const authUser = computed(() => page.props.auth?.user ?? null);

        const sidebarOpen = ref(false);
        const profileMenuOpen = ref(false);

        const profileMenuRoot = ref(null);
        const closeProfileMenu = (event) => {
            if (!profileMenuRoot.value?.contains(event.target)) profileMenuOpen.value = false;
        };
        onMounted(() => document.addEventListener('click', closeProfileMenu));
        onBeforeUnmount(() => document.removeEventListener('click', closeProfileMenu));

        // The sidebar is fixed on desktop, so its top edge sits below the
        // measured navbar plus this header, and its bottom edge rises above the
        // site footer while that footer is in view.
        const headerEl = ref(null);
        const updateSidebarTopVar = () => {
            if (headerEl.value) {
                document.documentElement.style.setProperty(
                    '--admin-shell-sidebar-top',
                    `calc(var(--app-navbar-h, 5rem) + ${headerEl.value.offsetHeight}px + 2rem)`,
                );
            }
        };
        let headerResizeObserver = null;
        onMounted(() => {
            updateSidebarTopVar();
            headerResizeObserver = new ResizeObserver(updateSidebarTopVar);
            if (headerEl.value) headerResizeObserver.observe(headerEl.value);
            window.addEventListener('resize', updateSidebarTopVar);
        });
        onBeforeUnmount(() => {
            headerResizeObserver?.disconnect();
            window.removeEventListener('resize', updateSidebarTopVar);
        });

        const sidebarBottom = ref('1.5rem');
        const updateSidebarBottom = () => {
            // Only the app footer counts; a page-level <footer> such as Pagination is ignored.
            const footerTop = document.querySelector('[data-app-footer]')?.getBoundingClientRect().top ?? Infinity;
            sidebarBottom.value = `calc(${Math.max(0, window.innerHeight - footerTop)}px + 1.5rem)`;
        };
        let pageResizeObserver = null;
        onMounted(() => {
            updateSidebarBottom();
            window.addEventListener('scroll', updateSidebarBottom, { passive: true });
            window.addEventListener('resize', updateSidebarBottom);
            pageResizeObserver = new ResizeObserver(updateSidebarBottom);
            pageResizeObserver.observe(document.body);
        });
        onBeforeUnmount(() => {
            window.removeEventListener('scroll', updateSidebarBottom);
            window.removeEventListener('resize', updateSidebarBottom);
            pageResizeObserver?.disconnect();
        });

        // The sidebar scrolls on its own when the menu is taller than the
        // viewport; keep the active module in view until the user scrolls it
        // themselves.
        const sidebarEl = ref(null);
        let userScrolledSidebar = false;
        const scrollActiveIntoView = () => {
            const sidebar = sidebarEl.value;
            const activeLink = sidebar?.querySelector('[aria-current="page"]');
            if (!sidebar || !activeLink || userScrolledSidebar || sidebar.scrollHeight <= sidebar.clientHeight) return;
            const linkTop = activeLink.getBoundingClientRect().top - sidebar.getBoundingClientRect().top + sidebar.scrollTop;
            sidebar.scrollTop = linkTop - (sidebar.clientHeight - activeLink.offsetHeight) / 2;
        };
        const markUserScrolled = () => { userScrolledSidebar = true; };
        let sidebarResizeObserver = null;
        onMounted(() => {
            if (!sidebarEl.value) return;
            sidebarResizeObserver = new ResizeObserver(scrollActiveIntoView);
            sidebarResizeObserver.observe(sidebarEl.value);
            ['wheel', 'touchmove', 'keydown', 'mousedown'].forEach((type) => sidebarEl.value.addEventListener(type, markUserScrolled, { passive: true }));
            nextTick(scrollActiveIntoView);
            document.fonts?.ready.then(scrollActiveIntoView);
        });
        onBeforeUnmount(() => sidebarResizeObserver?.disconnect());
        watch(sidebarOpen, (open) => {
            if (open) {
                userScrolledSidebar = false;
                nextTick(scrollActiveIntoView);
            }
        });

        /*
         * The modules this user can actually reach.
         *
         * Both the grouped nav and the quick-search below read this, never the
         * raw MODULES array — otherwise the search would happily jump someone
         * to a page the sidebar is hiding, which is the bug that makes a
         * filtered sidebar feel like a lie.
         */
        const visibleModules = computed(() => {
            const permissions = page.props.auth?.permissions ?? [];

            return MODULES.filter((module) => module.permission === null
                || permissions.includes(module.permission));
        });

        // Jumping straight to a module by name.
        const moduleQuery = ref('');

        /*
         * The shell's own overlays get out of the way when a tour starts.
         *
         * The sidebar is the exception that proves the rule: the system tour
         * *opens* it deliberately on a narrow screen, through the step's
         * `activate` selector, after this has closed it. Closing first and
         * opening on purpose is predictable; leaving whatever the user had open
         * is not.
         */
        useCloseOnTutorial(() => {
            sidebarOpen.value = false;
            profileMenuOpen.value = false;
            moduleQuery.value = '';
        });

        const moduleSearchRoot = ref(null);
        const highlighted = ref(0);
        const moduleMatches = computed(() => {
            const needle = moduleQuery.value.trim().toLowerCase();

            if (needle === '') {
                return [];
            }

            // The group name is included so "documents" or "directory" finds a
            // section, and the label is weighted ahead of it so an exact module
            // name never sits below a group match.
            return visibleModules.value
                .map((module) => {
                    const label = module.label.toLowerCase();
                    const group = (module.group ?? '').toLowerCase();
                    const rank = label.startsWith(needle) ? 0 : label.includes(needle) ? 1 : group.includes(needle) ? 2 : -1;

                    return { ...module, rank };
                })
                .filter((module) => module.rank !== -1)
                .sort((a, b) => a.rank - b.rank || a.label.localeCompare(b.label))
                .slice(0, 8);
        });
        const searchOpen = computed(() => moduleQuery.value.trim() !== '');
        const clearModuleSearch = () => { moduleQuery.value = ''; highlighted.value = 0; };
        const chooseMatch = () => {
            const match = moduleMatches.value[highlighted.value];

            if (!match) {
                return;
            }

            clearModuleSearch();
            sidebarOpen.value = false;
            router.visit(match.href);
        };
        const moveHighlight = (step) => {
            const total = moduleMatches.value.length;
            if (total === 0) return;
            highlighted.value = (highlighted.value + step + total) % total;
        };
        // Typing changes the list under the cursor, so start from the top again.
        watch(moduleQuery, () => { highlighted.value = 0; });
        const closeModuleSearch = (event) => {
            if (!moduleSearchRoot.value?.contains(event.target)) clearModuleSearch();
        };
        onMounted(() => document.addEventListener('click', closeModuleSearch));
        onBeforeUnmount(() => document.removeEventListener('click', closeModuleSearch));

        const groupedModules = computed(() => GROUP_ORDER
            .map((label) => ({ label, items: visibleModules.value.filter((module) => module.group === label) }))
            .filter((section) => section.items.length > 0));

        return {
            authUser,
            chooseMatch,
            clearModuleSearch,
            confirmingLogout,
            feedback,
            groupedModules,
            headerEl,
            highlighted,
            loggingOut,
            logout,
            moduleMatches,
            moduleQuery,
            moduleSearchRoot,
            moveHighlight,
            navigationState,
            profileMenuOpen,
            profileMenuRoot,
            searchOpen,
            sidebarBottom,
            sidebarEl,
            sidebarOpen,
        };
    },
    template: `
    <main class="flex-1 bg-slate-50 pb-8">
        <header ref="headerEl" class="sticky top-[var(--app-navbar-h,5rem)] z-40 w-full border-b border-slate-200 bg-slate-50 shadow-[0_4px_6px_-4px_rgb(0_0_0_/_0.1)]">
            <div class="mx-auto flex w-full max-w-[1800px] items-center justify-between gap-3 px-4 py-4 sm:gap-4 sm:px-6 sm:py-6 lg:px-10">
                <div class="flex min-w-0 items-center gap-3 sm:gap-5">
                    <button type="button" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-slate-300 text-slate-600 transition hover:border-[#07559e] hover:text-[#07559e] lg:hidden" data-tour="open-sidebar" aria-label="Open modules menu" @click="sidebarOpen = true">
                        <i class="fa-solid fa-bars" aria-hidden="true"></i>
                    </button>
                    <div class="min-w-0">
                        <p class="truncate text-[11px] font-semibold uppercase tracking-[0.16em] text-[#07559e] sm:text-xs">DocTrack</p>
                        <h1 class="mt-0.5 text-lg font-semibold leading-tight text-slate-900 sm:mt-1 sm:text-xl lg:text-2xl">{{ title }}</h1>
                    </div>
                </div>
                <div class="flex shrink-0 items-center gap-1 sm:gap-3">
                    <span data-tour="notifications"><NotificationBell /></span>
                    <TutorialMenu />
                    <span data-tour="theme-toggle"><ThemeToggle /></span>
                    <div class="relative" ref="profileMenuRoot">
                        <button type="button" class="flex items-center gap-2 rounded-lg px-1.5 py-1.5 text-left transition hover:bg-slate-100 sm:gap-3 sm:px-3 sm:py-2" data-tour="account" @click="profileMenuOpen = !profileMenuOpen">
                            <!-- The face, from the same component every other
                                 list of people uses: an uploaded photo, or an
                                 avatar generated from the person's own seed. -->
                            <UserAvatar :person="authUser" class="!h-9 !w-9 !text-xs sm:!h-12 sm:!w-12 sm:!text-sm" />
                            <span class="hidden text-left sm:block">
                                <span class="block text-sm font-semibold text-slate-900">{{ authUser?.name }}</span>
                                <span class="block text-xs text-slate-500">{{ authUser?.employee_id }}</span>
                            </span>
                            <i class="fa-solid fa-chevron-down hidden text-xs text-slate-400 sm:block" aria-hidden="true"></i>
                        </button>
                        <div v-show="profileMenuOpen" class="absolute right-0 z-30 mt-2 w-56 rounded-lg border border-slate-200 bg-white py-2 shadow-lg">
                            <Link href="/profile" class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-100" @click="profileMenuOpen = false"><i class="fa-solid fa-circle-user w-4 text-center" aria-hidden="true"></i>My Account</Link>
                            <Link href="/" class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-100" @click="profileMenuOpen = false"><i class="fa-solid fa-house w-4 text-center" aria-hidden="true"></i>Public Site</Link>
                            <button type="button" class="flex w-full items-center gap-2 border-t border-slate-100 px-4 py-2 text-left text-sm text-rose-600 hover:bg-rose-50" @click="profileMenuOpen = false; confirmingLogout = true"><i class="fa-solid fa-arrow-right-from-bracket w-4 text-center" aria-hidden="true"></i>Sign Out</button>
                        </div>
                    </div>
                </div>
            </div>
        </header>
        <section class="mx-auto w-full max-w-[1800px] px-4 sm:px-6 lg:px-10">
            <div class="mt-8 grid gap-8 lg:grid-cols-[280px_minmax(0,1fr)]">
                <div v-if="sidebarOpen" class="fixed inset-0 z-40 bg-slate-950/50 lg:hidden" @click="sidebarOpen = false"></div>
                <!-- Desktop: the grid cell only reserves the 280px column; the sidebar itself is position:fixed
                     from below the sticky header to 1.5rem above the viewport bottom, rising to stay above the
                     site footer while it is in view. -->
                <div class="contents lg:block">
                <aside data-tour="sidebar" ref="sidebarEl" class="fixed inset-y-0 left-0 z-50 w-72 -translate-x-full overflow-y-auto overscroll-contain border border-slate-200 bg-white px-3 pb-3 scroll-shadows transition-transform duration-300 ease-out lg:bottom-[var(--admin-shell-sidebar-bottom,1.5rem)] lg:left-auto lg:top-[var(--admin-shell-sidebar-top,7.5rem)] lg:z-10 lg:w-[280px] lg:translate-x-0" :class="sidebarOpen ? 'translate-x-0' : ''" :style="{ '--admin-shell-sidebar-bottom': sidebarBottom }">
                    <div class="mb-2 flex justify-end px-1 pt-3 lg:hidden">
                        <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full text-2xl leading-none text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Close modules menu" @click="sidebarOpen = false">&times;</button>
                    </div>
                    <div ref="moduleSearchRoot" class="sticky top-0 z-30 -mx-3 mb-3 border-b border-slate-100 bg-white px-4 pb-3 pt-3">
                      <div class="relative">
                        <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                        <input
                            v-model="moduleQuery"
                            type="search"
                            role="combobox"
                            aria-label="Search modules"
                            aria-controls="module-search-results"
                            :aria-expanded="searchOpen"
                            autocomplete="off"
                            placeholder="Search modules…"
                            class="h-10 w-full rounded-lg border border-slate-300 bg-white pl-9 pr-3 text-sm text-slate-700 placeholder:text-slate-400 focus:border-[#07559e] focus:outline-none"
                            @keydown.down.prevent="moveHighlight(1)"
                            @keydown.up.prevent="moveHighlight(-1)"
                            @keydown.enter.prevent="chooseMatch"
                            @keydown.esc.prevent="clearModuleSearch" />
                        <!-- A real dropdown over the list, not a filter applied to it.
                             It is capped and scrolls, so the sidebar's own overflow never clips it. -->
                        <div v-if="searchOpen" id="module-search-results" role="listbox" class="absolute inset-x-0 top-full z-20 mt-1 max-h-72 overflow-y-auto rounded-xl border border-slate-200 bg-white py-1 shadow-xl">
                            <Link
                                v-for="(module, index) in moduleMatches"
                                :key="module.key"
                                :href="module.href"
                                role="option"
                                :aria-selected="index === highlighted"
                                class="flex items-center gap-3 px-3 py-2.5 text-sm font-semibold transition"
                                :class="index === highlighted ? 'bg-sky-100 text-[#07559e]' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'"
                                @mouseenter="highlighted = index"
                                @click="clearModuleSearch(); sidebarOpen = false;">
                                <i :class="module.icon" class="w-5 shrink-0 text-center" aria-hidden="true"></i>
                                <span class="min-w-0 flex-1 truncate" :title="module.label">{{ module.label }}</span>
                                <span v-if="module.group" class="shrink-0 text-[11px] font-bold uppercase tracking-wide text-slate-400">{{ module.group }}</span>
                            </Link>
                            <p v-if="!moduleMatches.length" class="px-3 py-4 text-center text-sm text-slate-500">No module matches “{{ moduleQuery }}”.</p>
                        </div>
                      </div>
                    </div>
                    <nav aria-label="Modules">
                        <div v-for="(section, index) in groupedModules" :key="section.label" :class="index > 0 ? 'mt-4' : ''">
                            <p class="px-3 pb-2.5 pt-1 text-xs font-bold uppercase tracking-[0.16em] text-slate-400">{{ section.label }}</p>
                            <div class="space-y-1">
                                <Link v-for="module in section.items" :key="module.key" :href="module.href" :aria-current="active === module.key ? 'page' : undefined" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold transition" :class="active === module.key ? 'bg-sky-100 text-[#07559e]' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'" @click="sidebarOpen = false"><i :class="module.icon" class="w-5 shrink-0 text-center" aria-hidden="true"></i><span class="min-w-0 flex-1 truncate" :title="module.label">{{ module.label }}</span></Link>
                            </div>
                        </div>
                    </nav>
                </aside>
                </div>
                <div data-admin-content data-tour="content" class="relative min-w-0" :class="navigationState.loading ? 'min-h-[70vh]' : ''">
                    <slot />
                    <div v-if="navigationState.loading" class="absolute inset-0 z-20 overflow-hidden bg-slate-50" role="status" aria-label="Loading page"><div class="animate-pulse space-y-6">
                        <div class="border border-slate-200 bg-white p-5 sm:p-7">
                            <div class="h-3 w-32 rounded bg-slate-200"></div>
                            <div class="mt-3 h-7 w-2/3 max-w-md rounded bg-slate-200"></div>
                            <div class="mt-3 h-4 w-full max-w-lg rounded bg-slate-100"></div>
                        </div>
                        <div class="border border-slate-200 bg-white p-5 sm:p-7">
                            <div class="h-10 w-full rounded-lg bg-slate-100"></div>
                            <div class="mt-6 space-y-4">
                                <div v-for="row in 6" :key="row" class="flex items-center gap-4">
                                    <div class="h-4 w-1/4 rounded bg-slate-200"></div>
                                    <div class="h-4 flex-1 rounded bg-slate-100"></div>
                                    <div class="hidden h-4 w-1/6 rounded bg-slate-200 sm:block"></div>
                                </div>
                            </div>
                        </div>
                    </div></div>
                </div>
            </div>
        </section>
        <FeedbackModal :open="feedback.open" :title="feedback.title" :message="feedback.message" :tone="feedback.tone" :icon="feedback.tone === 'error' ? 'fa-solid fa-circle-exclamation' : 'fa-solid fa-circle-check'" @close="feedback.open = false" />
        <ConfirmActionModal :open="confirmingLogout" :processing="loggingOut" title="Sign out?" message="Are you sure you want to sign out of your account?" confirm-label="Sign out" tone="danger" icon="fa-solid fa-arrow-right-from-bracket" @close="confirmingLogout = false" @confirm="logout" />
    </main>
`,
});
