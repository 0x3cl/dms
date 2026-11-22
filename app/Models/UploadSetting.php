<?php

namespace App\Models;

use App\Support\FileTypes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * The upload policy — **one row, for every upload in the application**.
 *
 * Read on every upload and on every page that renders the limits, so it is
 * cached — and the cache is busted on save rather than given a short TTL, because
 * an administrator who tightens a policy expects the next upload to obey it,
 * not the one after the TTL expires.
 *
 * ## One policy, not one per module
 *
 * There were two rows, `documents` and `files`, on the reasoning that a tracked
 * document and a file in someone's personal storage are not the same kind of
 * thing. They are: the office asked for My Files and Documents to be one
 * workflow over one backing store, and two policies over one store is two
 * answers to a single question — "may this office upload a spreadsheet?" —
 * which is the condition that produced the bug below rather than a refinement
 * of it. An administrator also had to find the right tab before their change
 * meant anything, with nothing on screen saying which upload form read which
 * row.
 *
 * So `scope` survives only as the single row's key. Nothing branches on it.
 *
 * ## One list, not three
 *
 * A policy used to carry `allowed_extensions`, `allowed_mime_types` and
 * `prohibited_extensions`, and a file had to satisfy all three. That is the
 * shape that rejected a genuine `pds.xlsx` with “is not the kind of file it
 * claims to be”: the extension had been allowed and the second list, in a
 * vocabulary nobody types by hand, had not gained the matching OOXML type.
 *
 * Now there is **one** list — `allowed_types`, an entry per permitted
 * extension, each with its own size window — and everything else is derived:
 *
 * - the MIME types an extension may sniff as come from {@see FileTypes}, because
 *   that is a fact about the format rather than a decision about office policy;
 * - the refusal of executable formats is {@see FileTypes::DANGEROUS}, in code and
 *   unconditional, rather than a list an administrator could empty.
 *
 * If an extension is not in `allowed_types`, it is refused. That is the whole
 * rule, and it is the one the settings screen now states.
 */
class UploadSetting extends Model
{
    use HasFactory;

    /**
     * The one row's `scope` value.
     *
     * The column is kept, and kept unique, because it is what makes "there is
     * exactly one policy" a constraint the database enforces rather than a
     * convention the code remembers.
     */
    public const SCOPE = 'all';

    private const CACHE_KEY = 'upload-settings:all';

    protected $fillable = [
        'scope', 'min_size_kb', 'max_size_kb', 'allowed_types',
        'max_files_per_upload', 'max_archive_size_kb',
        'max_archive_entries', 'max_extracted_kb', 'max_archive_depth',
        'allow_nested_archives', 'updated_by_id', 'updated_by_name',
    ];

    protected $casts = [
        'allowed_types' => 'array',
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
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /**
     * The policy.
     *
     * Falls back to a conservative in-memory default rather than throwing if
     * the row is missing: an unseeded install must still refuse a dangerous
     * upload, not crash on the way to refusing it — and crucially must not
     * fall through to "no limits".
     */
    public static function current(): self
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addHour(),
            fn () => self::query()->orderBy('id')->first() ?? self::conservativeDefault(),
        );
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    // ---- The type list -----------------------------------------------------

    /**
     * The permitted types, normalised: extension => [min_size_kb, max_size_kb].
     *
     * A type whose own window is missing or zero inherits the policy's default.
     * That is what lets an administrator set “everything 25 MB, except video at
     * 200” without restating a number per row.
     *
     * @return array<string, array{min: int, max: int}>
     */
    public function typeRules(): array
    {
        $rules = [];

        foreach ($this->allowed_types ?? [] as $entry) {
            $extension = FileTypes::normalize((string) ($entry['extension'] ?? ''));

            if ($extension === '' || FileTypes::isDangerous($extension)) {
                continue;
            }

            $max = (int) ($entry['max_size_kb'] ?? 0);
            $min = (int) ($entry['min_size_kb'] ?? 0);

            $rules[$extension] = [
                'min' => max(0, $min),
                'max' => $max > 0 ? $max : $this->max_size_kb,
            ];
        }

        return $rules;
    }

    /**
     * Derived from `allowed_types`, never stored.
     *
     * Kept as an attribute because callers across the application ask a policy
     * what it allows, and a second stored column is exactly the duplication
     * this consolidation removed.
     *
     * @return list<string>
     */
    public function getAllowedExtensionsAttribute(): array
    {
        return array_keys($this->typeRules());
    }

    /**
     * Is this extension permitted?
     *
     * The dangerous list is checked first and cannot be configured away, so a
     * policy that somehow names `php` still refuses it.
     */
    public function permitsExtension(string $extension): bool
    {
        $extension = FileTypes::normalize($extension);

        if ($extension === '' || FileTypes::isDangerous($extension)) {
            return false;
        }

        return array_key_exists($extension, $this->typeRules());
    }

    /**
     * Does the file's sniffed type agree with its extension?
     *
     * Delegated to the catalogue: an extension's legitimate types are a
     * property of the format. Only a positive contradiction is a refusal.
     */
    public function permitsMimeType(string $extension, string $mimeType): bool
    {
        return FileTypes::matchesMimeType($extension, $mimeType);
    }

    public function isArchiveExtension(string $extension): bool
    {
        return FileTypes::isArchive($extension);
    }

