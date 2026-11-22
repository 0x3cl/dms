<?php

namespace App\Http\Controllers;

use App\Models\UploadSetting;
use App\Support\FileTypes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The upload policy. One row, one screen.
 *
 * There is no create or delete: there is exactly one policy, keyed by a unique
 * `scope` column so the database is what enforces that rather than a convention
 * the code remembers.
 *
 * It used to be two rows — `documents` and `files` — edited as two stacked
 * forms. The modules they governed are one workflow over one store, so the
 * split was two answers to one question, and an administrator had to know which
 * upload form read which row before their change meant anything.
 *
 * ## One list, and it is a list of types rather than of tokens
 *
 * The screen used to ask for three comma-separated lists — extensions, MIME
 * types, and extensions that are never allowed — and an upload had to satisfy
 * all three. That is what rejected a genuine `.xlsx` whose extension had been
 * permitted but whose OOXML MIME type nobody had thought to add to the second
 * box. So the form now offers the catalogue in {@see FileTypes}: an
 * administrator ticks the extensions the office accepts and sets a size window
 * per extension, and everything else is derived.
 *
 * What the screen can no longer do is name an arbitrary extension or MIME type.
 * That is the point. A token typed into a box is a permission nobody can check
 * — a stray dot or a capital letter made an entry that silently never matched,
 * which reads as a grant that is not there — while a tick against a catalogue
 * entry carries the MIME types that make the check correct.
 */
class UploadSettingController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('upload-settings/index', [
            'setting' => $this->payload(UploadSetting::current()),
            // The whole vocabulary the form may choose from, grouped for the
            // tabs.
            'catalogue' => FileTypes::catalogue(),
            'neverAllowed' => FileTypes::DANGEROUS,
        ]);
    }

    /**
     * Save the policy.
     *
     * No route parameter: there is one policy, so naming which one to save
     * would be an identifier the caller could get wrong about a choice it does
     * not have. A missing row is created rather than refused — an unseeded
     * install should be fixable from the screen that fixes it.
     */
    public function update(Request $request): RedirectResponse
    {
        $request->merge(['allowed_types' => $this->normalizeTypes($request->input('allowed_types'))]);

        $validated = $request->validate([
            'min_size_kb' => ['required', 'integer', 'min:0', 'max:5242880'],
            'max_size_kb' => ['required', 'integer', 'min:1', 'max:5242880', 'gte:min_size_kb'],

            /*
             * At least one type, because a policy permitting nothing is not a
             * policy — it is a module switched off by a screen that does not
             * say so. An administrator who wants uploads closed has the
             * permission to take the route away.
             */
            'allowed_types' => ['present', 'array', 'min:1'],
            'allowed_types.*.extension' => [
                'required', 'string',
                // Only catalogue entries. Anything else would be an extension
                // with no known MIME types, which the gate could not check.
                Rule::in(array_values(array_diff(FileTypes::extensions(), FileTypes::DANGEROUS))),
            ],
            'allowed_types.*.min_size_kb' => ['present', 'integer', 'min:0', 'max:5242880'],
            'allowed_types.*.max_size_kb' => ['present', 'integer', 'min:0', 'max:5242880'],

            'max_files_per_upload' => ['required', 'integer', 'min:1', 'max:200'],
            'max_archive_size_kb' => ['required', 'integer', 'min:0', 'max:5242880'],
            'max_archive_entries' => ['required', 'integer', 'min:0', 'max:100000'],
            'max_extracted_kb' => ['required', 'integer', 'min:0', 'max:52428800'],
            'max_archive_depth' => ['required', 'integer', 'min:1', 'max:32'],
            'allow_nested_archives' => ['boolean'],
        ], [
            'allowed_types.min' => 'Permit at least one file type, or nothing can be uploaded here at all.',
            'allowed_types.*.extension.in' => 'That file type is not one this application can verify, so it cannot be permitted.',
        ]);

        /*
         * A type whose floor is above its own ceiling would refuse every file
         * of that type while the screen displayed it as permitted. Checked here
         * rather than as a rule, because `gte:` cannot address a sibling inside
         * an array entry by index.
         */
        foreach ($validated['allowed_types'] as $index => $type) {
            $ceiling = $type['max_size_kb'] > 0 ? $type['max_size_kb'] : $validated['max_size_kb'];

            if ($type['min_size_kb'] > 0 && $type['min_size_kb'] > $ceiling) {
                return back()
                    ->withInput()
                    ->withErrors([
                        "allowed_types.{$index}.min_size_kb" => 'The smallest size for .'
                            .$type['extension'].' is above its largest, so no file of that type could ever be accepted.',
                    ]);
            }
        }

        $setting = UploadSetting::query()->firstOrNew(['scope' => UploadSetting::SCOPE]);

        $setting->fill([
            ...$validated,
            'allow_nested_archives' => $request->boolean('allow_nested_archives'),
            'updated_by_id' => $request->user()->id,
            // Snapshotted, so "who loosened this" stays answerable after that
            // person has left.
            'updated_by_name' => $request->user()->name,
        ])->save();

        return back()->with('success', 'Upload rules updated.');
    }

    /**
     * Normalise the posted type list before validation.
     *
     * Blank rows are dropped rather than rejected — a form that lets somebody
     * add a row has to let them leave it empty — and a duplicate extension
     * keeps its last entry, because the alternative is two rows disagreeing
     * about one extension's limit with nothing to say which wins.
     *
     * @return list<array{extension: string, min_size_kb: int, max_size_kb: int}>
     */
    private function normalizeTypes(mixed $posted): array
    {
        if (! is_array($posted)) {
            return [];
        }

        $types = [];

        foreach ($posted as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $extension = FileTypes::normalize((string) ($entry['extension'] ?? ''));

            if ($extension === '') {
                continue;
            }

            $types[$extension] = [
                'extension' => $extension,
                'min_size_kb' => (int) ($entry['min_size_kb'] ?? 0),
                'max_size_kb' => (int) ($entry['max_size_kb'] ?? 0),
            ];
        }

        return array_values($types);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(UploadSetting $setting): array
    {
        $rules = $setting->typeRules();

        return [
            'id' => $setting->id,
            'min_size_kb' => $setting->min_size_kb,
            'max_size_kb' => $setting->max_size_kb,
            'allowed_types' => collect($rules)
                ->map(fn (array $rule, string $extension) => [
                    'extension' => $extension,
                    'label' => FileTypes::label($extension),
                    'group' => FileTypes::group($extension),
                    // Sent back raw-ish: a zero means “inherit the default
                    // window”, and the form has to be able to show that as
                    // blank rather than as a real limit of 0 KB.
                    'min_size_kb' => $rule['min'],
                    'max_size_kb' => $rule['max'] === $setting->max_size_kb ? 0 : $rule['max'],
                ])
                ->values()
                ->all(),
            'max_files_per_upload' => $setting->max_files_per_upload,
            'max_archive_size_kb' => $setting->max_archive_size_kb,
            'max_archive_entries' => $setting->max_archive_entries,
            'max_extracted_kb' => $setting->max_extracted_kb,
            'max_archive_depth' => $setting->max_archive_depth,
            'allow_nested_archives' => $setting->allow_nested_archives,
            'updated_by' => $setting->updated_by_name,
            'updated_at' => $setting->updated_at,
        ];
    }
}
