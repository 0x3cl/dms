# Upload rules

What can be uploaded, as a row an administrator edits rather than a literal in a controller.

Gated on `upload-settings.read` / `.write`, **SUPERADMIN only**. This is the one screen in the application that can permit an executable, so it is not part of the `Staff` floor.

## One policy, not one per module

There were **two** rows, `documents` and `files`, and `UploadPolicy` took a `$scope` argument to pick between them. The reasoning was that a tracked document and a file in personal storage are not the same kind of thing.

They are. My Files and Documents are one workflow over one store — a tracked upload already writes a `files` row beside its `documents` row — so two policies were two answers to one question ("may this office upload a spreadsheet?"), and the gate's answer depended on which screen the upload came from rather than on what the file is. The screen made it worse by saying nothing about which upload form read which form, so an administrator could widen the rules and watch the upload fail anyway.

`scope` survives as the single row's key, kept unique, because that is what makes "there is exactly one policy" a constraint the database enforces rather than a convention the code remembers. **Nothing branches on it.** Asserted by `test_one_policy_governs_both_upload_paths`.

> **The merge widens, never narrows.** `2026_11_11_000003_unify_the_upload_policy` takes the union of what the two rows permitted and the wider window wherever they disagreed, plus the larger file count and archive allowances. These are one store now, so a file that may be uploaded has to be uploadable from either screen — and narrowing would revoke a permission somebody is relying on without saying so.

> **Every step of that migration is guarded**, so it lands on the same place from either starting state and running it twice is a no-op. What `down()` cannot restore is the *difference* between the two scopes, or a hand-edited block-list: the merge discards both.

### What is permitted out of the box

**Every safe type in every category** — documents, spreadsheets, presentations, images, video, audio and archives — with a ceiling per category (50 MB documents, 20 MB images, 500 MB video, 100 MB archives).

`UploadSetting::defaults()` derives that from `FileTypes::catalogue()` rather than restating it, so a format added to the catalogue is permitted on a fresh install without anybody remembering a second list. SVG is the one deliberate omission (`NOT_PERMITTED_BY_DEFAULT`): it is a document that can carry script, so it stays available to tick and does not arrive switched on. Covered by `test_every_category_is_seeded_with_its_safe_types`.

> **The seeder repairs a row that permits nothing, and only that.** `firstOrCreate` protects an administrator's edits — but "permits nothing" cannot be one, because the screen refuses to save an empty list. The only way a row reaches that state is by never having been configured, which is what a half-applied schema change leaves behind: a policy refusing every upload in the application while the screen reports *0 permitted*. A policy that permits one type is somebody's deliberately narrow choice and is left alone. `test_the_seeder_repairs_a_policy_that_permits_nothing`, `test_the_seeder_leaves_a_deliberately_narrow_policy_alone`.

## The gate is the server

`App\Services\Files\UploadPolicy` is used by **both** upload paths — `DocumentController::store()`/`update()` and `FileActionController::upload()` — and by `ArchiveService` for every entry inside an archive. None of them passes a scope any more; there is one policy and the gate reads it.

The limits are also sent to the browser (`UploadPolicy::limits()`) so a form can fail fast and show the rules, but **that is a courtesy, not a control**. A frontend check is a hint to a cooperative browser.

### One list, and it is a list of types

A policy is **one** editable list: `allowed_types`, an entry per permitted extension carrying its own `min_size_kb` / `max_size_kb`. **Not in the list means refused.** That is the whole rule, and it is the rule the screen states.

Everything else is derived and lives in `App\Support\FileTypes`:

| Derived | Where | Why it is not a setting |
| --- | --- | --- |
| The MIME types an extension may sniff as | `FileTypes::mimeTypes()` | Which bytes a `.xlsx` starts with is a fact about the format, not a decision about office policy. |
| The refusal of program and script formats | `FileTypes::DANGEROUS` | Nothing an office does requires uploading a `.phtml`, and a veto an administrator can empty is not a floor. |

> **This replaced three lists that had to agree.** `allowed_extensions`, `allowed_mime_types` and `prohibited_extensions` were all administrator-edited, and an upload had to satisfy all three. It failed the way two copies of one fact always fail: somebody added `xlsx` to the extensions — the list the screen presents as the answer to "what may be uploaded" — and had no reason to know that a second list, in a vocabulary nobody types by hand, also needed `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`. A genuine `pds.xlsx` was then refused with *"is not the kind of file it claims to be (application/vnd.openxmlformats-officedocument.spreadsheetml.sheet)"*, naming the very type that should have let it through. Regression: `test_a_genuine_spreadsheet_is_accepted`.

### Extension *and* contents, and only a contradiction is a lie

Both are still checked, and neither alone is enough:

- Only the extension → a renamed executable gets through.
- Only the sniffed MIME type → a file still named `.php` gets through, which is what a misconfigured web server executes.

What changed is what counts as disagreement. `FileTypes::matchesMimeType()` refuses only a **positive contradiction** — the bytes say one format and the name says a different one:

