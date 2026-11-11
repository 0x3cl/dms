<?php

namespace App\Http\Controllers;

use App\Models\DocumentType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The kinds of document the office tracks, and the abbreviation each one
 * contributes to a tracking code.
 *
 * This list was `DocumentController::TYPES`, a PHP constant, so adding a type
 * or correcting an abbreviation was a deploy. It became a table; this is the
 * screen that finally makes it editable by the office rather than by a
 * developer with a console.
 *
 * Two rules are enforced here and not only in the UI, because both of them
 * protect records that are already printed on paper.
 */
class DocumentTypeController extends Controller
{
    public function index(Request $request): Response
    {
        $entries = $this->entries($request);
        $search = $request->string('search')->toString();
        $status = $request->string('status')->toString();

        return Inertia::render('document-types/index', [
            'types' => DocumentType::withTrashed()
                ->withCount(['documents' => fn ($query) => $query->withTrashed()])
                ->when($search !== '', fn ($query) => $query
                    ->where(fn ($inner) => $inner
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")))
                ->when($status === 'active', fn ($query) => $query->whereNull('deleted_at')->where('is_active', true))
                ->when($status === 'retired', fn ($query) => $query->whereNull('deleted_at')->where('is_active', false))
                ->when($status === 'deleted', fn ($query) => $query->whereNotNull('deleted_at'))
                ->orderBy('position')
                ->orderBy('name')
                ->paginate($entries)
                ->withQueryString()
                ->through(fn (DocumentType $type) => [
                    'id' => $type->id,
                    'code' => $type->code,
                    'name' => $type->name,
                    'description' => $type->description,
                    'is_active' => $type->is_active,
                    'position' => $type->position,
                    'deleted_at' => $type->deleted_at,
                    'documents_count' => $type->documents_count,
                    // Whether this type is part of the record now, rather than
                    // still just a list entry. Decides what may be changed.
                    'has_issued' => $type->hasIssued(),
                ]),
            'filters' => [
                'entries' => $entries,
                'search' => $search,
                'status' => $status,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('document-types/form', ['type' => null]);
    }

    public function edit(DocumentType $documentType): Response
    {
        return Inertia::render('document-types/form', [
            'type' => [
                'id' => $documentType->id,
                'code' => $documentType->code,
                'name' => $documentType->name,
                'description' => $documentType->description,
                'is_active' => $documentType->is_active,
                'position' => $documentType->position,
                'has_issued' => $documentType->hasIssued(),
                'documents_count' => $documentType->documentCount(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request, null);

        DocumentType::create([
            ...$validated,
            'code' => strtoupper($validated['code']),
        ]);

        return to_route('document-types.index')->with('success', 'Document type added.');
    }

    /**
     * Update a type.
     *
     * **`code` is immutable once the type has issued a tracking code.** Every
     * code that type has already handed out carries the old abbreviation, and
     * those are printed on labels stuck to physical folders — so changing it
     * would not correct history, it would make the labels disagree with the
     * system. The rule is applied here rather than only disabling the input,
     * because a disabled input is a suggestion.
     */
    public function update(Request $request, DocumentType $documentType): RedirectResponse
    {
        $validated = $this->validated($request, $documentType);

        $documentType->update([
            ...$validated,
            'code' => $documentType->hasIssued()
                ? $documentType->code
                : strtoupper($validated['code']),
        ]);

        return to_route('document-types.index')->with('success', 'Document type updated.');
    }

    /**
     * Delete a type.
     *
     * A soft delete, and it is safe for every document already filed under it:
     * `documents.type` carries the type's name as it stood at upload and is
     * what every screen renders, while `document_type_id` keeps pointing at the
     * row — so the document keeps both its label and its link, and the register
     * can still filter and count it.
     *
     * Nothing is refused here. The flash says what happened to the documents,
     * because "deleted a type" and "did not touch 40 documents" are both facts
     * the person needs, and silence about the second invites them to assume
     * the worst.
     */
    public function destroy(DocumentType $documentType): RedirectResponse
    {
        $count = $documentType->documentCount();

        $documentType->delete();

        return to_route('document-types.index')->with(
            'success',
            $count === 0
                ? 'Document type deleted.'
                : "Document type deleted. The {$count} document(s) already filed under it are unchanged and keep their type.",
        );
    }

    /** Bring a deleted type back, exactly as it was. */
    public function restore(DocumentType $documentType): RedirectResponse
    {
        $documentType->restore();

        return to_route('document-types.index')->with('success', 'Document type restored.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?DocumentType $type): array
    {
        return $request->validate([
            'code' => [
                'required', 'string', 'alpha_dash', 'max:16',
                // Unique across deleted ones too: a code is stamped into every
                // tracking code the type issued, so reusing one would make two
                // different types indistinguishable in the register.
                Rule::unique('document_types', 'code')->ignore($type?->id),
            ],
            'name' => ['required', 'string', 'max:255', Rule::unique('document_types', 'name')->ignore($type?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'position' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);
    }

    private function entries(Request $request): int
    {
        $entries = $request->integer('entries', 10);

        return in_array($entries, [10, 25, 50], true) ? $entries : 10;
    }
}
