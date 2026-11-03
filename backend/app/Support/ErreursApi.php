<?php

namespace App\Support;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Ce que l'API répond quand une erreur TECHNIQUE survient.
 *
 * POURQUOI. Sans ce filtre, une table absente sur le serveur affichait à l'utilisateur
 * « SQLSTATE[42S02] … Nom d'objet 'T_NIVEAU' non valide (Connection: economat, SQL: …) » :
 * la requête, le nom des bases, parfois un chemin du serveur. Inutile pour l'utilisateur,
 * précieux pour un attaquant.
 *
 * DÉSORMAIS. L'utilisateur lit une phrase claire et une RÉFÉRENCE courte (ex. « E-7K2QH9 »).
 * Le détail complet part au journal (storage/logs/laravel.log) sous cette même référence :
 * l'administrateur retrouve la cause en une recherche. En local (APP_DEBUG=true), le
 * détail est aussi renvoyé dans `detail`, que l'interface n'affiche qu'en développement.
 *
 * Les erreurs « métier » ne sont pas touchées : validation (422), session (401), refus
 * (403), introuvable (404), jeton CSRF (419), trop de tentatives (429)… Elles portent
 * déjà un message écrit pour l'utilisateur.
 */
final class ErreursApi
{
    public static function enregistrer(Exceptions $exceptions): void
    {
        // Le journal porte la référence : on la crée une fois, au signalement, et le rendu
        // la réutilise.
        $exceptions->context(fn () => ['reference' => self::reference()]);

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! self::concerneApi($request) || self::estMetier($e)) {
                return null; // Laravel s'en charge, comme avant.
            }

            return self::reponse($e);
        });
    }

    private static function concerneApi(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    private static function estMetier(Throwable $e): bool
    {
        return $e instanceof HttpExceptionInterface
            || $e instanceof HttpResponseException
            || $e instanceof ValidationException
            || $e instanceof AuthenticationException;
    }

    private static function reponse(Throwable $e): JsonResponse
    {
        $reference = self::reference();
        [$statut, $code, $message] = self::traduire($e);

        Log::error("[{$reference}] {$code} sur ".request()->method().' '.request()->path(), [
            'utilisateur' => Auth::id(),
            'erreur' => $e->getMessage(),
        ]);

        $corps = [
            'message' => $message." (référence {$reference})",
            'code' => $code,
            'reference' => $reference,
        ];

        if (config('app.debug')) {
            $corps['detail'] = $e->getMessage();
        }

        return response()->json($corps, $statut);
    }

    /** @return array{int, string, string} statut HTTP, code stable, message pour l'écran */
    private static function traduire(Throwable $e): array
    {
        if ($e instanceof QueryException || $e instanceof PDOException) {
            $etat = (string) ($e instanceof QueryException ? ($e->errorInfo[0] ?? $e->getCode()) : $e->getCode());
            $texte = $e->getMessage();

            // Table, vue ou colonne absente : la base du serveur n'a pas la structure attendue.
            if (in_array($etat, ['42S02', '42S22'], true)
                || Str::contains($texte, ['non valide', 'Invalid object name', 'Invalid column name', 'no such table', 'no such column'])) {
                return [500, 'STRUCTURE_BASE',
                    "Cet écran ne peut pas s'afficher : une partie des données attendues est absente "
                    .'de la base sur ce serveur. Prévenez votre administrateur'];
            }

            // Serveur SQL injoignable, délai dépassé, compte refusé ou mot de passe expiré.
            if (Str::startsWith($etat, ['08', 'HYT', '28'])
                || Str::contains($texte, ['Login failed', 'Échec de l\'ouverture de session', 'timeout', 'expir'])) {
                return [503, 'BASE_INJOIGNABLE',
                    'La base de données ne répond pas pour le moment. Réessayez dans un instant ; '
                    .'si cela persiste, prévenez votre administrateur'];
            }

            return [500, 'ERREUR_BASE',
                "L'opération n'a pas pu aboutir à cause d'une erreur de base de données. "
                .'Réessayez, et prévenez votre administrateur si cela persiste'];
        }

        return [500, 'ERREUR_SERVEUR',
            "Une erreur inattendue s'est produite. Réessayez, et prévenez votre administrateur "
            .'si cela persiste'];
    }

    /** Une référence par requête, partagée entre le journal et la réponse. */
    private static function reference(): string
    {
        $requete = request();
        if (! $requete->attributes->has('reference_erreur')) {
            $requete->attributes->set('reference_erreur', 'E-'.Str::upper(Str::random(6)));
        }

        return $requete->attributes->get('reference_erreur');
    }
}
