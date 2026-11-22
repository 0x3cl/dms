<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentSeries;
use App\Models\DocumentType;
use App\Models\DocumentTypeGroup;
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
            // Back to the set it belongs to: a type with no group lives in
            // "Other", which is a real page rather than a gap.
        ])->assertRedirect('/document-types/group/other');

        $type = DocumentType::query()->where('name', 'Policy Brief')->sole();

        // Uppercased on save, because the code is printed and compared.
        $this->assertSame('BRIEF', $type->code);
        $this->assertTrue(DocumentType::selectable()->contains('id', $type->id));
    }

    /**
     * Types are offered under headings.
     *
     * The list had grown past the point where a flat dropdown is a list of
     * choices and become a wall somebody reads top to bottom. The grouping is
     * built on the server because the upload form and the file manager's
     * tracking dialog both offer it, and two hand-built groupings is how one
     * screen quietly offers a type the other does not.
     */
    public function test_types_are_offered_under_their_groups(): void
    {
        $grouped = DocumentType::grouped();

        $this->assertNotEmpty($grouped);
        $this->assertSame('Documents', $grouped[0]['label']);
        $this->assertContains('MEMO', array_column($grouped[0]['options'], 'code'));

        // Every seeded group is offered, in its own order.
        $labels = array_column($grouped, 'label');
        $this->assertSame(['Documents', 'Assets', 'Images', 'Videos'], $labels);
    }

    /**
     * A type with no group is listed under "Other" rather than vanishing.
     *
     * That is what a *deleted* group must produce: `group_id` is
     * `nullOnDelete`, because a group is an arrangement of the picker and a
     * type is part of the office's record — deleting the first must never
     * retire the second.
     */
    public function test_an_ungrouped_type_falls_under_other_and_last(): void
    {
        $group = DocumentTypeGroup::query()->where('code', 'VID')->sole();
        $type = DocumentType::query()->create([
            'code' => 'LOOSE', 'name' => 'Unfiled Thing', 'is_active' => true, 'position' => 999,
            'group_id' => $group->id,
        ]);

        $group->delete();

        $this->assertNull($type->fresh()->group_id);
        $this->assertNotNull($type->fresh(), 'Deleting a group must not delete its types.');

        $grouped = DocumentType::grouped();
        $last = end($grouped);

        $this->assertSame('Other', $last['label']);
        $this->assertContains('LOOSE', array_column($last['options'], 'code'));
    }

    /**
     * A group is created, renamed and deleted on its own, like the parent of
     * a type that it is.
     *
     * It began as a name typed into the type form, which meant a group could
     * be invented and then never renamed, reordered or removed — a list the
     * office is stuck with the first time somebody mistypes a heading.
     */
    public function test_a_group_has_its_own_crud(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/document-type-groups', [
            'name' => 'Governance',
            'description' => 'Board and management papers.',
            'position' => 9,
        ])->assertRedirect('/document-types?tab=groups');

        $group = DocumentTypeGroup::query()->where('name', 'Governance')->sole();

        // Derived, not asked for: unlike a type's code, a group's never
        // reaches a tracking code, so there is nothing to decide.
        $this->assertSame('GOVERNANCE', $group->code);

        $this->actingAs($admin)->put("/document-type-groups/{$group->id}", [
            'name' => 'Governance Papers',
            'position' => 3,
        ])->assertSessionHasNoErrors();

        $group->refresh();

        $this->assertSame('Governance Papers', $group->name);
        $this->assertSame(3, $group->position);
        // The handle does not follow the label: a seeder matches on it, so
        // regenerating it would make the next run recreate this group.
        $this->assertSame('GOVERNANCE', $group->code);
    }

    /** A type is filed under an existing group, chosen by id. */
    public function test_a_type_is_filed_under_a_group(): void
    {
        $group = DocumentTypeGroup::query()->where('code', 'ASSET')->sole();

        $this->actingAs($this->admin())->post('/document-types', [
            'code' => 'minutes',
            'name' => 'Minutes of the Meeting',
            'is_active' => true,
            'position' => 60,
            'group_id' => $group->id,
        ])->assertRedirect("/document-types/group/{$group->id}");

        $this->assertSame($group->id, DocumentType::query()->where('code', 'MINUTES')->value('group_id'));
    }

    /** Deleting a group moves its types to Other; it never retires one. */
    public function test_deleting_a_group_keeps_its_types(): void
    {
        $group = DocumentTypeGroup::query()->where('code', 'IMG')->sole();
        $types = DocumentType::query()->where('group_id', $group->id)->pluck('id');

        $this->assertNotEmpty($types);

        $this->actingAs($this->admin())
            ->delete("/document-type-groups/{$group->id}")
            ->assertRedirect('/document-types?tab=groups');

        $this->assertDatabaseMissing('document_type_groups', ['id' => $group->id]);
        $this->assertSame($types->count(), DocumentType::query()->whereIn('id', $types)->whereNull('group_id')->count());
    }

    /**
     * The module opens on the groups, and a group is opened to see its types.
     *
     * A flat table of every type answered the wrong question first: an office
     * looking at twenty rows wants to know what *kinds* of thing it files
     * before which exact type.
     */
    public function test_the_module_opens_on_the_groups(): void
    {
        $props = $this->actingAs($this->admin())->get('/document-types')
            ->assertOk()->viewData('page')['props'];

        $this->assertContains('Documents', array_column($props['groups'], 'name'));
        // The count somebody is looking for is what is on offer today.
        $this->assertGreaterThan(0, $props['groups'][0]['active_types_count']);
    }

    /** A group's page lists its types and nobody else's. */
    public function test_a_group_lists_only_its_own_types(): void
    {
        $group = DocumentTypeGroup::query()->where('code', 'VID')->sole();

        $codes = array_column(
            $this->actingAs($this->admin())->get("/document-types/group/{$group->id}")
                ->assertOk()->viewData('page')['props']['types']['data'],
            'code',
        );

        $this->assertContains('VIDEO', $codes);
        $this->assertNotContains('MEMO', $codes);
    }

    /**
     * The ungrouped are a set with a page, not a gap.
     *
     * `group_id` is `nullOnDelete`, so deleting a group produces them — and a
     * set that exists and cannot be opened is how rows go missing from a
     * screen that looks complete.
     */
    public function test_the_ungrouped_types_are_reachable(): void
    {
        $loose = DocumentType::query()->create([
            'code' => 'LOOSE', 'name' => 'Unfiled Thing', 'is_active' => true, 'position' => 999,
        ]);

        $props = $this->actingAs($this->admin())->get('/document-types/group/other')
            ->assertOk()->viewData('page')['props'];

        $this->assertContains($loose->code, array_column($props['types']['data'], 'code'));
        $this->assertFalse($props['group']['editable']);

        // And the landing says the set is there to be opened.
        $this->assertSame(
            1,
            $this->actingAs($this->admin())->get('/document-types')
                ->viewData('page')['props']['ungrouped']['types_count'],
        );
    }

    /** An unknown set is a 404, not an empty list that looks like an answer. */
    public function test_an_unknown_group_is_not_found(): void
    {
        $this->actingAs($this->admin())->get('/document-types/group/nonsense')->assertNotFound();
        $this->actingAs($this->admin())->get('/document-types/group/999999')->assertNotFound();
    }

    /** Adding a type from inside a group opens the form already filed there. */
    public function test_the_add_form_preselects_the_group_it_came_from(): void
    {
        $group = DocumentTypeGroup::query()->where('code', 'ASSET')->sole();

        $props = $this->actingAs($this->admin())->get("/document-types/create?group={$group->id}")
            ->assertOk()->viewData('page')['props'];

        $this->assertSame($group->id, $props['presetGroup']);
    }

    /** Two groups with one name is two identical headings in the picker. */
    public function test_a_group_name_is_unique(): void
    {
        $this->actingAs($this->admin())->post('/document-type-groups', [
            'name' => 'Videos',
            'position' => 1,
        ])->assertSessionHasErrors('name');
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
            // `back()`: delete is pressed from a list that may be searched,
            // filtered and on page three, and the person should stay there.
            ->from('/document-types/group/all')
            ->delete("/document-types/{$type->id}")
            ->assertRedirect('/document-types/group/all');

        $this->assertSoftDeleted($type);

        $after = $document->fresh();

        // Every column identical, the type link included.
        $this->assertSame($before, $after->only(array_keys($before)));
        $this->assertSame($type->name, $after->type, 'The snapshot is what every screen renders.');
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

        $this->assertSame($type->name, $payload['type']);
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
        $this->assertSame($type->name, $after->type);

        $this->actingAs($user)->get("/documents/view/{$document->code}")->assertOk();
    }

    public function test_a_deleted_type_can_be_restored(): void
    {
        $type = DocumentType::query()->where('code', 'MEMO')->sole();
        $type->delete();

        $this->actingAs($this->admin())
            ->from('/document-types/group/all')
            ->post("/document-types/{$type->id}/restore")
            ->assertRedirect('/document-types/group/all');

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

        // Captured before the rename, because that is the whole assertion:
        // the snapshot keeps what the type was called when the document was
        // filed, whatever it is called now.
        $filedAs = $type->name;

        $this->actingAs($this->admin())->put("/document-types/{$type->id}", [
            'code' => 'MEMO', 'name' => 'Internal Memorandum', 'is_active' => true, 'position' => $type->position,
        ])->assertRedirect();

        $this->assertSame($filedAs, $document->fresh()->type);
        $this->assertSame('Internal Memorandum', $type->fresh()->name);
    }
}
