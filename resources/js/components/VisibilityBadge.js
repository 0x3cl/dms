import { computed, defineComponent } from 'vue';
import Badge from './Badge';

/**
 * A document's access level, as a badge.
 *
 * Three levels, matching `App\Enums\DocumentVisibility`:
 *
 * - **Public** — anyone holding the tracking code, and a link opens it without
 *   an account.
 * - **Private** — the uploader and the people tagged on it, plus anyone sent a
 *   link.
 * - **Confidential** — the uploader and the tagged people only. No link can be
 *   minted, and any link already issued stops working the moment a document is
 *   classified this way.
 *
 * The tone and the icon are derived here rather than passed in, so a badge
 * cannot be rendered with the wrong colour for its level by a caller that
 * forgot to update. They match `FileVisibility::tone()` level for level,
 * because the same word must not be two colours in one application.
 *
 * An unrecognised value falls back to the most restrictive appearance: a badge
 * that defaults to "Public" on a value it does not know is a badge that will
 * eventually describe a restricted document as open to everyone.
 */
const LEVELS = {
    public: { label: 'Public', tone: 'green', icon: 'fa-solid fa-globe' },
    private: { label: 'Private', tone: 'orange', icon: 'fa-solid fa-lock' },
    confidential: { label: 'Confidential', tone: 'red', icon: 'fa-solid fa-user-shield' },
};

export default defineComponent({
    name: 'VisibilityBadge',
    components: { Badge },
    props: {
        visibility: { type: String, required: true },
    },
    setup(props) {
        const level = computed(() => LEVELS[props.visibility] ?? LEVELS.confidential);

        return { level };
    },
    template: `
        <Badge :tone="level.tone">
            <i :class="level.icon" class="mr-1.5" aria-hidden="true"></i>{{ level.label }}
        </Badge>
    `,
});
