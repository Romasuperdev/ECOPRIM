<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SocieteApplicationEcrivain;
use Illuminate\Http\Request;

/**
 * Société - Applications : quelles applications de la suite sont ouvertes à quelle
 * société (dbmasterbacou.US_SOCIETE_APPLICATION), comme l'écran parcauto du même nom.
 * Super Admin seul (groupe `console:generale`).
 */
class SocieteApplicationController extends Controller
{
    public function __construct(private SocieteApplicationEcrivain $ecrivain) {}

    /** Les sociétés avec leurs applications, et le catalogue pour le formulaire. */
    public function index()
    {
        return [
            'societes' => $this->ecrivain->lister(),
            'applications' => $this->ecrivain->applications(),
        ];
    }

    /** Enregistre la liste complète des applications cochées pour la société. */
    public function synchroniser(Request $request, string $code)
    {
        $data = $request->validate([
            'applications' => ['present', 'array'],
            'applications.*' => ['string', 'max:50'],
        ]);

        return response()->json($this->ecrivain->synchroniser($code, $data['applications']));
    }

    /** Le × d'une pastille : détache une seule application. */
    public function detacher(string $code, string $codeApp)
    {
        $this->ecrivain->detacher($code, $codeApp);

        return response()->noContent();
    }

    /** « Retirer tout ». */
    public function retirerTout(string $code)
    {
        return response()->json(['retirees' => $this->ecrivain->retirerTout($code)]);
    }
}
