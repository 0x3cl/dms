# Realtime

Notifications and both audit trails update without a reload, over **Laravel Reverb**. There is no screen and no permission of its own: realtime is a property of pages that already exist, gated by the rules those pages already apply.

## Key files

| Path | What it holds |
| --- | --- |
| `routes/channels.php` | Who may listen — **the entire gate** |
| `app/Events/DocumentTrailUpdated.php` | A document's trail changed: id + timestamp, nothing more |
| `app/Events/ActivityTrailUpdated.php` | The file-and-folder trail changed |
| `resources/js/echo.js` | The one connection, and `listen()` — a no-op unsubscribe when there is no socket |
| `resources/js/composables/useLiveTrail.js` | What the client does with a signal |
| `resources/js/components/NotificationBell.js` | Listens on `users.{id}` |
| `config/reverb.php`, `config/broadcasting.php`, `.env.example` | The server and the four `VITE_` values |
| `tests/Feature/BroadcastingTest.php` | That a row announces itself, and what the payload may contain |
| `tests/Feature/ChannelAuthorizationTest.php` | A matrix of readers and levels, differential against `AccessResolver` |

## A trail broadcasts a signal, not the row

`DocumentTrailUpdated` carries a document id, a log id and a timestamp. The obvious implementation pushes the new row and the page prepends it; it is wrong here for three separate reasons.

1. **These lists are paginated.** Splicing a pushed row into page two produces a list whose totals disagree with its contents — the same class of bug `FileListingTest` exists to catch on the listing side. A refetch goes back through the controller, which re-paginates correctly.
2. **A refetch is re-authorized and re-serialized by the one code path that already gets it right.** A broadcast payload is a second serializer, and a second serializer is how a field nobody meant to expose gets exposed.
3. **No audit row ever carries a live credential.** A signal cannot violate that invariant; a payload has to be re-checked every time it changes. `test_the_payload_carries_no_record_and_no_credential` holds the line.

The events are dispatched from `DocumentLog::record()` and `ActivityLog::record()` — the same single factories that build the rows — so nothing can write to a trail without the page hearing about it.

> **Trap.** They are `ShouldBroadcastNow`, not `ShouldBroadcast`. Queued, they would reach no browser until a worker ran, which on an installation without a supervised `queue:work` is never. The payload is two values and the write is already inside a request.

## The client decides what to do with it, and usually that is "offer"

`useLiveTrail(channel, { prop, isLive })`:

- **Page one, no filter** (`isLive()`): reload *that prop only*, and let the server re-paginate.
- **Anywhere else**: count, and show a pill — *"3 new entries — refresh"*. A viewer on page two must not have page-one rows injected under them, and a filtered viewer must not be told a new row matched their filter when nobody has checked.

Two guards keep it from becoming a refetch storm: a burst inside `COALESCE_MS` (1500 ms) becomes one reload, and nothing happens at all while the tab is hidden — ten people with a trail open must not mean ten reloads per event. The unsubscribe runs in `onBeforeUnmount`, because Inertia keeps the app alive across visits and a missed unsubscribe accumulates one subscription per navigation.

## `routes/channels.php` is the whole gate

`/broadcasting/auth` carries `web` and `auth` and **no `permission:` middleware**, so anything these callbacks forget is open.

| Channel | Who |
| --- | --- |
| `documents.{document}` | `documents.read` **and** `AccessResolver` granting `View` on the document — the same rule that decides whether the trail page opens |
| `activity-trails` | `activity-trails.read`, the permission its route carries |
| `users.{id}` | That user, and nobody else |

A document's travel history is exactly as sensitive as the document: a log listing who received a confidential case file is a disclosure of its own. So `ChannelAuthorizationTest` resolves a matrix of readers and visibility levels through `AccessResolver` and asserts the channel answers **identically**, in the same spirit as `FileListingTest` — the two statements of one rule cannot drift.

> **Trap.** `find()`, never `findOrFail()`. An exception inside an authorization callback is a 500, not a refusal. A missing or soft-deleted document returns null and is refused, which is correct — its trail page is unreachable too.

> **Trap.** In a channel test, swap the broadcaster **and** `Broadcast::forgetDrivers()`. Channels are registered on the *resolved* driver, so swapping without re-registering means every assertion passes against a driver that has no callbacks.

## It fails soft, and the secret stays server-side

`echo.js` builds no connection when `VITE_REVERB_APP_KEY` is absent, and `listen()` hands back a no-op unsubscribe, so every page still works from its Inertia props on a deployment with no websocket — a dashboard that breaks because a daemon is not running is worse than one that simply does not live-update. A misconfigured host is caught and treated the same way. `phpunit.xml` sets `BROADCAST_CONNECTION=null`, so the suite needs no server.

> **Trap.** `REVERB_APP_SECRET` must never gain a `VITE_` twin. Anything prefixed that way is compiled into the browser bundle and served to every visitor. Only the key, host, port and scheme are exposed.

## Running it

`composer run dev` starts `reverb:start` alongside `php artisan serve`, the queue listener and Vite. In production it needs a supervised `reverb:start` and a websocket route proxied to `REVERB_SERVER_PORT`; without them, leave `VITE_REVERB_APP_KEY` empty rather than pointing the browser at a socket that will not answer.
