<?php

namespace App\Http\Controllers;

use App\Models\Section;
use App\Models\Division;
use Illuminate\Http\Request;

class SectionController extends Controller
{
    // Show all sections
    public function index()
    {
        $sections = Section::with('division')->get(); // Eager load division
        return view('sections.index', compact('sections'));
    }

    // Show create form
    public function create()
    {
        $divisions = Division::all(); // For division dropdown
        return view('sections.create', compact('divisions'));
    }

    // Store new section
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'division_id' => 'required|exists:divisions,id',
        ]);

        Section::create([
            'name' => $request->input('name'),
            'division_id' => $request->input('division_id'),
        ]);

        return redirect()->route('sections.index')->with('success', 'Section created successfully.');
    }

    // Show edit form
    public function edit($id)
    {
        $section = Section::findOrFail($id);
        $divisions = Division::all();
        return view('sections.edit', compact('section', 'divisions'));
    }

    // Update section
    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'division_id' => 'required|exists:divisions,id',
        ]);

        $section = Section::findOrFail($id);
        $section->update([
            'name' => $request->input('name'),
            'division_id' => $request->input('division_id'),
        ]);

        return redirect()->route('sections.index')->with('success', 'Section updated successfully.');
    }

    // Delete section
    public function destroy($id)
    {
        $section = Section::findOrFail($id);
        $section->delete();

        return redirect()->route('sections.index')->with('success', 'Section deleted successfully.');
    }

    // Optional: for dynamic dropdowns
    public function byDivision($divisionId)
    {
        $sections = Section::where('division_id', $divisionId)->get();
        return response()->json($sections);
    }
}
