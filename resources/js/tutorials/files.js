/**
 * Personal storage: folders, uploading, the two views, previewing.
 */
export default {
    key: 'file-manager',
    label: 'Organise your files',
    hint: 'Folders, upload, grid or table, preview',
    module: 'Storage',
    icon: 'fa-solid fa-folder-tree',
    paths: ['/files'],
    permission: 'files.read',
    pages: [
        {
            href: '/files',
            steps: [
                {
                    popover: {
                        title: 'Your own storage, {name}',
                        description: 'Everything here is private until you change its privacy or share it. Nobody else sees it, including administrators — they can audit that a file exists, not open it.',
                    },
                },
                {
                    element: '[data-tour="files-new-folder"]',
                    popover: {
                        title: 'Folders',
                        description: 'A new folder takes the privacy of the folder it is created in, so a folder inside a confidential one starts confidential too.',
                    },
                    fallback: {
                        title: 'Creating folders',
                        description: 'The New folder button sits at the top right of this page.',
                    },
                },
                {
                    element: '[data-tour="files-upload"]',
                    popover: {
                        title: 'Uploading',
                        description: 'Several files at once. Which formats and what size are allowed is set by your administrator.',
                    },
                },
                {
                    element: '[data-tour="view-toggle"]',
                    popover: {
                        title: 'Grid or table',
                        description: 'Switch freely — your page, search, sort and filters all survive the switch, because they live in the address bar rather than being forgotten on the way.',
                    },
                },
                {
                    element: '[data-tour="index-search"]',
                    popover: {
                        title: 'Search and sort',
                        description: 'Search names in this folder. In the table view, the column headings sort.',
                    },
                },
                {
                    element: '[data-tour="file-row"]',
                    popover: {
                        title: 'Opening a file',
                        description: 'Click a file to preview it full-screen, without downloading it. PDFs and images open in place; other formats offer a download instead, because browsers cannot display them.',
                    },
                    fallback: {
                        title: 'Opening a file',
                        description: 'Once you have uploaded something, clicking it opens a full-screen preview — no download needed. Escape closes it, and the arrow keys move between files.',
                    },
                },
            ],
        },
    ],
};
