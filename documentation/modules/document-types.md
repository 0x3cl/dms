# Document types and groups

The office's filing vocabulary: the types a document can be filed under, the tracking-code prefix each one stamps, and the groups that arrange them in the picker.

## Key files

| Path | What it holds |
| --- | --- |
| `app/Http/Controllers/DocumentTypeController.php` | `index()` is the group shelf; `group()` is what is on a shelf; create/edit/delete/restore of a type |
| `app/Http/Controllers/DocumentTypeGroupController.php` | Create, edit and delete a group — behind the same grants |
| `app/Models/DocumentType.php` | `selectable()`, `grouped()`, `filterable()`, and whether a type has issued a code |
| `app/Models/DocumentTypeGroup.php` | The arrangement, and its generated `code` |
| `database/seeders/DocumentTypeSeeder.php` | Documents, Assets, Images, Videos on a fresh install, matched on `code` |
| `resources/js/pages/document-types/{index,group,form}.js`, `document-type-groups/form.js` | The shelf, a shelf's contents, the two forms |
| `tests/Feature/DocumentTypeTest.php` | The screens, the grouping, and what deleting a group does |

## A table, not a constant

`document_types` is both the list the upload form offers **and** the map to code prefixes, so the two cannot drift. It replaced `DocumentController::TYPES`, a PHP constant, because adding a type, correcting an abbreviation or retiring one is office business rather than a deploy.

A type's `code` is immutable in practice: it is stamped into every tracking code that type has issued, and those are printed on paper. Retire a type with `is_active` rather than deleting it; `filterable()` reads `withTrashed()`, because a document filed under a type that has since been retired is still in the register and a filter that cannot find it would be a filter that lies.

## The module opens on the groups

A flat table of every type answered the wrong question first. An office looking at twenty rows wants to know what *kinds* of thing it files before which exact type, so:

- `/document-types` is the **shelf** — a card per group with what it holds (`types_count` is what exists at all, `active_types_count` is what is on offer today), and Add group above.
- `/document-types/group/{group}` is **what is on it**, with Add type carrying the group so a type opened from inside one is already filed there.

`{group}` is a group's id, `other`, or `all`. **"Other" is a set with a page, not a gap**: `group_id` is `nullOnDelete`, so deleting a group produces ungrouped types, and a set that exists and cannot be opened is how rows go missing from a screen that looks complete. The group is a **path segment** because it is what somebody navigated to; search and status stay query parameters, because those really are filters on what is already there.

> **Trap.** `document-types/group/{group}` and `document-types/create` are literal segments and must stay declared **before** `document-types/{documentType}`, or the router captures them as a type id.

## A group has its own create, edit and delete — and no module of its own

It began as a name typed into the type form, on the reasoning that a heading is something you name in passing. That was half right: a thing you can create but never rename, reorder or remove is a thing the office is stuck with the first time somebody mistypes one.

What survives of the reasoning is that there is **no Groups module**. A group and a type are one piece of office vocabulary, so `document-types.{read,write,delete}` gate both — two sets of permission names is how a role ends up holding half of one grant.

> **Trap.** A group's `code` is generated and never shown, which is the difference from a type's. A type's code is stamped into tracking codes and printed, so it is asked for and then locked. A group's never leaves the database — it is only the handle the seeder matches on — so renaming a group deliberately does **not** regenerate it, or the next `db:seed` would recreate the group it replaced.

## `grouped()` is built once, on the server

`DocumentType::grouped()` returns `[{ label, options: [{ value, label, code }] }]`, sorted by each group's `position`, with **Other last** (`PHP_INT_MAX`). It is built on the server because two surfaces offer the same picker: the document upload form and the file manager's Track-this-file dialog. Two client-side groupings of one list is how one of them quietly stops matching.

## The type is a tag, and it gates nothing

It picks the tracking-code prefix and labels the row. A document of type "Confidential" is **not** thereby confidential — somebody has to classify it, and classification is `documents.visibility`, resolved by `AccessResolver`. Deleting a group rearranges a picker; it must never retire a type, which is part of the record.
