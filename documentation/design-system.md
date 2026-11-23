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

In practice the codebase writes the literal hexes as arbitrary values (`bg-[#00aeef]`, `text-[#07559e]`) because that is what PROCMIS does and what keeps the two diffable. Everything else is stock Tailwind slate/sky/emerald/rose/amber, plus green/orange/red, which are the three access levels and nothing else — a traffic light read without being read.

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

## The logo and the icon set

`public/ptri.png` is the DOST-PTRI seal at full resolution (810x706, transparent) and the **only brand image anyone edits**. It is referenced through `resources/js/organization.js` (`logo: '/ptri.png'`), which `AppNavbar` and `auth/login.js` render with `object-contain` — the seal is not square, so a fixed `h-`/`w-` box without `object-contain` distorts it.

Everything a browser asks for in an icon slot is **generated** from that one file by `php artisan brand:icons`: `icon-{16,32,48,180,192,512}.png` and a real multi-size `favicon.ico`. Replacing the seal is therefore one file plus one command, not seven files kept in step by hand. `resources/views/app.blade.php` already points at the generated set.

Two things the command does that are easy to get wrong, and were both wrong here:

- **It letterboxes rather than stretches.** An icon slot is square and the seal is not, so each size is scaled to fit and centred on a transparent square canvas.
- **It writes a real `favicon.ico`.** A browser requests `/favicon.ico` unprompted whatever the markup says. The file that used to sit there was **zero bytes**, which is worse than absent: an empty body is a broken image, not a fallback. Meanwhile the `<link rel="icon">` pointed at the full 139 KB seal, downloaded on every page load to be drawn at 16 pixels.

GD, not Imagick — the same constraint that decides the QR label format.

## Base classes

Defined in `@layer base` in `app.css`, so a utility in `@layer utilities` still overrides them:

| Class | What it is |
| --- | --- |
| `.field` | The standard form input: `mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 outline-none transition focus:border-[#00aeef] focus:ring-4 focus:ring-sky-100` |
| `.error` | The validation message under a field: `mt-1 text-sm text-rose-600` |
| `.required-label` | Appends a red `*` via `::after` |
| `.scroll-shadows` | Inner top/bottom shadows on a scrollable panel, shown only while there is more to scroll (the sidebar) |
| `.hover-scroll` | One-line heading with an ellipsis that becomes horizontally scrollable on hover or focus — for free text, like a document title, where wrapping would break the layout and truncating alone would hide the page's own subject. Set `title` on the element too: a tooltip is what keyboard and touch readers get. `scrollbar-gutter: stable` keeps the bar's height reserved in both states, or the heading shifts down as the pointer arrives |

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

## Layering

Overlays are stacked by hand, and the order is written here because the one time it was not, a toast spent its life behind a scrim. Every value is a Tailwind arbitrary `z-[n]` on a `fixed` element in the root stacking context — none of the wrappers in `app.js` create one of their own, so these numbers really do compete with each other.

| Layer | `z` | What sits here |
| --- | --- | --- |
| Page chrome | `z-30` / `z-40` | Sticky headers, drawer scrims |
| Drawers and ordinary modals | `z-50` | The audit-trail, comments and version slide-overs, `ConfirmDeleteModal`, the default `ConfirmActionModal` |
| Full-screen preview | `z-[70]` | `FilePreviewModal` |
| Dialogs opened *from* the preview | `z-[80]` | `ConfirmActionModal` with `layer-class="z-[80]"` — the one case a caller lifts a component by hand, because the same dialog is opened from both depths |
| Blocking failure | `z-[90]` | `FeedbackModal`, the `error` flash |
| Toasts | `z-[100]` | `ToastStack` |

Two rules follow from that table and are the reason it exists.

**A global surface is lifted once, at the component, never per caller.** `ToastStack` was `z-[60]` and `FeedbackModal` `z-50`, both *below* the full-screen preview. Neither was broken in any way a test or an error could catch: the toast was raised, announced to a screen reader and timed out on schedule, and the modal opened — they were simply painted underneath the scrim. So posting an empty comment from the preview, copying a link there, or taking a refusal looked exactly like a button that did nothing. `ConfirmActionModal`'s `layer-class` is the deliberate exception and only because that component is opened from two depths; anything mounted once and raised from anywhere must clear the whole ladder by itself, or it is invisible wherever somebody forgets to lift it.

