<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Withdrawing a document, and the way back.
 *
 * `documents` has been soft-deleted from the start, so a withdrawn document
 * was always still in the table — it simply appeared on no screen and had no
 * restore, which made Delete a one-way door whose only undo was a
 * hand-written UPDATE. These tests are the contract for the other half: the
 * row is reachable on the register's Deleted tab, restoring it is the exact
 * inverse of deleting it, and nothing else about the document was altered in
 * between.
 */
class DocumentTrashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('public');
    }

    /** @return array{Document, User, User} document, uploader, tagged reader */
    private function scene(): array
    {
        $uploader = User::factory()->superadmin()->create();
        $tagged = User::factory()->create();

        $this->actingAs($uploader)->post('/documents', [
            'title' => 'Case File',
            'document_type_id' => DocumentType::query()->where('code', 'MEMO')->value('id'),
            'visibility' => 'private',
            'file' => UploadedFile::fake()->create('case.pdf', 16, 'application/pdf'),
            'recipients' => [$tagged->id],
        ])->assertSessionHasNoErrors();

        return [Document::query()->latest('id')->firstOrFail(), $uploader, $tagged];
    }

    private function props(User $user, string $query = '')
    {
        return $this->actingAs($user)->get('/documents'.$query)->assertOk()->viewData('page')['props'];
    }

    public function test_a_deleted_document_is_listed_on_the_deleted_tab_and_nowhere_else(): void
    {
        [$document, $uploader] = $this->scene();

        $this->actingAs($uploader)->delete("/documents/{$document->id}")->assertRedirect();

        $this->assertSoftDeleted('documents', ['id' => $document->id]);

        $mine = $this->props($uploader, '?tab=mine');
        $this->assertSame([], collect($mine['documents']['data'])->pluck('id')->all());
        $this->assertSame(1, $mine['counts']['deleted']);

        $deleted = $this->props($uploader, '?tab=deleted');
        $this->assertSame([$document->id], collect($deleted['documents']['data'])->pluck('id')->all());
    }

    /**
     * The trash is the uploader's own, even for an administrator: restoring
     * mirrors deleting, and what somebody took off the register is a decision
     * rather than something the register goes on showing to everybody.
     */
    public function test_the_deleted_tab_does_not_show_somebody_elses_withdrawn_documents(): void
    {
        [$document, $uploader] = $this->scene();
        $other = User::factory()->superadmin()->create();

        $this->actingAs($uploader)->delete("/documents/{$document->id}");

        $props = $this->props($other, '?tab=deleted');

        $this->assertSame([], collect($props['documents']['data'])->pluck('id')->all());
        $this->assertSame(0, $props['counts']['deleted']);
    }

    /**
     * Restoring is clearing `deleted_at` and nothing else — which is the
     * point of the delete having been soft. The code, the file, the tagging
     * and the trail were never altered, so the document comes back as the
     * same document rather than as a copy of one.
     */
    public function test_restoring_puts_the_document_back_exactly_as_it_was(): void
    {
        [$document, $uploader, $tagged] = $this->scene();
        $code = $document->code;
        $fileId = $document->file_id;
        $logs = $document->logs()->count();

        $this->actingAs($uploader)->delete("/documents/{$document->id}");
        $this->actingAs($uploader)->post("/documents/{$document->id}/restore")
            ->assertSessionHasNoErrors();

        $restored = Document::query()->findOrFail($document->id);

        $this->assertNull($restored->deleted_at);
        $this->assertSame($code, $restored->code);
        $this->assertSame($fileId, $restored->file_id);
        $this->assertSame($logs, $restored->logs()->count(), 'a restore must not add to the trail');
        $this->assertTrue($restored->recipients->contains($tagged));

        // And it opens again for the person it was shared with.
        $this->assertSame(
            'documents/show',
            $this->actingAs($tagged)->get("/documents/view/{$code}")->viewData('page')['component'],
        );
    }

    public function test_only_the_uploader_may_restore(): void
    {
        [$document, $uploader, $tagged] = $this->scene();

        $this->actingAs($uploader)->delete("/documents/{$document->id}");

        $this->actingAs($tagged)->post("/documents/{$document->id}/restore")
            ->assertRedirect(route('dashboard'));

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    /**
     * The route binds `withTrashed()`. Without it the model binding applies
     * the global scope and the one route that exists to reach a withdrawn
     * document 404s on every one of them.
     */
    public function test_the_restore_route_can_resolve_a_trashed_document(): void
    {
        [$document, $uploader] = $this->scene();

        $this->actingAs($uploader)->delete("/documents/{$document->id}");

        $this->actingAs($uploader)->post("/documents/{$document->id}/restore")
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    /** A second restore is a no-op, because the usual way to send one is a double-click. */
    public function test_restoring_a_live_document_is_refused_without_changing_anything(): void
    {
        [$document, $uploader] = $this->scene();

        $this->actingAs($uploader)->post("/documents/{$document->id}/restore")
            ->assertSessionHas('error');

        $this->assertNull($document->fresh()->deleted_at);
    }

    /**
     * The tab is a filter on one screen, so an unknown or unreachable one
     * degrades to "Uploaded by me" rather than refusing the page — the same
     * rule the All tab follows.
     */
    public function test_an_unknown_tab_still_renders_the_register(): void
    {
        [, $uploader] = $this->scene();

        $this->assertSame('mine', $this->props($uploader, '?tab=nonsense')['filters']['tab']);
    }

    // ------------------------------------------------------- the Trash screen

    /**
     * The Trash is the screen named after the question, so everything
     * soft-deleted has to be on it — a withdrawn document included.
     */
    public function test_a_withdrawn_document_is_listed_in_the_trash(): void
    {
        [$document, $uploader] = $this->scene();

        $this->actingAs($uploader)->delete("/documents/{$document->id}");

        $props = $this->actingAs($uploader)->get('/files/trash')->assertOk()->viewData('page')['props'];

        $this->assertSame([$document->id], collect($props['documents'])->pluck('id')->all());
        $this->assertSame($document->code, $props['documents'][0]['code']);
        // Per row, like every other permission this module ships: the grid
        // and the table read each row's own.
        $this->assertTrue($props['documents'][0]['can_restore']);
    }

    /** And it can be restored from there, through the documents module's own route. */
    public function test_a_withdrawn_document_can_be_restored_from_the_trash(): void
    {
        [$document, $uploader] = $this->scene();

        $this->actingAs($uploader)->delete("/documents/{$document->id}");
        $this->actingAs($uploader)->post("/documents/{$document->id}/restore")->assertSessionHasNoErrors();

        $props = $this->actingAs($uploader)->get('/files/trash')->assertOk()->viewData('page')['props'];

        $this->assertSame([], $props['documents']);
    }

    /** Somebody else's withdrawals are not in your trash, as they are not on your tab. */
    public function test_the_trash_lists_only_your_own_withdrawn_documents(): void
    {
        [$document, $uploader] = $this->scene();
        $other = User::factory()->superadmin()->create();

        $this->actingAs($uploader)->delete("/documents/{$document->id}");

        $props = $this->actingAs($other)->get('/files/trash')->assertOk()->viewData('page')['props'];

        $this->assertSame([], $props['documents']);
    }

    /**
     * A document has no folder — its *file* does, and withdrawing the
     * document did not delete that file. Listing one inside a deleted folder
     * would claim a containment that does not exist.
     */
    public function test_withdrawn_documents_are_not_listed_inside_a_deleted_folder(): void
    {
        [$document, $uploader] = $this->scene();

        $this->actingAs($uploader)->delete("/documents/{$document->id}");

        // A folder of the person's own: a register upload lands at the top of
        // their storage now, so there is no folder to borrow.
        $folders = app(\App\Services\Files\FolderService::class);
        $folder = $folders->create($uploader, $folders->rootFor($uploader), 'Memoranda');

        $this->actingAs($uploader)
            ->post('/files/delete', ['items' => [['kind' => 'folder', 'public_id' => $folder->public_id]]])
            ->assertSessionHasNoErrors();

        $props = $this->actingAs($uploader)
            ->get("/files/trash?folder={$folder->public_id}")
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame([], $props['documents']);
    }

    /**
     * And the file is **not** in the trash, because withdrawing a document
     * never deleted it: the bytes stay in My Files, which is what the row on
     * the trash says.
     */
    public function test_withdrawing_a_document_does_not_trash_its_file(): void
    {
        [$document, $uploader] = $this->scene();

        $this->actingAs($uploader)->delete("/documents/{$document->id}");

        $this->assertNotSoftDeleted('files', ['id' => $document->file_id]);

        $props = $this->actingAs($uploader)->get('/files/trash')->assertOk()->viewData('page')['props'];

        $this->assertSame([], collect($props['files']['data'])->pluck('name')->all());
    }
}
