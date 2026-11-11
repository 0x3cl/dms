<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `files:import-documents` carries pre-existing documents off the public disk.
 *
 * Those rows point at `storage/app/public/documents/<hash>.pdf`, which the web
 * server serves directly with no authorization check. Until the command has
 * run, Document::storedFile() falls back to that path so the application keeps
 * working — which is what makes this safe to run at leisure rather than as part
 * of the deploy.
 */
class DocumentImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('public');
    }

    /** A document as it existed before private storage: a public-disk path, no file row. */
    private function legacyDocument(?User $uploader = null): Document
    {
        $uploader ??= User::factory()->create();

        Storage::disk('public')->put('documents/abc123.pdf', 'the original bytes');
        Storage::disk('public')->put('qrcodes/MEMO-20260101-0001.svg', '<svg/>');

        return Document::create([
            'code' => 'MEMO-20260101-0001',
            'title' => 'Legacy memo',
            'document_type_id' => DocumentType::query()->where('code', 'MEMO')->value('id'),
            'type' => 'Memo',
            'file_path' => 'documents/abc123.pdf',
            'file_name' => 'legacy memo.pdf',
            'qr_code_path' => 'MEMO-20260101-0001.svg',
            'user_id' => $uploader->id,
            'uploader_name' => $uploader->name,
            'status' => 'active',
            'visibility' => 'public',
        ]);
    }

    public function test_a_legacy_document_is_still_readable_before_the_import(): void
    {
        $document = $this->legacyDocument();

        $this->actingAs($document->user)
            ->get("/documents/{$document->id}/file/download")
            ->assertOk();
    }

    public function test_the_import_moves_the_bytes_and_links_the_file(): void
    {
        $document = $this->legacyDocument();

        $this->artisan('files:import-documents')->assertSuccessful();

        $document->refresh()->load('file');

        $this->assertNotNull($document->file_id);
        $this->assertSame('import', $document->file->source);
        $this->assertSame('legacy memo.pdf', $document->file->original_name);

        Storage::disk('documents')->assertExists($document->file->storage_path);
        $this->assertSame('the original bytes', Storage::disk('documents')->get($document->file->storage_path));

        // The opaque path carries nothing the uploader named it.
        $this->assertStringNotContainsString('legacy', $document->file->storage_path);
        $this->assertStringNotContainsString('abc123', $document->file->storage_path);
    }

    /** The label moves too, and its stored name is deliberately untouched. */
    public function test_the_import_moves_the_qr_label_without_renaming_it(): void
    {
        $document = $this->legacyDocument();

        $this->artisan('files:import-documents')->assertSuccessful();

        $this->assertSame('MEMO-20260101-0001.svg', $document->refresh()->qr_code_path);
        Storage::disk('documents')->assertExists('qrcodes/MEMO-20260101-0001.svg');

        $this->actingAs($document->user)
            ->get('/documents/qr/download/MEMO-20260101-0001.svg')
            ->assertOk();
    }

    public function test_the_import_is_idempotent(): void
    {
        $this->legacyDocument();

        $this->artisan('files:import-documents')->assertSuccessful();
        $firstPath = Document::sole()->file->path;

        $this->artisan('files:import-documents')->assertSuccessful();

        $this->assertSame(1, \App\Models\File::query()->count());
        $this->assertSame($firstPath, Document::sole()->refresh()->file->path);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->legacyDocument();

        $this->artisan('files:import-documents', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull(Document::sole()->file_id);
        $this->assertSame(0, \App\Models\File::query()->count());
    }

    /**
     * A document whose bytes have already gone is left alone rather than given
     * an empty file row: the record, its code and its trail are still the
     * office's account of what happened, and inventing a file would hide that
     * the file is missing.
     */
    public function test_a_document_whose_file_is_gone_is_skipped(): void
    {
        $document = $this->legacyDocument();
        Storage::disk('public')->delete('documents/abc123.pdf');

        $this->artisan('files:import-documents')->assertSuccessful();

        $this->assertNull($document->refresh()->file_id);
        $this->assertSame(0, \App\Models\File::query()->count());
    }

    /**
     * `documents.user_id` is nullOnDelete, so an uploader-less document is an
     * expected state. Its bytes still need a folder, and a folder needs an
     * owner.
     */
    public function test_a_document_with_no_uploader_is_imported_under_a_holding_account(): void
    {
        $uploader = User::factory()->create();
        $document = $this->legacyDocument($uploader);
        // Erased, not closed: a closed account keeps the link, so the document
        // would still have an uploader and the holding-account path would not run.
        $uploader->forceDelete();

        $this->artisan('files:import-documents')->assertSuccessful();

        $document->refresh()->load('file');

        $this->assertNotNull($document->file_id);
        // The file outlives the account, and keeps the name the audit trail needs.
        $this->assertNull($document->file->user_id);
        $this->assertNotNull($document->file->owner_name);
        Storage::disk('documents')->assertExists($document->file->storage_path);
    }
}
