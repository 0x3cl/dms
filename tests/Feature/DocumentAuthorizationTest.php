<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression cover for the document access rules.
 *
 * Every one of these described a real hole: track-by-code skipped the
 * visibility gate entirely, and edit/update/destroy had no ownership check at
 * all, so any authenticated user could rewrite or delete any document.
 */
class DocumentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function document(User $owner, string $visibility = 'private'): Document
    {
        return Document::factory()
            ->ofType('CONFID')
            ->for($owner)
            ->create([
                'title' => 'Case File',
                'file_path' => 'documents/case-file.pdf',
                'uploader_name' => $owner->name,
                'visibility' => $visibility,
            ]);
    }

    public function test_track_by_code_refuses_a_private_document_to_a_stranger(): void
    {
        $document = $this->document(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->get("/documents/track?code={$document->code}")
            ->assertRedirect('/dashboard')
            ->assertSessionHas('error');
    }

    public function test_track_by_code_allows_a_named_recipient(): void
    {
        $document = $this->document(User::factory()->create());
        $recipient = User::factory()->create();
        $document->recipients()->attach($recipient);

        $this->actingAs($recipient)
            ->get("/documents/track?code={$document->code}")
            ->assertOk();
    }

    public function test_a_public_document_is_readable_by_anyone_with_the_code(): void
    {
        $document = $this->document(User::factory()->create(), 'public');

        $this->actingAs(User::factory()->create())
            ->get("/documents/view/{$document->code}")
            ->assertOk();
    }

    public function test_only_the_uploader_can_edit_a_document(): void
    {
        $document = $this->document(User::factory()->create(), 'public');

        $this->actingAs(User::factory()->create())
            ->get("/documents/{$document->id}/edit")
            ->assertRedirect('/dashboard');
    }

    public function test_only_the_uploader_can_update_a_document(): void
    {
        $document = $this->document(User::factory()->create(), 'public');

        $this->actingAs(User::factory()->create())
            ->put("/documents/{$document->id}", [
                'title' => 'Rewritten',
                'document_type_id' => DocumentType::query()->where('code', 'MEMO')->value('id'),
            ])
            ->assertRedirect('/dashboard');

        $this->assertSame('Case File', $document->fresh()->title);
    }

    public function test_only_the_uploader_can_delete_a_document(): void
    {
        $document = $this->document(User::factory()->create(), 'public');

        $this->actingAs(User::factory()->create())
            ->delete("/documents/{$document->id}")
            ->assertRedirect('/dashboard');

        $this->assertModelExists($document);
    }

    public function test_the_qr_label_route_refuses_a_filename_no_document_owns(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/documents/qr/download/'.urlencode('../../../.env'))
            ->assertNotFound();
    }

    /**
     * The hand-off endpoint validated `action` as `string|max:255`, so a
     * client could post any text it liked — including a forged "received"
     * entry it had not earned. Only the three actions a person performs by
     * hand are accepted now.
     */
    public function test_the_hand_off_endpoint_refuses_an_action_a_person_cannot_perform(): void
    {
        $owner = User::factory()->create();
        $document = $this->document($owner, 'public');

        $this->actingAs($owner)
            ->post('/document/log', [
                'document_id' => $document->id,
                'action' => 'received',
            ])
            ->assertSessionHasErrors('action');

        $this->actingAs($owner)
            ->post('/document/log', [
                'document_id' => $document->id,
                'action' => 'Anything at all',
            ])
            ->assertSessionHasErrors('action');

        $this->assertSame(0, $document->logs()->count());
    }

    public function test_a_document_history_is_refused_to_someone_who_cannot_read_the_document(): void
    {
        $document = $this->document(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->get("/documents/{$document->id}/logs")
            ->assertRedirect();
    }
}
