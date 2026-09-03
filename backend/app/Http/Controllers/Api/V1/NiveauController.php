<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Niveau;
use App\Services\ReferentielEcrivain;
use App\Support\AnneeScolaireGuard;
use App\Support\ContexteScolaire;
use App\Support\DependancesReferentiel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Niveaux — ECONOMAT.T_NIVEAU.
 *
 * Un niveau appartient à une année : il est créé dans l'année de travail, et une année
 * clôturée reste en consultation seule.
 */
class NiveauController extends Controller
{
    private const LIENS = [
        ['table' => 'T_CLASSE', 'colonne' => 'CodN', 'libelle' => 'classe'],
        ['table' => 'T_PREREQUIS', 'colonne' => 'CODENIVEAU', 'libelle' => 'document paramétré'],
    ];

    private ReferentielEcrivain $ecrivain;

    public function __construct()
    {
        $this->ecrivain = new ReferentielEcrivain('T_NIVEAU', 'Num', [
            'code' => 'CodeNiveau',
            'libelle' => 'LibelleNiveau',
            'cycle_code' => 'CodeCycle',
            'ordre' => 'Ordre',
            'annee' => 'ANNEE',
        ]);
    }

    public function index()
    {
        return Niveau::with('cycle')
            ->tap(fn ($q) => ContexteScolaire::appliquer($q, 'ANNEE'))
            ->orderBy('Ordre')->get();
    }

    public function show(Niveau $niveau)
    {
        return $niveau->load('cycle');
    }

    private function regles(bool $creation): array
    {
        return [
            'code' => [$creation ? 'required' : 'sometimes', 'string', 'max:20'],
            'libelle' => [$creation ? 'required' : 'sometimes', 'string', 'max:60'],
            'cycle_code' => ['nullable', 'string', 'max:20'],
            'ordre' => ['nullable', 'integer', 'min:0', 'max:99'],
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->regles(true));
        $annee = ContexteScolaire::annee();
        AnneeScolaireGuard::assertModifiable($annee, "La création d'un niveau");
        $this->assertCodeLibre($data['code'], $annee);
        $this->assertCycleConnu($data['cycle_code'] ?? null);

        $num = $this->ecrivain->creer($data + ['annee' => $annee]);

        return response()->json(Niveau::where('Num', $num)->firstOrFail(), 201);
    }

    public function update(Request $request, Niveau $niveau)
    {
        $data = $request->validate($this->regles(false));
        $num = $niveau->getRawOriginal('Num');
        AnneeScolaireGuard::assertModifiable($niveau->getRawOriginal('ANNEE'), 'La modification de ce niveau');

        if (isset($data['code'])) {
            $this->assertCodeLibre($data['code'], $niveau->getRawOriginal('ANNEE'), $num);
        }
        if (array_key_exists('cycle_code', $data)) {
            $this->assertCycleConnu($data['cycle_code']);
        }

        $this->ecrivain->modifier($num, $data);

        return response()->json(Niveau::where('Num', $num)->firstOrFail());
    }

    public function destroy(Niveau $niveau)
    {
        AnneeScolaireGuard::assertModifiable($niveau->getRawOriginal('ANNEE'), 'La suppression de ce niveau');
        DependancesReferentiel::assertRetraitPossible(
            self::LIENS, $niveau->getRawOriginal('CodeNiveau'), 'Ce niveau'
        );

        $this->ecrivain->supprimer($niveau->getRawOriginal('Num'));

        return response()->noContent();
    }

    /** Un cycle inconnu laisserait le niveau rattaché à un code qui n'existe pas. */
    private function assertCycleConnu(?string $cycle): void
    {
        $cycle = trim((string) $cycle);
        if ($cycle === '') {
            return;
        }

        try {
            $existe = DB::connection('economat')->table('T_CYCLE')->where('CodeCycle', $cycle)->exists();
        } catch (\Throwable $e) {
            return; // Référentiel injoignable : on ne bloque pas sur ce qu'on ne peut vérifier.
        }

        if (! $existe) {
            throw ValidationException::withMessages([
                'cycle_code' => ["Le cycle « {$cycle} » n'existe pas dans le référentiel."],
            ]);
        }
    }

    /** Un code de niveau est unique DANS une année : il se réutilise d'une année sur l'autre. */
    private function assertCodeLibre(string $code, ?string $annee, $sauf = null): void
    {
        $existe = $this->ecrivain->requete()
            ->where('CodeNiveau', $code)
            ->when($annee, fn ($q) => $q->where('ANNEE', $annee))
            ->when($sauf !== null, fn ($q) => $q->where('Num', '!=', $sauf))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages([
                'code' => ["Le niveau « {$code} » existe déjà pour cette année."],
            ]);
        }
    }
}
