<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\File;
use App\Models\User;
use App\Services\Files\FolderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Files are served from a private disk, at an opaque URL, through a gate.
 *
 * Before this, every uploaded document sat on the `public` disk and was
 * readable at /storage/documents/<hash>.pdf by anyone holding the URL, with no
 * authorization check at all.
 */
class FileAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('public');
    }

    /**
     * @param  list<int>  $recipients
     */
    private function upload(User $user, string $visibility = 'public', array $recipients = []): Document
    {
        // A private upload must name at least one recipient — the validation
        // rule is `required_if:visibility,private`, so an empty array is a
        // failed upload, not a private document with nobody on it.
        if ($visibility === 'private' && $recipients === []) {
            $recipients = [User::factory()->create()->id];
        }

        $this->actingAs($user)->post('/documents', [
            'title' => 'Memo',
            'document_type_id' => \App\Models\DocumentType::query()->where('code', 'MEMO')->value('id'),
            'visibility' => $visibility,
            'recipients' => $recipients,
            'file' => UploadedFile::fake()->create('memo.pdf', 16, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        return Document::query()->with('file')->latest('id')->sole();
    }

    private function streamUrl(File $file): string
    {
        return "/files/{$file->owner->public_id}/{$file->public_id}";
    }

    public function test_an_upload_is_not_reachable_on_the_public_disk(): void
    {
        $document = $this->upload(User::factory()->create());

        $this->assertEmpty(
            Storage::disk('public')->allFiles(),
            'Nothing a document upload writes may land where the web server serves it directly.',
        );
        Storage::disk('documents')->assertExists($document->file->storage_path);
    }

    public function test_the_owner_can_stream_their_own_file(): void
    {
        $owner = User::factory()->create();
        $document = $this->upload($owner, 'private');

        $this->actingAs($owner)->get($this->streamUrl($document->file))->assertOk();
    }

    /**
     * The owner segment is checked against the file's actual owner, so a file
     * identifier cannot be recombined under a different owner to probe what
     * exists.
     */
    public function test_a_mismatched_owner_segment_is_not_found(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $document = $this->upload($owner);

        $this->actingAs($owner)
            ->get("/files/{$other->public_id}/{$document->file->public_id}")
            ->assertNotFound();
    }

    /**
     * A refusal and a non-existent file answer identically. A 403 here would
     * confirm the identifier is real to anyone probing it, which is the
     * enumeration the opaque URL exists to prevent.
     */
    public function test_a_stranger_gets_the_same_answer_as_for_a_missing_file(): void
    {
        $document = $this->upload(User::factory()->create(), 'private');
        $stranger = User::factory()->create();

        $refused = $this->actingAs($stranger)->get($this->streamUrl($document->file));
        $absent = $this->actingAs($stranger)
            ->get('/files/'.str_repeat('a', 26).'/'.str_repeat('b', 26));

        $refused->assertNotFound();
        $absent->assertNotFound();
    }

    /**
     * The route constraints do the traversal check, before any controller code
     * runs: a segment that must be exactly 26 alphanumeric characters cannot
     * carry a `..` or a slash.
     */
    public function test_a_traversal_attempt_cannot_match_the_route(): void
    {
        $user = User::factory()->create();

        foreach (['../../../.env', '..%2f..%2f.env', 'short', str_repeat('a', 27)] as $attempt) {
            $this->actingAs($user)
                ->get('/files/'.str_repeat('a', 26).'/'.$attempt)
                ->assertNotFound();
        }
    }

    /** A tracked file is reachable by the document's rule, not a looser one. */
    public function test_a_recipient_of_a_private_document_can_stream_its_file(): void
    {
        $owner = User::factory()->create();
        $recipient = User::factory()->create();

        $document = $this->upload($owner, 'private', [$recipient->id]);

        $this->actingAs($recipient)->get($this->streamUrl($document->file))->assertOk();
    }

    /**
     * The file a tracking code points at must not be reachable by anyone the
     * document itself refuses — otherwise the opaque URL is a second, looser
     * way in.
     */
    public function test_a_tracked_file_is_refused_to_someone_the_document_refuses(): void
    {
        $document = $this->upload(User::factory()->create(), 'private');

        $this->actingAs(User::factory()->create())
            ->get($this->streamUrl($document->file))
            ->assertNotFound();
    }

    public function test_a_signed_out_visitor_cannot_stream_a_private_file(): void
    {
        $document = $this->upload(User::factory()->create(), 'private');

        // actingAs() in the upload helper persists for the rest of the test,
        // so the visitor has to be signed out explicitly — otherwise this
        // passes while asserting nothing.
        Auth::logout();

        $this->get($this->streamUrl($document->file))->assertNotFound();
        $this->assertFalse(
            app(\App\Services\Access\AccessResolver::class)
                ->allows(null, $document->file, \App\Enums\FileAbility::View),
        );
    }

    /**
     * An inline response is only ever given for types the browser can be
     * trusted to render. An uploaded SVG or HTML file rendered in place would
     * execute its own script against this origin.
     */
    public function test_an_inline_request_for_a_pdf_is_sandboxed(): void
    {
        $owner = User::factory()->create();
        $document = $this->upload($owner);

        $response = $this->actingAs($owner)
            ->get($this->streamUrl($document->file).'?disposition=inline');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
    }

    public function test_a_non_previewable_type_is_forced_to_download(): void
    {
        $owner = User::factory()->create();
        $folder = app(FolderService::class)->rootFor($owner);

        $file = app(\App\Services\Files\FileStorage::class)->store(
            UploadedFile::fake()->createWithContent('notes.html', '<script>alert(1)</script>'),
            $folder,
            $owner,
        );

        $response = $this->actingAs($owner)->get($this->streamUrl($file->fresh()).'?disposition=inline');

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    /** Every account has exactly one root, however many times it is asked for. */
    public function test_an_account_has_exactly_one_root_folder(): void
    {
        $user = User::factory()->create();
        $service = app(FolderService::class);

        $first = $service->rootFor($user);
        $second = $service->rootFor($user);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, \App\Models\Folder::query()
            ->where('user_id', $user->id)->where('is_root', true)->count());
    }

    /**
     * A folder moved inside its own subtree is detached from the root: its path
     * claims an ancestor chain that no longer reaches one, so it vanishes from
     * every listing while its rows stay in the table.
     */
    public function test_a_folder_cannot_be_moved_into_its_own_subtree(): void
    {
        $user = User::factory()->create();
        $service = app(FolderService::class);

        $root = $service->rootFor($user);
        $parent = $service->create($user, $root, 'Parent');
        $child = $service->create($user, $parent, 'Child');

        $this->expectException(\RuntimeException::class);
        $service->move($parent, $child);
    }

    /** A copy is a new row with its own bytes, never a reference. */
    public function test_a_copy_is_independent_of_its_source(): void
    {
        $owner = User::factory()->create();
        $taker = User::factory()->create();
        $storage = app(\App\Services\Files\FileStorage::class);
        $folders = app(FolderService::class);

        $source = $storage->store(
            UploadedFile::fake()->create('shared.pdf', 8, 'application/pdf'),
            $folders->rootFor($owner),
            $owner,
        );

        $copy = $storage->copy($source, $folders->rootFor($taker), $taker);

        $this->assertNotSame($source->public_id, $copy->public_id);
        $this->assertNotSame($source->path, $copy->storage_path);
        $this->assertSame($taker->id, $copy->user_id);

        $storage->delete($source);
        $source->delete();

        Storage::disk('documents')->assertExists($copy->storage_path);
        $this->actingAs($taker)->get($this->streamUrl($copy->fresh()))->assertOk();
    }
}
