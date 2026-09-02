<?php

namespace App\Services;

/**
 * Écriture d'un élève dans ECONOMAT.dbo.T_ETUDIANT (74 colonnes).
 *
 * Périmètre volontairement restreint : identité, scolarité et filiation seulement.
 * Le FINANCIER (Scolarite, TotalPaye, Rb_PC, Rb_SCO, Remise, PC…) et le TECHNIQUE
 * (MDP, MDP2, NUM…) ne sont JAMAIS écrits par NEXORA — ils appartiennent à ECONOMAT.
 * Création et modification uniquement, jamais de suppression.
 */
class EtudiantEcrivain
{
    /** Largeurs réelles des colonnes écrites. */
    public const LARGEURS = [
        'matricule' => 255, 'nom' => 255, 'prenom' => 255, 'sexe' => 17,
        'lieu_naissance' => 255, 'nationalite' => 255, 'adresse' => 50,
        'email' => 50, 'telephone' => 15, 'ville' => 50, 'commune' => 50, 'quartier' => 50,
        'classe_code' => 50, 'niveau_code' => 50, 'cycle_code' => 50, 'annee' => 50,
        'redoublant' => 50, 'etab_origine' => 50, 'niveau_origine' => 255,
        'pere_nom' => 50, 'pere_prenom' => 50, 'pere_profession' => 50,
        'pere_telephone' => 50, 'pere_email' => 50,
        'mere_nom' => 50, 'mere_prenom' => 50, 'mere_profession' => 50,
        'mere_telephone' => 50, 'mere_email' => 50,
        'societe_code' => 50,
    ];

    /** Champ applicatif -> colonne réelle de T_ETUDIANT. */
    private const MAP = [
        'Matricule' => 'matricule', 'Nom' => 'nom', 'Prenom' => 'prenom', 'Sexe' => 'sexe',
        'DateNaiss' => 'date_naissance', 'LieuNaiss' => 'lieu_naissance', 'Nationalite' => 'nationalite',
        'Adresse' => 'adresse', 'Email' => 'email', 'Telephone' => 'telephone',
        'Ville' => 'ville', 'Commune' => 'commune', 'Quartier' => 'quartier',
        'CodeClasse' => 'classe_code', 'CodeNiveau' => 'niveau_code', 'CodeCycle' => 'cycle_code',
        'AnneeAcad' => 'annee', 'Redoublant' => 'redoublant',
        'EtabOrigine' => 'etab_origine', 'NiveauOrigine' => 'niveau_origine',
        'DateInscription' => 'date_inscription',
        'NomPereTuteur' => 'pere_nom', 'PrenomPereTuteur' => 'pere_prenom',
        'ProfessionPereTuteur' => 'pere_profession', 'TelephonePereTuteur' => 'pere_telephone',
        'EmailPereTuteur' => 'pere_email',
        'NomMere' => 'mere_nom', 'PrenomMere' => 'mere_prenom', 'ProfessionMere' => 'mere_profession',
        'TelephoneMere' => 'mere_telephone', 'EmailMere' => 'mere_email',
        'CODESOCIETE' => 'societe_code',
    ];

    /** Les quatre mouvements possibles, traduits en indicateurs T_ETUDIANT. */
    public const MOUVEMENTS = ['inscription', 'reinscription', 'transfert_entrant', 'transfert_sortant'];

    private function table(): EconomatTable
    {
        return EconomatTable::pour('T_ETUDIANT', 'Code');
    }

    public function creer(array $d): int
    {
        $ligne = $this->colonnes($d) + $this->indicateurs($d['mouvement'] ?? 'inscription') + [
            'Etat' => 1,
            'DateInscription' => $d['date_inscription'] ?? now()->toDateString(),
        ];

        return (int) $this->table()->inserer($ligne);
    }

    public function modifier(int $code, array $d): void
    {
        $ligne = $this->colonnes($d);
        if (! empty($d['mouvement'])) {
            $ligne += $this->indicateurs($d['mouvement']);
        }
        $this->table()->modifier($code, $ligne);
    }

    public function trouver(int $code)
    {
        return $this->table()->trouver($code);
    }


    /** L'élève portant ce matricule, ou null. T_ETUDIANT ne garde qu'une ligne par élève. */
    public function parMatricule(string $matricule)
    {
        return $this->table()->requete()->where('Matricule', trim($matricule))->first();
    }

    /**
     * Doublon d'identité : même nom, même prénom et même date de naissance.
     * Filet de sécurité derrière l'unicité du matricule — deux matricules différents
     * peuvent désigner la même personne saisie deux fois.
     */
    public function memeIdentite(array $d)
    {
        $nom = trim((string) ($d['nom'] ?? ''));
        $prenom = trim((string) ($d['prenom'] ?? ''));
        if ($nom === '' || $prenom === '') {
            return null;
        }

        $requete = $this->table()->requete()
            ->whereRaw('LOWER(Nom) = ?', [mb_strtolower($nom)])
            ->whereRaw('LOWER(Prenom) = ?', [mb_strtolower($prenom)]);

        // Sans date de naissance, deux homonymes sont plausibles : on ne bloque pas.
        $naissance = trim((string) ($d['date_naissance'] ?? ''));
        if ($naissance === '') {
            return null;
        }
        $requete->whereDate('DateNaiss', substr($naissance, 0, 10));

        return $requete->first();
    }

    public function matriculeExiste(string $matricule, ?int $sauf = null): bool
    {
        return $this->table()->requete()
            ->where('Matricule', $matricule)
            ->when($sauf, fn ($q) => $q->where('Code', '!=', $sauf))
            ->exists();
    }

    /** Ne construit que les colonnes réellement fournies : rien n'est écrasé par du vide. */
    private function colonnes(array $d): array
    {
        $ligne = [];
        foreach (self::MAP as $colonne => $cle) {
            if (array_key_exists($cle, $d)) {
                $ligne[$colonne] = $d[$cle] === '' ? null : $d[$cle];
            }
        }

        return $ligne;
    }

    private function indicateurs(string $mouvement): array
    {
        return [
            'Inscription' => $mouvement === 'inscription' ? 1 : 0,
            'Reinscription' => $mouvement === 'reinscription' ? 1 : 0,
            'Transfert' => str_starts_with($mouvement, 'transfert') ? 1 : 0,
        ];
    }
}
