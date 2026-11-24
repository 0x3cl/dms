/**
 * Drawing a document's tracking code onto its own pages.
 *
 * A code on a screen travels with the record; a code in the file travels with
 * every printed copy, which is the thing an office hands around. Images are
 * stamped on the server with GD. A **PDF cannot be**: there is no PDF library
 * on these servers and the documented constraint is that there will not be
 * one, so this is the only place a PDF can be drawn into — the same reasoning
 * that put `docx-preview` and SheetJS in the browser rather than asking for
 * LibreOffice.
 *
 * `pdf-lib` is imported dynamically, exactly as those two are: it is large,
 * and most uploads are not PDFs, so a page that never stamps never pays for
 * it.
 *
 * **It never throws and never blocks an upload.** Every refusal returns null
 * and the file stays as it is, with the server recording honestly that these
 * bytes carry no code. The bytes are the person's; the stamp is ours.
 */

/**
 * The symbol on the cover page, in points.
 *
 * A cover page has room a margin never did, so this is sized to be read
 * across a desk rather than squeezed into a corner — and comfortably above
 * the floor measured for the corner mark it replaces, where a stamp at
 * 168 px failed to decode at every opacity and 216 px read at all of them.
 * At 160 pt this is 667 px at 300 dpi and 333 px at 150 dpi, so it scans
 * from a draft print as well as a good one.
 */
const QR_PT = 160;

/** The page's own margin, used for the footer. */
const MARGIN_PT = 56;

/** How far below the top edge the symbol starts. */
const TOP_PT = 50;

/**
 * The largest PDF worth rewriting in a browser tab.
 *
 * pdf-lib parses and re-serialises the whole document, which for a very
 * large file is a frozen tab rather than a slow one. Refusing is the honest
 * answer.
 */
const MAX_BYTES = 25 * 1024 * 1024;

/**
 * Can these bytes carry a stamp drawn here?
 *
 * Only a PDF. Everything else is either stamped on the server or cannot be
 * stamped at all, and this module is deliberately not the place that decides
 * which — `DocumentStampService::plan()` is, and the upload dialog is told
 * the answer rather than working it out twice.
 */
export function stampable(file) {
    return file?.type === 'application/pdf' || /\.pdf$/i.test(file?.name ?? '');
}

/**
 * Formats GD can draw into here, mirroring `DocumentStampService::IMAGE_MIMES`.
 *
 * Listed rather than `image/*` because the two that are missing are missing
 * on purpose: an SVG is a document that can carry script, and a TIFF is not
 * something GD opens on these servers.
 */
const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp'];

/**
 * Can this file carry a tracking mark at all?
 *
 * **The question the upload form asks before it offers the choice**, which
 * is why it is here beside `stampable()` rather than in either form: a
 * spreadsheet, a video or a zip has no surface to draw on, and a tickbox
 * offering to stamp one would be a control that does nothing.
 *
 * `stampable()` is narrower and answers a different question — "must the
 * *browser* draw this one", which is true only of a PDF. An image is drawn
 * on the server and is still something the person can decline.
 */
export function canCarryMark(file) {
    return stampable(file)
        || IMAGE_TYPES.includes(file?.type)
        // The Office family, by extension: every OOXML file is a zip
        // underneath, so a correctly sniffed .docx is application/zip. The
        // server writes the cover page, sheet or slide into these directly
        // — ext-zip is present — so unlike a PDF there is nothing for the
        // browser to draw. Their OLE2 ancestors (.doc, .xls, .ppt) are not
        // zips and are deliberately absent.
        || /\.(docx|docm|xlsx|xlsm|pptx|pptm|ppsx)$/i.test(file?.name ?? '');
}

/**
 * Prepend a cover page carrying the document's record, and hand back a new
 * File.
 *
 * **A page rather than a mark in the corner**, which is what this replaced.
 * A corner stamp can only ever be the symbol: it says "this is tracked" and
 * nothing about *what* is tracked. Somebody holding a printout off a shared
 * printer needs to know what the document is, what kind of thing it is and
 * who may read it before they decide what to do with it — and all three are
 * things the register already knows and the paper did not.
 *
 * It is inserted as page one rather than appended, because a cover is the
 * thing you see first, and because a document put face-down on a desk is
 * read from its first page. **This shifts the original page numbering by
 * one**, which is the real cost: a reference to "page 4" made before the
 * document was filed now points at page 5.
 *
 * Returns null — meaning "store what you already had" — when the file is too
 * large, encrypted, unparseable, or **carries a signature**. Rewriting a
 * signed PDF invalidates its signature, and losing a signature is worse than
 * losing a cover page.
 *
 * @param {File} file the PDF as the person selected it
 * @param {{labelPng: string, code: string, cover: object}} stamp
 * @returns {Promise<File|null>}
 */
