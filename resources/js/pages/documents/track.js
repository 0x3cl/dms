import { Head } from '@inertiajs/vue3';
import { defineComponent } from 'vue';
import AdminShell from '../../components/AdminShell';
import Card from '../../components/Card';
import TrackDocumentCard from '../../components/TrackDocumentCard';

/**
 * Track-by-code. On a hit the controller renders documents/show instead, so
 * this page only ever shows the chooser or a not-found message.
 *
 * The card itself is shared with the Track modal on the Documents register:
 * they were two designs for one question, and the third method (uploading a
 * picture of a label) would otherwise have had to be built twice.
 *
 * Full width, like every other card under AdminShell. It was centred at
 * `max-w-2xl`, which made one screen in the register narrower than the rest
 * of them for no reason a reader could see — a page that is a different
 * shape from its neighbours reads as a different kind of page.
 */
export default defineComponent({
    name: 'DocumentTrack',
    components: { AdminShell, Card, Head, TrackDocumentCard },
    props: {
        code: { type: String, default: '' },
        notFound: { type: Boolean, default: false },
        /** The input could not be a tracking code at all — a separate miss. */
        malformed: { type: Boolean, default: false },
        /**
         * Which of the three ways in the screen opens on.
         *
         * `/scan` used to be a page of its own for the middle one, which is
         * the same list at a second address. Arriving here with
         * `?method=scan` opens the camera on this screen instead.
         */
        method: { type: String, default: 'code' },
    },
    template: `
    <Head title="Track Document" />
    <AdminShell active="track" title="Track Document">
        <Card class="w-full">
            <TrackDocumentCard :code="code" :not-found="notFound" :malformed="malformed" :initial-method="method" />
        </Card>
    </AdminShell>
`,
});