**A transient message may sit over a modal; a modal may never sit over a toast.** That is why `FeedbackModal` stops at `z-[90]`. A toast blocks nothing — its container is `pointer-events-none` and only the toasts themselves take clicks, so the corner it occupies is still clickable through to the dialog underneath.

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
| `Badge` | `tone` (`slate`\|`sky`\|`emerald`\|`rose`\|`amber`\|`green`\|`orange`\|`red`); slot. The uppercase pill. `green`/`orange`/`red` are reserved for the access levels — public, private, confidential — so recolouring a level never recolours an unrelated status pill. |
| `VisibilityBadge` | `visibility` — `public`, `private` or `confidential`. Tone and icon are derived from the value here, not passed in, so a caller cannot render a level in the wrong colour; they match `FileVisibility::tone()` level for level: public is **green**, private **orange**, confidential **red**. An unrecognised value falls back to the **most restrictive** appearance — a badge that defaults to "Public" on a value it does not know will eventually describe a restricted document as open. |
| `CountUp` | `value`, `format`, `duration`. Animated number, reduced-motion aware. |
| `ToggleSwitch` | `modelValue`, `label`, `disabled`; emits `update:modelValue`. |
| `UserAvatar` | `person` (`{name, profile_image, avatar_seed}`), or the older `name`/`image`/`seed` separately. The uploaded photo, else a generated avatar (`resources/js/avatar.js`), else initials. **Initials are the last resort, not the default** — a generated image is a third-party request, so `@error` falls back, and the watcher resets that fallback when the person changes or one failure strands every later avatar in a list. |
| `PersonName` | `person` (`{id, name}`), or `name`/`id` separately, plus `fallback`. A name, with `(You)` appended when the id matches the shared `auth.user`. **Matched on the id, never the name** — an office shares surnames and an audit row's `actor_name` is a snapshot, so comparing text would label a colleague's row yours and stop labelling yours after a rename; a row whose actor is gone (`actor_id` null) is correctly nobody. A **suffix, not a replacement**, because a trail is printed and read back by other people and "You downloaded this" is a sentence with no subject. It decides nothing — every gate stays on the server. `mark: false` suppresses it where the surrounding screen has already said it, as on the register's "Uploaded by me" tab, where every row is yours and a marker on each one is noise. |
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
| `FeedbackModal` | `open`, `title` (required), `message` (required), `tone`, `icon`, `closeLabel`, `actionHref`/`actionLabel`; emits `close`. Answers `flash.error` only: a refusal has to block, because one the reader scrolls past is one they will act as though never happened. |
| `ToastStack` | No props. Mounted once in `app.js`, outside the Inertia page. Answers `flash.success`, and exports `pushToast({ message, title, tone, timeout })` / `dismissToast(id)` for outcomes that never reach a flash. Top-right, stacking, dismissable, auto-expiring (longer for an error), capped at four. **A single-field form says its complaint here rather than under the field** — the control's own red ring already says *which* control, a message says only *what*, and a reserved line of height beneath one input is empty almost all of the time. That holds only while there is one control: a form with several fields keeps its per-field messages, because a toast cannot say which field it means. The ring outlives the toast, since the toast expires and the input is still wrong. |
| `SearchableSelect` | `modelValue`, `options` (`{value, label, icon?}[]`), `placeholder`, `searchPlaceholder`, `emptyText`, `disabled`, `invalid`; emits `update:modelValue`. Keyboard: type to filter, ↑/↓, Enter, Escape. |
| `NotificationBell` | No props — reads the shared `notifications` prop. Each row is the actor's face with the kind badged on it (the trail's shape), the actor's name and kind label above the sentence, the sentence clamped to two lines with the full text as a tooltip, and an unread dot; the panel is `min(26rem, 100vw-2rem)` and the list scrolls. Icon, colour and label come from `App\Enums\NotificationKind` on the server, never from a list of kinds in the template. |
| `ThemeToggle` | No props. Writes `preferences.theme`. |
| `QrScanner` | `mode` (`camera`\|`file`); emits `scanned(text)`, `error`. Wraps `html5-qrcode` and **stops the camera in `onBeforeUnmount`** — the reason it is a component at all. A camera that will not start is written below the control, because it is a *condition* that is still true in ten seconds; a file that could not be read raises a toast, because it is an *attempt* that is over. |
| `RowActions` | `actions` (`{key, label, icon, href?, tone?, disabled?}[]`), `label`; emits `select(action)`. **Every index table's Actions column.** Icon-only: each button carries `title` (the hover tooltip) *and* `aria-label` (what a screen reader announces) — dropping the text without both makes the row unreadable to anyone not using a mouse. An entry with `href` renders a `<Link>`, otherwise a `<button>` that emits. Tones: `primary`, `default`, `danger`. Actions are data, not slots, so no table can invent a fourth button style. The known cost: a touch device has no hover, so an unusual action should keep a visible label rather than be squeezed in here. |
| `ActionMenu` | `items` (`{key, label, icon, hint, tone, disabled}[]`), `label`, `align`; emits `select(item)`. The kebab menu; items are data, not slots, so every caller gets the same target size. |
| `FilePreviewModal` | `open`, `file` — **the server's descriptor** from `GET /files/preview` (`{mode, name, src, viewer_url, viewer_unreachable, renders_locally, download_url, can_download}`), null while it is in flight — `hasPrevious`, `hasNext`; emits `close`, `navigate(step)`. Five modes: `document` (a PDF from our own stream, or Word/Excel/PowerPoint through Microsoft's Office viewer) in an iframe, `image`, `video`, `audio`, and `none`. **The page never picks the mode** — see `documentation/modules/file-manager.md`. The fullscreen preview. **The only overlay in this system that traps focus, closes on Escape and the scrim, and locks body scroll** — the other three do none of those, so copy from this one, not from them. `h-[100dvh]`, not `h-screen`. |
| `FolderPickerModal` | `open`, `action` (`move`\|`copy`), `items` (`{kind, public_id, name}[]`), `processing`, `error`; emits `close`, `confirm(targetPublicId)`. Walks `GET /files/destinations` — one flat request, not one per level — already narrowed server-side to folders the person may write into. Excludes the items being moved and everything inside them. **Do not name a setup binding `open`**: it shadows the `open` prop and the modal receives a function. |
| `FileFilterModal` | `open`, `filters`, `categories`, `owners`; emits `close`, `apply(draft)`. Holds a **draft** and applies on confirm — six filters that each reload the page is six reloads to describe one search. Applied values go into the query string like `view` does. |
| `ConfirmDeleteModal` | `open`, `purpose` (`delete`\|`purge`), `items`, `trashed`, `processing`, `error`; emits `close`, `confirm(typedCode)`. Fetches a **server-issued** four-digit code and a count of what the delete would take. The code is verified server-side; never reimplement it in the browser. |
| `LoadingFrame` | `src`, `title`. An `<iframe>` with a loading state, re-shown whenever `src` changes. Ported from PROCMIS. Size it with classes on the component. |
| `ShareDrawer` | `open`, `item` (`{kind, public_id, name}`); emits `close`. The slide-in sharing panel — **links only**, plus a read-only list of who holds access, with revoke. People are named where the item is created. **The only extracted drawer** — the other off-canvas panel is baked into `AdminShell`'s mobile sidebar. Fetches its own state on open rather than taking page props. |
| `LoadingModal` | `open`, `title`, `message`. The blocking "please wait" card for a submit that cannot be cancelled. |
| `TrailFilters` | `open`, `filters` (required), `actions`, `offices`, `sizes`; emits `close`, `apply`. The **one** filter dialog shared by the document page's trail panel and the full-history page — search, action, office, date range, page size. Five controls that each reload the page is five reloads to describe one question, so it holds a draft and applies on confirm. Search stays *outside* it, because it is the one people actually use. |
| `TrailVersionTabs` | `tabs` (`[{version, entries}]`), `current`; emits `select`. The trail read one file version at a time — `All versions (n)` plus a tab per version the history holds, the unversioned rows last. Shared by the trail panel and the full-history page. It **is** the version filter, so the select left `TrailFilters` when it arrived, and it renders nothing at all until a file has been replaced: one version is not a choice. |
| `AccessRequestsMenu` | No props — reads `accessRequests.pending` for the badge and **fetches** its three tabs from `/access-requests` when opened. Incoming leads, because it is the only tab with an action attached. |
| `TrackDocumentCard` | `code`, `notFound`, `heading`; emits `found`. Type a tracking code or scan a label — one card wrapping `QrScanner`, which is why Scan QR Code is not its own screen. |
| `RecipientPicker` | `modelValue` (ids), `people`, `invalid`, `addLabel`, `emptyText`, `searchPlaceholder`; emits `update:modelValue`. Naming people **where the thing is made**: the upload form, the document **edit** form (private and confidential only) and the New folder dialog. There is still no "add someone later" *panel* — the list is corrected on the form that made it, which is where the whole list is visible at once. |
| `CollapseTransition` | Default slot. The height transition used by expanders; wraps `<Transition>` so no page hand-rolls one. |
| `TutorialMenu` | No props. Filters the tour registry against `auth.permissions`, so nobody is offered a walkthrough of a page they cannot open. |

