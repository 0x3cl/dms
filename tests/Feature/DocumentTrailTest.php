<?php

namespace Tests\Feature;

use App\Enums\DocumentAction;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentDownload;
use App\Models\DocumentLog;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The audit trail's guarantees.
 *
 * `document_logs` used to store only foreign keys, so the history was
 * rendered by joining live rows: renaming a division silently rewrote where a
 * document had been, deleting one blanked it, and deleting an account took the
 * document with it. An audit trail that changes when the org chart changes is
 * not an audit trail, so each row now snapshots who the actor was and which
 * office they sat in at the time.
 */
class DocumentTrailTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        $section = Section::factory()->create(['name' => 'Records Section']);

        return User::factory()->inSection($section)->create(['first_name' => 'Ana', 'last_name' => 'Cruz']);
    }

    public function test_a_trail_entry_snapshots_the_actor_and_their_office(): void
    {
        $actor = $this->actor();
        $document = Document::factory()->create();

        $log = DocumentLog::record($document, DocumentAction::Acknowledged, $actor, 'Passed to Records.');

        $this->assertSame('Ana Cruz', $log->actor_name);
        $this->assertSame($actor->employee_id, $log->actor_employee_id);
        $this->assertSame('Records Section', $log->section_name);
        $this->assertSame($actor->division->name, $log->division_name);
        $this->assertSame(DocumentAction::Acknowledged, $log->action);
        $this->assertSame('Acknowledged / Forwarded', $log->action_label);
    }

    public function test_renaming_a_division_does_not_rewrite_the_history(): void
    {
        $actor = $this->actor();
        $log = DocumentLog::record(Document::factory()->create(), DocumentAction::Viewed, $actor);
        $originalName = $log->division_name;

        $actor->division->update(['name' => 'Renamed Division']);

        $this->assertSame($originalName, $log->fresh()->division_name);
        $this->assertNotSame('Renamed Division', $log->fresh()->division_name);
    }

    public function test_deleting_a_division_leaves_the_history_readable(): void
    {
        $actor = $this->actor();
        $log = DocumentLog::record(Document::factory()->create(), DocumentAction::Viewed, $actor);

        // forceDelete, not delete: divisions are soft-deleted now, and a soft
        // delete deliberately keeps the key intact. This asserts the harder
        // guarantee — that the history still reads after the row is gone for
        // good, which is what `nullOnDelete` beside a snapshot is for.
        $actor->division->forceDelete();
        $log = $log->fresh();

        // The key is cleared, because the office is gone; the name it had is
        // what the history renders, and that stays.
        $this->assertNull($log->division_id);
        $this->assertSame('Records Section', $log->section_name);
        $this->assertNotNull($log->division_name);
    }

    /**
     * Closing an account keeps everything it owned, **and** keeps the link.
     *
     * `users` is soft-deleted, so the account cannot sign in — the auth
     * provider applies the model's global scopes — while `documents.user_id`
     * still points at it. The document therefore still has an owner who can be
     * identified and transferred, rather than becoming ownerless the moment
     * somebody closes their account.
     */
    public function test_closing_an_account_keeps_the_document_and_its_owner(): void
    {
        $actor = $this->actor();
        $document = Document::factory()->for($actor)->create(['uploader_name' => $actor->name]);
        $log = DocumentLog::record($document, DocumentAction::Downloaded, $actor);
        $download = DocumentDownload::register($document, $actor, $log);

        $actor->delete();

        $this->assertSoftDeleted($actor);
        $this->assertModelExists($document);
        $this->assertSame($actor->id, $document->fresh()->user_id, 'A soft delete keeps the link.');
        $this->assertSame('Ana Cruz', $document->fresh()->uploaderName());

        $this->assertModelExists($log);
        $this->assertSame('Ana Cruz', $log->fresh()->actor_name);

        $this->assertModelExists($download);
        $this->assertSame('Ana Cruz', $download->fresh()->user_name);
    }

    /**
     * And the harder guarantee: erasing the account for good still leaves the
     * document and the whole trail standing.
     *
     * `documents.user_id` used to **cascade**, so deleting an account — which
     * /profile lets a user do to their own — deleted every document that
     * account had uploaded, including ones other offices were still tracking.
     */
    public function test_erasing_an_account_still_leaves_the_trail_and_the_document_standing(): void
    {
        $actor = $this->actor();
        $document = Document::factory()->for($actor)->create(['uploader_name' => $actor->name]);
        $log = DocumentLog::record($document, DocumentAction::Downloaded, $actor);
        $download = DocumentDownload::register($document, $actor, $log);

        $actor->forceDelete();

        $this->assertModelExists($document);
        $this->assertNull($document->fresh()->user_id);
        $this->assertSame('Ana Cruz', $document->fresh()->uploaderName());

        $this->assertModelExists($log);
        $this->assertSame('Ana Cruz', $log->fresh()->actor_name);
        $this->assertNull($log->fresh()->actor_id);

        $this->assertModelExists($download);
        $this->assertSame('Ana Cruz', $download->fresh()->user_name);
    }

    public function test_a_download_registers_a_release_tied_to_its_trail_entry(): void
    {
        $actor = $this->actor();
        $document = Document::factory()->create();

        $log = DocumentLog::record($document, DocumentAction::Downloaded, $actor);
        $download = DocumentDownload::register($document, $actor, $log, '203.0.113.7');

        $this->assertTrue($download->documentLog->is($log));
        $this->assertSame('203.0.113.7', $download->ip_address);
    }

    public function test_the_history_page_renders_without_eager_loading_the_actor(): void
    {
        $actor = $this->actor();
        $document = Document::factory()->create();
        DocumentLog::record($document, DocumentAction::Acknowledged, $actor);

        $this->actingAs($actor)
            ->get("/documents/{$document->id}/logs")
            ->assertOk();
    }

    /**
     * `Division` and `Section` are reachable from a trail entry for filtering
     * and for following a row back to a live office — the page just does not
     * use them to render.
     */
    public function test_a_trail_entry_still_points_at_the_live_office(): void
    {
        $actor = $this->actor();
        $log = DocumentLog::record(Document::factory()->create(), DocumentAction::Viewed, $actor);

        $this->assertInstanceOf(Division::class, $log->division);
        $this->assertInstanceOf(Section::class, $log->section);
        $this->assertTrue($log->actor->is($actor));
    }
}
