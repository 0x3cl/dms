/**
 * The shell: where everything is.
 *
 * Offered to everyone (`permission: null`), because every signed-in user sees
 * this layout and nothing in it is gated.
 */
export default {
    key: 'system',
    label: 'Find your way around',
    hint: 'The sidebar, the bell, your account',
    module: 'Getting started',
    icon: 'fa-solid fa-compass',
    paths: ['/dashboard'],
    permission: null,
    pages: [
        {
            href: '/dashboard',
            steps: [
                {
                    popover: {
                        title: 'Welcome, {name}',
                        description: 'This is DocTrack. A short tour of the furniture — about a minute. You can leave at any point with Escape.',
                    },
                },
                {
                    element: '[data-tour="sidebar"]',
                    activate: '[data-tour="open-sidebar"]',
                    popover: {
                        title: 'Everything lives here',
                        description: 'The sidebar lists only what your role lets you open, so if something is missing it is not hidden — you do not have access to it. Ask an administrator if you need it for your work.',
                    },
                    fallback: {
                        title: 'The sidebar',
                        description: 'On a wider screen the sidebar sits down the left and lists every module you can open.',
                    },
                },
                {
                    element: '[data-tour="content"]',
                    popover: {
                        title: 'The page itself',
                        description: 'Your dashboard: what you have uploaded, what has been shared with you, and what is still in circulation.',
                    },
                },
                {
                    element: '[data-tour="notifications"]',
                    popover: {
                        title: 'Notifications',
                        description: 'When somebody shares a private document with you, it appears here.',
                    },
                },
                {
                    element: '[data-tour="tutorials"]',
                    popover: {
                        title: 'These tutorials',
                        description: 'The lightbulb. Tours for the page you are on are listed first.',
                    },
                },
                {
                    element: '[data-tour="account"]',
                    popover: {
                        title: 'Your account',
                        description: 'Your details, your password, the light and dark theme, and closing your account.',
                    },
                },
            ],
        },
    ],
};
