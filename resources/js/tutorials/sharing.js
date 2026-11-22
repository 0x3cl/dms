/**
 * Sharing, and what the three privacy levels actually mean.
 *
 * The most consequential tour in the set: the difference between private and
 * confidential is the difference between "nobody unless I say so" and "not even
 * through a folder I shared", and getting that wrong is a disclosure.
 */
export default {
    key: 'sharing',
    label: 'Share a file or folder',
    hint: 'Links, tagging, confidentiality',
    module: 'Storage',
    icon: 'fa-solid fa-share-nodes',
    paths: ['/files'],
    permission: 'files.share',
    pages: [
        {
            href: '/files',
            steps: [
                {
                    popover: {
                        title: 'Three levels of privacy',
                        description: 'Private is the default: only you, and whoever you name. Public means anyone signed in can open it. Confidential is stricter than private — read on, because it is the one worth understanding.',
                    },
                },
                {
                    element: '[data-tour="file-row"]',
                    popover: {
                        title: 'Confidential is not just "very private"',
                        description: 'A confidential file can only be reached by someone named on the file itself. Sharing the folder it sits in does not reach it, and no share link can ever be made for it. Use it when the folder may be shared but one item inside must not travel with it.',
                    },
                    fallback: {
                        title: 'Confidential is not just "very private"',
                        description: 'A confidential file can only be reached by someone named on the file itself. Sharing the folder it sits in does not reach it, and no share link can ever be made for it.',
                    },
                },
                {
                    element: '[data-tour="files-new-folder"]',
                    popover: {
                        title: 'Name people when you create the folder',
                        description: 'Tagging recipients happens where you are already deciding who something is for: New folder has a recipient picker, and an uploaded document has one too. The folder then shows who holds it, and everything you put inside follows.',
                    },
                    fallback: {
                        title: 'Name people when you create the folder',
                        description: 'Tagging recipients happens at creation — the New folder dialog and the document upload form both have a picker.',
                    },
                },
                {
                    popover: {
                        title: 'Folders pass access down',
                        description: 'Share a folder and everything inside it follows — except confidential items. Where a subfolder has a share of its own, the nearer one wins, so a narrow grant deep in the tree is not quietly widened by a broad one near the top.',
                    },
                },
                {
                    popover: {
                        title: 'One share link, which you can change',
                        description: 'The Share option on a file or folder makes a link, and an item has one at a time — so there is never a question of which link somebody was sent. It can be view-only, expire, be limited to a number of opens, and be revoked. Changing its terms keeps the same URL, so nobody you have already sent it to is cut off. It never allows renaming or deleting, and never works for a confidential item, even if the item became confidential after you sent the link.',
                    },
                },
            ],
        },
        {
            href: '/files',
            steps: [
                {
                    popover: {
                        title: 'The other direction',
                        description: 'What other people have given you sits at the top of My Files, badged so you can tell it apart from your own. Taking a copy makes it yours — if they revoke the share later, your copy stays.',
                    },
                },
                {
                    element: '[data-tour="files-filter"]',
                    popover: {
                        title: 'Or filter down to it',
                        description: 'Filter has a Sharing option: everything you can see, only what you own, or only what was shared with you.',
                    },
                    fallback: {
                        title: 'Or filter down to it',
                        description: 'The Filter button can narrow the list to only what was shared with you.',
                    },
                },
            ],
        },
    ],
};
