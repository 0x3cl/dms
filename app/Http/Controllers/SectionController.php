<?php

namespace App\Http\Controllers;

use App\Models\Division;
use App\Models\Section;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SectionController extends Controller
{
    public function index(Request $request): Response
    {
        $entries = $this->entries($request);
        $search = $request->string('search')->toString();
        $division = $request->integer('division') ?: null;

        return Inertia::render('sections/index', [
            'sections' => Section::query()
                ->with('division:id,name')
                ->withCount('users')
                ->matching($search)
                ->when($division, fn ($query) => $query->where('division_id', $division))
                ->orderBy('name')
                ->paginate($entries)
                ->withQueryString(),
            'divisions' => Division::query()->orderBy('name')->get(['id', 'code', 'name']),
            'filters' => [
                'entries' => $entries,
                'search' => $search,
                'division' => $division,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('sections/form', [
            'section' => null,
            'divisions' => Division::query()->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Section::create($this->normalised($this->validated($request)));

        return to_route('sections.index')->with('success', 'Section created.');
    }

    /**
     * The resource route declares `sections.show`; it redirects to the edit
     * screen rather than 404ing on a link that looks like it should work.
     */
    public function show(Section $section): RedirectResponse
    {
        return to_route('sections.edit', $section);
    }

    public function edit(Section $section): Response
    {
        return Inertia::render('sections/form', [
            'section' => $section->only(['id', 'code', 'name', 'division_id', 'description']),
            'divisions' => Division::query()->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function update(Request $request, Section $section): RedirectResponse
    {
        $section->update($this->normalised($this->validated($request, $section)));

        return to_route('sections.edit', $section)->with('success', 'Section updated.');
    }

    public function destroy(Section $section): RedirectResponse
    {
        if ($section->users()->exists()) {
            return back()->with('error', 'That section still has staff assigned to it. Reassign them first.');
        }

        $section->delete();

        return to_route('sections.index')->with('success', 'Section deleted.');
    }

    /**
     * Sections of one division, as JSON.
     *
     * The registration page no longer needs this — it receives divisions with
     * their sections nested — but it stays for any caller that wants to narrow
     * a section picker without a page load.
     */
    public function byDivision(Division $division): JsonResponse
    {
        return response()->json(
            $division->sections()->orderBy('name')->get(['id', 'code', 'name', 'division_id'])
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Section $section = null): array
    {
        $ignore = $section?->id;

        // Both the code and the name are unique *within* a division, matching
        // the table's constraints: two divisions may each run a "Records"
        // section, but one division may not run two.
        return $request->validate([
            'division_id' => ['required', 'exists:divisions,id'],
            'code' => [
                'required', 'string', 'max:16', 'alpha_dash',
                Rule::unique('sections', 'code')
                    ->where(fn ($query) => $query->where('division_id', $request->integer('division_id')))
                    ->ignore($ignore),
            ],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('sections', 'name')
                    ->where(fn ($query) => $query->where('division_id', $request->integer('division_id')))
                    ->ignore($ignore),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalised(array $attributes): array
    {
        $attributes['code'] = strtoupper($attributes['code']);

        return $attributes;
    }

    private function entries(Request $request): int
    {
        $entries = $request->integer('entries', 10);

        return in_array($entries, [10, 25, 50], true) ? $entries : 10;
    }
}
