<?php

namespace App\Http\Controllers;

use App\Models\DocumentTypeGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The headings document types are arranged under.
 *
 * A parent of a type, and managed exactly like one: its own create, edit and
 * delete, listed on the Document Types screen's second tab. It began as a
 * name typed into the type form — a group invented in passing while filing a
 * type — and that was the wrong shape for the obvious reason: a thing you can
 * create but never rename, reorder or remove is a thing the office is stuck
 * with the first time somebody mistypes one.
 *
 * **No permission of its own.** A group and a type are one screen and one
 * piece of office vocabulary, so `document-types.{read,write,delete}` gate
 * both. A second set of names would be a second half of one grant for a role
 * to end up holding — the same reason `files-browse.read` was retired.
 *
 * `code` is generated rather than asked for. Unlike a *type's* code it never
 * reaches a tracking code: it is only the stable handle a seeder matches on,
 * so there is nothing for an administrator to decide and one less field to
 * get wrong.
 */
class DocumentTypeGroupController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('document-type-groups/form', ['group' => null]);
    }

    public function edit(DocumentTypeGroup $documentTypeGroup): Response
    {
        return Inertia::render('document-type-groups/form', [
            'group' => [
                'id' => $documentTypeGroup->id,
                'code' => $documentTypeGroup->code,
                'name' => $documentTypeGroup->name,
                'description' => $documentTypeGroup->description,
                'position' => $documentTypeGroup->position,
                'types_count' => $documentTypeGroup->types()->withTrashed()->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request, null);

        DocumentTypeGroup::create([
            ...$validated,
            'code' => $this->code($validated['name']),
        ]);

        return to_route('document-types.index', ['tab' => 'groups'])
            ->with('success', 'Group added.');
    }

    /**
     * Rename, re-describe, reorder.
     *
     * The `code` is deliberately *not* editable and deliberately not
     * regenerated from a new name either: a seeder matches on it, so changing
     * it would make the next `db:seed` recreate the group this one replaced.
     * It is a handle, not a label — the label is `name`, and renaming is what
     * this screen is for.
     */
    public function update(Request $request, DocumentTypeGroup $documentTypeGroup): RedirectResponse
    {
        $documentTypeGroup->update($this->validated($request, $documentTypeGroup));

        return to_route('document-types.index', ['tab' => 'groups'])
            ->with('success', 'Group updated.');
    }

    /**
     * Delete a group.
     *
     * A hard delete, and safe to be one: `document_types.group_id` is
     * `nullOnDelete`, so the types inside survive and fall under "Other" in
     * the picker. A group is an arrangement of a list; a type is part of the
     * office's record, stamped into every tracking code it has issued.
     * Deleting the first must never retire the second.
     */
    public function destroy(DocumentTypeGroup $documentTypeGroup): RedirectResponse
    {
        $orphaned = $documentTypeGroup->types()->withTrashed()->count();

        $documentTypeGroup->delete();

        return to_route('document-types.index', ['tab' => 'groups'])
            ->with('success', $orphaned === 0
                ? 'Group deleted.'
                : "Group deleted. {$orphaned} type(s) moved to Other.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?DocumentTypeGroup $group): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                // Two groups with one name is two identical headings in the
                // picker, which reads as a bug in the picker.
                Rule::unique('document_type_groups', 'name')->ignore($group?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'position' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);
    }

    /** A unique handle derived from the name. Never shown, never edited. */
    private function code(string $name): string
    {
        $base = Str::limit(Str::upper(Str::slug($name, '_')), 24, '') ?: 'GROUP';
        $code = $base;

        while (DocumentTypeGroup::query()->where('code', $code)->exists()) {
            $code = Str::limit($base, 24, '').'_'.Str::upper(Str::random(4));
        }

        return $code;
    }
}