### Files

| Component | API |
| --- | --- |
| `ViewToggle` | `modelValue` (`grid`\|`table`); emits `update:modelValue`. Two icon buttons, pinned `h-[42px]` to line up with `IndexControls`. Drive it from the query string, never local state. |
| `FileGrid` | `folders`, `files`, `folderHref` (fn), `selected`, `selectable`, `mode` (`browse`\|`trash`); emits `open`, `preview`, `act`, `prefetch` (a hover/focus hint, so the preview descriptor is already in hand when the click lands). The thumbnail view. `mode="trash"` swaps the action list for Restore / Delete permanently, which is what gives the Trash page both views for free. |
| `FileTable` | the same, plus `sort`/`direction`; emits `sort(column)` as well. The table view with sortable headers. |
| `utils/file-rows.js` | Not a component: `readableSize`, `accessBadge`, `rowActions(item, kind, mode)`. Both views import it, because a byte formatter and a thirteen-line action list duplicated across two bodies is exactly the pair that drifts — an action added to one view and not the other is invisible until somebody switches. |
| `UploadLimitsHint` | `limits` — the `UploadPolicy::limits()` payload verbatim. Renders what a file input accepts: the extensions grouped **by ceiling** (a dozen rows each saying "25 MB" is one sentence twelve times), the file count, and an expander for the full list. Used by both upload surfaces, because the one statement that must not drift between them is what is permitted. A page must never restate the limits as literals — the document picker's `accept=".pdf,.doc,.docx"` outlived its policy and hid every permitted spreadsheet from the browser's own dialog. |
| `FileIcon` | `mimeType`, `folder`, `size` (`sm`\|`lg`). The MIME-keyed tile shown when there is no thumbnail — which is every non-image, because these servers have no Imagick. |
| `FileBreadcrumbs` | `rootLabel`, `rootHref`, `crumbs`. The root is never shown by its stored name (`root-{id}`). |
| `FileVersionsModal` | `open`, `item` (`{public_id, owner_public_id, name}`); emits `close`, `reverted`. A file's history, **fetched when it opens** — each entry carries URLs that open bytes. View and Download are plain `<a href>` (file responses); Restore is confirmed in a second dialog, because it changes what the next person downloads. `can_download` and `can_revert` arrive resolved once for the file, never per entry. |
| `ReplaceFileModal` | `open`, `item` (`{public_id, name, version}`), `limits`; emits `close`, `replaced`. New bytes behind an existing file, with an optional "what changed?" note. `accept` comes from `UploadPolicy::limits()`, never a literal. The message says what a replacement keeps — the name, the link, the shares — so nobody reaches for it wanting a separate file. |
| `FilePreviewPane` | `file` — the server's descriptor, or null while it is being fetched. **The one preview renderer**: five modes (`document`, `image`, `video`, `audio`, `none`). `FilePreviewModal` keeps only what is *modal* about a preview (scrim, focus trap, Escape, arrows) and delegates the rendering here, and the document page renders it inline under the details card. It decides nothing — the server says which viewer, because a template choosing between a renderer we run and a URL we hand to Microsoft is a template deciding whether a confidential document leaves the building. |
| `OfficeRenderer` | `src`, `renderer` (required), `name`, `size`, `download-url`. Draws Word via `docx-preview` and Excel/CSV via SheetJS **in the browser**, from our own stream. A spreadsheet's cells are read as data and drawn by text interpolation, never inserted as markup. `size` is what the **large-file gate** decides on — past the limit it states the size and offers Download or "Preview anyway" rather than starting a render that costs the tab — and the work is bounded below it too: one sheet and one 300-row window at a time, with a real download percentage. |

