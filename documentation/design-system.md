# Design System

DocTrack's interface is a port of PROCMIS v2's, so the two read as one product family. This document is the contract: the tokens, the component inventory, and the page recipes. Read it before writing any page or component.

The whole visual language lives in **`resources/css/app.css`**. Tailwind 4 is configured CSS-first through `@theme` — there is no `tailwind.config.js`, and adding one would be ignored.

## Tokens

```css
@theme {
    --font-sans: 'Poppins', ui-sans-serif, system-ui, sans-serif, …;

    --color-brand-primary: #00aeef;        /* primary action: buttons, active tab underline */
    --color-brand-primary-hover: #009bd8;
    --color-brand-accent: #07559e;         /* accent: eyebrow labels, active nav text */
}
```

In practice the codebase writes the literal hexes as arbitrary values (`bg-[#00aeef]`, `text-[#07559e]`) because that is what PROCMIS does and what keeps the two diffable. Everything else is stock Tailwind slate/sky/emerald/rose/amber.

| Role | Value |
| --- | --- |
| Primary action, focus ring border | `#00aeef` (hover `#009bd8`; auth buttons hover `#008dcc`) |
| Accent, eyebrow labels, active nav text | `#07559e` (hover `#043d78`) |
| Navbar gradient | `from-[#0d5ba6] via-[#07559e] to-[#043d78]`, with `border-t-7 border-slate-800` |
| Hero gradient | `from-[#07559e] via-[#0a6bb8] to-[#00aeef]` |
| Footer gradient | `from-[#f7fbff] via-[#eef7ff] to-[#f8fcff]`, text `#4b78b5` |
| Page background | `bg-slate-50` |
| Card surface | `bg-white` + `border-slate-200` + `shadow-sm` |
| Table row hover | `hover:bg-sky-50/50` |
| Active nav pill | `bg-sky-100 text-[#07559e]` |
| Error | `text-rose-600`; invalid input border `border-rose-500` |
| Muted text | `text-slate-500` / `text-slate-400` |

**Radius convention.** `rounded-lg` for buttons, selects, nav links and filter inputs; `rounded-xl` for `.field` form inputs, icon tiles and dropdown panels; `rounded-2xl` for hero, dashboard and auth cards; `rounded-3xl` for modals; `rounded-full` for badges, avatars and toggles. `Card` itself is deliberately **square-cornered** — cards that want a radius add `rounded-2xl` themselves.

**Spacing.** Card padding `p-5 sm:p-7`; hero and modal `p-6 sm:p-8` or more. Vertical rhythm between cards `space-y-5`; inside a card `mt-6`/`mt-7`. Table cells `px-4 py-4`. Control height is pinned to `h-[42px]` in filter rows. Page container is `max-w-[1800px]` with `px-4 sm:px-6 lg:px-10`.

**Typography.** Poppins. The eyebrow label above a heading is `text-xs font-bold uppercase tracking-[.18em] text-[#07559e]`.

> **Every card, panel, drawer and modal title is `uppercase tracking-wide`.** The only exceptions are the page-title `<h1>` in `AdminShell`'s sticky header and the landing page's hero heading.

## Base classes

Defined in `@layer base` in `app.css`, so a utility in `@layer utilities` still overrides them:

| Class | What it is |
| --- | --- |
| `.field` | The standard form input: `mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 outline-none transition focus:border-[#00aeef] focus:ring-4 focus:ring-sky-100` |
| `.error` | The validation message under a field: `mt-1 text-sm text-rose-600` |
| `.required-label` | Appends a red `*` via `::after` |
| `.scroll-shadows` | Inner top/bottom shadows on a scrollable panel, shown only while there is more to scroll (the sidebar) |

### Control height

**Every form control is 50px tall**, so a grid row holding a text input beside a select or an upload field lines up instead of stepping. Left to themselves the three disagree — a text input comes to 50px, a `SearchableSelect` to 46px (its label is `text-sm`), and a file input to 58px.

`app.css` pins `input.field` and `select.field` to `3.125rem`. The rule is scoped to those two elements on purpose: a `<textarea>` also carries `.field` and must stay free to grow with its `rows`.

