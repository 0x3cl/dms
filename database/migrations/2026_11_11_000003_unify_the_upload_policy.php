<?php

use App\Support\FileTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `upload_settings` becomes **one row with one list**.
 *
 * Two things were wrong with the shape, and they compounded each other.
 *
 * ## Three lists that had to agree
 *
 * A policy carried `allowed_extensions`, `allowed_mime_types` and
 * `prohibited_extensions`, all administrator-edited, and an upload had to
 * satisfy all three. It failed the way two copies of one fact always fail:
 * somebody added `xlsx` to the extensions — the list the screen presents as the
 * answer to “what may be uploaded” — and had no reason to know that a second
 * list, written in a vocabulary nobody types by hand, also needed
 * `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`. A
 * genuine `pds.xlsx` was then refused with “is not the kind of file it claims
 * to be (application/vnd.openxmlformats-officedocument.spreadsheetml.sheet)”,
 * naming the very type that should have let it through.
 *
 * So `allowed_types` is the one list: an entry per permitted extension, each
 * with its own size window. Not in the list means refused. The MIME types an
 * extension may legitimately sniff as are derived from {@see FileTypes}, in
 * code, because which bytes a `.xlsx` starts with is a fact about the format
 * rather than a decision about office policy — and the refusal of executable
 * formats is {@see FileTypes::DANGEROUS}, also in code and unconditional,
 * because nothing an office does requires uploading a `.phtml` and a veto an
 * administrator can empty is not a floor.
 *
 * ## Two rows for one question
 *
 * There was a `documents` policy and a `files` policy, on the reasoning that a
 * tracked document and a file in personal storage are different kinds of thing.
 * They are not: the office asked for My Files and Documents to be one workflow
 * over one store. Two policies over one store is two answers to “may this
 * office upload a spreadsheet?”, and an administrator had to find the right tab
 * before their change meant anything, with nothing on screen saying which
 * upload form read which row.
 *
 * The merge takes the **union** of what the two permitted and the **wider**
 * window wherever they disagreed. Widening is the only safe direction for a
 * merge: these are now one store, so a file that may be uploaded has to be
 * uploadable from either screen, and narrowing would revoke a permission
 * somebody is relying on without saying so.
 *
 * `scope` survives as the single row's key, kept unique, because that is what
 * makes “there is exactly one policy” a constraint the database enforces.
 *
 * ## Why every step is guarded
 *
 * This migration has to land on the same place from either starting state — the
 * original two-row, three-list schema, or a database that already took an
 * earlier form of this change. So each step asks what is there before acting,
 * and running it twice is a no-op rather than an error.
 *
 * `down()` restores the two-row, three-list schema and gives both rows the
 * merged policy. It cannot restore the *difference* between the two scopes, or
 * a hand-edited block-list, because this migration discards both — recorded
 * here rather than discovered later.
 */
