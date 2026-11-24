<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Services\Documents\DocumentTypeInference;
use App\Support\FileTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which type a file is filed as when nobody was asked.
 *
 * A type's `code` is the tracking code's prefix, so there is no code without
 * a type — which makes this the one inference the feature cannot do without,
 * and the fallback chain the part that has to survive an office editing its
 * own vocabulary.
 */
class DocumentTypeInferenceTest extends TestCase
{
    use RefreshDatabase;

    private function infer(): DocumentTypeInference
    {
        return app(DocumentTypeInference::class);
    }

    public function test_a_format_is_filed_as_its_group(): void
    {
        $this->assertSame('OTHER', $this->infer()->for('pdf')->code);
        $this->assertSame('OTHER', $this->infer()->for('docx')->code);
        $this->assertSame('DATA', $this->infer()->for('xlsx')->code);
        $this->assertSame('PRES', $this->infer()->for('pptx')->code);
        $this->assertSame('PHOTO', $this->infer()->for('jpg')->code);
        $this->assertSame('VIDEO', $this->infer()->for('mp4')->code);
        $this->assertSame('AUDIO', $this->infer()->for('mp3')->code);
        // A container, not a kind of document — what is inside it is filed
        // per entry when it is extracted.
        $this->assertSame('OTHER', $this->infer()->for('zip')->code);
    }

    /** A scanner writes TIFFs, so the format says more than its group does. */
    public function test_a_tiff_is_a_scan_rather_than_a_photograph(): void
    {
        $this->assertSame('SCAN', $this->infer()->for('tif')->code);
        $this->assertSame('SCAN', $this->infer()->for('tiff')->code);
    }

    /**
     * Every extension the catalogue knows resolves to something.
     *
     * Asserted over the catalogue rather than as a list, so a format added
     * there cannot arrive with no type and silently stop being filed.
     */
    public function test_every_catalogued_extension_resolves(): void
    {
        foreach (FileTypes::extensions() as $extension) {
            $this->assertNotNull(
                $this->infer()->for($extension),
                "[{$extension}] resolves to no document type, so a file of that format could not be filed.",
            );
        }

        // And something the catalogue has never heard of still files.
        $this->assertSame('OTHER', $this->infer()->for('wat')->code);
        $this->assertSame('OTHER', $this->infer()->for('')->code);
    }

    /**
     * The chain survives the office editing its own vocabulary.
     *
     * A type's code can never be invented or reused — it is stamped into
     * every code that type has issued and printed on paper — so each step
     * falls back to a type the office actually has rather than recreating
     * one behind their back.
     */
    public function test_the_fallback_chain_walks_down_to_what_the_office_still_has(): void
    {
        // Deactivating the group's own choice falls through to OTHER.
        DocumentType::query()->where('code', 'DATA')->update(['is_active' => false]);
        $this->assertSame('OTHER', $this->infer()->for('xlsx')->code);

        // Deactivating OTHER too falls to the picker's first choice, which is
        // a type the office uses rather than one we create.
        DocumentType::query()->where('code', 'OTHER')->update(['is_active' => false]);
        $first = DocumentType::query()->active()->orderBy('position')->first();
        $this->assertSame($first->code, $this->infer()->for('xlsx')->code);
        $this->assertNotSame('OTHER', $this->infer()->for('xlsx')->code);
    }

    /**
     * With no selectable type at all, nothing is filed — and that is the
     * honest answer rather than a code with an invented prefix.
     */
    public function test_an_office_with_no_types_files_nothing(): void
    {
        DocumentType::query()->update(['is_active' => false]);

        $this->assertNull($this->infer()->for('pdf'));
    }
}
