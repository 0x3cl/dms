<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Division;

class DivisionController extends Controller
{
    public function index()
    {
        $divisions = Division::all();
        return view('Divisions.index', compact('divisions'));
    }

    public function create(Request $request)
    {
        // Pass the previous URL to the view for the "Return" button
        return view('Divisions.create', [
            'backUrl' => url()->previous()
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        Division::create($request->all());

        return redirect()->route('divisions.index')->with('success', 'Division created successfully.');
    }

    public function edit($id)
    {
        $division = Division::findOrFail($id);
        return view('Divisions.edit', compact('division'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $division = Division::findOrFail($id);
        $division->update($request->all());

        return redirect()->route('divisions.index')->with('success', 'Division updated successfully.');
    }

    public function destroy($id)
    {
        $division = Division::findOrFail($id);
        $division->delete();

        return redirect()->route('divisions.index')->with('success', 'Division deleted successfully.');
    }
}