return new class extends Migration
{
    private const SCOPE = 'all';

    /**
     * The default type set: an entry per extension with its ceiling in KB.
     *
     * Written out here rather than read from `UploadSetting`, deliberately. A
     * migration is a record of a change that already happened; one that reads
     * today's model re-runs differently after the model moves on, which makes
     * replaying history on a new installation produce a state no deployment was
     * ever in.
     *
     * @var array<int, array{0: int, 1: list<string>}>
     */
    private const DEFAULT_BANDS = [
        [51200, [
            'pdf', 'doc', 'docx', 'rtf', 'odt', 'txt', 'md',
            'xls', 'xlsx', 'xlsm', 'csv', 'ods', 'ppt', 'pptx', 'odp',
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic',
            'mp4', 'm4v', 'mov', 'avi', 'mkv', 'webm', 'wmv',
            'mp3', 'wav', 'm4a', 'aac', 'ogg',
            'zip', '7z', 'rar', 'tar', 'gz',
        ]],
    ];

    /**
     * The floor a *filled-in* default gets here is 0, not the 1 KB a fresh
     * install is seeded with.
     *
     * A migration must not start refusing uploads that were accepted the day
     * before. The 1 KB floor is a sensible new default — a zero-byte file is
     * never a document somebody meant to file — but imposing it on an existing
     * installation is narrowing a policy without being asked, which is the one
     * thing this migration is careful never to do. An administrator can set it
     * on the settings screen.
     */
    private const DEFAULT_MIN_KB = 0;

    public function up(): void
    {
        if (! Schema::hasColumn('upload_settings', 'allowed_types')) {
            Schema::table('upload_settings', function (Blueprint $table) {
                $table->json('allowed_types')->nullable()->after('max_size_kb');
            });
        }

        $rows = DB::table('upload_settings')->orderBy('id')->get();

        if ($rows->isEmpty()) {
            // A fresh install: the seeder writes the row. Nothing to merge.
            $this->dropLegacyColumns();

            return;
        }

        $merged = $this->merge($rows);
        $keep = $rows->first();

        DB::table('upload_settings')->where('id', $keep->id)->update([
            'scope' => self::SCOPE,
            'min_size_kb' => $merged['min_size_kb'],
            'max_size_kb' => $merged['max_size_kb'],
            'allowed_types' => json_encode($merged['allowed_types']),
            'max_files_per_upload' => $merged['max_files_per_upload'],
            'max_archive_size_kb' => $merged['max_archive_size_kb'],
            'max_archive_entries' => $merged['max_archive_entries'],
            'max_extracted_kb' => $merged['max_extracted_kb'],
            'max_archive_depth' => $merged['max_archive_depth'],
            'allow_nested_archives' => $merged['allow_nested_archives'],
        ]);

        DB::table('upload_settings')->where('id', '!=', $keep->id)->delete();

        $this->dropLegacyColumns();
    }

    public function down(): void
    {
        foreach (['allowed_extensions', 'allowed_mime_types', 'prohibited_extensions'] as $column) {
            if (! Schema::hasColumn('upload_settings', $column)) {
                Schema::table('upload_settings', function (Blueprint $table) use ($column) {
                    $table->json($column)->nullable();
                });
            }
        }

        $row = DB::table('upload_settings')->orderBy('id')->first();

        if ($row === null) {
            $this->dropTypesColumn();

            return;
        }

        $types = collect(json_decode((string) $row->allowed_types, true) ?: []);
        $extensions = $types->pluck('extension')->filter()->unique()->values();

        $legacy = [
            'allowed_extensions' => json_encode($extensions->all()),
            'allowed_mime_types' => json_encode($extensions
                ->flatMap(fn (string $extension) => FileTypes::mimeTypes($extension))
                ->unique()->sort()->values()->all()),
            'prohibited_extensions' => json_encode(FileTypes::DANGEROUS),
        ];

        // Both scopes come back carrying the merged policy. The difference
        // between them is not recoverable — the merge discarded it.
        DB::table('upload_settings')->where('id', $row->id)->update([...$legacy, 'scope' => 'documents']);

        DB::table('upload_settings')->insert([
            ...$legacy,
            'scope' => 'files',
            'min_size_kb' => $row->min_size_kb,
            'max_size_kb' => $row->max_size_kb,
            'max_files_per_upload' => $row->max_files_per_upload,
            'max_archive_size_kb' => $row->max_archive_size_kb,
            'max_archive_entries' => $row->max_archive_entries,
            'max_extracted_kb' => $row->max_extracted_kb,
            'max_archive_depth' => $row->max_archive_depth,
            'allow_nested_archives' => $row->allow_nested_archives,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->dropTypesColumn();
    }

    /**
     * Merge every existing row into one policy.
     *
     * Widening throughout: the union of permitted types, the wider window for a
     * type both permitted, the larger file count and archive allowances. A
     * merge that narrowed would revoke a permission in silence.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return array<string, mixed>
     */
    private function merge(\Illuminate\Support\Collection $rows): array
    {
        /** @var array<string, array{extension: string, min_size_kb: int, max_size_kb: int}> $types */
        $types = [];
        $defaultMax = 0;
        $defaultMin = null;

        foreach ($rows as $row) {
            $rowMax = (int) $row->max_size_kb;
            $defaultMax = max($defaultMax, $rowMax);
            // The narrowest floor, so a floor one scope set cannot start
            // refusing small files uploaded through the other.
            $defaultMin = $defaultMin === null ? (int) $row->min_size_kb : min($defaultMin, (int) $row->min_size_kb);

            foreach ($this->typesOf($row, $rowMax) as $type) {
                $extension = $type['extension'];
                $existing = $types[$extension] ?? null;

                $types[$extension] = $existing === null ? $type : [
                    'extension' => $extension,
                    'min_size_kb' => min($existing['min_size_kb'], $type['min_size_kb']),
                    'max_size_kb' => max($existing['max_size_kb'], $type['max_size_kb']),
                ];
            }
        }

        foreach (self::DEFAULT_BANDS as [$ceiling, $extensions]) {
            foreach ($extensions as $extension) {
                // `??=` so an administrator's own window for an extension
                // survives the arrival of a default for it.
                $types[$extension] ??= [
                    'extension' => $extension,
                    'min_size_kb' => self::DEFAULT_MIN_KB,
                    'max_size_kb' => $ceiling,
                ];
            }
        }

        return [
            'min_size_kb' => $defaultMin ?? 0,
            'max_size_kb' => max($defaultMax, 51200),
            'allowed_types' => array_values($types),
            'max_files_per_upload' => max(1, (int) $rows->max('max_files_per_upload')),
            'max_archive_size_kb' => (int) $rows->max('max_archive_size_kb'),
            'max_archive_entries' => (int) $rows->max('max_archive_entries'),
            'max_extracted_kb' => (int) $rows->max('max_extracted_kb'),
            'max_archive_depth' => max(1, (int) $rows->max('max_archive_depth')),
            'allow_nested_archives' => (bool) $rows->max('allow_nested_archives'),
        ];
    }

    /**
     * One row's permitted types, from whichever shape it is in.
     *
     * A row that already holds `allowed_types` keeps its per-extension windows;
     * one still in the three-list shape has its extensions read across, taking
     * the row's own window, and anything the old block-list vetoed is dropped —
     * the block-list won over the allow-list, so a vetoed extension was never
     * actually permitted and must not become permitted by being carried over.
     *
     * @return list<array{extension: string, min_size_kb: int, max_size_kb: int}>
     */
    private function typesOf(object $row, int $rowMax): array
    {
        $existing = json_decode((string) ($row->allowed_types ?? ''), true);

        if (is_array($existing) && $existing !== []) {
            return collect($existing)
                ->map(fn ($type) => [
                    'extension' => FileTypes::normalize((string) ($type['extension'] ?? '')),
                    'min_size_kb' => (int) ($type['min_size_kb'] ?? 0),
                    'max_size_kb' => (int) ($type['max_size_kb'] ?? 0) ?: $rowMax,
                ])
                ->filter(fn (array $type) => $type['extension'] !== '' && ! FileTypes::isDangerous($type['extension']))
                ->values()
                ->all();
        }

        $blocked = collect(json_decode((string) ($row->prohibited_extensions ?? ''), true) ?: [])
            ->map(fn ($extension) => FileTypes::normalize((string) $extension))
            ->all();

        return collect(json_decode((string) ($row->allowed_extensions ?? ''), true) ?: [])
            ->map(fn ($extension) => FileTypes::normalize((string) $extension))
            ->filter()
            ->reject(fn (string $extension) => FileTypes::isDangerous($extension) || in_array($extension, $blocked, true))
            ->unique()
            ->map(fn (string $extension) => [
                'extension' => $extension,
                'min_size_kb' => (int) $row->min_size_kb,
                'max_size_kb' => $rowMax,
            ])
            ->values()
            ->all();
    }

    private function dropLegacyColumns(): void
    {
        $present = array_values(array_filter(
            ['allowed_extensions', 'allowed_mime_types', 'prohibited_extensions'],
            fn (string $column) => Schema::hasColumn('upload_settings', $column),
        ));

        if ($present === []) {
            return;
        }

        Schema::table('upload_settings', function (Blueprint $table) use ($present) {
            $table->dropColumn($present);
        });
    }

    private function dropTypesColumn(): void
    {
        if (! Schema::hasColumn('upload_settings', 'allowed_types')) {
            return;
        }

        Schema::table('upload_settings', function (Blueprint $table) {
            $table->dropColumn('allowed_types');
        });
    }
};
