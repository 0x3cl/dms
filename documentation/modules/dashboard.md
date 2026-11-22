# Dashboard

The signed-in landing screen: a KPI strip, three quick actions, and short "recently…" lists.

## Key files

| File | Role |
| --- | --- |
| `app/Http/Controllers/DashboardController.php` | The figures and the short lists |
| `resources/js/pages/dashboard/index.js` | The page |
| `resources/js/components/CountUp.js` | The animated KPI numbers |

## What it shows

Four KPIs, all scoped to the signed-in user: documents **uploaded**, documents **received** (`DocumentAction::deliveries()`), **shared** — their own uploads at the `private` level, i.e. restricted to named staff — and **open**, their own uploads that are `public` and so readable office-wide. Then quick actions (upload, scan, track-by-code), the five most recent uploads, the five most recent documents received, and the six most recent log entries by this user.

`App\Enums\DocumentAction::deliveries()` — `downloaded` and `received` — is the list of actions that count as having taken delivery, read through `User::deliveredDocumentIds()`. It lives on the enum rather than in a constant here so the dashboard and the trail cannot disagree about what "received" means.

## Traps

> There is no "ended" figure, and this is where the lesson behind the enums comes from. It once counted `document_logs` rows with `action = 'ended'` while the writer stored `'Ended'`, so the KPI was **permanently zero** for months and nobody noticed, because nothing depended on the answer. `App\Enums\DocumentStatus` and the End Action are gone entirely — see the trap at the end of this file.

> Every list here is capped (`limit(5)`/`limit(6)`). The Blade dashboard was seven Bootstrap tab panes in one file, three of which were whole unpaginated tables — including `Document::latest()->get()`, every document in the system. Those are their own paginated screens now — `/documents`, which is one page with three tabs. Do not reintroduce an uncapped query here.

> Timestamps come through `formatDay`/`formatTimestamp` from `resources/js/utils/format-date.js`. `formatDate` in that file expects a **date-only** string and will not parse a serialized Carbon value.

## The tiles count what somebody can act on

Uploaded, received, shared with named staff, readable office-wide. Both recent lists render from the trail's own snapshots, so `recentActivity` needs only the document it points at — no actor is joined.

> **Trap.** There is no "ended" tile, and reinstating one would mean reinstating `documents.status`. That figure was permanently zero for months — one comparison looked for `'ended'` while the writer stored `'Ended'` — and nobody noticed precisely because nothing depended on it. The End Action is gone; see `documents.md`.
