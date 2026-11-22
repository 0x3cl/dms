<?php

namespace App\Support;

/**
 * The catalogue of file types this application knows about.
 *
 * This exists because the upload policy used to hold **two** administrator-edited
 * lists — allowed extensions and allowed MIME types — and a file had to satisfy
 * both. That is one list too many, and it failed in exactly the way two copies
 * of one fact always fail: an administrator added `xlsx` to the extensions and
 * had no reason to know that a second list, written in a vocabulary nobody
 * types by hand, also had to gain
 * `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` — so a
 * genuine spreadsheet was refused with “is not the kind of file it claims to
 * be”, naming the very type that should have let it through.
 *
 * So the MIME types are **derived from the extension** and live in code, where
 * they belong: which bytes a `.xlsx` legitimately starts with is a fact about
 * the format, not a decision about office policy. An administrator now chooses
 * extensions, and nothing else.
 *
 * ## Why an extension maps to several types
 *
 * The check compares the extension against what the file *sniffs* as, and a
 * sniff is a guess from the first few bytes:
 *
 * - Every OOXML file (`docx`, `xlsx`, `pptx`, and the OpenDocument family) is a
 *   zip archive. A sniffer that reads the central directory reports the precise
 *   type; one that stops at the magic number reports `application/zip`. Both
 *   are correct about the same bytes.
 * - Every legacy Office file (`doc`, `xls`, `ppt`) is a CFB/OLE2 container, and
 *   is variously reported as `application/msword`, `application/vnd.ms-office`,
 *   `application/x-ole-storage` or `application/CDFV2`.
 * - Plain text has no magic number at all, so a `.csv` is `text/csv`,
 *   `text/plain` or `application/csv` depending on the sniffer's mood.
 *
 * Listing those aliases is what makes the gate correct. It is not a loosening:
 * the file still has to sniff as something its extension could plausibly be, so
 * a renamed executable — which sniffs as `application/x-dosexec` — is still
 * refused.
 */
final class FileTypes
{
    public const GROUP_DOCUMENTS = 'documents';

    public const GROUP_SPREADSHEETS = 'spreadsheets';

    public const GROUP_PRESENTATIONS = 'presentations';

    public const GROUP_IMAGES = 'images';

    public const GROUP_VIDEO = 'video';

    public const GROUP_AUDIO = 'audio';

    public const GROUP_ARCHIVES = 'archives';

    /** Group keys in the order the settings screen should present them. */
    public const GROUPS = [
        self::GROUP_DOCUMENTS => 'Documents',
        self::GROUP_SPREADSHEETS => 'Spreadsheets',
        self::GROUP_PRESENTATIONS => 'Presentations',
        self::GROUP_IMAGES => 'Images',
        self::GROUP_VIDEO => 'Video',
        self::GROUP_AUDIO => 'Audio',
        self::GROUP_ARCHIVES => 'Archives',
    ];

    /**
     * Extension => [label, group, MIME types it may legitimately sniff as].
     *
     * The **first** MIME type of each entry is the canonical one — what the
     * format actually is, and what is sent as `Content-Type` when we serve it.
     * The rest are sniffer aliases, as described above.
     *
     * @var array<string, array{label: string, group: string, mimes: list<string>}>
     */
    private const CATALOGUE = [
        // ---- Documents ----------------------------------------------------
        'pdf' => ['label' => 'PDF', 'group' => self::GROUP_DOCUMENTS, 'mimes' => ['application/pdf', 'application/x-pdf']],
        'doc' => ['label' => 'Word 97-2003', 'group' => self::GROUP_DOCUMENTS, 'mimes' => self::CFB_CONTAINER],
        'docx' => ['label' => 'Word', 'group' => self::GROUP_DOCUMENTS, 'mimes' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ...self::ZIP_CONTAINER,
        ]],
        'rtf' => ['label' => 'Rich text', 'group' => self::GROUP_DOCUMENTS, 'mimes' => ['application/rtf', 'text/rtf', 'text/plain']],
        'odt' => ['label' => 'OpenDocument text', 'group' => self::GROUP_DOCUMENTS, 'mimes' => [
            'application/vnd.oasis.opendocument.text',
            ...self::ZIP_CONTAINER,
        ]],
        'txt' => ['label' => 'Plain text', 'group' => self::GROUP_DOCUMENTS, 'mimes' => ['text/plain']],
        'md' => ['label' => 'Markdown', 'group' => self::GROUP_DOCUMENTS, 'mimes' => ['text/markdown', 'text/plain', 'text/x-markdown']],

