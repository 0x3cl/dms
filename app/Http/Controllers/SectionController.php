<?php

namespace App\Http\Controllers;

use App\Models\Section;
use App\Models\Department;
use Illuminate\Http\Request;

class SectionController extends Controller
{
    // Show all sections
    public function index()
    {
        $sections = Section::with('department')->get(); // eager load department
        return view('sections.index', compact('sections'));
    }

    // Show create form
    public function create()
    {
        $departments = Department::all(); // for dropdown
        return view('sections.create', compact('departments'));
    }

    // Store new section
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'department_id' => 'required|exists:departments,id',
        ]);

        Section::create([
            'name' => $request->input('name'),
            'department_id' => $request->input('department_id'),
        ]);

        return redirect()->route('sections.index')->with('success', 'Section created successfully.');
    }

    // Show edit form
    public function edit($id)
    {
        $section = Section::findOrFail($id);
        $departments = Department::all();
        return view('sections.edit', compact('section', 'departments'));
    }

    // Update section
    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'department_id' => 'required|exists:departments,id',
        ]);

        $section = Section::findOrFail($id);
        $section->update([
            'name' => $request->input('name'),
            'department_id' => $request->input('department_id'),
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
    public function byDepartment($departmentId)
    {
    $sections = \App\Models\Section::where('department_id', $departmentId)->get();
    return response()->json($sections);
    }

}
