<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Console\AideArticle;
use App\Models\Console\AideCategorie;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Aide — documentation interne (base propre `ecoprim`).
 *
 * Deux publics, deux jeux de routes :
 *   - la LECTURE (tout compte connecté) : ne voit que l'actif — catégorie active ET
 *     article actif. Un article désactivé répond 404, même à qui connaît son adresse ;
 *   - la GESTION (Super Admin, console générale) : voit TOUT, actif ou non. C'est ce qui
 *     permet de réactiver ce qu'on a désactivé — un article retiré de la gestion en même
 *     temps que de la lecture ne pourrait plus jamais revenir.
 *
 * Le contenu est du texte brut : le serveur le rend tel quel, l'écran l'affiche comme du
 * texte. Rien n'est interprété comme du HTML, ni ici ni côté navigateur.
 */
class AideController extends Controller
{
    // --- Lecture --------------------------------------------------------------------

    /** Sommaire : catégories actives et leurs articles actifs, dans l'ordre choisi. */
    public function index()
    {
        $categories = AideCategorie::query()
            ->where('active', true)
            ->with(['articles' => fn ($q) => $q->where('active', true)])
            ->orderBy('ordre')->orderBy('titre')
            ->get();

        return $categories->map(fn (AideCategorie $c) => [
            ...$this->categorie($c),
            'articles' => $c->articles->map(fn (AideArticle $a) => $this->resumeArticle($a))->values(),
        ])->values();
    }

    /** Recherche plein texte (titre et contenu) parmi ce qu'un lecteur peut voir. */
    public function recherche(Request $request)
    {
        $d = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $terme = '%'.trim($d['q']).'%';

        return AideArticle::query()
            ->visible()
            ->with('categorie')
            ->where(fn ($q) => $q->where('titre', 'like', $terme)->orWhere('contenu', 'like', $terme))
            ->orderBy('titre')
            ->limit(50)
            ->get()
            ->map(fn (AideArticle $a) => [
                ...$this->resumeArticle($a),
                'categorie' => ['id' => $a->categorie->id, 'titre' => $a->categorie->titre],
                'extrait' => Str::limit(preg_replace('/\s+/u', ' ', $a->contenu), 200),
            ])
            ->values();
    }

    /**
     * Un article, s'il est visible — 404 sinon, sans distinguer « inexistant » de
     * « désactivé » : le lecteur n'a pas à savoir qu'il a existé. Accompagné de ses
     * voisins dans la catégorie, pour les liens précédent / suivant.
     */
    public function article(int $id)
    {
        $article = AideArticle::query()->visible()->with('categorie')->find($id);
        abort_unless($article, 404, 'Cet article d’aide est introuvable.');

        $freres = AideArticle::query()
            ->where('categorie_id', $article->categorie_id)
            ->where('active', true)
            ->orderBy('ordre')->orderBy('id')
            ->get(['id', 'titre']);
        $position = $freres->search(fn ($a) => $a->id === $article->id);
        $voisin = fn ($a) => $a ? ['id' => $a->id, 'titre' => $a->titre] : null;

        return [
            ...$this->resumeArticle($article),
            'contenu' => $article->contenu,
            'categorie' => $this->categorie($article->categorie),
            'precedent' => $voisin($position > 0 ? $freres[$position - 1] : null),
            'suivant' => $voisin($freres[$position + 1] ?? null),
        ];
    }

    // --- Gestion (Super Admin) ------------------------------------------------------

    /** Tout le contenu, actif ou non, pour l'écran de la console. */
    public function gestion()
    {
        return AideCategorie::query()
            ->with('articles')
            ->orderBy('ordre')->orderBy('titre')
            ->get()
            ->map(fn (AideCategorie $c) => [
                ...$this->categorie($c),
                'articles' => $c->articles->map(fn (AideArticle $a) => [
                    ...$this->resumeArticle($a),
                    'contenu' => $a->contenu,
                ])->values(),
            ])
            ->values();
    }

    public function storeCategorie(Request $request)
    {
        $categorie = AideCategorie::create($this->validerCategorie($request));

        return response()->json($this->categorie($categorie->refresh()), 201);
    }

    public function updateCategorie(Request $request, AideCategorie $categorie)
    {
        $categorie->update($this->validerCategorie($request));

        return response()->json($this->categorie($categorie->refresh()));
    }

    /** Supprime la catégorie ET ses articles (clé étrangère en cascade). */
    public function destroyCategorie(AideCategorie $categorie)
    {
        $categorie->delete();

        return response()->noContent();
    }

    public function storeArticle(Request $request)
    {
        $article = AideArticle::create($this->validerArticle($request));

        return response()->json([...$this->resumeArticle($article->refresh()), 'contenu' => $article->contenu], 201);
    }

    public function updateArticle(Request $request, AideArticle $article)
    {
        $article->update($this->validerArticle($request));

        return response()->json([...$this->resumeArticle($article->refresh()), 'contenu' => $article->contenu]);
    }

    public function destroyArticle(AideArticle $article)
    {
        $article->delete();

        return response()->noContent();
    }

    // --- Outils ---------------------------------------------------------------------

    private function validerCategorie(Request $request): array
    {
        $d = $request->validate([
            'titre' => ['required', 'string', 'max:200'],
            // Un nom d'icône lucide (« BookOpen ») : lettres et chiffres seulement.
            'icone' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9]+$/'],
            'ordre' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'active' => ['nullable', 'boolean'],
        ]);

        return [
            'titre' => trim($d['titre']),
            'icone' => $d['icone'] ?? null,
            'ordre' => $d['ordre'] ?? 0,
            'active' => $d['active'] ?? true,
        ];
    }

    private function validerArticle(Request $request): array
    {
        $d = $request->validate([
            'categorie_id' => ['required', 'integer', 'exists:ecoprim.aide_categories,id'],
            'titre' => ['required', 'string', 'max:200'],
            'contenu' => ['required', 'string', 'max:100000'],
            // Libellé d'un groupe du menu de l'application, choisi dans une liste côté écran.
            'module' => ['nullable', 'string', 'max:100'],
            'ordre' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'active' => ['nullable', 'boolean'],
        ]);

        return [
            'categorie_id' => $d['categorie_id'],
            'titre' => trim($d['titre']),
            'contenu' => $d['contenu'],
            'module' => filled($d['module'] ?? null) ? trim($d['module']) : null,
            'ordre' => $d['ordre'] ?? 0,
            'active' => $d['active'] ?? true,
        ];
    }

    private function categorie(AideCategorie $c): array
    {
        return [
            'id' => $c->id,
            'titre' => $c->titre,
            'icone' => $c->icone,
            'ordre' => (int) $c->ordre,
            'active' => (bool) $c->active,
        ];
    }

    private function resumeArticle(AideArticle $a): array
    {
        return [
            'id' => $a->id,
            'categorie_id' => (int) $a->categorie_id,
            'titre' => $a->titre,
            'module' => $a->module,
            'ordre' => (int) $a->ordre,
            'active' => (bool) $a->active,
        ];
    }
}