export async function stampPdf(file, { labelPng, code, cover }) {
    if (!stampable(file) || file.size > MAX_BYTES || !labelPng) return null;

    try {
        const { PDFDocument, StandardFonts, rgb } = await import('pdf-lib');

        const bytes = await file.arrayBuffer();

        /*
         * `ignoreEncryption` is deliberately absent: an encrypted PDF throws
         * here and is refused, rather than being silently rewritten into a
         * decrypted copy of itself.
         */
        const pdf = await PDFDocument.load(bytes);

        // A signature is a claim about exact bytes. Rewriting the file breaks
        // it, so a signed document keeps its signature and loses the cover.
        if (isSigned(pdf)) return null;

        const [original] = pdf.getPages();

        if (!original) return null;

        // The cover takes the document's own page size, so printing the file
        // does not ask the tray for two different papers.
        const { width, height } = original.getSize();
        const page = pdf.insertPage(0, [width, height]);

        const label = await pdf.embedPng(labelPng);
        const body = await pdf.embedFont(StandardFonts.Helvetica);
        const bold = await pdf.embedFont(StandardFonts.HelveticaBold);
        const mono = await pdf.embedFont(StandardFonts.Courier);

        const ink = rgb(0.05, 0.13, 0.25);
        const muted = rgb(0.42, 0.47, 0.54);
        const centre = (text, font, size) => (width - font.widthOfTextAtSize(text, size)) / 2;

        /*
         * Laid out from the top down, horizontally centred.
         *
         * It was measured from the centre line, so that a wrapping title
         * could not move the symbol. Nothing below it wraps — every line is
         * truncated to one — so what that bought was a block of text floating
         * in the middle of an otherwise empty page, with the symbol lower
         * than anything a reader scans for first. It starts near the top
         * edge, where somebody flipping a stack of paper finds it.
         */
        let y = height - TOP_PT;

        page.drawImage(label, { x: (width - QR_PT) / 2, y: y - QR_PT, width: QR_PT, height: QR_PT });
        y -= QR_PT + 26;

        // The code under the symbol, in the one typeface where a zero and an
        // O are different shapes — it is meant to be typed in by hand when a
        // print is too poor to scan.
        page.drawText(code, { x: centre(code, mono, 13), y, size: 13, font: mono, color: ink });
        y -= 34;

        const title = truncate(cover?.title ?? '', bold, 18, width - (MARGIN_PT * 2));

        if (title) {
            page.drawText(title, { x: centre(title, bold, 18), y, size: 18, font: bold, color: ink });
            y -= 24;
        }

        // Type and classification on one line, because they answer one
        // question together: what this is, and who may read it.
        const facts = [cover?.type, cover?.visibility].filter(Boolean).join('  ·  ');

        if (facts) {
            page.drawText(facts, { x: centre(facts, body, 11), y, size: 11, font: body, color: muted });
            y -= 18;
        }

        if (cover?.visibility_note) {
            const note = truncate(cover.visibility_note, body, 9, width - (MARGIN_PT * 2));
            page.drawText(note, { x: centre(note, body, 9), y, size: 9, font: body, color: muted });
        }

        // Who filed it and when, along the foot — provenance rather than
        // instruction, so it sits away from everything above.
        const footer = [cover?.uploader, cover?.filed_on].filter(Boolean).join('  ·  ');

        if (footer) {
            page.drawText(footer, {
                x: centre(footer, body, 9),
                y: MARGIN_PT,
                size: 9,
                font: body,
                color: muted,
            });
        }

        const stamped = await pdf.save();

        return new File([stamped], file.name, { type: 'application/pdf' });
    } catch {
        // Unparseable, encrypted, or out of memory. The upload has already
        // succeeded; this half simply did not happen.
        return null;
    }
}

/**
 * Cut a line to the width it has, with an ellipsis.
 *
 * A title is arbitrary user input and a cover page is one line of it: an
 * untruncated one runs off the paper, which looks like a broken document
 * rather than a long name.
 */
function truncate(text, font, size, maxWidth) {
    let out = (text ?? '').trim();

    if (!out) return '';

    while (out.length > 1 && font.widthOfTextAtSize(out + '…', size) > maxWidth) {
        out = out.slice(0, -1);
    }

    return font.widthOfTextAtSize(text, size) > maxWidth ? out + '…' : text;
}

/**
 * Does this document carry a signature?
 *
 * Read off the AcroForm's field types rather than by searching the raw bytes
 * for `/Sig`, which matches inside any stream that happens to contain those
 * characters and would refuse to stamp ordinary documents.
 */
function isSigned(pdf) {
    try {
        return pdf.getForm().getFields().some((field) => field.constructor?.name === 'PDFSignature');
    } catch {
        return false;
    }
}
