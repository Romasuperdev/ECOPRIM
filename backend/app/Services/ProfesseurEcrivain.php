<?php

namespace App\Services;

/**
 * Écriture d'un enseignant dans ECONOMAT.dbo.T_PROFESSEUR (63 colonnes).
 *
 * Périmètre restreint : état civil, coordonnées, carrière pédagogique. Le SALAIRE
 * (SalaireMensuel) et les identifiants de connexion (Mdp) ne sont JAMAIS écrits ici.
 * Création et modification uniquement, jamais de suppression : un enseignant qui part
 * est renseigné via DateDepart / Motif.
 */
class ProfesseurEcrivain
{
    public const LARGEURS = [
        'matricule' => 50, 'nom' => 50, 'prenom' => 50, 'nom_complet' => 100,
        'sexe' => 50, 'adresse' => 50, 'telephone' => 50, 'cellulaire' => 50,
        'email' => 50, 'statut' => 50, 'grade' => 30, 'matiere' => 50,
        'date_embauche' => 50, 'date_naissance' => 50, 'lieu_naissance' => 100,
        'situation_matrimoniale' => 50, 'diplome' => 50, 'ville' => 50,
        'annee_code' => 50, 'motif_depart' => 500, 'etab_accueil' => 200,
    ];

    private const MAP = [
        'MatriculeProfesseur' => 'matricule',
        'NomProfesseur' => 'nom',
        'PrenomProfesseur' => 'prenom',
        'NomComplet' => 'nom_complet',
        'Sexe' => 'sexe',
        'AdresseProfesseur' => 'adresse',
        'ContactProfesseur' => 'telephone',
        'Cellulaire' => 'cellulaire',
        'EmailProfesseur' => 'email',
        'TypeProfesseur' => 'statut',
        'GradeProfesseur' => 'grade',
        'Matiere' => 'matiere',
        'DateEmbauche' => 'date_embauche',
        'DateNaiss' => 'date_naissance',
        'LieuNaiss' => 'lieu_naissance',
        'SituationMatrimoniale' => 'situation_matrimoniale',
        'DiplTitrUniv' => 'diplome',
        'Ville' => 'ville',
        'CodeAnnee' => 'annee_code',
        'DateDepart' => 'date_depart',
        'Motif' => 'motif_depart',
        'EtabAccueil' => 'etab_accueil',
    ];

    private function table(): EconomatTable
    {
        return EconomatTable::pour('T_PROFESSEUR', 'Code');
    }

    public function creer(array $d): int
    {
        return (int) $this->table()->inserer($this->colonnes($d));
    }

    public function modifier(int $code, array $d): void
    {
        $this->table()->modifier($code, $this->colonnes($d));
    }

    public function trouver(int $code)
    {
        return $this->table()->trouver($code);
    }

    public function matriculeExiste(string $matricule, ?int $sauf = null): bool
    {
        return $this->table()->requete()
            ->where('MatriculeProfesseur', $matricule)
            ->when($sauf, fn ($q) => $q->where('Code', '!=', $sauf))
            ->exists();
    }

    private function colonnes(array $d): array
    {
        $ligne = [];
        foreach (self::MAP as $colonne => $cle) {
            if (array_key_exists($cle, $d)) {
                $ligne[$colonne] = $d[$cle] === '' ? null : $d[$cle];
            }
        }

        // NomComplet est dérivé si l'appelant ne le fournit pas : utilisé par les listes ECONOMAT.
        if (! isset($ligne['NomComplet']) && (isset($d['nom']) || isset($d['prenom']))) {
            $ligne['NomComplet'] = trim(($d['prenom'] ?? '').' '.($d['nom'] ?? '')) ?: null;
        }

        return $ligne;
    }
}
