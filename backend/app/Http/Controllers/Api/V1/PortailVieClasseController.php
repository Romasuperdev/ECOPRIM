<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Devoir;
use App\Services\Classe\VieDeClasse;
use App\Support\PiecesJointes;
use App\Support\PorteeEnseignant;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Espace Enseignant : devoirs (avec photo du tableau ou d'une fiche) et annonces aux
 * parents d'une de ses classes.
 */
class PortailVieClasseController extends Controller
{
    public function __construct(private VieDeClasse $vie) {}

    private function assertMaClasse(Request $request, ?string $classe): void
    {
        if (! $classe || ! in_array($classe, PorteeEnseignant::classes($request->user()), true)) {
            throw new HttpException(403, "Cette classe n'est pas parmi celles que vous enseignez.");
        }
    }

    private function monDevoir(Request $request, int $id): Devoir
    {
        $devoir = Devoir::find($id);
        abort_unless($devoir, 404, 'Devoir introuvable.');
        $this->assertMaClasse($request, $devoir->classe_code);

        return $devoir;
    }

    private function assertAuteur(Request $request, Devoir $devoir): void
    {
        if (! $this->vie->estAuteurDevoir($request->user(), $devoir)) {
            throw new HttpException(403, "Ce devoir a été donné par un collègue : vous ne pouvez pas le modifier.");
        }
    }

    public function devoirs(Request $request)
    {
        $classe = $request->validate(['classe' => ['required', 'string', 'max:50']])['classe'];
        $this->assertMaClasse($request, $classe);

        return ['devoirs' => $this->vie->devoirs([$classe], $request->user())];
    }

    public function creerDevoir(Request $request)
    {
        $d = $request->validate([
            'classe' => ['required', 'string', 'max:50'],
            'matiere' => ['required', 'string', 'max:50'],
            'titre' => ['required', 'string', 'max:150'],
            'consigne' => ['nullable', 'string', 'max:5000'],
            'date_remise' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'pieces' => ['nullable', 'array', 'max:'.VieDeClasse::PIECES_MAX],
            'pieces.*' => PiecesJointes::regles(),
        ], [
            'date_remise.after_or_equal' => 'Un devoir se donne pour aujourd’hui ou un jour à venir.',
            'pieces.max' => 'Quatre photos au plus par devoir.',
            'pieces.*.max' => 'Une photo dépasse '.(PiecesJointes::TAILLE_MAX_KO / 1024).' Mo.',
            'pieces.*.extensions' => 'Joignez des photos (JPG, PNG, HEIC) ou des PDF.',
        ]);
        if (! PorteeEnseignant::enseigne($request->user(), $d['classe'], $d['matiere'])) {
            throw new HttpException(403, "Vous n'enseignez pas cette matière dans cette classe.");
        }

        $devoir = $this->vie->creerDevoir($request->user(), $d, $request->file('pieces', []));

        return response()->json(
            collect($this->vie->devoirs([$devoir->classe_code], $request->user()))->firstWhere('id', $devoir->id),
            201
        );
    }

    public function modifierDevoir(Request $request, int $devoir)
    {
        $d = $this->monDevoir($request, $devoir);
        $this->assertAuteur($request, $d);
        $donnees = $request->validate([
            'titre' => ['sometimes', 'required', 'string', 'max:150'],
            'consigne' => ['nullable', 'string', 'max:5000'],
            'date_remise' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $this->vie->modifierDevoir($d, $donnees);

        return collect($this->vie->devoirs([$d->classe_code], $request->user()))->firstWhere('id', $d->id);
    }

    public function supprimerDevoir(Request $request, int $devoir)
    {
        $d = $this->monDevoir($request, $devoir);
        $this->assertAuteur($request, $d);
        $this->vie->supprimerDevoir($d);

        return response()->noContent();
    }

    public function piece(Request $request, int $devoir, int $piece)
    {
        $this->monDevoir($request, $devoir);
        $p = $this->vie->piece($devoir, $piece);

        return PiecesJointes::servir(VieDeClasse::DOSSIER_DEVOIRS, $p->fichier, $p->fichier_type);
    }

    public function annonces(Request $request)
    {
        $classe = $request->validate(['classe' => ['required', 'string', 'max:50']])['classe'];
        $this->assertMaClasse($request, $classe);

        return ['annonces' => $this->vie->annonces([$classe], $request->user())];
    }

    public function creerAnnonce(Request $request)
    {
        $d = $request->validate([
            'classe' => ['required', 'string', 'max:50'],
            'titre' => ['required', 'string', 'max:150'],
            'message' => ['nullable', 'string', 'max:2000'],
            'photo' => array_merge(['nullable'], PiecesJointes::regles()),
        ], [
            'photo.max' => 'La photo dépasse '.(PiecesJointes::TAILLE_MAX_KO / 1024).' Mo.',
            'photo.extensions' => 'Joignez une photo (JPG, PNG, HEIC) ou un PDF.',
        ]);
        $this->assertMaClasse($request, $d['classe']);

        return response()->json($this->vie->creerAnnonce($request->user(), $d, $request->file('photo')), 201);
    }

    public function supprimerAnnonce(Request $request, int $annonce)
    {
        $a = $this->vie->annonce($annonce);
        $this->assertMaClasse($request, $a->classe_code);
        if ((int) $a->rh_user_id !== (int) $request->user()->Id) {
            throw new HttpException(403, 'Cette annonce a été publiée par un collègue.');
        }
        $this->vie->supprimerAnnonce($a);

        return response()->noContent();
    }

    public function photoAnnonce(Request $request, int $annonce)
    {
        $a = $this->vie->annonce($annonce);
        $this->assertMaClasse($request, $a->classe_code);

        return PiecesJointes::servir(VieDeClasse::DOSSIER_ANNONCES, $a->fichier, $a->fichier_type);
    }
}
