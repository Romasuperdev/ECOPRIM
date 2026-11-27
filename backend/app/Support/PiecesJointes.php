<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Fichiers envoyés depuis les espaces Enseignant et Parent (photos du tableau, fiches,
 * justificatifs…) : rangés HORS du dossier public, sous storage/app/<dossier>/AAAA/MM/,
 * avec un nom tiré au hasard. On ne les sert que par l'API, après contrôle d'accès.
 */
final class PiecesJointes
{
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'pdf'];

    public const TAILLE_MAX_KO = 8192;

    /** @return array{fichier: string, fichier_nom: string, fichier_type: string, taille: int} */
    public static function ranger(UploadedFile $fichier, string $dossier): array
    {
        $extension = strtolower($fichier->getClientOriginalExtension() ?: $fichier->extension() ?: 'jpg');
        $relatif = now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
        $absolu = self::chemin($dossier, $relatif);
        $infos = [
            'fichier' => $relatif,
            'fichier_nom' => mb_substr($fichier->getClientOriginalName(), 0, 255),
            'fichier_type' => mb_substr((string) $fichier->getClientMimeType(), 0, 100),
            'taille' => (int) $fichier->getSize(),
        ];

        File::ensureDirectoryExists(dirname($absolu));
        $fichier->move(dirname($absolu), basename($absolu));

        return $infos;
    }

    public static function chemin(string $dossier, string $relatif): string
    {
        return storage_path('app/'.$dossier.'/'.$relatif);
    }

    /** Réponse HTTP du fichier, ou 404. L'appelant a vérifié le droit de le voir. */
    public static function servir(string $dossier, ?string $relatif, ?string $type)
    {
        $chemin = $relatif ? self::chemin($dossier, $relatif) : null;
        abort_unless($chemin && File::exists($chemin), 404, 'Fichier introuvable.');

        return response()->file($chemin, array_filter([
            'Content-Type' => $type,
            'Cache-Control' => 'private, max-age=86400',
        ]));
    }

    public static function supprimer(string $dossier, ?string $relatif): void
    {
        if ($relatif) {
            File::delete(self::chemin($dossier, $relatif));
        }
    }

    /** Règles de validation d'un fichier joint. */
    public static function regles(): array
    {
        return ['file', 'max:'.self::TAILLE_MAX_KO, 'extensions:'.implode(',', self::EXTENSIONS)];
    }
}
