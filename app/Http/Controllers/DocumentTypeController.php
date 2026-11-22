<?php

namespace App\Http\Controllers;

use App\Models\DocumentType;
use App\Models\DocumentTypeGroup;
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
    /**
     * The groups, which is what this screen opens on.
     *
     * A flat table of every type was the whole module, and it answered the
     * wrong question first: an office looking at twenty rows wants to know
     * what *kinds* of thing it files before which exact type. So the landing
     * is the shelf, and a group is opened to see what is on it.
     *
     * **"Other" is a row here, not an omission.** Types with no group are
     * real and have to be reachable, and `group_id` is `nullOnDelete`, so
     * deleting a group produces some. A set that exists and cannot be opened
     * is how rows go missing in a screen that looks complete.
     */
    public function index(): Response
    {
        $groups = DocumentTypeGroup::query()
            ->withCount([
                'types as types_count' => fn ($query) => $query->withTrashed(),
                // What is actually on offer today, which is the number
                // somebody is looking for; the first is what exists at all.
                'types as active_types_count' => fn ($query) => $query->where('is_active', true),
            ])
            ->orderBy('position')->orderBy('name')
            ->get(['id', 'code', 'name', 'description', 'position']);

        return Inertia::render('document-types/index', [
            'groups' => $groups,
            'ungrouped' => [
                'types_count' => DocumentType::withTrashed()->whereNull('group_id')->count(),
                'active_types_count' => DocumentType::query()->whereNull('group_id')->where('is_active', true)->count(),
            ],
            'totals' => [
                'types' => DocumentType::withTrashed()->count(),
                'active' => DocumentType::query()->where('is_active', true)->count(),
            ],
        ]);
    }

    /**
     * The types inside one group.
     *
     * `$group` is a group's id, `other` for the ungrouped ones or `all` for
     * every type. Three sets on one screen rather than three screens, because
     * which subset somebody wants is still a filter — what changed is that
     * the *group* is now the thing they navigate to, so it is a path segment
     * rather than a query parameter.
     */
    public function group(Request $request, string $group): Response
    {
        $entries = $this->entries($request);
        $search = $request->string('search')->toString();
        $status = $request->string('status')->toString();

        $model = ctype_digit($group) ? DocumentTypeGroup::query()->findOrFail($group) : null;

        abort_unless($model !== null || in_array($group, ['other', 'all'], true), 404);

        return Inertia::render('document-types/group', [
            'group' => $model === null
                ? [
                    'id' => $group,
                    'name' => $group === 'all' ? 'All types' : 'Other (ungrouped)',
                    'description' => $group === 'all'
                        ? 'Every type the office files under, whichever group it sits in.'
                        : 'Types with no group. They are offered last in the picker, under this heading.',
                    'editable' => false,
                ]
                : [
                    'id' => $model->id,
                    'name' => $model->name,
                    'description' => $model->description,
                    'position' => $model->position,
                    'editable' => true,
                ],
            'types' => DocumentType::withTrashed()
                ->withCount(['documents' => fn ($query) => $query->withTrashed()])
                ->when($model !== null, fn ($query) => $query->where('group_id', $model->id))
                ->when($group === 'other', fn ($query) => $query->whereNull('group_id'))
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

    public function create(Request $request): Response
    {
        return Inertia::render('document-types/form', [
            'type' => null,
            'groups' => DocumentTypeGroup::ordered(),
            // Reached from inside a group, the form opens already filed there
            // — the question has been answered once already and asking it
            // again is how a type ends up in the wrong place.
            'presetGroup' => DocumentTypeGroup::query()
                ->whereKey($request->integer('group'))
                ->value('id'),
        ]);
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
                'group_id' => $documentType->group_id,
                'has_issued' => $documentType->hasIssued(),
                'documents_count' => $documentType->documentCount(),
            ],
            'groups' => DocumentTypeGroup::ordered(),
            'presetGroup' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request, null);

        $type = DocumentType::create([
            ...$validated,
            'code' => strtoupper($validated['code']),
        ]);

        return $this->backToGroup($type)->with('success', 'Document type added.');
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

        return $this->backToGroup($documentType)->with('success', 'Document type updated.');
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

        // `back()`, not the group route: delete is pressed from a list that
        // may be searched, filtered and on page three, and sending somebody
        // to the top of it loses where they were for no reason.
        return back()->with(
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

        return back()->with('success', 'Document type restored.');
    }

    /**
     * Back to the list the type belongs to, which is where it came from.
     *
     * A type with no group lives in "Other" — a real set with its own page,
     * not a gap — so there is always somewhere to return to.
     */
    private function backToGroup(DocumentType $type): RedirectResponse
    {
        return to_route('document-types.group', ['group' => $type->group_id ?? 'other']);
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
            // A heading in the picker, and an optional one: an ungrouped type
            // shows under "Other" rather than disappearing, which is also
            // what deleting a group leaves behind.
            'group_id' => ['nullable', 'integer', Rule::exists('document_type_groups', 'id')],
        ]);
    }

    private function entries(Request $request): int
    {
        $entries = $request->integer('entries', 10);

        return in_array($entries, [10, 25, 50], true) ? $entries : 10;
    }
}
