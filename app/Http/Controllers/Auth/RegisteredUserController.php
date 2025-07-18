<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Division;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        $divisions = Division::with('sections')->get();
        return view('auth.register', compact('divisions'));
    }

    public function store(Request $request)
    {

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'employee_id' => ['required', 'string', 'max:50', 'unique:users'],
            'division_id' => ['required', 'exists:divisions,id'],
            'section_id' => ['required', 'exists:sections,id'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        User::create([
            'name' => $request->name,
            'employee_id' => $request->employee_id,
            'division_id' => $request->division_id,
            'section_id' => $request->section_id,
            'password' => Hash::make($request->password),
        ]);

        return redirect()->route('login')->with('status', 'Registration successful! You may now log in.');
    }
}
