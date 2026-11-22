<?php

namespace App\Support;

/**
 * The name a download carries, stamped with the version it holds.
 *
 * Two downloads of one file used to arrive as the same filename. That is the
 * precise problem versioning exists to solve, reproduced in the one place a
 * reader keeps the file: a folder holding `template.docx` and
 * `template(1).docx` says which one the *browser* saved second, and nothing
 * at all about which version of the document either is.
 *
 * Here rather than in `File` and `FileVersion`, which both implement
 * `downloadName()` and would otherwise hold two copies of the rule — the
 * same reason `FilePathFactory` owns where bytes are written.
 */
class VersionedFilename
{
    /** Matches a stamp this class already applied, at the end of a name. */
    private const STAMP = '/\s*\(v\d+\)$/';

    /**
     * `report.docx` at version 3 becomes `report (v3).docx`.
     *
     * **An existing stamp is replaced, not appended to.** Downloading
     * version 1, re-uploading that very file as version 2 and downloading
     * again is an ordinary sequence — more so since an identical
     * replacement stopped being refused — and without this it produces
     * `report (v1) (v2).docx`, then `report (v1) (v2) (v3).docx`.
     *
     * The extension is kept last, because it is what the operating system
     * opens the file with: `report.docx (v3)` is not a Word document as far
     * as a desktop is concerned.
     */
    public static function stamp(string $name, ?int $version): string
    {
        if ($version === null || $name === '') {
            return $name;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = $extension !== ''
            ? substr($name, 0, -(strlen($extension) + 1))
            : $name;

        $base = (string) preg_replace(self::STAMP, '', $base);

        // A name that was nothing but an extension — `.gitignore` — keeps it
        // rather than becoming an empty base with a stamp in front.
        if ($base === '') {
            return $name." (v{$version})";
        }

        return $extension !== ''
            ? "{$base} (v{$version}).{$extension}"
            : "{$base} (v{$version})";
    }
}
