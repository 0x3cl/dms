import { defineComponent } from 'vue';
import Badge from './Badge';

/**
 * Whether a document is readable by anyone holding its tracking code
 * (`public`) or only by its uploader and the recipients named at upload time
 * (`private`). See DocumentController::isAuthorized(), which is the one gate
 * every document read goes through.
 */
export default defineComponent({
    name: 'VisibilityBadge',
    components: { Badge },
    props: {
        visibility: { type: String, required: true },
    },
    computed: {
        isPrivate() {
            return this.visibility === 'private';
        },
    },
    template: `
        <Badge :tone="isPrivate ? 'amber' : 'emerald'">
            <i :class="isPrivate ? 'fa-solid fa-lock' : 'fa-solid fa-globe'" class="mr-1.5" aria-hidden="true"></i>{{ isPrivate ? 'Private' : 'Public' }}
        </Badge>
    `,
});
