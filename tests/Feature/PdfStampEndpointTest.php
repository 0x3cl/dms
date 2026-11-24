<?php

namespace Tests\Feature;

use App\Enums\DocumentStamp;
use App\Models\Document;
use App\Models\User;
use App\Services\Files\FolderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The second half of an upload, for the one format this server cannot draw
 * into.
 *
 * There is no PDF library on these servers, so `pdf-lib` draws the code onto
 * every page in the browser and posts the result back. That makes this
 * endpoint an unusual thing — it accepts bytes that **overwrite** a stored
 * file — which is precisely the capability `FileManager::replace()` exists to
 * version and audit. So the tests here are mostly about what it refuses.
 *
 * What it cannot check is that the bytes really are the uploaded file with a
 * stamp on them. That is inherent to drawing in the browser, and is why the
 * window, the ownership and the one-shot are as narrow as they are.
 */
class PdfStampEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function uploadPdf(User $user, string $name = 'gatepass.pdf'): Document
    {
        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [UploadedFile::fake()->create($name, 16, 'application/pdf')],
        ])->assertRedirect()->assertSessionHasNoErrors();

        return Document::query()->latest('id')->sole();
    }

    /**
     * A PDF whose bytes are distinct from the uploaded one's.
     *
     * `UploadedFile::fake()->create()` fills with the same filler at the same
     * size, so "the bytes changed" could not be asserted against it — the
     * before and the after were the same string. A real `%PDF-` header also
     * means the endpoint's `mimetypes` rule is exercised rather than
     * sidestepped.
     */
    private function stamped(string $marker = 'stamped'): UploadedFile
    {
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n% {$marker}\n%%EOF";

        return UploadedFile::fake()->createWithContent('gatepass.pdf', $pdf);
    }

    /**
     * A PDF arrives unstamped and honestly says so.
     *
     * The row must not claim `pdf` the moment a PDF is uploaded: an
     * interrupted browser would then be telling every later reader their file
     * carries a code that was never drawn into it.
     */
    public function test_an_uploaded_pdf_starts_unstamped_and_says_so(): void
    {
        $document = $this->uploadPdf(User::factory()->create());

        $this->assertSame(DocumentStamp::None, $document->stamp);
    }

    public function test_the_owner_can_post_the_stamp_the_browser_drew(): void
    {
        $user = User::factory()->create();
        $document = $this->uploadPdf($user);
        $before = Storage::disk($document->file->disk)->get($document->file->storage_path);

        $this->actingAs($user)->post('/files/stamp', [
            'code' => $document->code,
            'stamped' => $this->stamped(),
        ])->assertRedirect()->assertSessionHas('success');

        $document->refresh();
        $file = $document->file->refresh();
        $after = Storage::disk($file->disk)->get($file->storage_path);

        $this->assertSame(DocumentStamp::Pdf, $document->stamp);
        $this->assertNotSame($before, $after, 'The stored bytes must be the stamped ones.');
        // And the row describes the bytes that are now on disk.
        $this->assertSame(strlen($after), (int) $file->size_bytes);
        $this->assertSame(hash('sha256', $after), $file->checksum);
    }

    /** Once stamped, it refuses — this completes an upload, it does not rewrite a file. */
    public function test_it_works_exactly_once(): void
    {
        $user = User::factory()->create();
        $document = $this->uploadPdf($user);

        $this->actingAs($user)->post('/files/stamp', [
            'code' => $document->code,
            'stamped' => $this->stamped(),
        ])->assertRedirect();

        $this->actingAs($user)->post('/files/stamp', [
            'code' => $document->code,
            'stamped' => $this->stamped(),
        ])->assertNotFound();
    }

    /**
     * Somebody else's file is refused, and refused as a 404.
     *
     * Ownership, not a manage share: a share that carried this would make the
     * endpoint a quieter `replace()` for anybody holding one, with no version
     * and no audit row. "Not yours" and "no such file" stay
     * indistinguishable, matching every other route that takes an identifier.
     */
    public function test_somebody_elses_file_is_refused(): void
    {
        $owner = User::factory()->create();
        $document = $this->uploadPdf($owner);

        $this->actingAs(User::factory()->create())->post('/files/stamp', [
            'code' => $document->code,
            'stamped' => $this->stamped(),
        ])->assertNotFound();

        $this->assertSame(DocumentStamp::None, $document->fresh()->stamp);
    }

    /**
     * A format that is not a PDF is refused, so this cannot be used to put
     * arbitrary bytes behind a spreadsheet.
     */
    public function test_a_non_pdf_file_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [UploadedFile::fake()->create('budget.xlsx', 16, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')],
        ])->assertRedirect();

        $document = Document::query()->sole();

        $this->actingAs($user)->post('/files/stamp', [
            'code' => $document->code,
            'stamped' => $this->stamped(),
        ])->assertNotFound();
    }

    /**
     * The window closes behind the upload it belongs to.
     *
     * Measured from the **bytes**, not the document: replacing the file of a
     * document filed last year is exactly when a new version needs its mark,
     * and the document is months old at that moment.
     */
    public function test_it_closes_after_a_few_minutes(): void
    {
        $user = User::factory()->create();
        $document = $this->uploadPdf($user);

        $document->file->forceFill(['updated_at' => now()->subHour()])->save();

        $this->actingAs($user)->post('/files/stamp', [
            'code' => $document->code,
            'stamped' => $this->stamped(),
        ])->assertNotFound();
    }

    /** And the bytes have to be a PDF at all. */
    public function test_the_posted_bytes_must_be_a_pdf(): void
    {
        $user = User::factory()->create();
        $document = $this->uploadPdf($user);

        $this->actingAs($user)->post('/files/stamp', [
            'code' => $document->code,
            'stamped' => UploadedFile::fake()->create('notes.txt', 2, 'text/plain'),
        ])->assertSessionHasErrors('stamped');
    }
}
