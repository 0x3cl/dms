<?php

namespace App\Enums;

/**
 * Whether these bytes carry their tracking code, and how it got there.
 *
 * A code on a screen travels with the record; a code *in the file* travels
 * with every printed copy, which is the thing an office actually hands
 * around. But only two formats can be given one, so this records which
 * happened rather than letting a page guess from the extension — a reader
 * looking at a document needs to know whether printing it will carry the code
 * or lose it, and that is not a question they can answer by looking.
 *
 * `Unsupported` is a real answer and not a failure. Office formats, video,
 * audio, archives and plain text have no stamping path in any library this
 * project carries or would reasonably add, and writing one into OOXML or
 * appending a line to a `.txt` is a destructive edit of the content itself.
 * Those files carry their code on the row, on the register, on the printable
 * label and in their download filename.
 *
 * `None` is the honest default for every row that predates stamping, which is
 * why no backfill restates it.
 */
enum DocumentStamp: string
{
    /** Nothing was drawn into the bytes. */
    case None = 'none';

    /** Drawn onto every page in the browser, with pdf-lib. */
    case Pdf = 'pdf';

    /** Drawn into the image here, with GD. */
    case Image = 'image';

    /**
     * A cover page added to the start of a Word document.
     *
     * OOXML is a zip, and `ext-zip` is present, so the one format family
     * this server *can* write into is the one it was assumed it could not.
     * A page rather than a corner mark, for the same reason a PDF gets one:
     * a page can carry the record, and a Word document has no fixed
     * geometry to put a corner mark in.
     */
    case Docx = 'docx';

    /**
     * A cover sheet added as the first tab of a workbook.
     *
     * A workbook has no page to put a mark on and no fixed geometry to put
     * one in the corner of, so the record goes on a sheet of its own —
     * which is also the one place in a spreadsheet nobody's formulas point
     * at.
     */
    case Xlsx = 'xlsx';

    /**
     * A cover slide added as the first slide of a deck.
     *
     * The hardest of the three, because a slide is not free-standing: it
     * must name a layout, the layout names a master, and the master carries
     * the theme. The cover borrows whatever layout the deck's first slide
     * uses, so it looks like the deck rather than like a foreign page.
     */
    case Pptx = 'pptx';

    /** This format cannot carry one, and never will. */
    case Unsupported = 'unsupported';

    /** Do these bytes carry the code? */
    public function carriesTheCode(): bool
    {
        return in_array($this, [self::Pdf, self::Image, self::Docx, self::Xlsx, self::Pptx], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'Not stamped',
            self::Pdf => 'Stamped on every page',
            self::Image => 'Stamped on the image',
            self::Docx => 'A cover page was added',
            self::Xlsx => 'A cover sheet was added',
            self::Pptx => 'A cover slide was added',
            self::Unsupported => 'This format cannot be stamped',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
