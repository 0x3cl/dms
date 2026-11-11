import { router, usePage } from '@inertiajs/vue3';
import { driver } from 'driver.js';
import 'driver.js/dist/driver.css';
import { nextTick } from 'vue';
import { TUTORIAL_STARTED_EVENT } from '../composables/useCloseOnTutorial';
import { beginMascotStepChange, hideMascot, showMascot } from './mascot';

import systemTour from './system';
import documentsTour from './documents';
import trackingTour from './tracking';
import filesTour from './files';
import sharingTour from './sharing';
import rolesTour from './roles';
import accountTour from './account';

/**
 * Guided tours, on driver.js, with the DOST-PTRI mascot standing beside the
 * popover.
 *
 * **There is no backend.** No routes, no tables, no permission of its own — a
 * tour is a list of steps in a file here, and the menu filters them against the
 * shared `auth.permissions` prop so nobody is offered a walkthrough of a page
 * they cannot open.
 *
 * Ported from PROCMIS, deliberately leaner. PROCMIS's runner also carries
 * sample-data pages, a second "supplier" audience, and nested overlay tracking;
 * DocTrack has none of those, and porting the machinery for them would have
 * been dead code pretending to be capability.
 *
 * ## A tour
 *
 * ```js
 * {
 *     key: 'upload-document',          // unique id
 *     label: 'Upload and track a document',
 *     module: 'Documents',             // dropdown group heading
 *     icon: 'fa-solid fa-cloud-arrow-up',
 *     paths: ['/documents'],           // URL prefixes where it is listed first
 *     permission: 'documents.create',  // string, array (any-of), or null for everyone
 *     pages: [
 *         { href: '/documents', steps: [ … ] },
 *         { href: '/documents/upload', steps: [ … ] },
 *     ],
 * }
 * ```
 *
 * A **page** is a URL plus its steps. Finishing a page's last step continues on
 * the next page by visiting its `href`.
 *
 * A **step** is a driver.js step, plus two optional extras:
 * - `activate` — a selector, or list of them, clicked before the step runs, so
 *   a step can open the drawer or tab its element lives in.
 * - `fallback` — `{ title, description }` shown centred when the element is
 *   missing, ending the tour there. An empty folder has no row to point at, and
 *   a tour that silently stops is worse than one that says why.
 */
export const tutorials = [
    systemTour,
    documentsTour,
    trackingTour,
    filesTour,
    sharingTour,
    rolesTour,
    accountTour,
].flat();

/** The person's first name, so a tour can address them. */
const personalize = (text, auth) => {
    const first = (auth?.user?.name ?? '').trim().split(/\s+/)[0] || 'there';

    return String(text ?? '').replace(/\{name\}/g, first);
};

/** Is this tour offered to the signed-in user? */
export const isTutorialAvailable = (tutorial, auth) => {
    if (typeof tutorial.when === 'function' && !tutorial.when(auth)) {
        return false;
    }

    if (!tutorial.permission) {
        return true;
    }

    const held = auth?.permissions ?? [];
    const needed = Array.isArray(tutorial.permission) ? tutorial.permission : [tutorial.permission];

    return needed.some((permission) => held.includes(permission));
};

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Click whatever a step needs opened first.
 *
 * Each selector is clicked in order with a beat between them, because opening a
 * drawer and then a tab inside it needs the drawer rendered before the tab
 * exists to click.
 */
const activate = async (step) => {
    const selectors = Array.isArray(step.activate) ? step.activate : [step.activate];

    for (const selector of selectors.filter(Boolean)) {
        document.querySelector(selector)?.click();
        await nextTick();
        await wait(220);
    }
};

/** Run one page of a tour, resolving when it ends or moves on. */
const runPage = (tutorial, pageIndex, auth) => new Promise((resolve) => {
    const page = tutorial.pages[pageIndex];
    const isLastPage = pageIndex === tutorial.pages.length - 1;

    // Any open drawer, modal or menu closes itself, so the tour never runs
    // behind one. See useCloseOnTutorial.
    window.dispatchEvent(new Event(TUTORIAL_STARTED_EVENT));

    const steps = page.steps
        .map((step) => {
            if (!step.element) {
                return step;
            }

            const present = document.querySelector(step.element);

            if (present) {
                return step;
            }

            // The element is not on this page — an empty table has no first
            // row. If the step says what to do about that, show it and stop;
            // otherwise drop the step and carry on.
            return step.fallback
                ? { popover: { title: step.fallback.title, description: step.fallback.description }, __stop: true }
                : null;
        })
        .filter(Boolean);

    if (steps.length === 0) {
        resolve({ done: true });

        return;
    }

    let moveOn = false;

    const tour = driver({
        showProgress: steps.length > 1,
        allowClose: true,
        overlayOpacity: 0.6,
        popoverClass: 'doctrack-tour',
        nextBtnText: 'Next',
        prevBtnText: 'Back',
        doneBtnText: isLastPage ? 'Finish' : 'Next',
        steps: steps.map((step) => ({
            element: step.element,
            popover: {
                ...step.popover,
                title: personalize(step.popover?.title, auth),
                description: personalize(step.popover?.description, auth),
            },
        })),
        onHighlightStarted: (element, step, options) => {
            const index = options.state.activeIndex ?? 0;

            beginMascotStepChange();
            showMascot({
                stepNumber: index + 1,
                totalSteps: steps.length,
                element,
                hasElement: Boolean(element),
                costume: 'staff',
            });
        },
        onNextClick: async (element, step, options) => {
            const index = options.state.activeIndex ?? 0;
            const isLastStep = index === steps.length - 1;

            if (isLastStep) {
                moveOn = !steps[index].__stop && !isLastPage;
                tour.destroy();

                return;
            }

            const next = steps[index + 1];

            if (next?.element) {
                await activate(next);
            }

            tour.moveNext();
        },
        onDestroyed: () => {
            hideMascot();
            resolve({ done: !moveOn });
        },
    });

    tour.drive();
});

/**
 * Start a tour, walking its pages in order.
 *
 * Each page after the first is reached with a real Inertia visit and then given
 * a beat to render; driver.js measures an element's box, so highlighting one
 * before the page has painted puts the popover in the wrong place.
 */
export const openTutorial = async (tutorial) => {
    const auth = usePage().props.auth;

    for (let index = 0; index < tutorial.pages.length; index++) {
        const page = tutorial.pages[index];

        if (page.href && window.location.pathname + window.location.search !== page.href) {
            await new Promise((resolve) => router.visit(page.href, {
                preserveScroll: false,
                onFinish: resolve,
            }));
            await nextTick();
            await wait(350);
        }

        const first = page.steps.find((step) => step.element && step.activate);

        if (first) {
            await activate(first);
        }

        const { done } = await runPage(tutorial, index, auth);

        if (done) {
            break;
        }
    }

    tutorial.onEnd?.();
};
