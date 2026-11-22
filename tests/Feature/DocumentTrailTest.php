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

    /**
     * A logged action repeats, because the events it records repeat.
     *
     * Two rules used to sit on this endpoint: Received once per person per
     * document, and no two consecutive acknowledgements. Both reasoned from
     * the shape of the fact and both were wrong about the office — a
     * document reaches the same person as paper on Monday and as a re-sent
     * copy on Friday, and the two rows are not duplicates: they carry
     * different timestamps and usually different remarks, which is the
     * information a hand-off trail exists to hold.
     *
     * Refusing the entry never made the event un-happen; it made the trail
     * silent about it.
     */
    public function test_a_person_may_log_the_same_action_more_than_once(): void
    {
        $owner = User::factory()->create();
        $document = Document::factory()->ofType('MEMO')->for($owner)->create([
            'uploader_name' => $owner->name,
            'visibility' => 'public',
        ]);

        foreach ([DocumentAction::Received, DocumentAction::Received, DocumentAction::Acknowledged, DocumentAction::Acknowledged] as $action) {
            $this->actingAs($owner)
                ->post('/document/log', [
                    'document_id' => $document->id,
                    'action' => $action->value,
                    'remarks' => 'Came back for signature.',
                ])
                ->assertSessionHasNoErrors()
                ->assertSessionHas('success');
        }

        $this->assertSame(2, $document->logs()->where('action', DocumentAction::Received)->count());
        $this->assertSame(2, $document->logs()->where('action', DocumentAction::Acknowledged)->count());
    }

    /**
     * What a client still may not claim.
     *
     * The protection that matters is not how often somebody logs their own
     * work, it is that the actions the **system** observes cannot be posted
     * at all: a client claiming a download does not make bytes have left
     * the server.
     */
    public function test_a_client_cannot_post_an_action_the_system_observes(): void
    {
        $owner = User::factory()->create();
        $document = Document::factory()->ofType('MEMO')->for($owner)->create([
            'uploader_name' => $owner->name,
            'visibility' => 'public',
        ]);

        foreach ([DocumentAction::Downloaded, DocumentAction::Viewed, DocumentAction::Uploaded] as $action) {
            $this->actingAs($owner)
                ->post('/document/log', ['document_id' => $document->id, 'action' => $action->value])
                ->assertSessionHasErrors('action');
        }

        $this->assertSame(0, $document->logs()->count());
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

    // ------------------------------------------------- the trail is a list

    /**
     * The panel used to ship **every** row a document had ever collected.
     * On a long-running document that is a payload nobody reads the bottom of,
     * growing without limit.
     */
    public function test_the_trail_is_paginated_at_twelve(): void
    {
        $owner = User::factory()->create();
        $document = Document::factory()->create(['user_id' => $owner->id, 'visibility' => 'public']);

        foreach (range(1, 20) as $i) {
            DocumentLog::record($document, DocumentAction::Viewed, $owner, "entry {$i}");
        }

        $logs = $this->actingAs($owner)
            ->get("/documents/view/{$document->code}")
            ->assertOk()
            ->viewData('page')['props']['logs'];

        $this->assertCount(12, $logs['data']);
        $this->assertSame(20, $logs['total']);
    }

    /** Searching reads the snapshot columns, so it is one table and no joins. */
    public function test_the_trail_can_be_searched_by_person_and_remarks(): void
    {
        $owner = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Reyes']);
        $other = User::factory()->create(['first_name' => 'Ben', 'last_name' => 'Cruz']);
        $document = Document::factory()->create(['user_id' => $owner->id, 'visibility' => 'public']);

        DocumentLog::record($document, DocumentAction::Reviewed, $owner, 'checked the annexes');
        DocumentLog::record($document, DocumentAction::Reviewed, $other, 'nothing to add');

        $byPerson = $this->actingAs($owner)
            ->get("/documents/view/{$document->code}?search=Reyes")
            ->viewData('page')['props']['logs'];
        $this->assertCount(1, $byPerson['data']);
        $this->assertSame('Ana Reyes', $byPerson['data'][0]['actor_name']);

        $byRemark = $this->actingAs($owner)
            ->get("/documents/view/{$document->code}?search=annexes")
            ->viewData('page')['props']['logs'];
        $this->assertCount(1, $byRemark['data']);
    }

    /**
     * The filter offers every action a trail can contain, not only the
     * postable ones — a filter that cannot find an upload or a download is a
     * filter that lies about what the history holds.
     */
    public function test_the_trail_can_be_filtered_by_action(): void
    {
        $owner = User::factory()->create();
        $document = Document::factory()->create(['user_id' => $owner->id, 'visibility' => 'public']);

        DocumentLog::record($document, DocumentAction::Uploaded, $owner);
        DocumentLog::record($document, DocumentAction::Reviewed, $owner);
        DocumentLog::record($document, DocumentAction::Reviewed, $owner);

        $props = $this->actingAs($owner)
            ->get("/documents/view/{$document->code}?action=reviewed")
            ->viewData('page')['props'];

        $this->assertCount(2, $props['logs']['data']);
        $this->assertContains('uploaded', array_column($props['logActions'], 'value'));
    }

    /**
     * The trail filters on dates, office and page size as well as search and
     * action — the same vocabulary the activity report has, because they are
     * the same kind of question asked of two tables.
     *
     * Every one of them reads a snapshot column, so this is one table and no
     * joins. The office filter in particular narrows by where the actor sat
     * **when it happened**, which is the only answer an audit trail may give.
     */
    public function test_the_trail_filters_on_dates_office_and_page_size(): void
    {
        $owner = User::factory()->create();
        $document = Document::factory()->create(['user_id' => $owner->id, 'visibility' => 'public']);

        $old = DocumentLog::record($document, DocumentAction::Viewed, $owner, 'last year');
        $old->forceFill(['occurred_at' => now()->subYear()])->save();

        DocumentLog::record($document, DocumentAction::Viewed, $owner, 'today');

        $recent = $this->actingAs($owner)
            ->get("/documents/view/{$document->code}?from=".now()->subWeek()->toDateString())
            ->viewData('page')['props']['logs'];
        $this->assertCount(1, $recent['data']);
        $this->assertSame('today', $recent['data'][0]['remarks']);

        $older = $this->actingAs($owner)
            ->get("/documents/view/{$document->code}?to=".now()->subMonth()->toDateString())
            ->viewData('page')['props']['logs'];
        $this->assertCount(1, $older['data']);
        $this->assertSame('last year', $older['data'][0]['remarks']);

        // A page size the UI does not offer falls back to the default rather
        // than being honoured: the server decides what it will paginate at.
        $props = $this->actingAs($owner)
            ->get("/documents/view/{$document->code}?entries=25")
            ->viewData('page')['props'];
        $this->assertSame(25, $props['logs']['per_page']);

        $this->assertSame(
            12,
            $this->actingAs($owner)
                ->get("/documents/view/{$document->code}?entries=999")
                ->viewData('page')['props']['logs']['per_page'],
        );
    }

    /** The office filter narrows by the snapshot, and only offers what exists. */
    public function test_the_trail_filters_by_the_office_recorded_on_the_row(): void
    {
        $records = Section::factory()->create(['name' => 'Records Section']);
        $owner = User::factory()->inSection($records)->create();
        $document = Document::factory()->create(['user_id' => $owner->id, 'visibility' => 'public']);

        DocumentLog::record($document, DocumentAction::Viewed, $owner, 'from records');
        DocumentLog::record($document, DocumentAction::Viewed, User::factory()->create(), 'from nowhere');

        $office = $owner->division?->name ?? $records->division->name;

        $props = $this->actingAs($owner)
            ->get("/documents/view/{$document->code}?office=".urlencode($office))
            ->viewData('page')['props'];

        $this->assertCount(1, $props['logs']['data']);
        $this->assertSame('from records', $props['logs']['data'][0]['remarks']);
        $this->assertContains($office, $props['logOffices']);
    }

    /**
     * An unknown action empties nothing.
     *
     * Filtering on a value the enum does not know would otherwise return zero
     * rows and read on screen as "this document has no history", which is a
     * far worse answer than ignoring a bad parameter.
     */
    public function test_an_unknown_action_filter_is_ignored_rather_than_emptying_the_trail(): void
    {
        $owner = User::factory()->create();
        $document = Document::factory()->create(['user_id' => $owner->id, 'visibility' => 'public']);
        DocumentLog::record($document, DocumentAction::Uploaded, $owner);

        $logs = $this->actingAs($owner)
            ->get("/documents/view/{$document->code}?action=not-an-action")
            ->viewData('page')['props']['logs'];

        $this->assertCount(1, $logs['data']);
    }

    /**
     * The actor's face is snapshotted like their name, not joined.
     *
     * Joining the live account would mean the trail silently changes when
     * somebody updates their profile, and renders nothing once it is erased.
     */
    public function test_a_trail_entry_snapshots_the_actors_face(): void
    {
        $actor = User::factory()->create(['avatar_seed' => 'seed-at-the-time']);
        $document = Document::factory()->create(['user_id' => $actor->id, 'visibility' => 'public']);

        $log = DocumentLog::record($document, DocumentAction::Reviewed, $actor);

        $this->assertSame('seed-at-the-time', $log->actor_avatar_seed);

        $actor->update(['avatar_seed' => 'changed-later']);

        $this->assertSame('seed-at-the-time', $log->fresh()->actor_avatar_seed);
    }

    /** Each action reads apart at a glance, from the enum and not a template. */
    public function test_each_action_carries_its_own_icon_and_tone(): void
    {
        $owner = User::factory()->create();
        $document = Document::factory()->create(['user_id' => $owner->id, 'visibility' => 'public']);

        $received = DocumentLog::record($document, DocumentAction::Received, $owner)->toArray();
        $returned = DocumentLog::record($document, DocumentAction::Returned, $owner)->toArray();

        $this->assertNotSame($received['action_icon'], $returned['action_icon']);
        $this->assertNotSame($received['action_tone'], $returned['action_tone']);
        $this->assertStringNotContainsString('dark:', $received['action_tone']);
    }
}
