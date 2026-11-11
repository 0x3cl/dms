<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentSeries;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Document types are an editable list, and deleting one must not disturb a
 * single document already filed under it.
 *
 * That protection rests on two things the schema has had from the start:
 * `documents.type` carries the type's **name as it stood at upload** and is
 * what every screen renders, and `documents.document_type_id` is
 * `nullOnDelete`. Soft-deleting the type adds the third: the link survives too,
 * so the register can still filter and count.
 */
class DocumentTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('public');
    }

    private function admin(): User
    {
        return User::factory()->superadmin()->create();
    }

    private function upload(User $user, DocumentType $type): Document
    {
        $this->actingAs($user)->post('/documents', [
            'title' => 'Office memo',
            'document_type_id' => $type->id,
            'visibility' => 'public',
            'file' => UploadedFile::fake()->create('memo.pdf', 8, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        return Document::query()->latest('id')->sole();
    }

    // ------------------------------------------------------------ the module

    public function test_the_screen_needs_the_permission(): void
    {
        $this->actingAs(User::factory()->create())->get('/document-types')->assertForbidden();
        $this->actingAs($this->admin())->get('/document-types')->assertOk();
    }

    public function test_a_type_can_be_created_and_is_offered_on_the_upload_form(): void
    {
        $this->actingAs($this->admin())->post('/document-types', [
            'code' => 'brief',
            'name' => 'Policy Brief',
            'description' => 'Short briefing papers.',
            'is_active' => true,
            'position' => 50,
        ])->assertRedirect('/document-types');

        $type = DocumentType::query()->where('name', 'Policy Brief')->sole();

        // Uppercased on save, because the code is printed and compared.
        $this->assertSame('BRIEF', $type->code);
        $this->assertTrue(DocumentType::selectable()->contains('id', $type->id));
    }

    /** A new type issues its own series, from 1, on its own prefix. */
    public function test_a_new_type_issues_its_own_tracking_code_series(): void
    {
        $user = User::factory()->create();
        $this->actingAs($this->admin())->post('/document-types', [
            'code' => 'BRIEF', 'name' => 'Policy Brief', 'is_active' => true, 'position' => 50,
        ]);

        $type = DocumentType::query()->where('code', 'BRIEF')->sole();
        $document = $this->upload($user, $type);

        $this->assertStringStartsWith('BRIEF-'.now()->format('Ymd').'-0001', $document->code);
    }

    public function test_a_retired_type_leaves_the_upload_form_but_stays_filterable(): void
    {
        $type = DocumentType::query()->where('code', 'MEMO')->sole();

        $this->actingAs($this->admin())->put("/document-types/{$type->id}", [
            'code' => 'MEMO', 'name' => $type->name, 'is_active' => false, 'position' => $type->position,
        ])->assertRedirect();

        $this->assertFalse(DocumentType::selectable()->contains('id', $type->id));
        $this->assertTrue(DocumentType::filterable()->contains('id', $type->id));

        // And it can no longer be uploaded against.
        $this->actingAs(User::factory()->create())->post('/documents', [
            'title' => 'Memo',
            'document_type_id' => $type->id,
            'visibility' => 'public',
            'file' => UploadedFile::fake()->create('memo.pdf', 8, 'application/pdf'),
        ])->assertSessionHasErrors('document_type_id');
    }

    // ------------------------- the point: deleting does not touch documents

    /**
     * The whole requirement, in one test.
     */
    public function test_deleting_a_type_leaves_its_documents_completely_untouched(): void
    {
        $user = User::factory()->create();
        $type = DocumentType::query()->where('code', 'MEMO')->sole();
        $document = $this->upload($user, $type);

        $before = $document->fresh()->only(['code', 'title', 'type', 'document_type_id', 'file_id', 'status', 'visibility']);

        $this->actingAs($this->admin())
            ->delete("/document-types/{$type->id}")
            ->assertRedirect('/document-types');

        $this->assertSoftDeleted($type);

        $after = $document->fresh();

        // Every column identical, the type link included.
        $this->assertSame($before, $after->only(array_keys($before)));
        $this->assertSame('Memo', $after->type, 'The snapshot is what every screen renders.');
        $this->assertSame($type->id, $after->document_type_id, 'A soft delete keeps the link.');
    }

    /** And the document is still readable, with its type shown. */
    public function test_a_document_still_opens_after_its_type_is_deleted(): void
    {
        $user = User::factory()->create();
        $type = DocumentType::query()->where('code', 'MEMO')->sole();
        $document = $this->upload($user, $type);

        $type->delete();

        $payload = $this->actingAs($user)
            ->get("/documents/view/{$document->code}")
            ->assertOk()
            ->viewData('page')['props']['document'];

        $this->assertSame('Memo', $payload['type']);
    }

    /** It is still in the register, and still findable by its type filter. */
    public function test_a_document_is_still_listed_and_filterable_after_its_type_is_deleted(): void
    {
        $user = User::factory()->create();
        $type = DocumentType::query()->where('code', 'MEMO')->sole();
        $document = $this->upload($user, $type);

        $type->delete();

        $props = $this->actingAs($user)
            ->get("/documents?tab=all&type={$type->id}")
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame([$document->id], array_column($props['documents']['data'], 'id'));

        // The deleted type is still offered as a filter — otherwise its
        // documents would be in the register and unreachable through it.
        $this->assertContains($type->id, array_column(json_decode(json_encode($props['types']), true), 'id'));
    }

    /** Its file and its audit trail are untouched too. */
    public function test_the_file_and_trail_survive_their_types_deletion(): void
    {
        $user = User::factory()->create();
        $type = DocumentType::query()->where('code', 'MEMO')->sole();
        $document = $this->upload($user, $type);

        $type->delete();

        $this->actingAs($user)->get("/documents/{$document->id}/file/download")->assertOk();
        $this->actingAs($user)->get("/documents/{$document->id}/logs")->assertOk();
    }

    /**
     * Even a permanent erasure leaves the document standing — the guarantee
     * `nullOnDelete` beside the `type` snapshot exists for.
     */
    public function test_erasing_a_type_still_leaves_its_documents_readable(): void
    {
        $user = User::factory()->create();
        $type = DocumentType::query()->where('code', 'MEMO')->sole();
        $document = $this->upload($user, $type);

        DocumentSeries::query()->update(['document_type_id' => null]);
        $type->forceDelete();

        $after = $document->fresh();

        $this->assertNotNull($after);
        $this->assertNull($after->document_type_id);
        $this->assertSame('Memo', $after->type);

        $this->actingAs($user)->get("/documents/view/{$document->code}")->assertOk();
    }

    public function test_a_deleted_type_can_be_restored(): void
    {
        $type = DocumentType::query()->where('code', 'MEMO')->sole();
        $type->delete();

        $this->actingAs($this->admin())
            ->post("/document-types/{$type->id}/restore")
            ->assertRedirect('/document-types');

        $this->assertNull($type->fresh()->deleted_at);
        $this->assertTrue(DocumentType::selectable()->contains('id', $type->id));
    }

    public function test_a_deleted_type_is_not_offered_for_upload(): void
    {
        $type = DocumentType::query()->where('code', 'MEMO')->sole();
        $type->delete();

        $this->actingAs(User::factory()->create())->post('/documents', [
            'title' => 'Memo',
            'document_type_id' => $type->id,
            'visibility' => 'public',
            'file' => UploadedFile::fake()->create('memo.pdf', 8, 'application/pdf'),
        ])->assertSessionHasErrors('document_type_id');
    }

    // ------------------------------------------------- the immutable code

    /**
     * Every code this type has issued carries the old abbreviation, and those
     * are printed on labels stuck to physical folders. Changing it would not
     * correct history — it would make the labels disagree with the system.
     */
    public function test_the_abbreviation_cannot_be_changed_once_it_has_issued_a_code(): void
    {
        $user = User::factory()->create();
        $type = DocumentType::query()->where('code', 'MEMO')->sole();
        $document = $this->upload($user, $type);

        $this->actingAs($this->admin())->put("/document-types/{$type->id}", [
            'code' => 'CHANGED',
            'name' => 'Memo',
            'is_active' => true,
            'position' => $type->position,
        ])->assertRedirect();

        $this->assertSame('MEMO', $type->fresh()->code, 'A disabled input is a suggestion; the server is the rule.');
        $this->assertStringStartsWith('MEMO-', $document->fresh()->code);
    }

    public function test_the_abbreviation_can_be_corrected_before_anything_uses_it(): void
    {
        $this->actingAs($this->admin())->post('/document-types', [
            'code' => 'BREIF', 'name' => 'Policy Brief', 'is_active' => true, 'position' => 50,
        ]);

        $type = DocumentType::query()->where('name', 'Policy Brief')->sole();

        $this->actingAs($this->admin())->put("/document-types/{$type->id}", [
            'code' => 'BRIEF', 'name' => 'Policy Brief', 'is_active' => true, 'position' => 50,
        ])->assertRedirect();

        $this->assertSame('BRIEF', $type->fresh()->code);
    }

    /**
     * A code must stay unique across deleted types too: reusing one would make
     * two different types indistinguishable in the register, where the only
     * record of the type is the prefix inside a tracking code.
     */
    public function test_a_deleted_types_code_cannot_be_reused(): void
    {
        $type = DocumentType::query()->where('code', 'MEMO')->sole();
        $type->delete();

        $this->actingAs($this->admin())->post('/document-types', [
            'code' => 'MEMO', 'name' => 'Memo Again', 'is_active' => true, 'position' => 1,
        ])->assertSessionHasErrors('code');
    }

    /** Renaming a type does not relabel documents already filed under it. */
    public function test_renaming_a_type_does_not_rewrite_what_documents_were_filed_as(): void
    {
        $user = User::factory()->create();
        $type = DocumentType::query()->where('code', 'MEMO')->sole();
        $document = $this->upload($user, $type);

        $this->actingAs($this->admin())->put("/document-types/{$type->id}", [
            'code' => 'MEMO', 'name' => 'Internal Memorandum', 'is_active' => true, 'position' => $type->position,
        ])->assertRedirect();

        $this->assertSame('Memo', $document->fresh()->type);
        $this->assertSame('Internal Memorandum', $type->fresh()->name);
    }
}
