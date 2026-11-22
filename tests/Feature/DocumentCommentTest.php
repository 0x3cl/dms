<?php

namespace Tests\Feature;

use App\Enums\DocumentAction;
use App\Enums\DocumentVisibility;
use App\Models\Document;
use App\Models\DocumentComment;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\Documents\DocumentRegistrar;
use App\Services\Files\FileManager;
use App\Services\Files\FolderService;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Comments on a document, written about one version of its file.
 *
 * Two properties carry the feature, and both are easy to lose:
 *
 * - **Who may comment is the document's own read rule**, asked of
 *   `AccessResolver` rather than restated. A second gate here would be a
 *   second statement of one rule, and the kind that drifts into letting
 *   somebody discuss a document they cannot open.
 * - **A comment belongs to the version it was written about.** A thread
 *   that carries forward across a replacement tells the next reader that a
 *   resolved objection is still open.
 */
class DocumentCommentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    private function staff(string $employeeId = '1001'): User
    {
        $user = User::factory()->create(['employee_id' => $employeeId]);
        $user->assignRole(Roles::STAFF);

        return $user;
    }

    private function document(User $owner, string $visibility = 'public'): Document
    {
        $folder = app(FolderService::class)->rootFor($owner);
        $file = app(FileManager::class)->upload(
            $owner,
            $folder,
            UploadedFile::fake()->create('order.pdf', 12, 'application/pdf'),
        );

        return app(DocumentRegistrar::class)->register(
            $file,
            DocumentType::query()->where('is_active', true)->firstOrFail(),
            'Office Order',
            DocumentVisibility::from($visibility),
            [],
            $owner,
        );
    }

    public function test_commenting_is_on_by_default(): void
    {
        $this->assertTrue($this->document($this->staff())->comments_enabled);
    }

    public function test_a_reader_may_comment_and_the_trail_records_the_fact(): void
    {
        $owner = $this->staff();
        $reader = $this->staff('1002');
        $document = $this->document($owner);

        $this->actingAs($reader)
            ->post("/documents/{$document->id}/comments", ['body' => 'Annex is missing.'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $comment = DocumentComment::query()->sole();

        $this->assertSame('Annex is missing.', $comment->body);
        $this->assertSame($reader->id, $comment->user_id);
        $this->assertSame($reader->name, $comment->actor_name, 'the author is snapshotted, like every other actor here');
        $this->assertSame(1, $comment->version);

        /*
         * The trail says a comment was written and never what it said: a
         * comment is content its author may delete, and the trail must not
         * change.
         */
        $entry = $document->logs()->where('action', DocumentAction::Commented)->sole();

        $this->assertSame(1, $entry->version);
        $this->assertNull($entry->remarks);
        $this->assertStringNotContainsString('Annex', (string) $entry->remarks);
    }

    public function test_somebody_who_cannot_read_the_document_cannot_comment(): void
    {
        $owner = $this->staff();
        $stranger = $this->staff('1003');
        $document = $this->document($owner, 'private');

        $this->actingAs($stranger)
            ->post("/documents/{$document->id}/comments", ['body' => 'Let me in.'])
            ->assertSessionHas('error');

        $this->assertSame(0, DocumentComment::query()->count());
    }

    public function test_a_visitor_with_no_account_cannot_comment(): void
    {
        $document = $this->document($this->staff());

        auth()->logout();

        $this->post("/documents/{$document->id}/comments", ['body' => 'Hello.'])
            ->assertRedirect('/login');

        $this->assertSame(0, DocumentComment::query()->count());
    }

    /**
     * A comment stays with the bytes it was written about.
     */
    public function test_a_comment_belongs_to_the_version_it_was_written_on(): void
    {
        $owner = $this->staff();
        $document = $this->document($owner);

        $this->actingAs($owner)->post("/documents/{$document->id}/comments", ['body' => 'About version one.']);

        app(FileManager::class)->replace(
            $owner,
            $document->file,
            UploadedFile::fake()->create('order.pdf', 14, 'application/pdf'),
        );

        $this->actingAs($owner)->post("/documents/{$document->id}/comments", ['body' => 'About version two.']);

        $this->assertSame('About version one.', DocumentComment::query()->where('version', 1)->value('body'));
        $this->assertSame('About version two.', DocumentComment::query()->where('version', 2)->value('body'));
    }

    /**
     * The page says which version the person was looking at, and that is
     * what is recorded — not whatever is current when the request lands.
     */
    public function test_the_version_the_reader_was_looking_at_is_the_one_recorded(): void
    {
        $owner = $this->staff();
        $document = $this->document($owner);

        app(FileManager::class)->replace(
            $owner,
            $document->file,
            UploadedFile::fake()->create('order.pdf', 14, 'application/pdf'),
        );

        $this->actingAs($owner)
            ->post("/documents/{$document->id}/comments", ['body' => 'Still about the first one.', 'version' => 1]);

        $this->assertSame(1, DocumentComment::query()->sole()->version);
    }

    public function test_the_toggle_closes_the_thread_without_erasing_it(): void
    {
        $owner = $this->staff();
        $document = $this->document($owner);

        $this->actingAs($owner)->post("/documents/{$document->id}/comments", ['body' => 'Said while open.']);

        $document->update(['comments_enabled' => false]);

        $this->actingAs($owner)
            ->post("/documents/{$document->id}/comments", ['body' => 'Said while closed.'])
            ->assertSessionHas('error');

        // Closing a thread is not deleting it: what was written stays
        // readable, because erasing other people's words as a side effect
        // of a setting is not something a toggle should do.
        $this->assertSame(1, DocumentComment::query()->count());
        $this->assertSame('Said while open.', DocumentComment::query()->sole()->body);
    }

    public function test_the_upload_form_can_file_a_document_with_comments_off(): void
    {
        $owner = $this->staff();

        $this->actingAs($owner)->post('/documents', [
            'title' => 'Quiet Memo',
            'document_type_id' => DocumentType::query()->where('is_active', true)->value('id'),
            'file' => UploadedFile::fake()->create('memo.pdf', 12, 'application/pdf'),
            'visibility' => 'public',
            'comments_enabled' => false,
        ])->assertSessionHasNoErrors();

        $this->assertFalse(Document::query()->where('title', 'Quiet Memo')->sole()->comments_enabled);
    }

    public function test_an_author_may_delete_their_own_comment_and_a_stranger_may_not(): void
    {
        $owner = $this->staff();
        $reader = $this->staff('1002');
        $other = $this->staff('1004');
        $document = $this->document($owner);

        $this->actingAs($reader)->post("/documents/{$document->id}/comments", ['body' => 'Mine.']);
        $comment = DocumentComment::query()->sole();

        $this->actingAs($other)
            ->delete("/documents/{$document->id}/comments/{$comment->id}")
            ->assertSessionHas('error');
        $this->assertSame(1, DocumentComment::query()->count());

        $this->actingAs($reader)
            ->delete("/documents/{$document->id}/comments/{$comment->id}")
            ->assertSessionHas('success');
        $this->assertSame(0, DocumentComment::query()->count());
    }

    /** The uploader answers for what accumulates on a document they filed. */
    public function test_the_uploader_may_delete_somebody_elses_comment(): void
    {
        $owner = $this->staff();
        $reader = $this->staff('1002');
        $document = $this->document($owner);

        $this->actingAs($reader)->post("/documents/{$document->id}/comments", ['body' => 'Theirs.']);
        $comment = DocumentComment::query()->sole();

        $this->actingAs($owner)
            ->delete("/documents/{$document->id}/comments/{$comment->id}")
            ->assertSessionHas('success');

        $this->assertSame(0, DocumentComment::query()->count());
    }

    /** A comment is not a trail row, so a document's deletion takes it. */
    public function test_comments_go_with_the_document(): void
    {
        $owner = $this->staff();
        $document = $this->document($owner);

        $this->actingAs($owner)->post("/documents/{$document->id}/comments", ['body' => 'Transient.']);

        $document->forceDelete();

        $this->assertSame(0, DocumentComment::query()->count());
    }
}