| Sniffed | Verdict | Why |
| --- | --- | --- |
| `…spreadsheetml.sheet` for `.xlsx` | pass | The precise type. |
| `application/zip` for `.xlsx` | pass | Every OOXML and OpenDocument file *is* a zip. A magic-number sniffer stops there; both answers are right about the same bytes. |
| `application/CDFV2`, `application/vnd.ms-office` for `.doc`/`.xls` | pass | Legacy Office is an OLE2 container, reported under several names. |
| `text/plain` for `.csv` | pass | Text has no magic number. |
| `application/octet-stream`, empty | pass | `finfo` says this for anything it has no signature for. **An inconclusive sniff is not evidence of a mismatch**, and treating it as one is what produced false rejections of ordinary office files. |
| `application/x-dosexec` for `.pdf` | **refused** | The bytes are an executable and the name is not. |

Covered by `test_an_office_file_is_accepted_however_it_sniffs`, `test_an_inconclusive_sniff_is_not_treated_as_a_lie` and `test_a_file_lying_about_its_type_is_refused`.

### A size window per type

Each permitted type carries its own; a blank ceiling inherits the scope's `max_size_kb`. A 200 MB video and a 2 MB signature scan are both reasonable and one shared ceiling cannot say so.

> **Trap.** Laravel's `max:` rule runs before anything has looked at the file's extension, so `UploadPolicy::rules()` bounds it by `largestMaxSizeKb()` — the **widest** window any permitted type has — and the per-extension window is applied in `assertPermitted()`. A coarse bound set to the scope default would refuse a 60 MB video before the rule permitting it was ever consulted. Covered by `test_each_extension_has_its_own_size_ceiling` and `test_the_coarse_rule_does_not_pre_empt_a_wider_per_type_window`.

> **Trap.** `UploadPolicy::rules()` deliberately does **not** use `mimes:`/`mimetypes:`. Those take a list written at code time; this policy is a row. Only the shape and the size go in the rules — the type checks happen in `assertPermitted()`, where the current row can be read.

> **Trap.** The whole batch is checked before the first byte is stored, so a rejected upload stores nothing rather than "everything up to the bad one". Covered by `test_one_bad_file_rejects_the_whole_batch`.

### Caching

`UploadSetting::forScope()` caches for an hour and **busts on save**, rather than relying on the TTL. An administrator who tightens a policy expects the next upload to obey it, not the one after the cache expires. Covered by `test_tightening_the_policy_takes_effect_on_the_next_upload`.

> **A missing row degrades to "too strict", never to "anything goes".** `conservativeDefault()` returns the document formats only, with archives off. An unseeded install must still refuse a dangerous upload rather than crash on the way to refusing it — or worse, fall through to no limits.

## Archives

`App\Services\Files\ArchiveService`, on PHP's core `ZipArchive`. No new Composer package; `ext-zip` is required.

### Extraction: the archive never chooses a path

**`ZipArchive::extractTo()` is never called.** Every entry is streamed out by index and written to a path *this application* generates — through `FileStorage` and `FilePathFactory`, from a fresh `public_id`. Nothing from the archive reaches a filesystem path: no name, no directory component, no symlink target.

That is what makes zip-slip **impossible here rather than filtered against**. There is no attacker-controlled path to sanitise. The entry-name checks are a second line, not the defence — and they are there because refusing an evidently hostile archive is more useful to the person than silently flattening it.

Folder structure is recreated as `folders` **rows**, from sanitised names.

### The guards

| Guard | Limit |
| --- | --- |
| Archive's own size | `max_archive_size_kb` |
| Entry count | `max_archive_entries` (0 forbids extraction entirely) |
| Total uncompressed | `max_extracted_kb` — the zip-bomb limit |
| Per-entry compression ratio | 120:1 (`ArchiveService::MAX_COMPRESSION_RATIO`) |
| Folder nesting | `max_archive_depth` |
| Archives inside archives | `allow_nested_archives`, off by default |
| Symlink entries | always refused |
| Encrypted archives | always refused |
| Entry file types | the same `allowed_types` list as a direct upload |

> **The ratio is checked per entry as well as in total.** A thousand entries each just under the total cap is the same attack spread out, and the total alone would let it through.

> **The total is read from the central directory before a byte is written** — but that header is the archive's own claim about itself, which is why the entry loop also holds a running total.

> **Trap.** `isEncryptedIndex()` is absent in some libzip builds, including the one these servers have, so calling it is a fatal error rather than a refused archive. The encryption flag is read from `statIndex()`'s `encryption_method` field instead. Found by a test, not by reading the docs.

### Atomicity

Ordering is the whole of "a failed operation leaves no incomplete records":

1. **Validate the entire archive.** Nothing is written until every entry has passed.
2. Open a transaction; create rows and write bytes, **collecting every path written**.
3. Commit.

On any exception the rows go with the transaction — but **disk writes are not transactional**, so the collector unlinks what was written. Without it, a rollback leaves orphaned bytes nothing points at. Covered by `test_a_traversal_entry_is_refused_and_nothing_is_written`.

### Zipping

Every file in a selection is **re-resolved** for `Download`: a selection posted from a stale page may name something the user could see when it rendered and cannot now. Files that fail are skipped silently and the archive is built from the rest; if nothing survives, the request is refused.

