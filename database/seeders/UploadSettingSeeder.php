<?php

namespace Database\Seeders;

use App\Models\UploadSetting;
use Illuminate\Database\Seeder;

/**
 * The upload policy. One row, for every upload in the application.
 *
 * It is **one list** — `allowed_types`, an entry per permitted extension with
 * its own size window. If an extension is not in the list it is refused; there
 * is no second MIME list to keep in step (those are derived from the extension
 * in `App\Support\FileTypes`) and no block-list to override it (executable
 * formats are refused in code, unconditionally).
 *
 * There were two rows, `documents` and `files`, on the reasoning that a tracked
 * document and a file in personal storage are different kinds of thing. They
 * are one store and one workflow now, so two policies were two answers to one
 * question.
 *
 * Permits **every safe type in every category** out of the box — documents,
 * spreadsheets, presentations, images, video, audio and archives — each with
 * the same window, 1 KB to 50 MB. Derived from the catalogue in
 * `App\Support\FileTypes` rather than restated here, so a format added there is
 * permitted on a fresh install without anybody remembering to add it in two
 * places. SVG is the one deliberate omission: it
 * is a document that can carry script, so it has to be ticked on purpose.
 *
 * Idempotent, and it does **not** overwrite a policy an administrator has since
 * edited — with one exception, below, for a row that permits nothing at all.
 */
class UploadSettingSeeder extends Seeder
{
    public function run(): void
    {
        $setting = UploadSetting::query()->firstOrCreate(
            ['scope' => UploadSetting::SCOPE],
            [
                /*
                 * The default window, inherited by any permitted type whose own
                 * maximum is left blank: 1 KB to 50 MB, the same for every
                 * category.
                 *
                 * The floor is 1 KB rather than 0 so a zero-byte file is
                 * refused. That is never a document somebody meant to file — it
                 * is a failed export or an interrupted copy — and it arrives
                 * looking exactly like a successful upload, tracking code and
                 * printed label and all, until somebody opens it weeks later.
                 */
                'min_size_kb' => UploadSetting::DEFAULT_MIN_KB,
                'max_size_kb' => UploadSetting::DEFAULT_MAX_KB,
                'allowed_types' => UploadSetting::defaults(),
                'max_files_per_upload' => 20,
                'max_archive_size_kb' => 102400,
                'max_archive_entries' => 2000,
                'max_extracted_kb' => 204800,
                'max_archive_depth' => 8,
                'allow_nested_archives' => false,
            ],
        );

        /*
         * A row that permits nothing is repaired rather than left alone.
         *
         * `firstOrCreate` protects an administrator's edits, which is right —
         * but "permits nothing" cannot be one of those edits: the settings
         * screen refuses to save an empty list, so the only way a row reaches
         * that state is by never having been configured. Leaving it is a policy
         * that refuses every upload in the application while the screen reports
         * "0 permitted", which is what a half-applied schema change leaves
         * behind.
         *
         * Only the empty case. A row that permits one type is somebody's
         * deliberately narrow policy and is none of this seeder's business.
         */
        if ($setting->allowed_extensions === []) {
            $setting->forceFill([
                'allowed_types' => UploadSetting::defaults(),
                'max_size_kb' => $setting->max_size_kb ?: UploadSetting::DEFAULT_MAX_KB,
            ])->save();
        }
    }
}
