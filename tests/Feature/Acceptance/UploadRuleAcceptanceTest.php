<?php

namespace Tests\Feature\Acceptance;

use App\Models\DocumentType;
use App\Models\File;
use App\Models\UploadSetting;
use App\Models\User;
use App\Services\Files\FolderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Acceptance items 7 and 8 — an administrator sets the rules, and the rules are
 * what actually happens.
 *
 * `UploadPolicyTest` covers the policy a clause at a time. This covers the act:
 * somebody opens the settings screen, writes a policy, and then both upload
 * forms behave as that screen says — including for the formats it did *not*
 * permit, which is item 8.
 *
 * Two things make this worth asserting end to end rather than against the
 * policy object:
 *
 * - the gate runs in three places (the document register, the file manager, and
 *   each entry inside an archive) and an administrator who has to find the
 *   right screen before their change means anything has a settings screen that
 *   lies;
 * - Laravel's `max:` rule runs *before* anything has looked at the extension,
 *   so the coarse bound has to be the widest window any permitted type has and
 *   the real one applied per file. A coarse bound set to the scope default
 *   refuses a large video before the rule permitting it is ever consulted.
 */
class UploadRuleAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');
        Storage::fake('public');
    }

    private function policy(): UploadSetting
    {
        return UploadSetting::query()->sole();
    }

    /**
     * Write a policy through the **screen**, as an administrator would.
     *
     * Through the endpoint rather than the model on purpose: a rule saved by a
     * test that bypasses validation is a rule the screen might have refused to
     * save, and item 7 is about the rules an administrator can actually set.
     *
     * @param  array<string, array{0: int, 1: int}|int>  $spec  extension => max KB, or [min, max]
     */
    private function savePolicy(array $spec, int $maxFiles = 5): void
    {
        $types = [];
        $ceiling = 0;

        foreach ($spec as $extension => $window) {
            [$min, $max] = is_array($window) ? $window : [0, $window];
            $types[] = ['extension' => $extension, 'min_size_kb' => $min, 'max_size_kb' => $max];
            $ceiling = max($ceiling, $max);
        }

        $this->actingAs(User::factory()->superadmin()->create())
            ->put('/upload-settings', [
                'min_size_kb' => 0,
                'max_size_kb' => $ceiling,
                'allowed_types' => $types,
                'max_files_per_upload' => $maxFiles,
                ...$this->archiveRules($ceiling),
            ])
            ->assertSessionHasNoErrors();
    }

    /**
     * The archive half of the form, which the screen always posts.
     *
     * Sent in full rather than omitted: every one of these is `required`, so a
     * partial post is a validation failure rather than "leave them as they
     * were" — and a test that posted half the form would be testing a request
     * the screen never makes.
     *
     * @return array<string, int|bool>
     */
    private function archiveRules(int $ceiling): array
    {
        return [
            'max_archive_size_kb' => $ceiling,
            'max_archive_entries' => 100,
            'max_extracted_kb' => $ceiling * 4,
            'max_archive_depth' => 4,
            'allow_nested_archives' => false,
        ];
    }

    private function toDocuments(User $user, UploadedFile $file)
    {
        return $this->actingAs($user)->post('/documents', [
            'title' => 'Filed',
            'document_type_id' => DocumentType::query()->where('code', 'MEMO')->value('id'),
            'visibility' => 'public',
            'file' => $file,
        ]);
    }

    /** @param  list<UploadedFile>  $files */
    private function toFiles(User $user, array $files)
    {
        return $this->actingAs($user)->post('/files/upload', [
            'folder' => app(FolderService::class)->rootFor($user)->public_id,
            'files' => $files,
        ]);
    }

    // ------------------------------------------------------------- item 7

    /**
     * Item 7: the permitted list is the list, on both forms.
     *
     * A policy permitting PDF and PNG and nothing else: a PDF is filed, a PNG
     * is filed, and a Word document — a format the office *usually* accepts —
     * is refused on both screens, because this policy does not name it.
     */
    public function test_the_permitted_list_governs_both_upload_forms(): void
    {
        $this->savePolicy(['pdf' => 2048, 'png' => 512]);
        $user = User::factory()->create();

        $this->toDocuments($user, UploadedFile::fake()->create('memo.pdf', 16, 'application/pdf'))
            ->assertSessionHasNoErrors();
        $this->toFiles($user, [UploadedFile::fake()->create('logo.png', 16, 'image/png')])
            ->assertSessionHasNoErrors();

        $this->toDocuments($user, UploadedFile::fake()->create('memo.docx', 16, 'application/zip'))
            ->assertSessionHasErrors('file');
        $this->toFiles($user, [UploadedFile::fake()->create('memo.docx', 16, 'application/zip')])
            ->assertSessionHasErrors();

        // Exactly the two that were permitted were stored.
        $this->assertSame(['memo.pdf', 'logo.png'], File::query()->orderBy('id')->pluck('original_name')->all());
    }

    /**
     * Item 7: each permitted type carries its own size window, and the window
     * is what applies — not one ceiling over everything.
     *
     * A 200 MB video and a 2 MB signature scan are both reasonable, and one
     * ceiling cannot say so. The coarse rule has to be the widest of them, or
     * the large one is refused before its own rule is read.
     */
    public function test_each_type_is_held_to_its_own_window(): void
    {
        $this->savePolicy(['png' => 64, 'mp4' => 8192]);
        $user = User::factory()->create();

        // Within its own window, and far over the small type's.
        $this->toFiles($user, [UploadedFile::fake()->create('clip.mp4', 4096, 'video/mp4')])
            ->assertSessionHasNoErrors();

        // Over its own, although well under the other type's.
        $this->toFiles($user, [UploadedFile::fake()->create('photo.png', 512, 'image/png')])
            ->assertSessionHasErrors();

        $this->assertSame(['clip.mp4'], File::query()->pluck('original_name')->all());
    }

    /** Item 7: a minimum is a rule too — an empty file is not an upload. */
    public function test_a_minimum_size_is_enforced(): void
    {
        $this->savePolicy(['pdf' => [64, 2048]]);
        $user = User::factory()->create();

        $this->toFiles($user, [UploadedFile::fake()->create('thin.pdf', 8, 'application/pdf')])
            ->assertSessionHasErrors();
        $this->toFiles($user, [UploadedFile::fake()->create('full.pdf', 128, 'application/pdf')])
            ->assertSessionHasNoErrors();
    }

    /** Item 7: the file-count limit, and the all-or-nothing batch. */
    public function test_the_file_count_limit_holds_and_one_bad_file_refuses_the_batch(): void
    {
        $this->savePolicy(['pdf' => 2048], maxFiles: 2);
        $user = User::factory()->create();

        $three = [
            UploadedFile::fake()->create('a.pdf', 8, 'application/pdf'),
            UploadedFile::fake()->create('b.pdf', 8, 'application/pdf'),
            UploadedFile::fake()->create('c.pdf', 8, 'application/pdf'),
        ];

        $this->toFiles($user, $three)->assertSessionHasErrors();
        $this->assertSame(0, File::query()->count());

        // Within the count, but one of them is not permitted: nothing is
        // stored, rather than everything up to the bad one.
        $this->toFiles($user, [
            UploadedFile::fake()->create('good.pdf', 8, 'application/pdf'),
            UploadedFile::fake()->create('bad.exe', 8, 'application/x-dosexec'),
        ])->assertSessionHasErrors();

        $this->assertSame(0, File::query()->count(), 'A refused batch stored part of itself.');
    }

    /**
     * Item 7: tightening the policy takes effect on the next upload, and
     * widening it on the one after that.
     *
     * The settings screen is only a settings screen if the answer changes
     * without a deploy.
     */
    public function test_changing_the_policy_changes_the_next_upload(): void
    {
        $user = User::factory()->create();

        $this->savePolicy(['pdf' => 2048]);
        $this->toFiles($user, [UploadedFile::fake()->create('a.pdf', 8, 'application/pdf')])
            ->assertSessionHasNoErrors();
        $this->toFiles($user, [UploadedFile::fake()->create('a.png', 8, 'image/png')])
            ->assertSessionHasErrors();

        $this->savePolicy(['png' => 512]);
        $this->toFiles($user, [UploadedFile::fake()->create('b.png', 8, 'image/png')])
            ->assertSessionHasNoErrors();
        // And what was permitted a moment ago is not any more.
        $this->toFiles($user, [UploadedFile::fake()->create('b.pdf', 8, 'application/pdf')])
            ->assertSessionHasErrors();
    }

    // ------------------------------------------------------------- item 8

    /**
     * Item 8: a format the list does not name is refused, whatever it is.
     *
     * Not in the list means refused — there is no "everything else is probably
     * fine" branch. The sweep is over formats an office might plausibly try as
     * well as ones nobody should.
     */
    public function test_a_format_outside_the_list_is_refused_on_both_forms(): void
    {
        $this->savePolicy(['pdf' => 2048]);
        $user = User::factory()->create();

        $refused = [
            ['notes.txt', 'text/plain'],
            ['sheet.xlsx', 'application/zip'],
            ['photo.jpg', 'image/jpeg'],
            ['archive.zip', 'application/zip'],
            ['page.html', 'text/html'],
        ];

        foreach ($refused as [$name, $mime]) {
            $this->toFiles($user, [UploadedFile::fake()->create($name, 8, $mime)])
                ->assertSessionHasErrors();
            $this->toDocuments($user, UploadedFile::fake()->create($name, 8, $mime))
                ->assertSessionHasErrors('file');
        }

        $this->assertSame(0, File::query()->count());
    }

    /**
     * Item 8: and an executable cannot be permitted even by an administrator
     * who tries to permit it.
     *
     * This is the floor rather than a preference. A veto an administrator can
     * empty is not a veto, which is why the refusal of program and script
     * formats lives in code and not in the editable list.
     */
    public function test_a_dangerous_format_cannot_be_permitted_at_all(): void
    {
        // The screen refuses to save it.
        $this->actingAs(User::factory()->superadmin()->create())
            ->put('/upload-settings', [
                'min_size_kb' => 0,
                'max_size_kb' => 2048,
                'allowed_types' => [
                    ['extension' => 'pdf', 'min_size_kb' => 0, 'max_size_kb' => 2048],
                    ['extension' => 'php', 'min_size_kb' => 0, 'max_size_kb' => 2048],
                ],
                'max_files_per_upload' => 5,
                ...$this->archiveRules(2048),
            ])
            ->assertSessionHasErrors();

        // And were the row to carry one anyway, the gate still refuses it.
        $this->policy()->update(['allowed_types' => [
            ['extension' => 'pdf', 'min_size_kb' => 0, 'max_size_kb' => 2048],
            ['extension' => 'php', 'min_size_kb' => 0, 'max_size_kb' => 2048],
        ]]);

        $user = User::factory()->create();

        $this->toFiles($user, [UploadedFile::fake()->create('shell.php', 2, 'text/x-php')])
            ->assertSessionHasErrors();
        $this->assertSame(0, File::query()->count(), 'A script format was stored.');
    }

    /**
     * Item 8: a file renamed to get past the list is refused on its contents,
     * and an honestly-named file whose sniff is merely *inconclusive* is not.
     *
     * Both halves matter. Only checking the extension lets a renamed executable
     * through; only checking the contents lets through a file still named
     * `.php`. But every OOXML file sniffs as `application/zip` and text has no
     * magic number at all, so treating an inconclusive sniff as a lie is what
     * produced refusals that named the very type that should have admitted the
     * file.
     */
    public function test_a_renamed_executable_is_refused_and_an_inconclusive_sniff_is_not(): void
    {
        $this->savePolicy(['pdf' => 2048, 'xlsx' => 2048, 'txt' => 2048]);
        $user = User::factory()->create();

        // A positive contradiction: the bytes say DOS executable.
        $this->toFiles($user, [UploadedFile::fake()->create('invoice.pdf', 8, 'application/x-dosexec')])
            ->assertSessionHasErrors();

        // Inconclusive, and correct: a real spreadsheet *is* a zip archive.
        $this->toFiles($user, [UploadedFile::fake()->create('figures.xlsx', 8, 'application/zip')])
            ->assertSessionHasNoErrors();
        // And text, which has no signature to read at all.
        $this->toFiles($user, [UploadedFile::fake()->create('notes.txt', 8, 'application/octet-stream')])
            ->assertSessionHasNoErrors();

        $this->assertSame(['figures.xlsx', 'notes.txt'], File::query()->orderBy('id')->pluck('original_name')->all());
    }

    /** Item 8: a file with no extension has nothing to check against. */
    public function test_a_file_with_no_extension_is_refused(): void
    {
        $this->savePolicy(['pdf' => 2048]);

        $this->toFiles(User::factory()->create(), [UploadedFile::fake()->create('README', 8, 'text/plain')])
            ->assertSessionHasErrors();
    }
}