A file input needs padding of its own. The browser's "Browse…" button is a box inside the control with its own padding, which stacks on `.field`'s — that is what makes an upload field tower over its neighbours. `input.field[type='file']` therefore takes `py-2 text-sm`: 8px is what is left once the 32px button is accounted for, so the total still comes to 50px. Keep that in step with the `file:` utilities on the input itself (`resources/js/pages/documents/form.js`).

`SearchableSelect` is not a native control, so it carries the matching `min-h-[50px]` in the component. It is `min-h-` rather than `h-` because an option with an icon renders a 36px avatar that would overflow a fixed height; `flex items-center` does the centring. **Change the CSS rule and the component together.**

Plus three global rules worth knowing: cells inside an `.overflow-x-auto` table get `white-space: nowrap` (a table scrolls sideways rather than wrapping a name across three lines and making every row a different height — escape it with `whitespace-normal` or `table-fixed`); `<select>` gets a custom chevron and is capped so one long option cannot stretch its column; and anything interactive gets `cursor: pointer`.

## Dark mode

**Dark mode is a variable remap, never a `dark:` variant.** Every Tailwind 4 color utility compiles to `var(--color-<family>-<shade>)`, so `app.css` redefines those custom properties under `html.dark`. A utility keeps its intent and only its value changes, which is why there is not one `dark:` class in the codebase — do not add the first.

Three things make it work:

1. The rules sit **outside `@layer`** on purpose. Tailwind's utilities live in `@layer utilities`, and unlayered CSS beats layered CSS regardless of specificity, which is what lets the per-utility corrections further down the file win.
2. The neutral ramp is inverted end to end; an accent family only has its tint end (50–200) and its text end (700–900) swapped, because 300–600 are the solid mid-strength colors that already carry white text.
3. `--color-white` is deliberately **not** remapped, because it is both a card surface and a button label. `.bg-white` is patched as its own rule instead, and `ToggleSwitch`'s knob uses the literal `bg-[#ffffff]` so it cannot vanish into its track.

The preference lives in `localStorage` under `dms.preferences` (`resources/js/preferences.js`), and an inline script in `resources/views/app.blade.php` applies it before first paint so a dark-mode user never sees a flash of light. **That script mirrors the key and the `theme` shape — change one and change the other.** The swap itself is a View Transition, with an instant fallback and a `prefers-reduced-motion` opt-out.

## Component inventory

All in `resources/js/components/`, all plain `.js` inline-template components.

### Layout

| Component | API |
| --- | --- |
| `AdminShell` | `active` (module key, required), `title` (required); default slot. The signed-in layout: sticky sub-header, fixed 280px sidebar with grouped nav and a search combobox, content slot, and the global `FeedbackModal` + sign-out `ConfirmActionModal`. Its `MODULES` array is the nav, filtered against the shared `auth.permissions` prop; each entry names the `permission` that reaches it, or `null` when its route is genuinely open. The quick-search reads the same filtered list. |
| `AppNavbar` | No props. Reads `resources/js/organization.js`. Publishes its own height as `--app-navbar-h` on `<html>`, which the shell's sticky header and fixed sidebar position against. |
| `AppFooter` | No props. Carries `[data-app-footer]`, which the shell looks for to lift the sidebar's bottom edge above it. |
| `NavigationSkeleton` | No props. Shows a placeholder after 150 ms of a real navigation, or sets `html[data-fetching]` for a filter/pagination visit so table rows become pulsing bars instead of going blank. |

### Primitives

