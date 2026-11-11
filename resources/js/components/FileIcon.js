import { computed, defineComponent } from 'vue';

/**
 * The tile a file shows when it has no thumbnail.
 *
 * Which is most of the time, and that is a deployment constraint rather than a
 * choice: these servers have GD but not Imagick — the same reason QR labels are
 * SVG — so a PDF's first page cannot be rasterised. Only images get a real
 * thumbnail. See App\Services\Files\ThumbnailGenerator.
 */
const BY_MIME = [
    { match: /^image\//, icon: 'fa-solid fa-image', tone: 'bg-violet-100 text-violet-600' },
    { match: /pdf$/, icon: 'fa-solid fa-file-pdf', tone: 'bg-rose-100 text-rose-600' },
    { match: /(msword|wordprocessingml)/, icon: 'fa-solid fa-file-word', tone: 'bg-sky-100 text-[#07559e]' },
    { match: /(ms-excel|spreadsheetml|csv)/, icon: 'fa-solid fa-file-excel', tone: 'bg-emerald-100 text-emerald-600' },
    { match: /(ms-powerpoint|presentationml)/, icon: 'fa-solid fa-file-powerpoint', tone: 'bg-orange-100 text-orange-600' },
    { match: /^(application\/zip|application\/x-7z|application\/x-rar|application\/gzip)/, icon: 'fa-solid fa-file-zipper', tone: 'bg-amber-100 text-amber-600' },
    { match: /^text\//, icon: 'fa-solid fa-file-lines', tone: 'bg-slate-100 text-slate-600' },
];

const FALLBACK = { icon: 'fa-solid fa-file', tone: 'bg-slate-100 text-slate-500' };

export default defineComponent({
    name: 'FileIcon',
    props: {
        mimeType: { type: String, default: '' },
        folder: { type: Boolean, default: false },
        size: { type: String, default: 'lg' },
    },
    setup(props) {
        const descriptor = computed(() => {
            if (props.folder) {
                return { icon: 'fa-solid fa-folder', tone: 'bg-sky-100 text-[#07559e]' };
            }

            return BY_MIME.find((entry) => entry.match.test(props.mimeType)) ?? FALLBACK;
        });

        const box = computed(() => (props.size === 'sm'
            ? 'h-10 w-10 rounded-lg text-base'
            : 'h-16 w-16 rounded-xl text-2xl'));

        return { box, descriptor };
    },
    template: `
    <span class="inline-flex shrink-0 items-center justify-center" :class="[box, descriptor.tone]" aria-hidden="true">
        <i :class="descriptor.icon"></i>
    </span>
`,
});
