# Sharing and access resolution

Who may do what with a file or folder, and the two ways access is granted: a **share** (to a person, division, section or role) and a **share link** (to whoever holds the URL).

## The one resolver

`App\Services\Access\AccessResolver` is the single place access to a file, folder or document is decided. **Nothing else may decide it.**

`DocumentController::isAuthorized()`, `::owns()` and `DocumentLogController::canSee()` all delegate to it. Those were three hand-copied statements of one rule, two of them carrying a comment saying the pair had to change together. A comment is not a mechanism.

### Resolution order — first match wins

| | Rule | Source |
| --- | --- | --- |
| 1 | a share link, when one is presented | `link` |
| 2 | the tracked-document rule, for a file carrying a tracking code | `uploader` / `public` / `recipient` |
| 3 | ownership | `owner` |
| 4 | an explicit share **on the item itself** | `share` |
| 5 | a share inherited from the **nearest** ancestor folder carrying one | `inherited_share` |
| 6 | effective visibility | `public` |
| 7 | the `files.manage` module permission — **read only** | `module_manage` |
| 8 | otherwise denied | `denied` |

`AccessGrant` carries the `source` as well as the abilities, so an audit row can record *why* something was allowed. After the fact, "because of the division it was shared with" and "because the item was public" are very different answers.

Everything fails closed: an unrecognised subject, a null user, a missing folder chain and an expired grant all resolve to denied, never to "no rule said no".

> **Trap.** A file carrying a tracking code is decided by the **document's** rule and nothing else. There must not be a second, looser way to reach a document's bytes than the one its trail and recipient list were built around — the obfuscated file URL is not a side door. Covered by `test_a_tracked_file_is_refused_to_someone_the_document_refuses`.

> **Trap.** The document clauses are reproduced in the resolver **verbatim and in order**, and `tests/Feature/DocumentAuthorizationTest.php` is their contract. That file did not move when the rule was lifted, and it must not.

> **Trap.** Those callers return `unauthorizedRedirect()`, not `$this->authorize()`. The test asserts a redirect to the dashboard with a flash, because the person is looking at a page and deserves an explanation. The *file stream* route is the opposite — see below.

## Inheritance, stated once

**Effective visibility is the most restrictive value on an item and every folder above it.** A file marked public inside a confidential folder is confidential. It cannot be widened from below, and it fails closed.

> **The account's root folder is excluded from that chain.** A root is a container, not a folder anyone sees or sets a visibility on — it is named `root-{id}` and never rendered, and it is created `private`. Leaving it in would clamp *everything* to private and make a public folder impossible. Nothing is weakened: a folder created under the root inherits `private` as its own value, so the default is still private — it just becomes the item's privacy rather than an unalterable ceiling. Found by a test, not by reading the code.

**Shares travel downward only, and the nearest ancestor carrying one wins.** The walk stops at the first folder with an applicable share. Continuing up and unioning everything above would let a broad grant near the root silently widen a narrower one made deliberately further down.

**A share on the item itself beats anything inherited.**

**Confidential is the one level inheritance does not reach into.** A confidential item is reachable only by a share naming the item itself — never inherited, never through a link. That *is* what confidential means here.

**Where several grants reach the same person, the most generous applies.** Someone can be reached by their division *and* their section *and* a role, saying different things. Taking the widest is the only answer that does not depend on row order.

## Listings: `scopeVisible()`

`AccessResolver::scopeVisible()` is the same rule in SQL, because a listing has to be paginated and cannot resolve each row in turn.

> **Every listing query must go through it.** A query that fetches broadly and filters in PHP afterwards is the leak: the paginator totals are wrong, those totals leak existence even when the row is never rendered, and the first `->map()` that forgets the filter discloses the rows themselves.

The two implementations are kept honest by a **differential test**. `FileListingTest::test_the_listing_scope_agrees_with_the_resolver_for_everyone` builds one scene containing every case the rule distinguishes, then — for four different viewpoints — resolves *every* row through `grant()` and asserts the listing returns exactly that set. It asserts no hand-written expectation, so it cannot drift as the rule grows.

That test has already earned its place twice: it caught the root-visibility clamp above, and the column collision below.

> **Trap: `files.storage_path`, never `files.path`.** `folders.path` is the materialized *ancestor* path; a file's equivalent is its **folder's** path plus that folder's own id, because the folder holding a file is one of its ancestors. The column was called `path` on both tables at first, and the SQL searched a file's **disk** path for a folder id. That never matches, so every ancestor rule — the visibility clamp and inherited shares alike — silently evaluated as "no ancestors": a public file inside a private folder was listed to everyone, and a division share two levels up reached nobody. Renamed so the trap cannot recur. `AccessResolver::ancestorPathSql()` is the one place the distinction lives.

> **Trap: EXISTS, not a join.** The share checks are `EXISTS` subqueries. A join returns the same row once per matching share, and someone reached by their division *and* a role is normal — so a join quietly corrupts every paginator total. Covered by `test_several_matching_shares_do_not_duplicate_a_row`.

