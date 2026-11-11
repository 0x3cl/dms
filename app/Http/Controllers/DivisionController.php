<?php

namespace App\Http\Controllers;

use App\Models\Division;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DivisionController extends Controller
{
    public function index(Request $request): Response
    {
        $entries = $this->entries($request);
        $search = $request->string('search')->toString();

        return Inertia::render('divisions/index', [
            'divisions' => Division::query()
                ->withCount(['sections', 'users'])
                ->matching($search)
                ->orderBy('name')
                ->paginate($entries)
                ->withQueryString(),
            'filters' => [
                'entries' => $entries,
                'search' => $search,
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('divisions/form', [
            'division' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Division::create($this->normalised($this->validated($request)));

        return to_route('divisions.index')->with('success', 'Division created.');
    }

    public function edit(Division $division): Response
    {
        return Inertia::render('divisions/form', [
            'division' => $division->only(['id', 'code', 'name', 'description']),
        ]);
    }

    public function update(Request $request, Division $division): RedirectResponse
    {
        $division->update($this->normalised($this->validated($request, $division)));

        // An edit returns to the record it edited rather than the list, so a
        // correction can be followed by another without re-navigating.
        return to_route('divisions.edit', $division)->with('success', 'Division updated.');
    }

    public function destroy(Division $division): RedirectResponse
    {
        // Sections cascade on delete and users fall back to null, so a division
        // with either attached is refused rather than silently taking them with
        // it.
        if ($division->sections()->exists() || $division->users()->exists()) {
            return back()->with('error', 'That division still has sections or staff assigned to it. Reassign them first.');
        }

        $division->delete();

        return to_route('divisions.index')->with('success', 'Division deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Division $division = null): array
    {
        $ignore = $division ? ",{$division->id}" : '';

        return $request->validate([
            // A code is the short, stable handle for a division: it survives a
            // rename, which is what makes it safe to print on a report or
            // compare against another system. Uppercased so two spellings of
            // the same code cannot both exist.
            'code' => ['required', 'string', 'max:16', 'alpha_dash', "unique:divisions,code{$ignore}"],
            'name' => ['required', 'string', 'max:255', "unique:divisions,name{$ignore}"],
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
