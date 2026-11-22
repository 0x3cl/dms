<?php

namespace Tests\Feature\Acceptance;

use App\Enums\DocumentAction;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentTypeGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Acceptance items 1 and 2 — filing a mixed batch, and what the office's own
 * vocabulary may do to it afterwards.
 *
 * These are written from the office's side of the screen rather than from a
 * unit's: one person files several documents of different types and formats in
 * one sitting, then somebody tidies up the type list. The claim under test is
 * that the second act cannot reach the first — a document is a record, and the
 * picker it was filed through is an arrangement of a form.
 *
 * `DocumentUploadTest` and `DocumentTypeTest` cover the mechanisms a clause at
 * a time. This covers them together, which is where a wrong answer actually
 * shows up: a type deleted a year after the documents were filed through it.
 */
class DocumentRegisterAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('public');
    }

    private function typeId(string $code): int
    {
        return DocumentType::query()->where('code', $code)->value('id');
    }

    /**
     * File one document and get back its row, so a test can say what it is
     * about rather than restate the form.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function file(User $uploader, string $typeCode, string $title, UploadedFile $file, array $overrides = []): Document
    {
        $this->actingAs($uploader)->post('/documents', [
            'title' => $title,
            'document_type_id' => $this->typeId($typeCode),
            'visibility' => 'public',
            'file' => $file,
            ...$overrides,
        ])->assertRedirect();

        return Document::query()->where('title', $title)->sole();
    }

    // ------------------------------------------------------------- item 1

    /**
     * Item 1: a morning's filing — different types, different formats.
     *
     * Each document comes away with a code carrying its *own* type's
     * abbreviation, its own label, its own opening trail entry and its own
     * bytes. The cross-cutting failure this guards is the one the series
     * service was written for: a counter that counted the day's documents
     * rather than the day's documents *of that type*, which hands the second
     * type uploaded that morning a number the first has already used.
     */
    public function test_a_mixed_batch_of_documents_each_comes_away_with_its_own_record(): void
    {
        $uploader = User::factory()->create();

        $batch = [
            ['MEMO', 'Memo on Office Hours', UploadedFile::fake()->create('memo.pdf', 16, 'application/pdf')],
            ['LETTER', 'Letter to the Director', UploadedFile::fake()->create('letter.docx', 24, 'application/zip')],
            ['MEMO', 'Memo on Parking', UploadedFile::fake()->create('parking.pdf', 12, 'application/pdf')],
            ['REPORT', 'Quarterly Report', UploadedFile::fake()->create('report.xlsx', 40, 'application/zip')],
        ];

        $filed = [];

        foreach ($batch as [$typeCode, $title, $upload]) {
            $filed[$title] = [$typeCode, $this->file($uploader, $typeCode, $title, $upload)];
        }

        $this->assertCount(4, $filed);
        $this->assertSame(4, Document::query()->count());

        foreach ($filed as $title => [$typeCode, $document]) {
            $this->assertStringStartsWith("{$typeCode}-", $document->code, "{$title} carries the wrong series.");
            $this->assertSame("{$document->code}.png", $document->qr_code_path);
            // The bytes are a `files` row of their own: a tracked document is a
            // file with a tracking jacket, not a path written into a column.
            $this->assertNotNull($document->file_id, "{$title} has no file row.");
            $this->assertSame($uploader->id, $document->file->user_id);
            // And the history starts where the document does.
            $this->assertSame(DocumentAction::Uploaded, $document->logs()->sole()->action);
        }

        // Every code is distinct, and each series counted only its own.
        $codes = collect($filed)->map(fn (array $pair) => $pair[1]->code);
        $this->assertCount(4, $codes->unique(), 'Two documents share a tracking code.');

        $memos = $codes->filter(fn (string $code) => str_starts_with($code, 'MEMO-'))
            ->map(fn (string $code) => (int) substr($code, -4))
            ->sort()
            ->values();

        $this->assertSame([1, 2], $memos->all(), 'The MEMO series did not count by itself.');
        $this->assertSame(1, (int) substr($filed['Letter to the Director'][1]->code, -4));
        $this->assertSame(1, (int) substr($filed['Quarterly Report'][1]->code, -4));
    }

    /**
     * Item 1, continued: the register lists the whole batch to its uploader,
     * and each row keeps the format it arrived as.
     */
    public function test_the_register_lists_the_whole_batch_with_each_file_name_intact(): void
    {
        $uploader = User::factory()->create();

        $this->file($uploader, 'MEMO', 'Memo', UploadedFile::fake()->create('memo.pdf', 16, 'application/pdf'));
        $this->file($uploader, 'REPORT', 'Report', UploadedFile::fake()->create('figures.xlsx', 32, 'application/zip'));

        $names = Document::query()->orderBy('id')->pluck('file_name')->all();

        // The name the person typed survives as metadata and nowhere else: the
        // bytes are stored under a path derived from the row's own identifier.
        $this->assertSame(['memo.pdf', 'figures.xlsx'], $names);
        $this->assertStringNotContainsString('memo.pdf', Document::query()->first()->file->storage_path);

        $this->actingAs($uploader)->get('/documents?tab=mine')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('counts.mine', 2));
    }

    // ------------------------------------------------------------- item 2

    /**
     * Item 2: deleting the *type* leaves every document filed under it whole.
     *
     * The type is a tag. It picked the tracking prefix and labelled the row,
     * both of which are snapshotted at upload — so retiring it is an edit to a
     * picker, and the documents are untouched: readable, listed, downloadable,
     * and still carrying what they were filed as.
     */
    public function test_deleting_a_type_leaves_its_documents_and_their_files_untouched(): void
    {
        $uploader = User::factory()->create();
        $document = $this->file($uploader, 'MEMO', 'Memo', UploadedFile::fake()->create('memo.pdf', 16, 'application/pdf'));

        $before = $document->only(['code', 'title', 'type', 'file_id', 'qr_code_path']);
        $fileId = $document->file_id;

        DocumentType::query()->where('code', 'MEMO')->sole()->delete();

        $after = $document->fresh();

        $this->assertNotNull($after, 'Deleting a type deleted its documents.');
        $this->assertSame($before['code'], $after->code);
        // The snapshot, not a join: the row still says what it was filed as
        // after the type it names is gone.
        $this->assertSame($before['type'], $after->type);
        $this->assertSame($before['qr_code_path'], $after->qr_code_path);
        /*
         * The foreign key is *kept* on a soft delete, and that is the right
         * answer rather than a leftover: the type row still exists, it has only
         * left the picker, so the document can still be followed back to it and
         * restoring the type restores the whole link. What makes the document
         * independent of it is the `type` snapshot above, which is what every
         * screen renders.
         */
        $this->assertSame($before['document_type_id'] ?? $after->document_type_id, $after->document_type_id);
        $this->assertNotNull(DocumentType::withTrashed()->find($after->document_type_id)?->deleted_at);

        $this->assertNotNull($after->file, 'The file row went with the type.');
        $this->assertSame($fileId, $after->file_id);
        $this->assertTrue(Storage::disk('documents')->exists($after->file->storage_path), 'The bytes went with the type.');

        // And it still opens, and still downloads.
        $this->actingAs($uploader)->get("/documents/view/{$after->code}")->assertOk();
        $this->actingAs($uploader)->get("/documents/{$after->id}/file/download")->assertOk();
    }

    /**
     * Item 2: deleting the *group* is one level further out, and reaches even
     * less.
     *
     * A group arranges the picker. Deleting one has to rearrange the picker and
     * retire nothing — the types fall under "Other", and the documents filed
     * through them do not notice at all.
     */
    public function test_deleting_a_group_keeps_its_types_its_documents_and_their_files(): void
    {
        $uploader = User::factory()->create();
        $document = $this->file($uploader, 'MEMO', 'Memo', UploadedFile::fake()->create('memo.pdf', 16, 'application/pdf'));

        $type = DocumentType::query()->where('code', 'MEMO')->sole();
        $group = $type->group;

        $this->assertNotNull($group, 'The seeded MEMO type is expected to sit in a group.');

        $group->delete();

        $this->assertDatabaseMissing('document_type_groups', ['id' => $group->id]);

        // The type survives, ungrouped — `group_id` is nullOnDelete, because a
        // type is part of the record and a group is an arrangement of a form.
        $type->refresh();
        $this->assertNull($type->group_id);
        $this->assertDatabaseHas('document_types', ['id' => $type->id, 'deleted_at' => null]);

        // The document and its bytes never entered into it.
        $after = $document->fresh();
        $this->assertSame($type->id, $after->document_type_id);
        $this->assertNotNull($after->file);
        $this->assertTrue(Storage::disk('documents')->exists($after->file->storage_path));

        // And the type is still offered for upload, now under Other.
        $this->actingAs(User::factory()->superadmin()->create())
            ->get('/documents/upload')
            ->assertOk();

        // `grouped()` is `[{label, options:[{value,label,code}]}]`, and Other
        // sorts last by construction.
        $grouped = collect(DocumentType::grouped());
        $other = $grouped->firstWhere('label', 'Other');

        $this->assertNotNull($other, 'An ungrouped type must still be offered somewhere.');
        $this->assertContains($type->id, collect($other['options'])->pluck('value')->all());
        $this->assertSame('Other', $grouped->last()['label'], 'Other must sort last.');
    }

    /**
     * Item 2, the far end of it: erasing the group *and* the type outright —
     * not a soft delete — still leaves the documents readable.
     *
     * This is the case a `cascadeOnDelete` would have destroyed silently, and
     * the reason every lookup reference on `documents` is `nullOnDelete` beside
     * a snapshot.
     */
    public function test_erasing_both_the_group_and_the_type_leaves_the_documents_readable(): void
    {
        $uploader = User::factory()->create();
        $document = $this->file($uploader, 'MEMO', 'Memo', UploadedFile::fake()->create('memo.pdf', 16, 'application/pdf'));

        $type = DocumentType::query()->where('code', 'MEMO')->sole();
        $typeName = $type->name;
        $groupId = $type->group_id;

        $type->forceDelete();
        DocumentTypeGroup::query()->whereKey($groupId)->delete();

        $after = $document->fresh();

        $this->assertNotNull($after);
        $this->assertNull($after->document_type_id);
        $this->assertSame($typeName, $after->type, 'The filed-as snapshot was lost.');

        $this->actingAs($uploader)->get("/documents/view/{$after->code}")->assertOk();
        // And the register still lists it — a filter that cannot find a
        // document filed under a retired type is a filter that lies.
        $this->actingAs($uploader)->get('/documents?tab=mine')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('counts.mine', 1));
    }
}
