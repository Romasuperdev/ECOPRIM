<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Cycle;

/** Cycles — lecture seule (ECONOMAT.T_CYCLE). */
class CycleController extends Controller
{
    public function index()
    {
        return Cycle::orderBy('LibelleCycle')->get();
    }
}
