<?php

namespace App\Services\Files;

use App\Models\UploadSetting;
use App\Support\FileTypes;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The server-side upload gate, derived from the administrator-editable policy.
 *
 * One place, one policy, used by both upload paths — DocumentController and
 * FileActionController. The limits were hard-coded as `mimes:pdf,doc,docx` and
 * `max:10240` in the document controller, so changing them was a deploy.
 *
 * There used to be a `$scope` argument on every method, selecting between a
 * `documents` policy and a `files` one. It is gone: the two modules are one
 * workflow over one store, so two policies were two answers to one question —
 * and the caller passing the scope meant the gate's answer depended on which
 * screen the upload came from rather than on what the file is.
 *
 * The client is also sent these limits for a fast failure, but that is a
 * courtesy: **this** is the gate. A frontend check is a hint to a cooperative
 * browser, not a control.
 */
class UploadPolicy
{
    public function policy(): UploadSetting
    {
        return UploadSetting::current();
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
     * The size bound here is the **widest** window any permitted type has, not
     * the scope default: Laravel applies this rule before anything has looked
     * at the file's extension, so a narrower bound would reject a 60 MB video
     * before the rule permitting video was ever consulted. The per-extension
     * window is applied in `assertPermitted()`.
     *
     * @return array<string, mixed>
     */
    public function rules(string $field = 'files'): array
    {
        $setting = $this->policy();

        return [
            $field => ['required', 'array', 'min:1', 'max:'.$setting->max_files_per_upload],
            "{$field}.*" => array_filter([
                'file',
                'max:'.$setting->largestMaxSizeKb(),
                $setting->smallestMinSizeKb() > 0 ? 'min:'.$setting->smallestMinSizeKb() : null,
            ]),
        ];
    }

    /**
     * Rules for a single upload, as the document form posts.
     *
     * @return list<string>
     */
    public function singleRules(bool $required = true): array
    {
        $setting = $this->policy();

        return array_values(array_filter([
            $required ? 'required' : 'nullable',
            'file',
            'max:'.$setting->largestMaxSizeKb(),
            $setting->smallestMinSizeKb() > 0 ? 'min:'.$setting->smallestMinSizeKb() : null,
        ]));
    }

    /**
     * Refuse anything the policy does not permit.
     *
     * Four questions, in this order, because each one's message is only
     * truthful once the previous has passed:
     *
     * 1. Is there an extension at all? Without one there is nothing to check a
     *    claim against.
     * 2. Is the extension permitted here? This is the one rule the settings
     *    screen states, and {@see FileTypes::DANGEROUS} vetoes it unconditionally.
     * 3. Does the file's *sniffed* type agree with its extension? Checking only
     *    the extension lets a renamed executable through; checking only the type
     *    lets through a file whose name still ends `.php`, which is what a
     *    misconfigured web server executes. A sniff that concluded nothing is
     *    not a contradiction — see {@see FileTypes::matchesMimeType()}.
     * 4. Is it inside *this extension's* size window?
     */
    public function assertPermitted(UploadedFile $upload, string $field = 'files'): void
    {
        $setting = $this->policy();
        $extension = FileTypes::normalize($upload->getClientOriginalExtension());
        $name = $upload->getClientOriginalName();

        if ($extension === '') {
            throw ValidationException::withMessages([
                $field => "“{$name}” has no file extension, so its type cannot be determined.",
            ]);
        }

        if (! $setting->permitsExtension($extension)) {
            throw ValidationException::withMessages([
                $field => "“{$name}” is a .{$extension} file, which is not on the list of permitted file types.",
            ]);
        }

        // The real type, from the file's contents rather than its name.
        $mimeType = $upload->getMimeType() ?: '';

        if (! $setting->permitsMimeType($extension, $mimeType)) {
            throw ValidationException::withMessages([
                $field => "“{$name}” does not contain .{$extension} data — its contents are {$mimeType}. "
                    .'A file whose name and contents disagree is refused.',
            ]);
        }

        $this->assertWithinSizeWindow($upload, $setting, $extension, $field);
    }

    /**
     * The per-extension size window.
     *
     * Separate from `rules()` because Laravel's `max:` cannot know which
     * extension it is bounding, so the coarse rule is the widest window on
     * offer and the real one is applied here. The message names the limit, in
     * MB where that reads better than five digits of KB.
     */
    private function assertWithinSizeWindow(
        UploadedFile $upload,
        UploadSetting $setting,
        string $extension,
        string $field,
    ): void {
        $name = $upload->getClientOriginalName();
        $sizeKb = (int) ceil(max($upload->getSize() ?: 0, 0) / 1024);
        $max = $setting->maxSizeKbFor($extension);
        $min = $setting->minSizeKbFor($extension);

        if ($max > 0 && $sizeKb > $max) {
            throw ValidationException::withMessages([
                $field => "“{$name}” is ".$this->readableSize($sizeKb).'. A .'.$extension
                    .' file may be at most '.$this->readableSize($max).' here.',
            ]);
        }

        if ($min > 0 && $sizeKb < $min) {
            throw ValidationException::withMessages([
                $field => "“{$name}” is ".$this->readableSize($sizeKb).'. A .'.$extension
                    .' file must be at least '.$this->readableSize($min).' here.',
            ]);
        }
    }

    private function readableSize(int $kilobytes): string
    {
        return $kilobytes >= 1024
            ? rtrim(rtrim(number_format($kilobytes / 1024, 1), '0'), '.').' MB'
            : $kilobytes.' KB';
    }

    /**
     * What the client is told, so it can fail fast and show the limits.
     *
     * Carries the per-extension windows as well as the coarse ones, because the
     * form has to be able to say “a .mp4 may be 200 MB” rather than quoting one
     * number that is wrong for most of the list.
     *
     * @return array<string, mixed>
     */
    public function limits(): array
    {
        $setting = $this->policy();
        $rules = $setting->typeRules();

        return [
            'min_kb' => $setting->smallestMinSizeKb(),
            'max_kb' => $setting->largestMaxSizeKb(),
            'default_min_kb' => $setting->min_size_kb,
            'default_max_kb' => $setting->max_size_kb,
            'extensions' => array_keys($rules),
            'max_files' => $setting->max_files_per_upload,
            // Built for an <input accept="…"> attribute.
            'accept' => collect(array_keys($rules))
                ->map(fn (string $extension) => '.'.$extension)
                ->implode(','),
            'archives_allowed' => $setting->max_archive_entries > 0,
            // extension => {min_kb, max_kb, label}, so the browser can refuse
            // the exact file rather than the whole selection.
            'types' => collect($rules)
                ->map(fn (array $rule, string $extension) => [
                    'extension' => $extension,
                    'label' => FileTypes::label($extension),
                    'group' => FileTypes::group($extension),
                    'min_kb' => $rule['min'],
                    'max_kb' => $rule['max'],
                ])
                ->values()
                ->all(),
        ];
    }
}
