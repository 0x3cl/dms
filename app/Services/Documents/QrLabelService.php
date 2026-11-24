<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Services\Files\FileStorage;
use Illuminate\Support\Facades\Storage;

/**
 * The scannable label a tracking code is printed on.
 *
 * **PNG, with the DOST-PTRI seal in the middle.** Both of those were
 * previously impossible, and for one reason: `simple-qrcode` renders PNG
 * through BaconQrCode's **Imagick** backend, and these servers have GD and not
 * Imagick — `format('png')` threw on every upload, so labels were SVG, and
 * `merge()` (the logo path) needed the same missing backend. `endroid/qr-code`
 * renders PNG through **GD**, which is present, and embeds a logo natively.
 * The constraint has not been waved away; a different library simply does not
 * meet it.
 *
 * Two things keep the label scannable, and they are not decoration:
 *
 * **Error correction is High.** A logo sits on top of modules the scanner
 * needs. High-level correction reconstructs up to about 30% of a damaged
 * symbol, which is what buys the room the seal occupies. At the default level
 * the same logo produces a label that looks right and does not read.
 *
 * **The logo is punched out and bounded.** `logoPunchoutBackground` clears the
 * modules under it rather than letting a transparent PNG blend into them — a
 * seal half-merged with the pattern confuses a decoder far more than a clean
 * hole does — and the width is capped at a fraction of the symbol so the
 * damaged area stays inside what the correction level can recover.
 *
 * `QrLabelTest` decodes what this produces and asserts the tracking URL comes
 * back. That test is not ceremony: a logo that breaks scanning defeats the
 * entire point of the label, and it fails silently to the eye.
 */
class QrLabelService
{
    /**
     * `$logoPath` stays first: it is what every caller and every test passes,
     * and reordering it to put the renderer in front would be a signature
     * change dressed up as a refactor.
     */
    public function __construct(
        private readonly string $logoPath = '',
        private readonly QrLabelRenderer $renderer = new QrLabelRenderer,
    ) {}

    /**
     * What a document's label resolves to.
     *
     * **The share link when there is one**, and the tracking URL otherwise.
     *
     * The label used to encode the tracking URL always, which worked while
     * that URL was public. It is not any more — a tracking code is printed on
     * the folder and is not a credential — so a label encoding it would send
     * whoever scanned it to a sign-in page, which is no use to the courier it
     * was stuck there for. A share link is the thing that opens for somebody
     * with no account *and* can be revoked, counted and expired.
     *
     * A confidential document has no link and never will, so its label falls
     * back to the tracking URL: staff scan it and sign in, which is exactly
     * what confidential means.
     *
     * **Consequence worth stating plainly.** The printed label is now a bearer
     * credential: whoever photographs the folder can open the document until
     * the link is revoked. That is the trade the office asked for, and it is
     * strictly better than the code was — a code could not be revoked at all —
     * but it is a trade, not a free win.
     */
    public function targetFor(Document $document): string
    {
        $link = $document->shareLinks()->whereNull('revoked_at')->latest('id')->first();

        return $link?->url() ?? $this->trackingUrl($document->code);
    }

    /** The in-app URL for a tracking code. Requires an account. */
    public function trackingUrl(string $code): string
    {
        return route('documents.view', ['code' => $code]);
    }

    /**
     * For printing and for the download — the format the office asked for.
     *
     * Takes the URL rather than the code, because what a label points at is
     * now a decision (`targetFor()`) rather than a formatting rule.
     */
    public function png(string $target, ?int $size = null): string
    {
        return $this->renderer->png($target, $this->logo(), $size);
    }

    /**
     * For rendering inline in a page.
     *
     * SVG stays because it is sharp at any size and costs no bytes to scale,
     * and because it is what the on-screen modal shows. The download is the
     * PNG.
     */
    public function svg(string $target): string
    {
        return $this->renderer->svg($target, $this->logo(inline: true));
    }

    /** The filename a label is stored and served under. Never the title. */
    public function fileName(string $code): string
    {
        return "{$code}.png";
    }

    /**
     * The stored label's path on the private disk, rendering it if it is not
     * there yet.
     *
     * Registration used to write this for every document. It stopped, because
     * every uploaded file is filed now and the label is the expensive part: a
     * 512px symbol is drawn on a 1536×1536 GD canvas and resampled down, once
     * per document, which a two-hundred-entry archive would do two hundred
     * times for labels nobody has asked to see. `documents.qr_code_path` is
     * still set at registration, so the stored-name match that guards
     * `downloadQr()` against traversal is unchanged — what is deferred is
     * only the drawing.
     *
     * Idempotent, and the one place the bytes are produced: both the download
     * route and the three link events that rewrite a label come through here,
     * so there is no second statement of where a label lives or what it
     * points at.
     */
    public function materialise(Document $document): string
    {
        $path = 'qrcodes/'.($document->qr_code_path ?: $this->fileName($document->code));
        $disk = Storage::disk(FileStorage::DISK);

        if (! $disk->exists($path)) {
            $disk->put($path, $this->png($this->targetFor($document)));
        }

        return $path;
    }

    /**
     * The seal, when there is one.
     *
     * A missing logo file produces a plain label rather than an exception: a
     * tracking code that cannot be issued because a brand asset is absent
     * would stop every upload in the office, and an unbranded label still
     * scans to exactly the right place.
     *
     * **The inline variant is a smaller file, and that is about page weight
     * rather than quality.** An SVG embeds the seal's bytes as base64, and
     * that string is shipped in the page every time a symbol is rendered —
     * the full-resolution `ptri.png` is 142 KB, which becomes ~190 KB of
     * markup for an image drawn at about 113 pixels. `icon-192.png` is
     * generated from the same seal by `brand:icons`, is already oversampled
     * for that size, and costs a seventh as much. The PNG keeps the full
     * seal: it is a file somebody prints, downloaded once, where resolution
     * is the whole point.
     *
     * An explicit `$logoPath` overrides both, because a caller that named a
     * file means that file.
     */
    private function logo(bool $inline = false): ?string
    {
        if ($this->logoPath !== '') {
            return is_file($this->logoPath) ? $this->logoPath : null;
        }

        foreach ($inline ? ['icon-192.png', 'ptri.png'] : ['ptri.png'] as $candidate) {
            if (is_file($path = public_path($candidate))) {
                return $path;
            }
        }

        return null;
    }
}
