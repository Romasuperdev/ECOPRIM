<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\JournalActivite;
use Illuminate\Http\Request;

// Journal en lecture seule : aucune méthode store/update/destroy exposée, y compris pour le Super Admin.
class JournalActiviteController extends Controller
{
    public function index(Request $request)
    {
        $query = JournalActivite::with('user');

        if ($request->filled('module')) {
            $query->where('module', $request->string('module'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        return $query->orderByDesc('created_at')->paginate(min($request->integer('per_page', 30), 200));
    }
}
