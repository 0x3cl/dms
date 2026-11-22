---
name: doctrack-design-system
description: "Use when writing or changing any page or component in this project's frontend — resources/js/pages/** or resources/js/components/**. Covers the inline-template .js convention, the brand tokens, the form field triad, the index/form/standalone page recipes, the dark-theme rule, and the conventions that are load-bearing rather than cosmetic. Activate before creating a page, adding a shared component, styling a table or form, or touching resources/css/app.css."
---

# DocTrack Design System

The full reference is `documentation/design-system.md`. This skill is the short form: what to do, and what will break if you do not.

## The convention that surprises people first

**Vue components are plain `.js` files, not `.vue` SFCs.** Every file in `resources/js/pages` and `resources/js/components` is:

```js
import { defineComponent } from 'vue';

export default defineComponent({
    name: 'Thing',
    props: { … },
    setup(props) { … return { … }; },
    template: `
    <div>…</div>
`,
});
```

`vite.config.js` aliases `vue` to `vue/dist/vue.esm-bundler.js` — the runtime **plus compiler** build — specifically so those backtick templates compile. Do not introduce a `.vue` file, and do not "fix" that alias.

Consequence: **a template error is a build-time failure**, not something an editor catches. Run `npm run build` after touching a template.

## Load-bearing conventions

These are not style preferences. Breaking one breaks behaviour.

1. **`border-rose-500` marks an invalid input.** The global `router.on('error')` hook in `resources/js/app.js` scrolls to and focuses the first element carrying it. Rename it and validation stops taking the user to the problem.
2. **No `dark:` classes.** Dark mode is a `--color-*` variable remap under `html.dark` in `app.css`. A `dark:` class would be the only one in the codebase and would fight the remap.
3. **A standalone page's `<main>` needs `flex-1`.** `app.js` wraps every page in a `flex min-h-screen flex-col` column between the navbar and footer, and Vue cannot fall a class through onto an Inertia page's fragment root. Without it a short page leaves a white gap above the footer. `AdminShell` handles this; the landing and auth pages do it themselves.
4. **File downloads are plain links.** `accessFile`, `downloadQr` and the file-manager stream routes return file responses that an Inertia XHR visit cannot follow. Use `<a href>` or `window.location` — never `router.visit`. (There is no `receiveFile`: "Receive & download" was split, because taking a copy is something the server observes and a document arriving is something only the person can assert.)
5. **Card, panel, drawer and modal titles are `uppercase tracking-wide`.** Only `AdminShell`'s page `<h1>` and the landing hero are exempt.
6. **Flash keys are `success` and `error`, nothing else — answered differently.** `success` becomes a `ToastStack` toast (mounted in `app.js`, outside the page, so it survives the visit that raised it); `error` opens `AdminShell`'s `FeedbackModal`, because a refusal must block. Do not add a third key. Anything may `pushToast()` directly for an outcome that never reaches a flash. **A form with one field says its validation complaint in a toast too**, not under the field: the control's red ring already says which control, and a reserved line under a single input is empty almost all of the time. Multi-field forms keep their per-field messages — a toast cannot say which of eight fields it means.

## Tokens

Brand primary `#00aeef` (hover `#009bd8`), accent `#07559e` (hover `#043d78`). Page `bg-slate-50`, card `bg-white border-slate-200 shadow-sm`, row hover `hover:bg-sky-50/50`, active nav `bg-sky-100 text-[#07559e]`, error `text-rose-600`. Font is Poppins; icons are Font Awesome classes (`fa-solid fa-…`).

Radius: `rounded-lg` buttons/nav, `rounded-xl` `.field` inputs, `rounded-2xl` hero/auth cards, `rounded-3xl` modals, `rounded-full` badges. `Card` is square-cornered on purpose.

Eyebrow label above a heading: `text-xs font-bold uppercase tracking-[.18em] text-[#07559e]`.

## The field triad

Every form field, everywhere:

```html
<label>
    <span class="required-label text-sm font-semibold text-slate-700">Name</span>
    <input v-model="form.name" class="field" :class="{ 'border-rose-500': form.errors.name }" />
    <p v-if="form.errors.name" class="error">{{ form.errors.name }}</p>
</label>
```

`.field`, `.error` and `.required-label` are defined in `@layer base` in `app.css`. Drop `required-label` for an optional field.

## Page recipes

**Index** — copy `resources/js/pages/divisions/index.js`. `<Head>` → `<AdminShell active title>` → `<Card>` → eyebrow/`h2`/description → `<IndexControls>` → table in `overflow-x-auto` with a `min-w-[…]` and an empty-state row → `<Pagination>` → modals as siblings. Filter visits are `router.get(url, filters, { preserveScroll: true, preserveState: true, replace: true })` with a 350 ms debounce on search.

**Form** — copy `resources/js/pages/divisions/form.js`. **One component serves create and edit**, branching on a nullable model prop. Submit through `useConfirmSubmit` + `ConfirmActionModal`. Use `useForm`; Inertia's `<Form>` component is not used in this codebase. File uploads pass `forceFormData: true` and show `form.progress.percentage`.

**Row actions** — `RowActions`, icon-only, actions passed as data. Each carries `title` for the hover tooltip and `aria-label` for screen readers; never render icon + visible label in a table's Actions column.

**Delete** — a `ConfirmActionModal` on the index page. There are no separate delete pages.

## Before finishing

- [ ] `npm run build` passes
- [ ] Titles `uppercase tracking-wide`
- [ ] `.field` / `.error` / `.required-label` / `border-rose-500` used correctly
- [ ] No `dark:` classes
- [ ] Table has `overflow-x-auto`, a `min-w-[…]` and an empty state
- [ ] Standalone page's `<main>` has `flex-1`
- [ ] Destructive action goes through `ConfirmActionModal`

## Do not add a permission check

`AdminShell`'s `MODULES` array is filtered against the shared `auth.permissions` prop, and each entry names the `permission` that reaches it (`null` when the route is deliberately public). A new entry needs its `permission:` route gate in `routes/web.php` **in the same change** — hiding a sidebar entry while leaving its route open reads as protection that is not there. Hide a button with a server-computed `can` prop on the page, never by checking `auth.permissions` in a template. See `documentation/architecture.md` → Access model.
