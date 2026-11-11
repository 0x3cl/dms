<?php

namespace App\Services\Files;

use App\Models\UploadSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The server-side upload gate, derived from the administrator-editable policy.
 *
 * One place, used by both upload paths — DocumentController and
 * FileActionController. The limits were hard-coded as `mimes:pdf,doc,docx` and
 * `max:10240` in the document controller, so changing them was a deploy.
 *
 * The client is also sent these limits for a fast failure, but that is a
 * courtesy: **this** is the gate. A frontend check is a hint to a cooperative
 * browser, not a control.
 */
class UploadPolicy
{
    public function for(string $scope): UploadSetting
    {
        return UploadSetting::forScope($scope);
    }

    /**
     * Laravel validation rules for an array of uploads.
     *
     * Deliberately not `mimes:`/`mimetypes:`. Those accept a fixed list written
     * at code time; this policy is a row an administrator edits, so the
     * extension and MIME checks happen in `assertPermitted()` where the current
     * row can be read. Only the shape and the size — which Laravel checks
     * before the file is moved anywhere — are expressed here.
     *
     * @return array<string, mixed>
     */
    public function rules(string $scope, string $field = 'files'): array
    {
        $setting = $this->for($scope);

        return [
            $field => ['required', 'array', 'min:1', 'max:'.$setting->max_files_per_upload],
            "{$field}.*" => array_filter([
                'file',
                'max:'.$setting->max_size_kb,
                $setting->min_size_kb > 0 ? 'min:'.$setting->min_size_kb : null,
            ]),
        ];
    }

    /**
     * Rules for a single upload, as the document form posts.
     *
     * @return list<string>
     */
    public function singleRules(string $scope, bool $required = true): array
    {
        $setting = $this->for($scope);

        return array_values(array_filter([
            $required ? 'required' : 'nullable',
            'file',
            'max:'.$setting->max_size_kb,
            $setting->min_size_kb > 0 ? 'min:'.$setting->min_size_kb : null,
        ]));
    }

    /**
     * Refuse anything the policy does not permit.
     *
     * Both the extension and the sniffed MIME type have to pass. Checking only
     * the extension lets a renamed executable through; checking only the MIME
     * type lets through a file whose name still ends `.php`, which is what a
     * misconfigured web server executes. The block-list is applied last, inside
     * `permitsExtension()`, so it always wins.
     */
    public function assertPermitted(UploadedFile $upload, string $scope, string $field = 'files'): void
    {
        $setting = $this->for($scope);
        $extension = strtolower($upload->getClientOriginalExtension());
        $name = $upload->getClientOriginalName();

        if ($extension === '') {
            throw ValidationException::withMessages([
                $field => "“{$name}” has no file extension, so its type cannot be determined.",
            ]);
        }

        if (! $setting->permitsExtension($extension)) {
            throw ValidationException::withMessages([
                $field => "“{$name}” is a .{$extension} file, which is not permitted here.",
            ]);
        }

        // The real type, from the file's contents rather than its name.
        $mimeType = $upload->getMimeType() ?: 'application/octet-stream';

        if (! $setting->permitsMimeType($mimeType)) {
            throw ValidationException::withMessages([
                $field => "“{$name}” is not the kind of file it claims to be ({$mimeType}).",
            ]);
        }
    }

    /**
     * What the client is told, so it can fail fast and show the limits.
     *
     * @return array<string, mixed>
     */
    public function limits(string $scope): array
    {
        $setting = $this->for($scope);

        return [
            'min_kb' => $setting->min_size_kb,
            'max_kb' => $setting->max_size_kb,
            'extensions' => array_values($setting->allowed_extensions ?? []),
            'max_files' => $setting->max_files_per_upload,
            // Built for an <input accept="…"> attribute.
            'accept' => collect($setting->allowed_extensions ?? [])
                ->map(fn (string $extension) => '.'.strtolower($extension))
                ->implode(','),
            'archives_allowed' => $setting->max_archive_entries > 0,
        ];
    }
}
