import { reactive } from 'vue';

/**
 * Whether a full page navigation is in flight. Set by NavigationSkeleton;
 * AdminShell reads it to skeleton only its content panel, keeping the sidebar.
 */
export const navigationState = reactive({ loading: false });
