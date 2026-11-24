<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\Files\FolderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * What a PDF's cover page is told to say.
 *
 * The page itself is drawn in the browser — there is no PDF library on these
 * servers — so what this can pin is the half the server owns: which facts are
 * sent, and to whom.
 *
 * They are sent rather than composed in the browser because each one belongs
 * to something here. The **type** is the name as it stood when the document
 * was filed, which is why `documents.type` exists beside the foreign key; the
 * **classification's wording** belongs to `DocumentVisibility`. A page that
 * wrote its own would be a second vocabulary to keep in step with this one.
 */
class CoverPageTest extends TestCase
{
    use RefreshDatabase;

    private function pdf(User $user): Document
    {
        $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => [UploadedFile::fake()->create('gatepass.pdf', 16, 'application/pdf')],
        ])->assertRedirect();

        return Document::query()->latest('id')->sole();
    }

    public function test_the_cover_carries_the_record_not_just_the_symbol(): void
    {
        $user = User::factory()->create();
        $document = $this->pdf($user);

        $cover = $this->actingAs($user)
            ->getJson("/documents/{$document->id}/qr-label")
            ->assertOk()
            ->json('cover');

        $this->assertSame($document->title, $cover['title']);
        $this->assertSame($document->type, $cover['type']);
        $this->assertSame($document->visibility->label(), $cover['visibility']);
        $this->assertSame($document->visibility->description(), $cover['visibility_note']);
        $this->assertSame($document->uploaderName(), $cover['uploader']);
        $this->assertNotEmpty($cover['filed_on']);
    }

    /**
     * The type is the snapshot, so a cover printed today still says what the
     * document was filed as.
     *
     * `documents.type` is the type's name at upload and `document_type_id`
     * is the live row; renaming a type must not rewrite the history of the
     * documents issued under its old name, and a cover page is history the
     * moment it is printed.
     */
    public function test_the_type_is_the_name_it_was_filed_under(): void
    {
        $user = User::factory()->create();
        $document = $this->pdf($user);

        $document->documentType->update(['name' => 'Renamed Since']);

        $cover = $this->actingAs($user)
            ->getJson("/documents/{$document->id}/qr-label")
            ->json('cover');

        $this->assertSame($document->type, $cover['title'] === null ? null : $cover['type']);
        $this->assertNotSame('Renamed Since', $cover['type']);
    }

    /**
     * A reader is sent none of it.
     *
     * The cover travels with `stamp_png`, which is the uploader's to draw
     * into their own file. A reader has the document page for all of this
     * and no file to stamp.
     */
    public function test_a_reader_is_sent_no_cover(): void
    {
        $user = User::factory()->create();
        $document = $this->pdf($user);
        $document->update(['visibility' => 'public']);

        $payload = $this->actingAs(User::factory()->create())
            ->getJson("/documents/{$document->id}/qr-label")
            ->assertOk()
            ->json();

        $this->assertNull($payload['cover']);
        $this->assertNull($payload['stamp_png']);
    }
}