## Shares

`item_shares` — a morph to `File` or `Folder`, a `grantee_type` of `user | division | section | role`, and an ability.

**A share is created where the thing is created.** The New folder dialog has a recipient picker and the document upload form has one; the share drawer does not. A second place to grant access, opened later from a panel, is how an item ends up shared with people nobody remembers choosing — and `ShareController::store()` is gone with the picker, along with the whole directory the share-state endpoint used to ship on every open.

> **Consequence worth knowing.** Somebody cannot be added to an *existing* folder from the UI. The service still takes any grantee, and `ShareController::destroy()` still revokes, so what was removed is one screen's ability to grant — not the model's. Tag at creation, or create a folder for them.

> A tag from somebody who holds `files.create` but not `files.share` is **refused**, not quietly dropped. A folder that reports itself as shared with four people who cannot open it is worse than an error.

Tags are granted `ShareAbility::Download` — view and take a copy — which is the useful default for a folder somebody is being given, and short of letting them rename or delete what is in it.

A **group is a role**. That reuses the membership the roles screen already manages rather than adding a second, parallel notion of a group that someone then has to keep in step.

> **The grantee foreign keys `cascadeOnDelete`**, unlike every foreign key on an audit row. A share is not a record of something that happened; it is a live grant. One whose grantee no longer exists grants access to nobody, and leaving it as a half-row would make every resolver and listing special-case a null grantee — the kind of branch that eventually gets one `OR` wrong and fails open. `granted_by_name` *is* snapshotted beside a `nullOnDelete` key, because "who gave me this" must stay answerable after that person leaves.

> **Trap.** A null grantee column must never match. `ItemShare::scopeReaching()` only adds the division clause when the user *has* a division — otherwise `where('division_id', null)` matches a division share for every user who also has no division. Covered by `test_a_user_without_a_division_is_not_matched_by_a_division_share`.

Re-sharing with the same grantee **updates** the grant (and clears any revocation) rather than failing on the unique index or leaving two rows whose effective answer depends on row order. That unique pair is the lesson `document_recipients` already learned.

Revoking **marks** rather than deletes: the screen has to show that access was withdrawn, and re-granting should be deliberate rather than something a resubmitted form does by accident.

Sharing an item with its own owner is refused — they already hold everything, and the row could only confuse whoever reads the list later.

## Share links

`share_links` — a 64-character CSPRNG token, an ability, and three independent ways to be spent: revoked by hand, past `expires_at`, or out of `max_views`. **All three are checked on every redemption.**

> **A link never grants write and never grants manage.** The holder of the URL is the audience. `SharingService::createLink()` refuses anything but restricted, view or download, and the resolver narrows the grant again on redemption, so a row holding `manage` — however it got there — still cannot grant write.

### The term decides whether the URL is a key

`ShareAbility::Restricted` is the third term a link may carry, and it is not a smaller view. It says **the link is an address, not a credential**: `AccessResolver::fromLink()` resolves it to the holder's *own* access, so following it is the same as typing the tracking code. The uploader and the tagged people reach the document with everything their grant allows — download included, which the term itself never carries — and a stranger reaches a page stating the level and nothing else, with the bytes behind it answering 404.

It is the **default for any document restricted to a named list** (`SharingService::defaultLinkAbility()`), which is what keeps the automatic link behind a printed QR label an address: whoever photographs the folder learns the classification and no more.

Widening it to view or download makes the URL a bearer credential **whatever the document's level**. That is the office's decision to make and the reason the term exists — a document restricted to three named colleagues is the ordinary case in which somebody outside the office has to be sent a copy, and the alternative was reclassifying the document as public, which opens it to the whole institute to let one outsider read it. Widening one URL is narrower than that in every direction: it is revocable, re-addressable, capped, expiring and counted, and the recipient list goes on governing every other way in.

> **This replaced a rule read off the document's level.** `ShareLinkController::opensForHolder()` used to ask whether the *document* was restricted; it asks the *link's term* now. The migration `2026_11_21_000002_restrict_links_already_in_circulation` moves every live link on a restricted document onto `restricted`, because leaving them as they were would silently turn URLs already sent — and labels already printed — into bearer credentials, and a link's reach is only ever narrowed after it has been sent.

> **Restricted is a document's term.** A file or folder has no named-reader rule for the link to defer to — its rule is its visibility and its shares, which a link is already the sanctioned way around — so `SharingService::assertLinkable()` refuses it there rather than inventing one.

> **The link's own state still decides first.** A revoked, expired, spent or reclassified link resolves to nothing whatever its term, so "the link no longer works" means one thing rather than two.

### One link, re-addressed

`SharingService::regenerateLink()` mints a new token on the same row, keeping the ability, the expiry and the cap. It is what somebody wants when a URL has travelled further than they meant it to, and it is not revoke-and-create: that leaves the document briefly with no link at all — which a public one's printed label points at — and starts a row whose history is empty.

