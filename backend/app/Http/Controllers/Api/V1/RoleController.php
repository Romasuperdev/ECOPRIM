<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Console\Role;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Rôles — catalogue CRUD (console_roles). */
class RoleController extends Controller
{
    public function index()
    {
        return Role::orderBy('nom')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', Rule::unique('ecoprim.console_roles', 'code')],
            'nom' => ['required', 'string', 'max:100'],
        ]);

        return response()->json(Role::create($data), 201);
    }

    public function destroy(Role $role)
    {
        $role->delete();

        return response()->noContent();
    }
}
