/**
 * Uploading a tracked document, and what the tracking code is for.
 *
 * Two tours: raising one, and working with the register.
 */
const upload = {
    key: 'upload-document',
    label: 'Upload and track a document',
    hint: 'Tracking code, QR label, who can see it',
    module: 'Documents',
    icon: 'fa-solid fa-cloud-arrow-up',
    paths: ['/documents'],
    permission: 'documents.create',
    pages: [
        {
            href: '/documents',
            steps: [
                {
                    popover: {
                        title: 'Tracked documents',
                        description: 'A tracked document gets a code and a printable QR label, and every view, download and hand-off after that is recorded. This is the register of the ones you are party to.',
                    },
                },
                {
                    element: '[data-tour="documents-upload"]',
                    popover: {
                        title: 'Start here',
                        description: 'Upload takes you to the form. Let us go.',
                    },
                    fallback: {
                        title: 'Uploading',
                        description: 'Upload sits at the top of this page, beside Track document.',
                    },
                },
            ],
        },
        {
            href: '/documents/upload',
            steps: [
                {
                    element: '[data-tour="document-title"]',
                    popover: {
                        title: 'Title and type',
                        description: 'The type decides the prefix of the tracking code — a memo becomes MEMO-…, a special order SO-…. Each type keeps its own numbering, per day.',
                    },
                    fallback: {
                        title: 'The upload form',
                        description: 'Title, type, the file, and who may see it.',
                    },
                },
                {
                    element: '[data-tour="document-file"]',
                    popover: {
                        title: 'The file',
                        description: 'Which formats and what size are allowed is set by your administrator, and the limits are shown beside the field.',
                    },
                },
                {
                    element: '[data-tour="document-visibility"]',
                    popover: {
                        title: 'Who can see it',
                        description: 'Public means anyone signed in who has the tracking code. Private means only the people you name — and they are notified. This is set once, at upload: changing it afterwards would rewrite the record those notifications already went out about.',
                    },
                },
                {
                    element: '[data-tour="document-submit"]',
                    popover: {
                        title: 'Then print the label',
                        description: 'After uploading you get the tracking code and a QR label to print and stick on the physical folder. Scanning it opens this document.',
                    },
                },
            ],
        },
    ],
};

const register = {
    key: 'document-register',
    label: 'Work with the office register',
    hint: 'Search, filter, open, hand off',
    module: 'Documents',
    icon: 'fa-solid fa-folder-open',
    paths: ['/documents'],
    permission: 'documents-all.read',
    pages: [
        {
            href: '/documents?tab=all',
            steps: [
                {
                    popover: {
                        title: 'Every document in the office',
                        description: 'The All tab is not only yours. You can see that a document exists and what type it is; whether you can open the file itself still depends on its visibility.',
                    },
                },
                {
                    element: '[data-tour="index-search"]',
                    popover: {
                        title: 'Find one',
                        description: 'Search by title or tracking code. Typing filters as you go.',
                    },
                },
                {
                    element: '[data-tour="index-filters"]',
                    popover: {
                        title: 'Narrow it down',
                        description: 'By document type, and by which of the three tabs you are looking at.',
                    },
                },
                {
                    element: '[data-tour="pagination"]',
                    popover: {
                        title: 'Your place is kept',
                        description: 'The page, the search and the filters all live in the address bar — so you can bookmark a view, or send someone the exact list you are looking at.',
                    },
                },
            ],
        },
    ],
};

export default [upload, register];
