# Module context docs

One doc per module, describing its purpose, key files, data flow and traps. Read a module's doc before changing it, and update it in the same change — see `AGENTS.md` → Keeping docs current.

| Module | Doc | Owns |
| --- | --- | --- |
| Documents | [documents.md](documents.md) | Upload, tracking codes, QR labels, visibility, the audit trail, download logs |
| Document types | [document-types.md](document-types.md) | The filing vocabulary: types, their code prefixes, and the groups that arrange them |
| Access requests | [access-requests.md](access-requests.md) | Asking the uploader to be let into a document, and answering |
| Dashboard | [dashboard.md](dashboard.md) | The signed-in summary and quick actions |
| Directory | [directory.md](directory.md) | User accounts, divisions, sections |
| Account | [account.md](account.md) | Profile, password, account deletion, theme |
| Auth | [auth.md](auth.md) | Sign-in by employee ID, registration, password reset |
| Roles | [roles.md](roles.md) | The access model: permissions, roles, the sidebar filter, route gates |
| File storage | [files.md](files.md) | The private disk, obfuscated URLs, the folder tree, visibility |
| Sharing | [sharing.md](sharing.md) | The access resolver, shares, inheritance, share links, listing scopes |
| File Manager | [file-manager.md](file-manager.md) | The screens, the operations, the view toggle, previews, the trash, move/copy, the share drawer |
| Upload rules | [upload-settings.md](upload-settings.md) | The one administrator-edited upload policy, and archive zip/extract safety |
| Tutorials | [tutorials.md](tutorials.md) | The guided-tour engine, the mascot, the tours and their anchors |
| Data integrity | [data-integrity.md](data-integrity.md) | Delete strategies, soft deletes, ownership transfer, purging |
| Activity trail | [activity-trail.md](activity-trail.md) | The general audit log, the single writer, and its boundary with `document_logs` |
| Realtime | [realtime.md](realtime.md) | Reverb, the trail signals, the channel gate, and how it fails soft |

A module doc is expected to cover: what the module is for; the controller, model, routes and page files; the data it reads and writes; and anything non-obvious a future change could break. Keep the architectural "why" that spans modules in `CLAUDE.md` instead.