| Component | API |
| --- | --- |
| `Card` | Default slot. `<section class="border border-slate-200 bg-white p-5 shadow-sm sm:p-7">` — square-cornered. Extra classes fall through. |
| `Button` | `href`, `type`, `variant` (`primary`\|`secondary`\|`danger`); default slot. Renders an Inertia `<Link>` when `href` is set, a native `<button>` otherwise — branched with `v-if`/`v-else` rather than `<component :is>`, because a dynamic string tag can resolve back to this component's own registered name. |
| `Badge` | `tone` (`slate`\|`sky`\|`emerald`\|`rose`\|`amber`); slot. The uppercase pill. |
| `VisibilityBadge` | `visibility` — `public`/`private`, with a lock or globe icon. |
| `CountUp` | `value`, `format`, `duration`. Animated number, reduced-motion aware. |
| `ToggleSwitch` | `modelValue`, `label`, `disabled`; emits `update:modelValue`. |
| `UserAvatar` | `person` (`{name, profile_image, avatar_seed}`), or the older `name`/`image`/`seed` separately. The uploaded photo, else a generated avatar (`resources/js/avatar.js`), else initials. **Initials are the last resort, not the default** — a generated image is a third-party request, so `@error` falls back, and the watcher resets that fallback when the person changes or one failure strands every later avatar in a list. |
| `AvatarField` | `image`, `seed`, `name`, `action`, `removeAction`, `label`, `hint`, `modelValue` (a `File`); emits `update:modelValue`, `update:seed`. Upload a photo, pick a generated avatar, or clear both. With `action` it posts to that endpoint itself (so changing a face does not drag the rest of a form along); without it, the choice rides along with the parent's create request. |

### Lists

| Component | API |
| --- | --- |
| `IndexControls` | `search` (required), `searchPlaceholder` (required), `entries` (required), `entriesOptions`, `addHref`, `addLabel`, `filterColumns`; emits `update:search`, `update:entries`, `search`; slot `filters`. The search box, page-size select and Add button, all pinned to `h-[42px]`. The `filters` slot is a responsive grid that styles its own slotted labels and inputs. |
| `Pagination` | `pagination` (a Laravel paginator object); emits `page(url)`. |

### Overlays and inputs

| Component | API |
| --- | --- |
| `ConfirmActionModal` | `open`, `title` (required), `message`, `details`, `icon`, `tone` (`primary`\|`danger`), `cancelLabel`, `confirmLabel`, `confirmDisabled`, `processing`, `progress`, `wide`, `wider`; emits `close`, `confirm`; default slot for extra body content. Three widths: `max-w-xl` by default, `wide` → `max-w-3xl` for a long `details` list, `wider` → `max-w-5xl` when the body is a **layout** rather than a message (the filter dialog's grid of selects, the destination picker's folder list, the Users View dialog). At `wider` the `details` list also becomes two columns, because a label/value pair across 1024px is a short label, a long gap and a short value. Prefer the narrow card — a dialog as wide as the page reads as a screen. The workhorse: every destructive action and every form submit goes through one. **Its X emits `close`, never submit.** |
| `FeedbackModal` | `open`, `title` (required), `message` (required), `tone`, `icon`, `closeLabel`, `actionHref`/`actionLabel`; emits `close`. This is the flash-message mechanism — there is no toast component. |
| `SearchableSelect` | `modelValue`, `options` (`{value, label, icon?}[]`), `placeholder`, `searchPlaceholder`, `emptyText`, `disabled`, `invalid`; emits `update:modelValue`. Keyboard: type to filter, ↑/↓, Enter, Escape. |
| `NotificationBell` | No props — reads the shared `notifications` prop. |
| `ThemeToggle` | No props. Writes `preferences.theme`. |
| `QrScanner` | No props; emits `scanned(text)`, `error`. Wraps `html5-qrcode` and **stops the camera in `onBeforeUnmount`** — the reason it is a component at all. |
| `ActionMenu` | `items` (`{key, label, icon, hint, tone, disabled}[]`), `label`, `align`; emits `select(item)`. The kebab menu; items are data, not slots, so every caller gets the same target size. |
| `FilePreviewModal` | `open`, `file` — **the server's descriptor** from `GET /files/preview` (`{mode, name, src, viewer_url, viewer_unreachable, renders_locally, download_url, can_download}`), null while it is in flight — `hasPrevious`, `hasNext`; emits `close`, `navigate(step)`. Five modes: `document` (a PDF from our own stream, or Word/Excel/PowerPoint through Microsoft's Office viewer) in an iframe, `image`, `video`, `audio`, and `none`. **The page never picks the mode** — see `documentation/modules/file-manager.md`. The fullscreen preview. **The only overlay in this system that traps focus, closes on Escape and the scrim, and locks body scroll** — the other three do none of those, so copy from this one, not from them. `h-[100dvh]`, not `h-screen`. |
| `FolderPickerModal` | `open`, `action` (`move`\|`copy`), `items` (`{kind, public_id, name}[]`), `processing`, `error`; emits `close`, `confirm(targetPublicId)`. Walks `GET /files/destinations` — one flat request, not one per level — already narrowed server-side to folders the person may write into. Excludes the items being moved and everything inside them. **Do not name a setup binding `open`**: it shadows the `open` prop and the modal receives a function. |
| `FileFilterModal` | `open`, `filters`, `categories`, `owners`; emits `close`, `apply(draft)`. Holds a **draft** and applies on confirm — six filters that each reload the page is six reloads to describe one search. Applied values go into the query string like `view` does. |
| `ConfirmDeleteModal` | `open`, `purpose` (`delete`\|`purge`), `items`, `trashed`, `processing`, `error`; emits `close`, `confirm(typedCode)`. Fetches a **server-issued** four-digit code and a count of what the delete would take. The code is verified server-side; never reimplement it in the browser. |
| `LoadingFrame` | `src`, `title`. An `<iframe>` with a loading state, re-shown whenever `src` changes. Ported from PROCMIS. Size it with classes on the component. |
| `ShareDrawer` | `open`, `item` (`{kind, public_id, name}`); emits `close`. The slide-in sharing panel — **links only**, plus a read-only list of who holds access, with revoke. People are named where the item is created. **The only extracted drawer** — the other off-canvas panel is baked into `AdminShell`'s mobile sidebar. Fetches its own state on open rather than taking page props. |

