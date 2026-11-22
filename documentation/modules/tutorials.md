# Tutorials (guided tours)

In-app walkthroughs on [driver.js](https://driverjs.com/), with the DOST-PTRI mascot standing beside the popover. A lightbulb beside the notification bell opens the menu.

**There is no backend.** No routes, no tables, no permission of its own. A tour is a list of steps in a file, and the menu filters them against the shared `auth.permissions` prop so nobody is offered a walkthrough of a page they cannot open.

## Ported, deliberately leaner

Adapted from PROCMIS, assets and all, so the two systems read as one product family. Three pieces of its runner were **not** ported:

- **sample-data pages** (`sample: { component, props }`) — DocTrack has no tour that runs on made-up records;
- **the second "supplier" audience** and its seven-pose costume — there is no supplier portal here, and an asset nothing references is weight in the repository;
- **nested overlay tracking** (`overlay: 'quotations/compare'`) — no tour here goes two overlays deep.

Porting that machinery would have been dead code pretending to be capability, which is the same reason `App\Support\Modules` holds only permissions that gate something.

## The tours

| Tour | Offered to | Covers |
| --- | --- | --- |
| Find your way around | everyone | the sidebar, bell, lightbulb, account menu |
| Upload and track a document | `documents.create` | the form, types and code prefixes, visibility-set-once, the printed label |
| Work with the office register | `documents-all.read` | the All tab, search, filters, and why the URL is shareable |
| Track or scan a document | everyone | track-by-code and the camera |
| Organise your files | `files.read` | folders, upload, the view toggle, preview |
| Share a file or folder | `files.share` | the three privacy levels, inheritance, tagging at creation, share links |
| Set up roles and permissions | `roles.write` | what a role is, and what a permission is *not* |
| Your account and theme | everyone | tabs, theme, closing the account |

`permission: null` is used only where the route genuinely is open to any signed-in user, which now includes the tracking tour: `/documents/track` is behind `auth`, because a tracking code is printed on the folder and is not a credential. Anybody with an account may track. Somebody without one has the public `/track`, which describes a public document and tells them only the classification of a restricted one, and a share link when they need a copy.

> **Trap, and it happened during the screen merges.** An anchor a tour references but no markup defines is a *silent* break: the step is dropped or falls back and nothing fails. Folding "All Documents" into a tab and "Shared with Me" into My Files moved or removed three anchors between them — the register tour's page href, the upload tour's `add-button` (now `documents-upload`) and the sharing tour's `/files/shared` page. Scan the tour files for `[data-tour="…"]` and diff against the markup after touching any page a tour visits.

> The sharing tour is the most consequential one in the set. The difference between *private* and *confidential* is the difference between "nobody unless I say so" and "not even through a folder I shared", and getting that wrong is a disclosure. It is explained there in those terms rather than as a list of levels.

## A tour

```js
{
    key: 'upload-document',
    label: 'Upload and track a document',
    hint: 'Tracking code, QR label, who can see it',
    module: 'Documents',            // dropdown group heading
    icon: 'fa-solid fa-cloud-arrow-up',
    paths: ['/documents'],         // URL prefixes where it is listed first
    permission: 'documents.create',// string, array (any-of), or null
    pages: [
        { href: '/documents', steps: [ … ] },
        { href: '/documents/create', steps: [ … ] },
    ],
}
```

A **page** is a URL plus its steps. Finishing a page's last step visits the next page's `href` and continues there.

A **step** is a driver.js step plus two optional extras:

- `activate` — a selector, or list of them, clicked before the step runs, so a step can open the drawer or tab its element lives in. Clicked in order with a beat between, because opening a drawer and then a tab inside it needs the drawer rendered before the tab exists.
- `fallback` — `{ title, description }` shown centred when the element is missing, ending the tour there.

> **`fallback` matters more than it looks.** An empty folder has no first row to point at, and a brand-new account has no documents. A tour that silently stops is worse than one that says why — so a step whose element is absent either shows its fallback and ends, or is dropped and the tour carries on.

`{name}` in a title or description is replaced with the person's first name.

## The mascot

`resources/js/tutorials/mascot.js`, ported nearly verbatim. Eighteen poses in `public/images/mascot/staff/` (≈490 KB).

It owns **one element appended to `document.body`**, reused for the whole session, rather than living inside the popover — driver.js destroys and recreates the popover on every step, so a mascot inside it would be torn down and rebuilt with it, losing its pose.

Pose choice is automatic: step one is `welcome`, the last is `celebration`, a step with no element prefers `thinking`, a step pointing at something clickable prefers `pointing`, and the rest are dealt from a shuffled bag so every pose is spent before any repeats.

> The box is **150×180**, in both `mascot.js` (`MASCOT_WIDTH`/`MASCOT_HEIGHT`) and the `.doctrack-tour-mascot` rule. Change one and change the other.

It hides below a 640×480 viewport, where the popover already fills the screen.

## Anchors

Steps point at `data-tour="…"` attributes.

`IndexControls` and `Pagination` already carried theirs — `index-search`, `index-entries`, `index-filters`, `add-button`, `pagination` — inherited from the original port and unused until now.

| Anchor | Where |
| --- | --- |
| `sidebar`, `open-sidebar`, `content`, `notifications`, `tutorials`, `account` | `AdminShell.js` |
| `index-search`, `index-entries`, `index-filters`, `add-button` | `IndexControls.js` |
| `pagination` | `Pagination.js` |
| `view-toggle` | `ViewToggle.js` |
| `file-row` | `FileGrid.js` / `FileTable.js`, **first row only** |
| `files-new-folder`, `files-upload` | `files/index.js` |
| `document-title`, `document-file`, `document-visibility`, `document-submit` | `documents/form.js` |
| `track-code` | `documents/track.js` |
| `scanner` | `documents/scan.js` |
| `account-tabs`, `theme-toggle` | `account/index.js`, `AdminShell.js` |

> **An anchor a tour references but no markup defines is a silent break** — the step is dropped or falls back, and nothing fails. Both of those were caught by scanning the tour files for `[data-tour="…"]` and diffing against the markup; do that after adding a step.

## Overlays get out of the way

A tour must never run behind an open drawer or under a fullscreen modal, so the runner dispatches `doctrack:tutorial-started` before each page and `useCloseOnTutorial()` closes anything listening: `AdminShell`'s mobile sidebar and profile menu, `ShareDrawer`, and `FilePreviewModal`.

> The sidebar is the exception that proves the rule: the system tour **opens** it deliberately on a narrow screen, through the step's `activate`, *after* this has closed it. Closing first and opening on purpose is predictable; inheriting whatever the user had open is not.

## Styling

`.doctrack-tour` and `.doctrack-tour-mascot` in `resources/css/app.css`, ported with the prefix changed — a shared prefix would imply a shared stylesheet that does not exist.

They sit **outside `@layer`**, like the dark-theme rules, because driver.js injects its own styles at runtime and unlayered CSS beats layered CSS regardless of specificity.

## Key files

| File | Role |
| --- | --- |
| `resources/js/tutorials/index.js` | The registry and the runner (`openTutorial`, `isTutorialAvailable`) |
| `resources/js/tutorials/mascot.js` | `showMascot`, `hideMascot`, `beginMascotStepChange` |
| `resources/js/tutorials/{system,documents,tracking,files,sharing,roles,account}.js` | The tours |
| `resources/js/components/TutorialMenu.js` | The lightbulb, mounted by `AdminShell` after `NotificationBell` |
| `resources/js/composables/useCloseOnTutorial.js` | The event, and the hook overlays register with |
| `public/images/mascot/staff/*.png` | Eighteen poses |

No tests: there is no backend to test, and the acceptance check is `npm run build` plus running each tour by hand.
