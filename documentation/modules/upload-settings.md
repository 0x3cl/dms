# Upload rules

What can be uploaded, as a row an administrator edits rather than a literal in a controller.

Gated on `upload-settings.read` / `.write`, **SUPERADMIN only**. This is the one screen in the application that can permit an executable, so it is not part of the `Staff` floor.

## Two scopes, not one

| Scope | Why it is separate |
| --- | --- |
| `documents` | A tracked document gets a tracking code and a printed QR label. The office has a reason to keep these to the formats it files. |
| `files` | Personal storage. A filing cabinet restricted to three document types is not a filing cabinet. |

One shared policy would force a choice between stamping tracking codes onto arbitrary images and crippling the file manager.

> **`documents` is seeded with exactly the limits that were hard-coded in `DocumentController` before this module existed** — `pdf,doc,docx` at 10 MB. Installing it changed no behaviour on day one. A settings module that silently loosens the rules the moment it arrives is not a settings module. Asserted by `test_the_documents_policy_reproduces_the_old_hard_coded_rules`.

## The gate is the server

`App\Services\Files\UploadPolicy` is used by **both** upload paths — `DocumentController::store()`/`update()` and `FileActionController::upload()`.

The limits are also sent to the browser (`UploadPolicy::limits()`) so a form can fail fast and show the rules, but **that is a courtesy, not a control**. A frontend check is a hint to a cooperative browser.

### Extension *and* type, and the block-list last

Both are checked, and neither alone is enough:

- Only the extension → a renamed executable gets through.
- Only the sniffed MIME type → a file still named `.php` gets through, which is what a misconfigured web server executes.

`prohibited_extensions` is applied **last**, inside `UploadSetting::permitsExtension()`, so it overrides the allow-list. A block-list alone is never complete, but as a veto over an allow-list it is how one risky format is retired without re-auditing everything already permitted. Covered by `test_a_prohibited_extension_is_refused_even_when_allow_listed`.

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
| Entry file types | the same allow-list and block-list as a direct upload |

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

`resources/js/pages/upload-settings/index.js` — one `useForm` per scope, so saving one cannot carry the other's unsaved edits.

Lists are edited as comma-separated text and split on submit. A tag widget would be nicer and is not worth a new component: these are short, occasionally-edited lists of tokens, and the server normalises, lowercases, strips leading dots, deduplicates and sorts whatever arrives.

> **Blank tokens are dropped before validation, not rejected by it.** A trailing comma produces an empty token, and failing the whole form over that is hostile. Caught by a test whose `assertRedirect()` was initially hiding the error — `assertRedirect()` with no target passes on a validation redirect-back too, so these tests assert `assertSessionHasNoErrors()` as well.

`updated_by_name` is snapshotted beside a `nullOnDelete` key, so "who loosened this" stays answerable after that person has left.

## Key files

| File | Role |
| --- | --- |
| `app/Models/UploadSetting.php` | The row, the cache, `permitsExtension()`/`permitsMimeType()`, `DANGEROUS` |
| `app/Services/Files/UploadPolicy.php` | **The gate.** Rules, `assertPermitted()`, `limits()` |
| `app/Services/Files/ArchiveService.php` | Zip and extract, with every guard |
| `app/Http/Controllers/UploadSettingController.php` | The screen |
| `database/seeders/UploadSettingSeeder.php` | The two policies; idempotent, does not overwrite edits |
| `tests/Feature/{UploadPolicyTest,ArchiveSafetyTest}.php` | |