### Composables

- `useConfirmSubmit(submit)` → `{ confirm, request, cancel, proceed }`. Wire `@submit.prevent="request"` on the form and `@confirm="proceed"` on the modal.
- `useUrlTab(param, allowed, fallback)` → a ref. Writes with `history.replaceState`, not an Inertia visit; keeps the default value out of the URL. **Each tab set names its own query parameter.**
- `useTabbedErrors(form, tab, map)` → nothing; it watches. On a refusal it switches `tab` to the one holding the first error and focuses that field. `map` is `{ tabKey: ['field', ...] }` **in tab order**, and a name claims its nested keys too (`allowed_types` covers `allowed_types.3.min_size_kb`). Pair it with any form whose fields are split across tabs: without it the server can refuse a form for a field that is not on screen, and from the reader's side the page simply declined to save. A field no tab claims leaves the tab alone, so a form-wide error cannot move somebody to a guess.
- `useLiveTrail(channel, { prop, isLive })` → `{ pending, refresh }`. What a page does with a broadcast trail **signal**: reload that prop on page one unfiltered, otherwise count and offer a pill. Coalesces a burst, does nothing while the tab is hidden, unsubscribes on unmount. See `documentation/modules/realtime.md`.
- `usePreviewDescriptor()` → `{ describe, descriptor, forget, loading, prefetch }`. The one fetch of `GET /files/preview`, with a short in-memory cache (held well inside a signed viewer URL's own lifetime, so a cached descriptor can never hand out an expired one), in-flight de-duplication, and a read-ahead of the immediate neighbours only — prefetching a whole folder would mint forty live credentials.
- `useCloseOnTutorial(close)` — dismiss an overlay when a tour starts, so a modal does not sit over the step it is explaining.

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
