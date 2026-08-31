<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

/** Rôles — lecture seule (dbmasterbacou.roles). */
class RoleController extends Controller
{
    public function index()
    {
        return DB::connection('master')->table('roles')->orderBy('name')->get(['id', 'name', 'code', 'codesociete']);
    }
}
