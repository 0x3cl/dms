# Dashboard

The signed-in landing screen: a KPI strip, three quick actions, and short "recently…" lists.

## Key files

| File | Role |
| --- | --- |
| `app/Http/Controllers/DashboardController.php` | The figures and the short lists |
| `resources/js/pages/dashboard/index.js` | The page |
| `resources/js/components/CountUp.js` | The animated KPI numbers |

## What it shows

Four KPIs, all scoped to the signed-in user: documents uploaded, documents received, still in circulation, and ended by you. Then quick actions (upload, scan, track-by-code), the five most recent uploads, the five most recent documents received, and the six most recent log entries by this user.

`App\Enums\DocumentAction::deliveries()` — `downloaded` and `received` — is the list of actions that count as having taken delivery, read through `User::deliveredDocumentIds()`. It lives on the enum rather than in a constant here so the dashboard and the trail cannot disagree about what "received" means.

## Traps

> The "ended" figure counts `documents.status` for this user, compared against `DocumentStatus::Ended`. It originally counted `document_logs` rows with `action = 'ended'` while `DocumentController@end` wrote `'Ended'`, so the KPI was **permanently zero** — which is the reason these three vocabularies are enums now.

> Every list here is capped (`limit(5)`/`limit(6)`). The Blade dashboard was seven Bootstrap tab panes in one file, three of which were whole unpaginated tables — including `Document::latest()->get()`, every document in the system. Those are their own paginated screens now (`/documents`, `/documents/all`). Do not reintroduce an uncapped query here.

> Timestamps come through `formatDay`/`formatTimestamp` from `resources/js/utils/format-date.js`. `formatDate` in that file expects a **date-only** string and will not parse a serialized Carbon value.

## The tiles count what somebody can act on

Uploaded, received, shared with named staff, readable office-wide.

> **Trap.** There is no "ended" tile, and reinstating one would mean reinstating `documents.status`. That figure was permanently zero for months — one comparison looked for `'ended'` while the writer stored `'Ended'` — and nobody noticed precisely because nothing depended on it. The End Action is gone; see `documents.md`.
