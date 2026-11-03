<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

/**
 * Photos des élèves : écriture dans le dossier partagé lu par ECONOMAT.
 *
 * T_ETUDIANT.Photo ne contient qu'un nom de fichier. Le nom est déterministe
 * (matricule ou code élève) : réimporter une photo remplace l'ancienne au lieu
 * d'accumuler des fichiers orphelins.
 *
 * L'EXTENSION VIENT DU CONTENU, jamais du nom envoyé par le navigateur : « x.php » ou
 * « x.html » rempli d'octets JPEG s'enregistre en « .jpg ». Le dossier est partagé et
 * parfois servi : un nom de fichier choisi par le client n'a rien à y décider.
 *
 * Les messages d'erreur rendus à l'écran ne citent jamais de chemin : le détail part au
 * journal.
 */
class PhotoEleveStockage
{
    public function dossier(): string
    {
        return rtrim((string) config('nexora.photos_eleves.chemin'), "/\\");
    }

    public function extensionsAutorisees(): array
    {
        return (array) config('nexora.photos_eleves.extensions', ['jpg', 'jpeg', 'png', 'webp']);
    }

    public function tailleMaxKo(): int
    {
        return (int) config('nexora.photos_eleves.taille_max_ko', 5120);
    }

    /**
     * Extension tirée du CONTENU du fichier (type MIME détecté), ramenée à la liste
     * blanche ; null si le contenu n'est pas une image admise.
     */
    public function extensionReelle(UploadedFile $fichier): ?string
    {
        $extension = strtolower((string) $fichier->guessExtension());
        if ($extension === 'jpe') {
            $extension = 'jpg';
        }

        return in_array($extension, $this->extensionsAutorisees(), true) ? $extension : null;
    }

    /**
     * Enregistre le fichier et renvoie le NOM DE FICHIER à écrire dans T_ETUDIANT.Photo.
     */
    public function enregistrer(UploadedFile $fichier, string $nomBase): string
    {
        $extension = $this->extensionReelle($fichier);
        if ($extension === null) {
            throw new RuntimeException('Format de photo non pris en charge : '
                .strtoupper(implode(', ', $this->extensionsAutorisees())).' uniquement.');
        }

        $dossier = $this->dossier();

        if (! is_dir($dossier) && ! @mkdir($dossier, 0775, true) && ! is_dir($dossier)) {
            Log::error('Dossier des photos inaccessible.', ['dossier' => $dossier]);

            throw new RuntimeException("Le dossier des photos est inaccessible. Prévenez l'administrateur.");
        }
        if (! is_writable($dossier)) {
            Log::error("Dossier des photos non accessible en écriture.", ['dossier' => $dossier]);

            throw new RuntimeException("Le dossier des photos n'est pas accessible en écriture. Prévenez l'administrateur.");
        }

        $nomFichier = $this->assainir($nomBase).'.'.$extension;

        try {
            $this->deplacerAvecReprise($fichier, $dossier, $nomFichier);
        } catch (FileException $e) {
            Log::error("Photo d'élève non enregistrée.", ['fichier' => $nomFichier, 'exception' => $e]);

            throw new RuntimeException("La photo n'a pas pu être enregistrée. Réessayez dans un instant.");
        }

        // Une photo par élève : les variantes d'une autre extension sont retirées — APRÈS
        // l'écriture de la nouvelle, pour qu'un échec ne laisse pas l'élève sans photo.
        // Y compris celles qu'aucune liste blanche n'admet plus (une ancienne « .php »).
        foreach (array_unique(array_merge($this->extensionsAutorisees(), ['jpe', 'gif', 'bmp', 'php', 'html', 'htm', 'svg'])) as $ext) {
            $ancien = $dossier.DIRECTORY_SEPARATOR.$this->assainir($nomBase).'.'.$ext;
            if ($ext !== $extension && is_file($ancien)) {
                @unlink($ancien);
            }
        }

        return $nomFichier;
    }

    /**
     * Déplace le fichier téléversé, avec quelques tentatives en cas d'échec transitoire.
     *
     * Sous Windows, un antivirus qui scanne le fichier fraîchement écrit dans le dossier
     * temporaire peut le verrouiller le temps d'un instant : `rename()` échoue alors avec
     * « Accès refusé », sans rapport avec la validité de l'upload — retenter quelques
     * dizaines de millisecondes plus tard suffit presque toujours.
     */
    private function deplacerAvecReprise(UploadedFile $fichier, string $dossier, string $nomFichier, int $tentatives = 4): void
    {
        for ($essai = 1; $essai <= $tentatives; $essai++) {
            try {
                $fichier->move($dossier, $nomFichier);

                return;
            } catch (FileException $e) {
                if ($essai === $tentatives) {
                    throw $e;
                }
                usleep(150_000 * $essai); // 150ms, 300ms, 450ms — 900ms cumulés au pire
            }
        }
    }

    /**
     * Chemin absolu d'une photo, ou null si absente.
     * La valeur venant de la base est réduite à son basename : aucune remontée
     * d'arborescence n'est possible même si la colonne contient un chemin complet.
     */
    public function chemin(?string $valeurEnBase): ?string
    {
        $nom = trim((string) $valeurEnBase);
        if ($nom === '') {
            return null;
        }

        $chemin = $this->dossier().DIRECTORY_SEPARATOR.basename(str_replace('\\', '/', $nom));

        return is_file($chemin) ? $chemin : null;
    }

    private function assainir(string $nom): string
    {
        $propre = preg_replace('/[^A-Za-z0-9._-]/', '-', $nom) ?? '';
        $propre = trim($propre, '-.');

        return $propre !== '' ? $propre : 'eleve';
    }
}
