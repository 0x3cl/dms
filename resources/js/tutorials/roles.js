/**
 * For administrators: what a role is, and what it is not.
 */
export default {
    key: 'roles',
    label: 'Set up roles and permissions',
    hint: 'What a role can and cannot grant',
    module: 'Settings',
    icon: 'fa-solid fa-user-shield',
    paths: ['/roles'],
    permission: 'roles.write',
    pages: [
        {
            href: '/roles',
            steps: [
                {
                    popover: {
                        title: 'Roles decide what people can reach',
                        description: 'Every account holds exactly one role, and a role is a set of permissions. Changing a role takes effect for every holder on their next request.',
                    },
                },
                {
                    element: '[data-tour="add-button"]',
                    popover: {
                        title: 'A new role',
                        description: 'Start from nothing and tick what the job actually needs. SUPERADMIN cannot be edited or deleted — it is the role that can hand out every other grant.',
                    },
                },
                {
                    element: '[data-tour="index-search"]',
                    popover: {
                        title: 'A permission is not a licence over other people’s work',
                        description: 'Granting "delete" in Documents lets somebody withdraw their own uploads — not anyone else’s. Who may act on a particular record is decided per record, every time. Ticking anything also grants Read, because a role that may change what it cannot open is not a usable grant.',
                    },
                },
            ],
        },
    ],
};