    // ---- Sizes -------------------------------------------------------------

    public function maxSizeKbFor(string $extension): int
    {
        return $this->typeRules()[FileTypes::normalize($extension)]['max'] ?? $this->max_size_kb;
    }

    public function minSizeKbFor(string $extension): int
    {
        $rule = $this->typeRules()[FileTypes::normalize($extension)] ?? null;

        return $rule === null ? $this->min_size_kb : max($rule['min'], 0);
    }

    /**
     * The widest ceiling any permitted type has.
     *
     * Laravel's `max:` rule runs before this policy sees the file and cannot
     * know its extension, so the coarse rule has to be the *most* permissive
     * window on offer; `UploadPolicy::assertPermitted()` then applies the
     * per-extension one. A coarse rule set to the scope default would reject a
     * 60 MB video before the rule that permits it was ever consulted.
     */
    public function largestMaxSizeKb(): int
    {
        $maxima = array_column($this->typeRules(), 'max');

        return $maxima === [] ? $this->max_size_kb : max([$this->max_size_kb, ...$maxima]);
    }

    /** The narrowest floor, for the same reason in the other direction. */
    public function smallestMinSizeKb(): int
    {
        $minima = array_column($this->typeRules(), 'min');

        return $minima === [] ? $this->min_size_kb : min([$this->min_size_kb, ...$minima]);
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

    // ---- Defaults ----------------------------------------------------------

    /**
     * The window every permitted type is seeded with: 1 KB to 50 MB.
     *
     * One window for every category rather than a band per category. The
     * per-type window stays a real capability — it is what lets an office say
     * "video to 500 MB, scans to 20" — but a *default* that already says
     * different things per category is a set of decisions nobody made, and the
     * office asked for one number.
     *
     * The floor is 1 KB rather than 0, so a zero-byte file is refused. That is
     * never a document somebody meant to file: it is a failed export, an
     * interrupted copy, or a placeholder — and it arrives looking exactly like
     * a successful upload, with a tracking code and a QR label, until somebody
     * opens it weeks later.
     */
    public const DEFAULT_MIN_KB = 1;

    public const DEFAULT_MAX_KB = 51200;

    /**
     * Permitted out of the box: **every safe type in every category**.
     *
     * Derived from the catalogue rather than written out as a list, so that a
     * format added to `FileTypes` is permitted on a fresh install without
     * anybody remembering to add it here as well. A hand-kept copy of the
     * catalogue is the same duplication that left `xlsx` permitted by one list
     * and refused by another.
     *
     * Two exclusions, both of them properties of the format rather than office
     * preference:
     *
     * - **`FileTypes::DANGEROUS`** — refused in code regardless, so offering it
     *   here would be seeding a grant the server overrules.
     * - **SVG** — an SVG is a document that can carry script, and this
     *   application serves uploaded bytes. It stays in the catalogue so an
     *   office that genuinely needs it can tick it deliberately; what it does
     *   not get is to arrive switched on.
     *
     * @return list<array{extension: string, min_size_kb: int, max_size_kb: int}>
     */
    public static function defaults(): array
    {
        $types = [];

        foreach (FileTypes::catalogue() as $group) {
            foreach ($group['types'] as $type) {
                if (in_array($type['extension'], self::NOT_PERMITTED_BY_DEFAULT, true)) {
                    continue;
                }

                $types[] = [
                    'extension' => $type['extension'],
                    'min_size_kb' => self::DEFAULT_MIN_KB,
                    'max_size_kb' => self::DEFAULT_MAX_KB,
                ];
            }
        }

        return $types;
    }

    /**
     * In the catalogue, but not ticked on a fresh install.
     *
     * @var list<string>
     */
    public const NOT_PERMITTED_BY_DEFAULT = ['svg'];

    /**
     * @param  list<array{0: int, 1: list<string>}>  $bands
     * @return list<array{extension: string, min_size_kb: int, max_size_kb: int}>
     */
    private static function buildTypes(array $bands): array
    {
        $types = [];

        foreach ($bands as [$ceiling, $extensions]) {
            foreach ($extensions as $extension) {
                $types[] = [
                    'extension' => $extension,
                    'min_size_kb' => 0,
                    'max_size_kb' => (int) $ceiling,
                ];
            }
        }

        return $types;
    }

    /**
     * What the policy looks like when no row exists.
     *
     * The narrowest thing that still lets the application work — the document
     * formats only — so a missing row degrades to "too strict" rather than to
     * "anything goes".
     */
    private static function conservativeDefault(): self
    {
        return new self([
            'scope' => self::SCOPE,
            'min_size_kb' => 0,
            'max_size_kb' => 10240,
            'allowed_types' => self::buildTypes([[10240, ['pdf', 'doc', 'docx']]]),
            'max_files_per_upload' => 5,
            'max_archive_size_kb' => 0,
            'max_archive_entries' => 0,
            'max_extracted_kb' => 0,
            'max_archive_depth' => 1,
            'allow_nested_archives' => false,
        ]);
    }

    /**
     * Formats that are never permitted.
     *
     * Retained as an alias of {@see FileTypes::DANGEROUS} because that list is
     * no longer a column an administrator edits — it is a refusal in code. The
     * constant stays here so existing callers keep reading one name.
     *
     * @var list<string>
     */
    public const DANGEROUS = FileTypes::DANGEROUS;
}
