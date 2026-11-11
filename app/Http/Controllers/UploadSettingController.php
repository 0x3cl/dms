<?php

namespace App\Http\Controllers;

use App\Models\UploadSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The upload policy, per scope.
 *
 * Two rows, edited on one screen. There is no create or delete: the scopes are
 * fixed by the code that reads them (`UploadSetting::SCOPES`), so offering to
 * add a third would be offering something nothing consumes.
 */
class UploadSettingController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('upload-settings/index', [
            'settings' => collect(UploadSetting::SCOPES)
                ->map(fn (string $scope) => $this->payload(UploadSetting::forScope($scope)))
                ->values(),
            'dangerousDefaults' => UploadSetting::DANGEROUS,
        ]);
    }

    public function update(Request $request, UploadSetting $setting): RedirectResponse
    {
        /*
         * Blank entries are dropped before validation, not rejected by it.
         *
         * The lists are edited as comma-separated text, so a trailing comma —
         * or a double one — produces an empty token. Failing the whole form
         * over that would be hostile, and the value is meaningless either way.
         */
        foreach (['allowed_extensions', 'allowed_mime_types', 'prohibited_extensions'] as $field) {
            if (is_array($request->input($field))) {
                $request->merge([
                    $field => array_values(array_filter(
                        $request->input($field),
                        fn ($value) => is_string($value) && trim($value) !== '',
                    )),
                ]);
            }
        }

        $validated = $request->validate([
            'min_size_kb' => ['required', 'integer', 'min:0', 'max:5242880'],
            'max_size_kb' => ['required', 'integer', 'min:1', 'max:5242880', 'gte:min_size_kb'],

            // Lists arrive as arrays of bare tokens. Normalised below rather
            // than trusted: a stray dot, space or uppercase letter would make
            // an entry that never matches, which reads as a permission granted
            // when it is not.
            'allowed_extensions' => ['present', 'array'],
            'allowed_extensions.*' => ['string', 'max:16', 'regex:/^\.?[A-Za-z0-9]+$/'],
            'allowed_mime_types' => ['present', 'array'],
            'allowed_mime_types.*' => ['string', 'max:191', 'regex:#^[-\w.+]+/[-\w.+*]+$#'],
            'prohibited_extensions' => ['present', 'array'],
            'prohibited_extensions.*' => ['string', 'max:16', 'regex:/^\.?[A-Za-z0-9]+$/'],

            'max_files_per_upload' => ['required', 'integer', 'min:1', 'max:200'],
            'max_archive_size_kb' => ['required', 'integer', 'min:0', 'max:5242880'],
            'max_archive_entries' => ['required', 'integer', 'min:0', 'max:100000'],
            'max_extracted_kb' => ['required', 'integer', 'min:0', 'max:52428800'],
            'max_archive_depth' => ['required', 'integer', 'min:1', 'max:32'],
            'allow_nested_archives' => ['boolean'],
        ]);

        $setting->update([
            ...$validated,
            'allowed_extensions' => $this->normalizeExtensions($validated['allowed_extensions']),
            'prohibited_extensions' => $this->normalizeExtensions($validated['prohibited_extensions']),
            'allowed_mime_types' => $this->normalizeList($validated['allowed_mime_types']),
            'allow_nested_archives' => $request->boolean('allow_nested_archives'),
            'updated_by_id' => $request->user()->id,
            // Snapshotted, so "who loosened this" stays answerable after that
            // person has left.
            'updated_by_name' => $request->user()->name,
        ]);

        return back()->with('success', 'Upload rules updated.');
    }

    /**
     * @param  list<string>  $extensions
     * @return list<string>
     */
    private function normalizeExtensions(array $extensions): array
    {
        return $this->normalizeList(array_map(
            fn (string $extension) => ltrim(trim($extension), '.'),
            $extensions,
        ));
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function normalizeList(array $values): array
    {
        return collect($values)
            ->map(fn (string $value) => strtolower(trim($value)))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(UploadSetting $setting): array
    {
        return [
            'id' => $setting->id,
            'scope' => $setting->scope,
            'label' => $setting->scope === UploadSetting::SCOPE_DOCUMENTS
                ? 'Tracked documents'
                : 'File manager',
            'description' => $setting->scope === UploadSetting::SCOPE_DOCUMENTS
                ? 'Uploads that get a tracking code and a printed QR label. Kept narrow on purpose — these are the formats the office files.'
                : 'Personal storage. Broader, because a filing cabinet restricted to three document types is not a filing cabinet.',
            'min_size_kb' => $setting->min_size_kb,
            'max_size_kb' => $setting->max_size_kb,
            'allowed_extensions' => $setting->allowed_extensions ?? [],
            'allowed_mime_types' => $setting->allowed_mime_types ?? [],
            'prohibited_extensions' => $setting->prohibited_extensions ?? [],
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