        // ---- Spreadsheets -------------------------------------------------
        'xls' => ['label' => 'Excel 97-2003', 'group' => self::GROUP_SPREADSHEETS, 'mimes' => [
            'application/vnd.ms-excel',
            ...self::CFB_CONTAINER,
        ]],
        'xlsx' => ['label' => 'Excel', 'group' => self::GROUP_SPREADSHEETS, 'mimes' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ...self::ZIP_CONTAINER,
        ]],
        'xlsm' => ['label' => 'Excel (macro-enabled)', 'group' => self::GROUP_SPREADSHEETS, 'mimes' => [
            'application/vnd.ms-excel.sheet.macroEnabled.12',
            ...self::ZIP_CONTAINER,
        ]],
        'csv' => ['label' => 'CSV', 'group' => self::GROUP_SPREADSHEETS, 'mimes' => ['text/csv', 'text/plain', 'application/csv', 'text/x-csv']],
        'ods' => ['label' => 'OpenDocument sheet', 'group' => self::GROUP_SPREADSHEETS, 'mimes' => [
            'application/vnd.oasis.opendocument.spreadsheet',
            ...self::ZIP_CONTAINER,
        ]],

        // ---- Presentations ------------------------------------------------
        'ppt' => ['label' => 'PowerPoint 97-2003', 'group' => self::GROUP_PRESENTATIONS, 'mimes' => [
            'application/vnd.ms-powerpoint',
            ...self::CFB_CONTAINER,
        ]],
        'pptx' => ['label' => 'PowerPoint', 'group' => self::GROUP_PRESENTATIONS, 'mimes' => [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            ...self::ZIP_CONTAINER,
        ]],
        'odp' => ['label' => 'OpenDocument slides', 'group' => self::GROUP_PRESENTATIONS, 'mimes' => [
            'application/vnd.oasis.opendocument.presentation',
            ...self::ZIP_CONTAINER,
        ]],

        // ---- Images -------------------------------------------------------
        'jpg' => ['label' => 'JPEG', 'group' => self::GROUP_IMAGES, 'mimes' => ['image/jpeg', 'image/pjpeg']],
        'jpeg' => ['label' => 'JPEG', 'group' => self::GROUP_IMAGES, 'mimes' => ['image/jpeg', 'image/pjpeg']],
        'png' => ['label' => 'PNG', 'group' => self::GROUP_IMAGES, 'mimes' => ['image/png']],
        'gif' => ['label' => 'GIF', 'group' => self::GROUP_IMAGES, 'mimes' => ['image/gif']],
        'webp' => ['label' => 'WebP', 'group' => self::GROUP_IMAGES, 'mimes' => ['image/webp']],
        'bmp' => ['label' => 'Bitmap', 'group' => self::GROUP_IMAGES, 'mimes' => ['image/bmp', 'image/x-ms-bmp']],
        'tif' => ['label' => 'TIFF', 'group' => self::GROUP_IMAGES, 'mimes' => ['image/tiff']],
        'tiff' => ['label' => 'TIFF', 'group' => self::GROUP_IMAGES, 'mimes' => ['image/tiff']],
        'heic' => ['label' => 'HEIC', 'group' => self::GROUP_IMAGES, 'mimes' => ['image/heic', 'image/heif']],
        /*
         * SVG is in the catalogue but is **not** in either default policy, and
         * it is never rendered inline (see FileStorage::INLINE_TYPES and
         * FilePreview). An SVG is a document that can carry script, and
         * serving one inline executes it against this origin.
         */
        'svg' => ['label' => 'SVG (script-capable)', 'group' => self::GROUP_IMAGES, 'mimes' => ['image/svg+xml', 'text/xml', 'text/plain']],

        // ---- Video --------------------------------------------------------
        'mp4' => ['label' => 'MP4', 'group' => self::GROUP_VIDEO, 'mimes' => ['video/mp4', 'application/mp4']],
        'm4v' => ['label' => 'M4V', 'group' => self::GROUP_VIDEO, 'mimes' => ['video/x-m4v', 'video/mp4']],
        'mov' => ['label' => 'QuickTime', 'group' => self::GROUP_VIDEO, 'mimes' => ['video/quicktime', 'video/mp4']],
        'avi' => ['label' => 'AVI', 'group' => self::GROUP_VIDEO, 'mimes' => ['video/x-msvideo', 'video/avi', 'video/msvideo']],
        'mkv' => ['label' => 'Matroska', 'group' => self::GROUP_VIDEO, 'mimes' => ['video/x-matroska', 'application/x-matroska']],
        'webm' => ['label' => 'WebM', 'group' => self::GROUP_VIDEO, 'mimes' => ['video/webm', 'audio/webm']],
        'wmv' => ['label' => 'Windows Media', 'group' => self::GROUP_VIDEO, 'mimes' => ['video/x-ms-wmv', 'video/x-ms-asf', 'application/vnd.ms-asf']],

        // ---- Audio --------------------------------------------------------
        'mp3' => ['label' => 'MP3', 'group' => self::GROUP_AUDIO, 'mimes' => ['audio/mpeg', 'audio/mp3', 'audio/x-mpeg']],
        'wav' => ['label' => 'WAV', 'group' => self::GROUP_AUDIO, 'mimes' => ['audio/wav', 'audio/x-wav', 'audio/wave']],
        'm4a' => ['label' => 'M4A', 'group' => self::GROUP_AUDIO, 'mimes' => ['audio/mp4', 'audio/x-m4a', 'video/mp4']],
        'aac' => ['label' => 'AAC', 'group' => self::GROUP_AUDIO, 'mimes' => ['audio/aac', 'audio/x-aac', 'audio/mp4']],
        'ogg' => ['label' => 'Ogg', 'group' => self::GROUP_AUDIO, 'mimes' => ['audio/ogg', 'application/ogg', 'video/ogg']],

        // ---- Archives -----------------------------------------------------
        'zip' => ['label' => 'ZIP', 'group' => self::GROUP_ARCHIVES, 'mimes' => self::ZIP_CONTAINER],
        '7z' => ['label' => '7-Zip', 'group' => self::GROUP_ARCHIVES, 'mimes' => ['application/x-7z-compressed']],
        'rar' => ['label' => 'RAR', 'group' => self::GROUP_ARCHIVES, 'mimes' => ['application/vnd.rar', 'application/x-rar-compressed', 'application/x-rar']],
        'tar' => ['label' => 'TAR', 'group' => self::GROUP_ARCHIVES, 'mimes' => ['application/x-tar', 'application/tar']],
        'gz' => ['label' => 'Gzip', 'group' => self::GROUP_ARCHIVES, 'mimes' => ['application/gzip', 'application/x-gzip']],
    ];

    /**
     * What a zip-based format may be reported as.
     *
     * `application/x-zip-compressed` is what older Windows tooling says, and
     * `application/zip` is what a magic-number sniffer says about every OOXML
     * and OpenDocument file, because that is literally what they are.
     *
     * @var list<string>
     */
    private const ZIP_CONTAINER = ['application/zip', 'application/x-zip-compressed', 'application/x-zip'];

    /**
     * What a legacy Office (CFB/OLE2) file may be reported as.
     *
     * @var list<string>
     */
    private const CFB_CONTAINER = [
        'application/msword',
        'application/vnd.ms-office',
        'application/x-ole-storage',
        'application/CDFV2',
        'application/CDFV2-unknown',
    ];

    /**
     * A sniff that concluded nothing.
     *
     * `finfo` answers `application/octet-stream` for anything it has no magic
     * number for, and PHP answers an empty string when the extension is
     * missing entirely. That is not evidence of a mismatch, so it is not
     * treated as one — the extension allow-list and the hard refusal in
     * `isDangerous()` are what carry the decision in that case. Refusing on an
     * inconclusive sniff is what produced false rejections of perfectly
     * ordinary office files.
     *
     * @var list<string>
     */
    public const INCONCLUSIVE = ['', 'application/octet-stream', 'application/x-empty', 'inode/x-empty', 'binary'];

    /**
     * Formats that are never permitted, whatever the policy says.
     *
     * This used to be an administrator-editable `prohibited_extensions` column
     * applied as a veto over the allow-list. Two lists pulling in opposite
     * directions is what made the settings screen confusing, and the veto is
     * not a policy question anyway: nothing an office does requires uploading
     * a `.phtml`. So it moved into code as an unconditional refusal — an
     * extension is permitted when it is allow-listed *and* not in here, and
     * there is no screen that can turn that around.
     *
     * `.js` is on the list and that is deliberate: this application serves
     * uploaded bytes, and a script in the storage tree is a script someone can
     * be sent a link to.
     *
     * @var list<string>
     */
    public const DANGEROUS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'phps', 'phtml', 'phar', 'pht',
        'exe', 'dll', 'com', 'bat', 'cmd', 'sh', 'bash', 'zsh', 'csh', 'ksh',
        'ps1', 'psm1', 'psd1', 'vbs', 'vbe', 'js', 'jse', 'mjs', 'cjs', 'jar',
        'msi', 'msp', 'mst', 'scr', 'cpl', 'hta', 'reg', 'wsf', 'wsh', 'lnk',
        'so', 'dylib', 'app', 'deb', 'rpm', 'dmg', 'pkg', 'apk', 'bin', 'run',
        'cgi', 'pl', 'py', 'rb', 'asp', 'aspx', 'jsp', 'jspx', 'cfm', 'htaccess',
    ];

    /** Every extension in the catalogue, in catalogue order. */
    public static function extensions(): array
    {
        return array_keys(self::CATALOGUE);
    }

    public static function known(string $extension): bool
    {
        return array_key_exists(self::normalize($extension), self::CATALOGUE);
    }

    /** Never permitted, whatever a policy row says. */
    public static function isDangerous(string $extension): bool
    {
        return in_array(self::normalize($extension), self::DANGEROUS, true);
    }

    public static function label(string $extension): string
    {
        $extension = self::normalize($extension);

        return self::CATALOGUE[$extension]['label'] ?? strtoupper($extension);
    }

    public static function group(string $extension): ?string
    {
        return self::CATALOGUE[self::normalize($extension)]['group'] ?? null;
    }

    public static function groupLabel(string $group): string
    {
        return self::GROUPS[$group] ?? ucfirst($group);
    }

    /**
     * Every MIME type this extension may legitimately be reported as.
     *
     * An unknown extension yields an empty list, which `matchesMimeType()`
     * reads as “no claim to check against” rather than as a refusal — the
     * extension allow-list has already had its say by then.
     *
     * @return list<string>
     */
    public static function mimeTypes(string $extension): array
    {
        return self::CATALOGUE[self::normalize($extension)]['mimes'] ?? [];
    }

    /** The type this format actually is, for a `Content-Type` header. */
    public static function canonicalMimeType(string $extension): ?string
    {
        return self::mimeTypes($extension)[0] ?? null;
    }

    /**
     * Does what the file sniffed as agree with what its name claims?
     *
     * True when the sniff is inconclusive, when the extension is outside the
     * catalogue, or when the sniffed type is one of the extension's known
     * aliases. Only a *positive contradiction* — the bytes say one format and
     * the name says a different one — is false.
     */
    public static function matchesMimeType(string $extension, string $mimeType): bool
    {
        $mimeType = strtolower(trim($mimeType));

        if (in_array($mimeType, array_map('strtolower', self::INCONCLUSIVE), true)) {
            return true;
        }

        $known = self::mimeTypes($extension);

        if ($known === []) {
            return true;
        }

        return in_array($mimeType, array_map('strtolower', $known), true);
    }

    /** Is this an archive this application can be asked to extract? */
    public static function isArchive(string $extension): bool
    {
        return self::group($extension) === self::GROUP_ARCHIVES;
    }

    /**
     * The catalogue as the settings screen needs it: grouped, labelled, and
     * carrying the MIME types so an administrator can see what the extension
     * they ticked actually accepts.
     *
     * @return list<array{group: string, group_label: string, types: list<array{extension: string, label: string, mimes: list<string>}>}>
     */
    public static function catalogue(): array
    {
        $grouped = [];

        foreach (self::CATALOGUE as $extension => $type) {
            $grouped[$type['group']][] = [
                'extension' => $extension,
                'label' => $type['label'],
                'mimes' => $type['mimes'],
            ];
        }

        $out = [];

        foreach (self::GROUPS as $group => $label) {
            if (! isset($grouped[$group])) {
                continue;
            }

            $out[] = [
                'group' => $group,
                'group_label' => $label,
                'types' => $grouped[$group],
            ];
        }

        return $out;
    }

    public static function normalize(string $extension): string
    {
        return strtolower(ltrim(trim($extension), '.'));
    }
}