`view_count` **is** reset, which is the one place this parts company with `updateLink()`, where views are a record rather than a budget. The URL those opens were spent against no longer exists, so carrying the count forward would hand somebody a new link that is already partly used up. What it was goes into the audit row, which is where a count that is no longer true belongs.

It is written to the activity log as `ActivityAction::LinkRegenerated` and to the **document's own** trail as `DocumentAction::LinkRegenerated`, because it ends a hand-off: every copy of the URL stops working, and a reader holding a dead link is entitled to learn why from the document's history. Neither row carries a token, old or new. The stored QR label is redrawn, since the address it encodes has changed.

> **A confidential item can never be linked**, and the check runs at creation *and* on every redemption. An item can be reclassified as confidential after a link has been handed out; the link has to stop working at that moment, not when somebody remembers to revoke it. Reclassifying a *folder above it* has the same effect. Both covered.

> **A link authorizes only the item it points at.** `AccessResolver::fromLink()` verifies the subject matches `shareable_type`/`shareable_id`. Without that, one legitimate link would authorize a request for any other file.

`ShareLink::recordAccess()` is an atomic increment, not a read-modify-write: a link with `max_views = 1` passed to two people at once would otherwise be spent twice, which is the case a view cap exists for.

### Everything 404s

On `/s/{token}` and on the file stream route, a refusal, a revoked link, a spent link and a token that never existed all answer **404**.

Distinguishing them confirms to anyone probing that a token was once real, which is the one thing a bearer URL must not leak. This is deliberately *unlike* the rest of the application, where an unauthorized page visit redirects with an explanation — there the caller is a person reading a page; here it is an iframe or a download that cannot render one.

## `files.manage` is a read, and it is Browse Storage

The administrator's permission over all storage grants **view and download only**, on everything. Auditing storage means being able to look, not to edit or delete in someone else's name. Write still requires ownership or a share. Covered by `test_files_manage_grants_read_but_not_write`.

It is also the gate on `/files/browse`. `files-browse.read` was a second name for the same grant — which meant a role could hold the screen without the rule, or the rule without the screen — so it has been retired along with `files-shared.read` (Shared with Me is part of My Files now) and `files.write` (which gated no route and never had). See `database/migrations/2026_11_11_000001_retire_merged_module_permissions.php`.

## Office previews are the one bearer URL besides a share link

A Word, Excel or PowerPoint file is rendered by Microsoft's Office viewer, which fetches the file itself, so `FilePreview::viewerUrl()` mints a signed URL that works without our session. (A PDF never gets one: the browser renders those, and the Office viewer cannot.) It answers to the **same** rule a share link does — minted only after a grant, expiring in minutes, naming one file, never for a confidential item, and re-checked on every fetch because an item can be reclassified after a URL has been handed out.

It also mints nothing at all when `app.url` names a host the internet cannot resolve, because a frame pointing at `localhost` can only ever stay empty. See `file-manager.md`.

## A link reaches the current version only

`ShareLinkController::stream()` answers **404** to a `version` parameter naming anything but the current one, and the landing page ships no version list — so it does not even disclose that a history exists.

Three reasons, and the second is decisive:

1. A link is a bearer credential minted for a thing at a moment. Its terms are an ability, an expiry and a view cap; none of them says anything about history, so reaching history through it is reach nobody granted.
2. **Every token already in circulation would have widened silently** the day versioning shipped, including links sent before the feature existed. This module's hardest rule is the opposite — a link's reach is re-evaluated on every redemption and *narrowed* when the item is reclassified, which is why `updateLink()` keeps the token so that narrowing works on a URL already sent.
3. `AccessResolver::linkCovers()` answers "does this link point at this subject". A version is not a subject the resolver knows, so admitting one would mean either teaching the resolver about versions — a second door — or skipping it, which is worse.

Covered by `test_a_share_link_cannot_reach_an_old_version`.

## Key files

| File | Role |
| --- | --- |
| `app/Services/Access/AccessResolver.php` | **The one place access is decided**, plus `scopeVisible()` and `effectiveVisibility()` |
| `app/Services/Access/{AccessGrant,AccessSource}.php` | The answer, and why |
| `app/Services/Files/SharingService.php` | Granting, revoking, minting and resolving links |
| `app/Models/{ItemShare,ShareLink}.php` | `scopeActive`/`scopeReaching`, `isUsable`/`recordAccess` |
| `app/Enums/{ShareAbility,GranteeType,FileAbility,FileVisibility}.php` | The vocabularies |
| `app/Http/Controllers/ShareLinkController.php` | Redeeming a link |
| `resources/js/pages/share/show.js` | The landing page — standalone, so its `<main>` carries `flex-1` |
| `tests/Feature/{FileSharingTest,FileListingTest,ShareLinkRouteTest}.php` | |