### Files

| Component | API |
| --- | --- |
| `ViewToggle` | `modelValue` (`grid`\|`table`); emits `update:modelValue`. Two icon buttons, pinned `h-[42px]` to line up with `IndexControls`. Drive it from the query string, never local state. |
| `FileGrid` | `folders`, `files`, `folderHref` (fn), `selected`, `selectable`, `mode` (`browse`\|`trash`); emits `open`, `preview`, `act`. The thumbnail view. `mode="trash"` swaps the action list for Restore / Delete permanently, which is what gives the Trash page both views for free. |
| `FileTable` | the same, plus `sort`/`direction`; emits `sort(column)` as well. The table view with sortable headers. |
| `utils/file-rows.js` | Not a component: `readableSize`, `accessBadge`, `rowActions(item, kind, mode)`. Both views import it, because a byte formatter and a thirteen-line action list duplicated across two bodies is exactly the pair that drifts — an action added to one view and not the other is invisible until somebody switches. |
| `FileIcon` | `mimeType`, `folder`, `size` (`sm`\|`lg`). The MIME-keyed tile shown when there is no thumbnail — which is every non-image, because these servers have no Imagick. |
| `FileBreadcrumbs` | `rootLabel`, `rootHref`, `crumbs`. The root is never shown by its stored name (`root-{id}`). |

### Composables

- `useConfirmSubmit(submit)` → `{ confirm, request, cancel, proceed }`. Wire `@submit.prevent="request"` on the form and `@confirm="proceed"` on the modal.
- `useUrlTab(param, allowed, fallback)` → a ref. Writes with `history.replaceState`, not an Inertia visit; keeps the default value out of the URL. **Each tab set names its own query parameter.**

## Page recipes

### Index page

Reference: `resources/js/pages/divisions/index.js`.

```js
props: { divisions: Object, filters: Object },
setup(props) {
    const filters = reactive({ ...props.filters });
    let searchTimer;
    const load = () => router.get('/divisions', filters, {
        preserveScroll: true, preserveState: true, replace: true,
    });
    const search = () => { clearTimeout(searchTimer); searchTimer = setTimeout(load, 350); };
    const page = (url) => url && router.get(url, {}, { preserveScroll: true });
    …
}
```

Template order: `<Head title>` → `<AdminShell active title>` → `<Card>` → eyebrow `<p>` / `<h2 class="uppercase tracking-wide">` / description → `<IndexControls>` (with a `#filters` slot if needed) → the table in `<div class="mt-6 overflow-x-auto">` with `min-w-[…]` → `<Pagination>` → any modals as siblings inside the shell.

The table head is `border-y border-slate-200 text-xs uppercase tracking-wide text-slate-500`; rows are `border-b border-slate-100 hover:bg-sky-50/50`; the last cell is `whitespace-nowrap px-4 py-4 text-right`; and there is always an empty-state row with a `colspan`.

