<?php

namespace App\Services\Documents;

use App\Models\DocumentType;
use App\Models\File;
use App\Support\FileTypes;
use Illuminate\Http\UploadedFile;

/**
 * Which document type a file is, when nobody was asked.
 *
 * Every uploaded file is filed now, and the register's form is the only place
 * a person picks a type. A file dropped into My Files has to be given one
 * anyway, because a type's `code` is the tracking code's prefix — there is no
 * code without a type.
 *
 * It infers from the **format** and nothing else, because the format is the
 * only thing the bytes actually say. A `.xlsx` is a dataset; whether it is a
 * budget or an attendance sheet is not knowable from the file and guessing
 * would write a wrong word into a permanent record. That is also why
 * `GROUP_DOCUMENTS` lands on `OTHER` rather than on `MEMO` or `LETTER`: the
 * office's paperwork is exactly where the format tells us least, and `OTHER`
 * is the honest answer, correctable on the document's own edit form.
 *
 * A service rather than a constant in `App\Support`, because the fallback
 * chain queries `document_types`: the office may deactivate or delete any of
 * these, and a type's code can never be invented or reused — it is stamped
 * into every code that type has already issued and printed on paper.
 */
class DocumentTypeInference
{
    /**
     * The group a format belongs to, and the type code that group files as.
     *
     * Every one of these is seeded by `DocumentTypeSeeder`, so a fresh
     * install resolves all of them on the first try.
     *
     * @var array<string, string>
     */
    private const BY_GROUP = [
        // The office's own paperwork. `OTHER` on purpose — see the class
        // docblock: this is where the format says least.
        FileTypes::GROUP_DOCUMENTS => 'OTHER',
        FileTypes::GROUP_SPREADSHEETS => 'DATA',
        FileTypes::GROUP_PRESENTATIONS => 'PRES',
        FileTypes::GROUP_IMAGES => 'PHOTO',
        FileTypes::GROUP_VIDEO => 'VIDEO',
        FileTypes::GROUP_AUDIO => 'AUDIO',
        // A zip is a container, not a kind of document. What is *inside* it is
        // filed per entry when it is extracted.
        FileTypes::GROUP_ARCHIVES => 'OTHER',
    ];

    /**
     * The few formats that say something the group does not.
     *
     * A TIFF is what a flatbed scanner writes, so it is a scanned copy rather
     * than a photograph. Deliberately short: every entry here is a claim
     * about intent read off an extension, which is the kind of guess this
     * class otherwise refuses to make.
     *
     * @var array<string, string>
     */
    private const BY_EXTENSION = [
        'tif' => 'SCAN',
        'tiff' => 'SCAN',
    ];

    public function forUpload(UploadedFile $upload): ?DocumentType
    {
        return $this->for((string) $upload->getClientOriginalExtension());
    }

    /**
     * The `extension` column, not the name.
     *
     * `FileStorage` normalises the extension onto the row at upload, so it is
     * the answer this application already settled on; re-deriving it from
     * `original_name` here would be a second parse of the same fact, and the
     * two would disagree for a file whose name carries no extension at all.
     */
    public function forFile(File $file): ?DocumentType
    {
        return $this->for((string) $file->extension);
    }

    /**
     * The type a file of this extension is filed as.
     *
     * Null is a real answer and the upload still succeeds — it simply is not
     * filed. Registration cannot invent a type, because the type's code *is*
     * the code prefix, and an office whose types have all been deactivated is
     * a broken install rather than a case to paper over. The backfill command
     * reports how many files it skipped for this reason.
     */
    public function for(string $extension): ?DocumentType
    {
        $extension = FileTypes::normalize($extension);
        $selectable = DocumentType::query()->active()->orderBy('position')->get();

        if ($selectable->isEmpty()) {
            return null;
        }

        $wanted = array_filter([
            self::BY_EXTENSION[$extension] ?? null,
            self::BY_GROUP[FileTypes::group($extension)] ?? null,
            // Anything the catalogue does not know at all still has to be
            // filed as something, and "others" is what the office calls that.
            'OTHER',
        ]);

        foreach ($wanted as $code) {
            $type = $selectable->firstWhere('code', $code);

            if ($type) {
                return $type;
            }
        }

        /*
         * Nothing matched, so take the picker's own first choice.
         *
         * An office that has deleted `OTHER` has chosen a vocabulary that
         * does not include "unclassified", and the first type by position is
         * what their own upload form offers first. Better a type they use
         * than a type we recreate behind their back: `firstOrCreate` here
         * would put a code into circulation that nobody chose, and a code is
         * forever.
         */
        return $selectable->first();
    }
}