> The audit rows describe **what was released, not what was asked for** — one `zipped` row per included file. My first version recorded a row for every file in the request, including the skipped ones, which would have made the register claim a release that never happened. Caught by `test_zipping_silently_skips_files_the_user_cannot_download`.

`files.zip` returns a file response, so the page reaches it with a real form post carrying the CSRF token from the meta tag — an Inertia XHR visit cannot follow a download. Same reason `documents/show.js` posts its receive form directly.

## The screen

`resources/js/pages/upload-settings/index.js` — **tabs, not one long form.** One form, three tabs: File types · Sizes & counts · Archives, on `?tab=`, the name every tab set in this application uses.

It was previously both policies stacked vertically, each with five number fields, three comma-separated text areas and an archive fieldset: about two thousand pixels of form in which the field somebody came to change was never on screen beside the thing it affects.

The category chips inside the File types tab (Documents, Spreadsheets, … each with a permitted/total count) are in the URL too, as `?category=`, so `?tab=types&category=spreadsheets` opens on the spreadsheet list rather than on whatever is first.

> **They cannot both be `?tab=`, and the reason is concrete rather than stylistic.** Both sets contain a value named `archives` — the Archives *panel* and the Archives *file category* — so one shared parameter would make `?tab=archives` mean two different things, and every click in one row would overwrite the other row's position. `?tab=` stays with the panel because it is the page's primary navigation.

`PUT /upload-settings` carries no route parameter: there is one policy, so the URL has nothing to name. A missing row is created rather than refused, so an unseeded install is fixable from the screen that fixes it.

### File types are a catalogue, not a text box

The form renders `FileTypes::catalogue()` — grouped (Documents, Spreadsheets, Presentations, Images, Video, Audio, Archives), with the group chips carrying a permitted/total count, a tick per entry, two size inputs, and the MIME types that entry accepts shown beneath it **as information rather than as a field**.

> **An administrator can no longer name an arbitrary extension or MIME type, and that is the point.** A token typed into a box is a permission nobody can check: a stray dot, a space or a capital letter made an entry that silently never matched, which reads on screen as a grant that is not there. A tick against a catalogue entry carries the MIME types that make the check correct. `Rule::in()` on the catalogue enforces it server-side — `test_the_screen_refuses_to_permit_a_dangerous_type`.

> **Blank rows are dropped before validation, not rejected by it.** A form that lets somebody add a row has to let them leave it empty. A duplicate extension keeps its **last** entry, because two rows disagreeing about one extension's limit has no answer to which applies. Covered by `test_saving_normalises_the_type_list_and_snapshots_who_changed_it`.

> **Permitting nothing is refused**, and so is a type whose floor is above its own ceiling — the second would display as permitted while refusing every file of that type. `test_permitting_nothing_is_refused`, `test_a_type_whose_minimum_exceeds_its_maximum_is_refused`.

### Both upload forms state the policy, not a literal

`UploadPolicy::limits()` carries the per-extension windows as well as the coarse ones, and `resources/js/components/UploadLimitsHint.js` renders them under the file input on **both** upload surfaces.

> **The document form's picker was `accept=".pdf,.doc,.docx"`,** a literal copied from the rules that existed before this module and left behind when the policy widened — so the browser's own file dialog hid every permitted spreadsheet, and the only error a person could reach named a MIME type. Both pickers now take `accept` from `limits.accept`, and My Files' upload dialog — which had no `accept` at all — takes it too.

`updated_by_name` is snapshotted beside a `nullOnDelete` key, so "who loosened this" stays answerable after that person has left.

## Every upload path, including a replacement

`POST /files/replace` and the document edit form both call `UploadPolicy::assertPermitted()` **before** the transaction opens, like every other upload path: a file the policy refuses must store nothing, rather than be rolled back after its bytes are on the disk. Covered by `test_the_replace_endpoint_goes_through_the_upload_policy`.

A **revert** runs no check, and that is deliberate rather than an oversight: those bytes satisfied the policy once already, and re-checking would let a policy tightened since make a file's own history unrevertable — a settings change deciding something about the past.

## Key files

| File | Role |
| --- | --- |
| `app/Support/FileTypes.php` | **The catalogue.** Extension → label, group, the MIME types it may sniff as; `DANGEROUS`; `matchesMimeType()` |
| `app/Models/UploadSetting.php` | The row, the cache, `typeRules()`, `permitsExtension()`, the per-type size window, the seeded defaults |
| `app/Services/Files/UploadPolicy.php` | **The gate.** Rules, `assertPermitted()`, `limits()` |
| `app/Services/Files/ArchiveService.php` | Zip and extract, with every guard |
| `app/Http/Controllers/UploadSettingController.php` | The screen |
| `database/seeders/UploadSettingSeeder.php` | The policy; idempotent, repairs only an empty one |
| `resources/js/components/UploadLimitsHint.js` | What this field accepts, said in the field — both upload forms |
| `database/migrations/2026_11_11_000003_unify_the_upload_policy.php` | Three lists → one, two rows → one; widening throughout |
| `tests/Feature/{UploadPolicyTest,ArchiveSafetyTest}.php` | |