Deletes open a `ConfirmActionModal` on the index page — there are no separate delete *pages*.

### Form page

Reference: `resources/js/pages/divisions/form.js`. **One component serves create and edit**, branching on a nullable model prop:

```js
const isEditing = computed(() => Boolean(props.division));
const form = useForm({ name: props.division?.name ?? '' });
const submitForm = (options) => {
    if (isEditing.value) { form.put(`/divisions/${props.division.id}`, options); }
    else { form.post('/divisions', options); }
};
const { confirm, request, cancel, proceed } = useConfirmSubmit(submitForm);
```

The field triad, used everywhere:

```html
<label>
    <span class="required-label text-sm font-semibold text-slate-700">Name</span>
    <input v-model="form.name" class="field" :class="{ 'border-rose-500': form.errors.name }" />
    <p v-if="form.errors.name" class="error">{{ form.errors.name }}</p>
</label>
```

> **`border-rose-500` is load-bearing.** The global `router.on('error')` hook in `app.js` scrolls to and focuses the first element carrying it. Rename the class and validation stops taking the user to the problem.

A form's header has the eyebrow/`h2`/"fields marked * are required" block plus a Back link; its footer is `flex justify-end gap-3 border-t border-slate-200 pt-5` with a secondary Cancel and a primary submit. File uploads pass `forceFormData: true` and surface `form.progress.percentage` in both the button label and the modal.

Forms use **`useForm`**. Inertia's `<Form>` component is not used anywhere in this codebase.

### Dual-view index page

Reference: `resources/js/pages/files/index.js`.

The index recipe above, plus a `ViewToggle` beside `IndexControls` and two interchangeable bodies:

```html
<div class="mt-5 flex flex-wrap items-center gap-3">
    <div class="min-w-0 flex-1"><IndexControls … /></div>
    <ViewToggle :model-value="filters.view" @update:model-value="setView" />
</div>
<FileGrid v-if="filters.view === 'grid'" … />
<FileTable v-else … />
<Pagination :pagination="files" @page="page" />
```

Three rules make it work:

- **`view` goes in the query string**, like every other filter, and the toggle just sets it and reloads. That is what makes the page, search, sort and filters survive the switch *by construction* — and it makes the URL shareable.
- **Both bodies render the same paginator**, so `Pagination` is reused untouched and the counts cannot disagree.
- **Per-row actions come from a server-resolved `can` object** on each item, never from reading `auth.permissions` in the template. A module permission says the capability exists for the user; only the server knows whether it applies to *this* row.
- **The same two bodies serve a different list by `mode`.** The Trash page passes `mode="trash"` to the identical `FileGrid`/`FileTable` and gets a grid, a table, sorting and pagination without a second listing component to keep in step. Reach for that before writing a third body.

### Standalone (unshelled) page

A page outside `AdminShell` — the landing page, the auth pages — must give its own `<main>` the class `flex-1`, because `app.js` wraps every page in a `flex min-h-screen flex-col` column and Vue cannot fall a class through onto an Inertia page's fragment root. Without it, a short page leaves a white gap above the footer. The auth pages add `flex items-center` to centre their card; `AdminShell` stays top-anchored on purpose, since tables should not recentre.

## Conventions checklist

Before finishing a page, confirm:

- [ ] Card/panel/modal titles carry `uppercase tracking-wide`.
- [ ] Invalid inputs get `border-rose-500`; messages use `.error`.
- [ ] Inputs use `.field`; required labels use `.required-label`.
- [ ] No `dark:` classes anywhere.
- [ ] Tables sit in `overflow-x-auto` with a `min-w-[…]` and have an empty state.
- [ ] Destructive actions go through `ConfirmActionModal`.
- [ ] File downloads are plain links, not `router.visit`.
- [ ] A standalone page's `<main>` has `flex-1`.
- [ ] A new overlay traps focus, closes on Escape and the scrim, and locks body scroll (copy `FilePreviewModal`, not the older modals).
- [ ] A dual-view list keeps `view` in the query string and renders one paginator.
- [ ] Row-level actions come from a server `can` object, not `auth.permissions`.
- [ ] `npm run build` passes — an inline template only fails at build time.
