<?php

namespace App\Enums;

use Illuminate\Database\Eloquent\Builder;

/**
 * The coarse kinds of file a person filters a folder by.
 *
 * Not a column, and deliberately not one: the authoritative facts about a file
 * are its sniffed `mime_type` and its `extension`, and a stored category would
 * be a third copy of the same information that goes stale the moment either of
 * those is corrected. This maps the two facts onto the handful of words someone
 * actually searches with — "show me the spreadsheets" — in one place, so the
 * filter dropdown, the SQL behind it and the preview's choice of viewer cannot
 * disagree about what a spreadsheet is.
 *
 * `Other` is the complement of every other case rather than a list of its own,
 * which is what keeps "everything that is not one of these" from quietly
 * excluding a format nobody thought of.
 */
enum FileCategory: string
{
    case Document = 'document';
    case Spreadsheet = 'spreadsheet';
    case Presentation = 'presentation';
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Archive = 'archive';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Document => 'Documents',
            self::Spreadsheet => 'Spreadsheets',
            self::Presentation => 'Presentations',
            self::Image => 'Images',
            self::Video => 'Video',
            self::Audio => 'Audio',
            self::Archive => 'Archives',
            self::Other => 'Other',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Document => 'fa-solid fa-file-lines',
            self::Spreadsheet => 'fa-solid fa-file-excel',
            self::Presentation => 'fa-solid fa-file-powerpoint',
            self::Image => 'fa-solid fa-file-image',
            self::Video => 'fa-solid fa-file-video',
            self::Audio => 'fa-solid fa-file-audio',
            self::Archive => 'fa-solid fa-file-zipper',
            self::Other => 'fa-solid fa-file',
        };
    }

    /**
     * The extensions that belong to this category.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        return match ($this) {
            self::Document => ['pdf', 'doc', 'docx', 'docm', 'dot', 'dotx', 'odt', 'rtf', 'txt', 'md'],
            self::Spreadsheet => ['xls', 'xlsx', 'xlsm', 'xlsb', 'ods', 'csv', 'tsv'],
            self::Presentation => ['ppt', 'pptx', 'ppsx', 'pps', 'potx', 'odp'],
            self::Image => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'svg', 'heic'],
            self::Video => ['mp4', 'webm', 'ogv', 'mov', 'avi', 'mkv', 'm4v'],
            self::Audio => ['mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac'],
            self::Archive => ['zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2'],
            self::Other => [],
        };
    }

    /**
     * Narrow a `files` query to this category.
     *
     * Extension-first, because that is what a person sees in a filename and
     * what an Office format can be told apart by at all — every modern Office
     * document is a zip archive, so its sniffed type would file it under
     * Archives. `Other` is expressed as "none of the known extensions", so a
     * format nobody enumerated still turns up somewhere.
     *
     * @param  Builder<\App\Models\File>  $query
     * @return Builder<\App\Models\File>
     */
    public function scope(Builder $query): Builder
    {
        if ($this === self::Other) {
            return $query->whereNotIn('extension', self::knownExtensions());
        }

        return $query->whereIn('extension', $this->extensions());
    }

    /**
     * Every extension any named category claims.
     *
     * @return list<string>
     */
    public static function knownExtensions(): array
    {
        $extensions = [];

        foreach (self::cases() as $category) {
            $extensions = [...$extensions, ...$category->extensions()];
        }

        return array_values(array_unique($extensions));
    }

    /** @return list<array{value: string, label: string, icon: string}> */
    public static function options(): array
    {
        return array_map(fn (self $category): array => [
            'value' => $category->value,
            'label' => $category->label(),
            'icon' => $category->icon(),
        ], self::cases());
    }
}
