<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Photos des élèves : écriture dans le dossier partagé lu par ECONOMAT.
 *
 * T_ETUDIANT.Photo ne contient qu'un nom de fichier. Le nom est déterministe
 * (matricule ou code élève) : réimporter une photo remplace l'ancienne au lieu
 * d'accumuler des fichiers orphelins.
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
        return (int) config('nexora.photos_eleves.taille_max_ko', 4096);
    }

    /**
     * Enregistre le fichier et renvoie le NOM DE FICHIER à écrire dans T_ETUDIANT.Photo.
     */
    public function enregistrer(UploadedFile $fichier, string $nomBase): string
    {
        $dossier = $this->dossier();

        if (! is_dir($dossier) && ! @mkdir($dossier, 0775, true) && ! is_dir($dossier)) {
            throw new RuntimeException("Le dossier des photos est inaccessible : {$dossier}");
        }
        if (! is_writable($dossier)) {
            throw new RuntimeException("Le dossier des photos n'est pas accessible en écriture : {$dossier}");
        }

        $extension = strtolower($fichier->getClientOriginalExtension() ?: 'jpg');
        $nomFichier = $this->assainir($nomBase).'.'.$extension;

        // Une photo par élève : on retire les variantes d'extension déjà présentes.
        foreach ($this->extensionsAutorisees() as $ext) {
            $ancien = $dossier.DIRECTORY_SEPARATOR.$this->assainir($nomBase).'.'.$ext;
            if ($ancien !== $dossier.DIRECTORY_SEPARATOR.$nomFichier && is_file($ancien)) {
                @unlink($ancien);
            }
        }

        $fichier->move($dossier, $nomFichier);

        return $nomFichier;
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
