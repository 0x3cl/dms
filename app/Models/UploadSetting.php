<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * One scope's upload policy.
 *
 * Read on every upload and on every page that renders the limits, so it is
 * cached — and the cache is busted on save rather than given a short TTL, because
 * an administrator who tightens a policy expects the next upload to obey it,
 * not the one after the TTL expires.
 */
class UploadSetting extends Model
{
    use HasFactory;

    public const SCOPE_DOCUMENTS = 'documents';

    public const SCOPE_FILES = 'files';

    /** @var list<string> */
    public const SCOPES = [self::SCOPE_DOCUMENTS, self::SCOPE_FILES];

    private const CACHE_PREFIX = 'upload-settings:';

    protected $fillable = [
        'scope', 'min_size_kb', 'max_size_kb', 'allowed_extensions', 'allowed_mime_types',
        'prohibited_extensions', 'max_files_per_upload', 'max_archive_size_kb',
        'max_archive_entries', 'max_extracted_kb', 'max_archive_depth',
        'allow_nested_archives', 'updated_by_id', 'updated_by_name',
    ];

    protected $casts = [
        'allowed_extensions' => 'array',
        'allowed_mime_types' => 'array',
        'prohibited_extensions' => 'array',
        'min_size_kb' => 'integer',
        'max_size_kb' => 'integer',
        'max_files_per_upload' => 'integer',
        'max_archive_size_kb' => 'integer',
        'max_archive_entries' => 'integer',
        'max_extracted_kb' => 'integer',
        'max_archive_depth' => 'integer',
        'allow_nested_archives' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(fn (self $setting) => Cache::forget(self::CACHE_PREFIX.$setting->scope));
        static::deleted(fn (self $setting) => Cache::forget(self::CACHE_PREFIX.$setting->scope));
    }

    /**
     * The policy for a scope.
     *
     * Falls back to a conservative in-memory default rather than throwing if
     * the row is missing: an unseeded install must still refuse a dangerous
     * upload, not crash on the way to refusing it — and crucially must not
     * fall through to "no limits".
     */
    public static function forScope(string $scope): self
    {
        $scope = in_array($scope, self::SCOPES, true) ? $scope : self::SCOPE_FILES;

        return Cache::remember(
            self::CACHE_PREFIX.$scope,
            now()->addHour(),
            fn () => self::query()->where('scope', $scope)->first() ?? self::conservativeDefault($scope),
        );
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    public function maxSizeBytes(): int
    {
        return $this->max_size_kb * 1024;
    }

    public function minSizeBytes(): int
    {
        return $this->min_size_kb * 1024;
    }

    public function maxExtractedBytes(): int
    {
        return $this->max_extracted_kb * 1024;
    }

    public function maxArchiveBytes(): int
    {
        return $this->max_archive_size_kb * 1024;
    }

    /**
     * Is this extension permitted? The block-list is checked last so it always
     * wins over the allow-list.
     */
    public function permitsExtension(string $extension): bool
    {
        $extension = strtolower(ltrim($extension, '.'));

        if (in_array($extension, array_map('strtolower', $this->prohibited_extensions ?? []), true)) {
            return false;
        }

        return in_array($extension, array_map('strtolower', $this->allowed_extensions ?? []), true);
    }

    public function permitsMimeType(string $mimeType): bool
    {
        return in_array(strtolower($mimeType), array_map('strtolower', $this->allowed_mime_types ?? []), true);
    }

    public function isArchiveExtension(string $extension): bool
    {
        return in_array(strtolower(ltrim($extension, '.')), ['zip', '7z', 'rar', 'gz', 'tar', 'bz2', 'xz'], true);
    }

    /**
     * What the policy looks like when no row exists.
     *
     * The narrowest thing that still lets the application work — the document
     * formats only — so a missing row degrades to "too strict" rather than to
     * "anything goes".
     */
    private static function conservativeDefault(string $scope): self
    {
        return new self([
            'scope' => $scope,
            'min_size_kb' => 0,
            'max_size_kb' => 10240,
            'allowed_extensions' => ['pdf', 'doc', 'docx'],
            'allowed_mime_types' => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
            'prohibited_extensions' => self::DANGEROUS,
            'max_files_per_upload' => 5,
            'max_archive_size_kb' => 0,
            'max_archive_entries' => 0,
            'max_extracted_kb' => 0,
            'max_archive_depth' => 1,
            'allow_nested_archives' => false,
        ]);
    }

    /**
     * Formats that are never a document and are a plausible way to get code
     * onto a machine — a server's, or a colleague's who downloads one.
     *
     * Seeded into `prohibited_extensions` for both scopes. An administrator can
     * edit the list; it is a default, not a hard rule, because what is
     * dangerous is a property of the office's machines rather than of this
     * application.
     *
     * @var list<string>
     */
    public const DANGEROUS = [
        'php', 'phtml', 'phar', 'exe', 'dll', 'com', 'bat', 'cmd', 'sh', 'bash',
        'ps1', 'psm1', 'vbs', 'vbe', 'js', 'jse', 'jar', 'msi', 'scr', 'cpl',
        'hta', 'reg', 'wsf', 'lnk', 'so', 'dylib', 'app', 'deb', 'rpm',
    ];
}
