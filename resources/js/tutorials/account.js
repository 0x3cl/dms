/**
 * Your own account. Offered to everyone — nothing here is gated.
 */
export default {
    key: 'account',
    label: 'Your account and theme',
    hint: 'Details, password, dark mode, closing it',
    module: 'Getting started',
    icon: 'fa-solid fa-circle-user',
    paths: ['/profile'],
    permission: null,
    pages: [
        {
            href: '/profile',
            steps: [
                {
                    popover: {
                        title: 'Your account',
                        description: 'Your details, your password, and the theme.',
                    },
                },
                {
                    element: '[data-tour="account-tabs"]',
                    popover: {
                        title: 'Three tabs',
                        description: 'Profile, password, and closing the account. You sign in with your employee ID — an email address is only needed so you can reset your own password.',
                    },
                    fallback: {
                        title: 'Your account page',
                        description: 'Profile, password and account closure are on separate tabs here.',
                    },
                },
                {
                    element: '[data-tour="theme-toggle"]',
                    popover: {
                        title: 'Light or dark',
                        description: 'Remembered on this device, and applied before the page paints so you never get a flash of the wrong one.',
                    },
                    fallback: {
                        title: 'Light or dark',
                        description: 'The theme toggle sits in the header, next to the notification bell.',
                    },
                },
                {
                    popover: {
                        title: 'If you ever close it',
                        description: 'Anything you still own has to be handed to a colleague first — otherwise your files would belong to an account nobody can sign into, and nobody could open them. Documents you uploaded stay in the office register either way, with their history intact.',
                    },
                },
            ],
        },
    ],
};
