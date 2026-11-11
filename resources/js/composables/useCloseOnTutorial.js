import { onBeforeUnmount, onMounted } from 'vue';

/**
 * Window event the tutorial runner dispatches before each tour page starts.
 */
export const TUTORIAL_STARTED_EVENT = 'doctrack:tutorial-started';

/**
 * Closes this component's modal, drawer or menu when a tutorial starts, so a
 * tour never runs behind — or under — an open overlay.
 *
 * `close` must be safe to call when nothing is open.
 */
export default function useCloseOnTutorial(close) {
    const handler = () => close();
    onMounted(() => window.addEventListener(TUTORIAL_STARTED_EVENT, handler));
    onBeforeUnmount(() => window.removeEventListener(TUTORIAL_STARTED_EVENT, handler));
}
