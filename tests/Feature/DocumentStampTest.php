<?php

namespace Tests\Feature;

use App\Enums\DocumentStamp;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentStampService;
use App\Services\Files\FolderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Zxing\QrReader;

/**
 * The code drawn into the file's own bytes.
 *
 * A code on a screen travels with the record; a code in the file travels with
 * every printed copy, which is the thing an office hands around. So the only
 * assertion that means anything here is that the stamp **decodes** — that a
 * phone pointed at the page reaches the document. Asserting that the bytes
 * merely changed would pass for a smudge.
 *
 * Only two formats can carry one. Everything else must come back byte for
 * byte identical, which is the other half of being honest about it.
 */
class DocumentStampTest extends TestCase
{
    use RefreshDatabase;

    private function upload(User $user, UploadedFile $file): void
    {
        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [$file],
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    /** A real JPEG, large enough to be worth stamping. */
    private function photograph(int $side = 1600, array $rgb = [150, 152, 156]): UploadedFile
    {
        $image = imagecreatetruecolor($side, $side);
        imagefilledrectangle($image, 0, 0, $side, $side, imagecolorallocate($image, ...$rgb));

        ob_start();
        imagejpeg($image, null, 92);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $path = tempnam(sys_get_temp_dir(), 'shot').'.jpg';
        file_put_contents($path, $bytes);

        return new UploadedFile($path, 'scan.jpg', 'image/jpeg', null, true);
    }

    private function decode(string $bytes): string|false
    {
        $path = tempnam(sys_get_temp_dir(), 'stamped').'.jpg';
        file_put_contents($path, $bytes);

        $read = (new QrReader($path))->text();
        unlink($path);

        return $read;
    }

    public function test_an_uploaded_photograph_carries_a_scannable_code(): void
    {
        $user = User::factory()->create();

        $this->upload($user, $this->photograph());

        $document = Document::query()->sole();
        $this->assertSame(DocumentStamp::Image, $document->stamp);

        $file = $document->file;
        $bytes = Storage::disk($file->disk)->get($file->storage_path);

        /*
         * The one assertion that matters: a decoder reads the code off the
         * picture.
         *
         * **The bare code, not a URL.** A symbol's module count follows what
         * it encodes, and that sets how small it can be drawn and still
         * read: 55 characters of tracking URL needs 164 px, 18 characters of
         * code needs 84. The office asked for the mark to be as small as it
         * could be, and carrying less is the only thing that buys that.
         *
         * The cost is that a generic phone camera shows text rather than
         * opening a page. This application's own scanner normalises a bare
         * code and a full URL to the same lookup, and the printable label —
         * the one handed to a courier with no account — still encodes a URL.
         */
        $this->assertSame(
            $document->code,
            $this->decode($bytes),
            'The mark must decode to the tracking code.',
        );

        // And the row describes the bytes that are actually on disk, or it
        // describes a file nobody has.
        $this->assertSame(strlen($bytes), (int) $file->size_bytes);
        $this->assertSame(hash('sha256', $bytes), $file->checksum);
    }

    /**
     * A format that cannot carry one comes back untouched, and says so.
     *
     * Video is the clearest case: there is no page, no canvas and no part
     * of the container a mark could live in, and no library here or
     * anywhere else would change that. The honest answer is the file
     * unchanged and a record that states it.
     *
     * This test used to use a spreadsheet, which stopped being an example
     * of the unstampable the day `ExcelCoverSheet` was written.
     */
    public function test_a_video_is_unchanged_and_says_so(): void
    {
        $user = User::factory()->create();
        $upload = UploadedFile::fake()->create('briefing.mp4', 64, 'video/mp4');
        $original = file_get_contents($upload->getRealPath());

        $this->upload($user, $upload);

        $document = Document::query()->sole();
        $this->assertSame(DocumentStamp::Unsupported, $document->stamp);
        $this->assertSame(
            $original,
            Storage::disk($document->file->disk)->get($document->file->storage_path),
            'A file that cannot be stamped must be stored byte for byte as it arrived.',
        );
    }

    /**
     * A picture too small to stamp is left alone and recorded as unstamped.
     *
     * Below the floor a symbol big enough to scan would cover the picture, so
     * the choice is between a useless stamp and an honest refusal. The record
     * must not claim a code the bytes do not carry.
     */
    public function test_a_small_picture_is_not_stamped_and_does_not_claim_to_be(): void
    {
        $user = User::factory()->create();
        // Under the 500px floor: a symbol big enough to decode would take a
        // sixth of this picture, so the honest answer is to leave it.
        $upload = $this->photograph(360);
        $original = file_get_contents($upload->getRealPath());

        $this->upload($user, $upload);

        $document = Document::query()->sole();

        $this->assertSame(DocumentStamp::None, $document->stamp);
        $this->assertSame($original, Storage::disk($document->file->disk)->get($document->file->storage_path));
    }

    /** A PDF is planned for the browser, never stamped here. */
    public function test_a_pdf_is_planned_for_the_browser(): void
    {
        $plan = app(DocumentStampService::class);

        $this->assertSame(DocumentStamp::Pdf, $plan->plan('pdf', 'application/pdf'));
        $this->assertSame(DocumentStamp::Image, $plan->plan('jpg', 'image/jpeg'));
        // A still GIF or BMP is an ordinary scanned page and carries its code.
        $this->assertSame(DocumentStamp::Image, $plan->plan('gif', 'image/gif'));
        $this->assertSame(DocumentStamp::Image, $plan->plan('bmp', 'image/bmp'));
        // SVG is a document that can carry script, so it is never rendered.
        $this->assertSame(DocumentStamp::Unsupported, $plan->plan('svg', 'image/svg+xml'));
        $this->assertSame(DocumentStamp::Unsupported, $plan->plan('mp4', 'video/mp4'));
        // The Office family is stamped now — a cover page, sheet or slide.
        $this->assertSame(DocumentStamp::Docx, $plan->plan('docx', 'application/zip'));
        $this->assertSame(DocumentStamp::Xlsx, $plan->plan('xlsx', 'application/zip'));
        $this->assertSame(DocumentStamp::Pptx, $plan->plan('pptx', 'application/zip'));
        // Their OLE2 ancestors are not zips, so nothing can be written in.
        $this->assertSame(DocumentStamp::Unsupported, $plan->plan('doc', 'application/msword'));
        $this->assertSame(DocumentStamp::Unsupported, $plan->plan('xls', 'application/vnd.ms-excel'));
    }

    /**
     * Bytes GD cannot open leave the file alone rather than throwing.
     *
     * Asserted against the service rather than through an upload, because the
     * upload policy refuses a `.png` whose bytes are not a PNG before
     * stamping is ever reached — which is the right answer there and makes
     * the upload the wrong place to ask this question. What has to hold is
     * narrower: the bytes are the person's, the stamp is ours, and a failure
     * in ours returns null so the caller stores what it already had.
     */
    public function test_bytes_gd_cannot_open_are_refused_rather_than_thrown(): void
    {
        $stamps = app(DocumentStampService::class);

        $this->assertNull($stamps->stampImage('not an image at all', 'MEMO-20261123-0001', 'image/jpeg'));
        $this->assertNull($stamps->stampImage('', 'MEMO-20261123-0001', 'image/png'));
        // A format it does not handle is refused without opening anything.
        $this->assertNull($stamps->stampImage('whatever', 'MEMO-20261123-0001', 'video/mp4'));
    }

    /**
     * An animated GIF keeps its animation and loses the stamp.
     *
     * GD's writer emits one frame, so stamping an animation would silently
     * flatten it — destroying the file in order to label it. The frames are
     * counted off the bytes because GD cannot tell us: it hands back the
     * first frame and says nothing about the rest.
     */
    public function test_an_animated_gif_is_not_flattened_to_stamp_it(): void
    {
        $stamps = app(DocumentStampService::class);

        $still = $this->gif(frames: 1);
        $moving = $this->gif(frames: 3);

        $this->assertNotNull(
            $stamps->stampImage($still, 'MEMO-20261123-0001', 'image/gif'),
            'A still GIF is an ordinary scanned page and should carry its code.',
        );
        $this->assertNull(
            $stamps->stampImage($moving, 'MEMO-20261123-0001', 'image/gif'),
            'An animation must keep its frames rather than be flattened for a stamp.',
        );
    }

    /** A GIF of the given frame count, built with GD. */
    private function gif(int $frames): string
    {
        $side = 800;
        $image = imagecreatetruecolor($side, $side);
        imagefilledrectangle($image, 0, 0, $side, $side, imagecolorallocate($image, 200, 200, 200));

        ob_start();
        imagegif($image);
        $one = (string) ob_get_clean();
        imagedestroy($image);

        if ($frames === 1) {
            return $one;
        }

        // Frames are recognised by their Graphic Control Extension, so the
        // marker is what has to repeat for this to read as an animation.
        return $one.str_repeat("\x00\x21\xF9\x04\x00\x00\x00\x00", $frames - 1);
    }

    /**
     * The mark is a watermark, and it still scans at that opacity.
     *
     * Asserted together because the two pull against each other: a decoder
     * binarises on luminance, so every point of opacity given up is contrast
     * given up. Measured rather than assumed — a stamp at 168 px failed at
     * every opacity tried while one at 216 px read at all of them, which is
     * what set `LABEL_MIN`. This pins the pair that shipped, so a later
     * change to either is caught by a decoder rather than by somebody
     * holding a printout.
     */
    public function test_the_watermark_still_decodes_over_a_photograph(): void
    {
        $user = User::factory()->create();

        $this->upload($user, $this->photograph(1600));

        $document = Document::query()->sole();
        $file = $document->file;

        $this->assertSame(
            $document->code,
            $this->decode(Storage::disk($file->disk)->get($file->storage_path)),
            'A watermark that cannot be read is decoration, not a tracking mark.',
        );
    }

    /**
     * The mark goes on transparently where the picture allows, and takes a
     * white plate where it does not.
     *
     * Both halves are the same requirement: the office asked for a
     * transparent mark, and a transparent mark over a dark photograph is one
     * nobody can scan. Measured against a real decoder, the symbol read over
     * near-white and over the mid-grey of an ordinary studio photograph and
     * failed outright over a dark field — so the picture decides, and the
     * only assertion worth making about either outcome is that it still
     * decodes.
     */
    public function test_the_mark_reads_over_a_light_picture_and_over_a_dark_one(): void
    {
        foreach ([[220, 222, 226, 'light'], [150, 152, 156, 'mid'], [30, 34, 40, 'dark']] as [$r, $g, $b, $name]) {
            $user = User::factory()->create();

            $this->upload($user, $this->photograph(1600, [$r, $g, $b]));

            $document = Document::query()->where('user_id', $user->id)->sole();
            $file = $document->file;

            $this->assertSame(
                $document->code,
                $this->decode(Storage::disk($file->disk)->get($file->storage_path)),
                "The mark must decode over a {$name} picture.",
            );
        }
    }

    /**
     * It is small — a mark on a picture, not a picture of a QR code.
     *
     * Pinned because "tiny" and "scannable" pull against each other and the
     * floor is measured: asked for at 160 the encoder returns 164, and at
     * 144 and below the mark decoded over no background at all. A change
     * that shrinks this fails here rather than on somebody's phone.
     */
    public function test_the_mark_is_a_small_fraction_of_the_picture(): void
    {
        $user = User::factory()->create();

        $this->upload($user, $this->photograph(2400));

        $file = Document::query()->sole()->file;
        $image = imagecreatefromstring(Storage::disk($file->disk)->get($file->storage_path));

        // Found by walking in from the corner the mark sits in until the
        // flat background stops — the mark's own extent, measured rather
        // than assumed from the constants it was drawn with.
        $background = imagecolorat($image, 10, 10);
        $edge = 0;

        for ($i = 1; $i < 2400; $i++) {
            if (imagecolorat($image, 2400 - $i, 2400 - $i) !== $background) {
                $edge = $i;
            }
        }

        $this->assertGreaterThan(0, $edge, 'Nothing was drawn into the picture.');
        // Halved when the payload became the bare code, so the bound is
        // tightened with it: a limit that no longer constrains anything is
        // not a test.
        $this->assertLessThan(
            0.08,
            $edge / 2400,
            'The mark should be a mark on the picture, not a feature of it.',
        );
    }

    /**
     * A modest scan carries its code too.
     *
     * The floor came down from 800 px to 500 when the mark halved, and this
     * is what that bought: the screenshots, scans and cropped photographs an
     * office actually holds. Asserted by decoding, because a mark that fits
     * and cannot be read would be worse than none.
     */
    public function test_a_modest_picture_still_carries_a_readable_code(): void
    {
        $user = User::factory()->create();

        $this->upload($user, $this->photograph(520));

        $document = Document::query()->sole();

        $this->assertSame(
            $document->code,
            $this->decode(Storage::disk($document->file->disk)->get($document->file->storage_path)),
        );
    }

    /**
     * A replaced file's new version carries the code too.
     *
     * The mark lived in the bytes that were just superseded, so without this
     * version 2 of a scan arrives unmarked while its row still claims
     * `image` — the record saying the file carries a code it does not. The
     * code itself is unchanged, because a replacement is a new version of
     * the same document and that code is already printed on paper.
     */
    public function test_a_replaced_image_is_stamped_again_with_the_same_code(): void
    {
        $user = User::factory()->create();
        $this->upload($user, $this->photograph(1600));

        $document = Document::query()->sole();
        $code = $document->code;
        $first = Storage::disk($document->file->disk)->get($document->file->storage_path);

        $this->actingAs($user)->post('/files/replace', [
            'public_id' => $document->file->public_id,
            'file' => $this->photograph(1600, [90, 140, 190]),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document->refresh();
        $file = $document->file->refresh();
        $second = Storage::disk($file->disk)->get($file->storage_path);

        $this->assertSame(2, $file->versionNumber());
        $this->assertSame($code, $document->code, 'A replacement keeps the printed code.');
        $this->assertNotSame($first, $second);
        $this->assertSame(DocumentStamp::Image, $document->stamp);
        $this->assertSame(
            $code,
            $this->decode($second),
            'The new version must carry the mark, not the version it replaced.',
        );
    }

    /**
     * And a replacement that changes the format recomputes what the bytes
     * can carry.
     *
     * A photograph replaced by a spreadsheet can no longer hold a mark, so
     * the row must stop saying `image` — carrying the old plan over would
     * leave it claiming a code those bytes never had.
     */
    public function test_replacing_an_image_with_an_unstampable_file_corrects_the_record(): void
    {
        $user = User::factory()->create();
        $this->upload($user, $this->photograph(1600));

        $document = Document::query()->sole();
        $this->assertSame(DocumentStamp::Image, $document->stamp);

        $this->actingAs($user)->post('/files/replace', [
            'public_id' => $document->file->public_id,
            'file' => UploadedFile::fake()->create('briefing.mp4', 64, 'video/mp4'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(DocumentStamp::Unsupported, $document->fresh()->stamp);
    }

    /**
     * A superseded version remembers whether *it* carried the code.
     *
     * The stamp was a column on `documents`, which only ever described
     * whatever version the document pointed at: replacing the file reset it
     * and the old answer was gone. That matters because the old version is
     * not gone — it is still on disk and still downloadable from the version
     * history, so a reader about to print version 1 needs to know whether
     * that copy carries its code.
     *
     * It lives on `files` now, mirrored into `file_versions` with the rest
     * of the descriptor, exactly as `original_name` and `checksum` are.
     */
    public function test_each_version_remembers_its_own_stamp(): void
    {
        $user = User::factory()->create();

        // Version 1: an image, stamped.
        $this->upload($user, $this->photograph(1600));

        $document = Document::query()->sole();
        $this->assertSame(DocumentStamp::Image, $document->stamp);

        // Version 2: a format that cannot carry one.
        $this->actingAs($user)->post('/files/replace', [
            'public_id' => $document->file->public_id,
            'file' => UploadedFile::fake()->create('briefing.mp4', 64, 'video/mp4'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document->refresh();

        // The current answer is about the current bytes...
        $this->assertSame(DocumentStamp::Unsupported, $document->stamp);

        // ...and version 1 still says what was true of version 1.
        $first = $document->file->versions()->where('version', 1)->sole();
        $this->assertSame(DocumentStamp::Image, $first->stamp);
    }

    /**
     * And new bytes start unmarked rather than inheriting the old answer.
     *
     * `FileVersionService::makeCurrent()` resets it for the same reason it
     * resets `has_thumbnail`: the row would otherwise claim a property
     * belonging to the version it just superseded.
     */
    public function test_new_bytes_do_not_inherit_the_previous_versions_mark(): void
    {
        $user = User::factory()->create();

        $this->upload($user, $this->photograph(1600));
        $document = Document::query()->sole();

        // Replaced with a picture too small to carry a mark, so nothing is
        // drawn — if the answer were inherited it would still say `image`.
        $this->actingAs($user)->post('/files/replace', [
            'public_id' => $document->file->public_id,
            'file' => $this->photograph(360),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(DocumentStamp::None, $document->fresh()->stamp);
    }
}
