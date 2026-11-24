/**
 * Finding a document from the paper in your hand.
 *
 * `permission: null` because both routes are deliberately public — a QR label
 * has to resolve for whoever is holding the folder.
 */
export default {
    key: 'track-document',
    label: 'Track or scan a document',
    hint: 'From the label on the folder',
    module: 'Documents',
    icon: 'fa-solid fa-qrcode',
    paths: ['/documents/track'],
    permission: null,
    pages: [
        {
            href: '/documents/track',
            steps: [
                {
                    popover: {
                        title: 'You have the paper, not the file',
                        description: 'Two ways in: type the tracking code, or scan the QR Tracker with your camera.',
                    },
                },
                {
                    element: '[data-tour="track-code"]',
                    popover: {
                        title: 'Type the code',
                        description: 'The code printed on the label, like MEMO-20260110-0001. It opens the document and its full history.',
                    },
                    fallback: {
                        title: 'Track by code',
                        description: 'Enter the code printed on the label to open the document.',
                    },
                },
            ],
        },
        {
            href: '/documents/track?method=scan',
            steps: [
                {
                    element: '[data-tour="scanner"]',
                    popover: {
                        title: 'Or point your camera',
                        description: 'Your browser will ask for the camera first. Scanning works with our own labels and with a bare code from anywhere else.',
                    },
                    fallback: {
                        title: 'Scanning',
                        description: 'The scanner needs a camera and permission to use it. If neither is available, type the code instead.',
                    },
                },
            ],
        },
    ],
};
