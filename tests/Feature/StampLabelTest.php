<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\Files\FolderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use Zxing\QrReader;

/**
 * The symbol that goes **into** a file, and why it is not the printed one.
 *
 * Two labels, two targets, and the difference is permanence. The printable
 * label points at `targetFor()` — the share link, where there is one —
 * because the uploader prints it deliberately and can print it again when
 * `regenerateLink()` re-addresses the link. A stamp cannot be reprinted: it
 * is in every copy already handed out, so a token baked into it would make
 * regeneration a lie for all of them and would put a bearer credential into
 * the bytes of every file in the office.
 *
 * The stamp therefore encodes the tracking URL, which is also the only symbol
 * anyone has measured small: `QrLabelTest` decodes it at 128px, where the
 * share-link symbol is denser and proven only at full size.
 */
class StampLabelTest extends TestCase
{
    use RefreshDatabase;

    private function pdfDocument(User $user): Document
    {
        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [UploadedFile::fake()->create('gatepass.pdf', 16, 'application/pdf')],
        ])->assertRedirect();

        return Document::query()->latest('id')->sole();
    }

    private function decode(string $png): string|false
    {
        $path = tempnam(sys_get_temp_dir(), 'label').'.png';
        file_put_contents($path, $png);
        $read = (new QrReader($path))->text();
        unlink($path);

        return $read;
    }

    /**
     * The stamp symbol decodes to the tracking URL, never to a share link.
     *
     * Decoded rather than compared as a string, because what matters is what
     * a phone pointed at the printed page actually reaches.
     */
    public function test_the_stamp_label_encodes_the_tracking_url(): void
    {
        $user = User::factory()->create();
        $document = $this->pdfDocument($user);

        $payload = $this->actingAs($user)
            ->getJson("/documents/{$document->id}/qr-label")
            ->assertOk()
            ->json();

        $this->assertNotNull($payload['stamp_png'], 'A PDF must be offered a symbol to stamp.');

        $this->assertSame(
            route('documents.view', ['code' => $document->code]),
            $this->decode(base64_decode($payload['stamp_png'])),
            'A stamp is permanent, so it must carry the tracking URL and never a share token.',
        );
    }

    /** A reader is never handed one — a label is the uploader's. */
    public function test_a_reader_is_offered_no_stamp_label(): void
    {
        $user = User::factory()->create();
        $document = $this->pdfDocument($user);
        $document->update(['visibility' => 'public']);

        $payload = $this->actingAs(User::factory()->create())
            ->getJson("/documents/{$document->id}/qr-label")
            ->assertOk()
            ->json();

        $this->assertNull($payload['stamp_png']);
    }

    /** And a format that cannot carry one is not sent kilobytes it cannot use. */
    public function test_a_format_that_cannot_be_stamped_is_offered_nothing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [UploadedFile::fake()->create('budget.xlsx', 16, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')],
        ])->assertRedirect();

        $document = Document::query()->sole();

        $payload = $this->actingAs($user)
            ->getJson("/documents/{$document->id}/qr-label")
            ->assertOk()
            ->json();

        $this->assertNull($payload['stamp_png']);
    }
}
