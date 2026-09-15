<?php

namespace App\Support;

/**
 * Catalogue fixe des permissions accordables à un rôle (console_roles). Fixe et porté par
 * le code — comme les types d'évaluation — pas éditable depuis l'écran : seule
 * l'association rôle → permissions l'est (table `console_role_permissions`).
 *
 * Un Super Admin, un Admin Société et un Admin Établissement passent toujours
 * (`RhUser::aLaPermission()`) : ce catalogue sert aux rôles « métier » qui ne sont pas
 * déjà administrateurs de la console (Enseignant, Secrétaire, Comptable...).
 */
class Permissions
{
    public const CATALOGUE = [
        'consulter_classes' => ['libelle' => 'Consulter les classes', 'groupe' => 'Pédagogie'],
        'consulter_emploi_du_temps' => ['libelle' => "Consulter l'emploi du temps", 'groupe' => 'Pédagogie'],
        'modifier_emplois_du_temps' => ['libelle' => 'Modifier les emplois du temps', 'groupe' => 'Pédagogie'],
        'consulter_eleves' => ['libelle' => 'Consulter les élèves', 'groupe' => 'Pédagogie'],
        'saisir_notes' => ['libelle' => 'Saisir les notes', 'groupe' => 'Pédagogie'],
        'creer_devoirs' => ['libelle' => 'Créer des devoirs', 'groupe' => 'Pédagogie'],
        'saisir_absences' => ['libelle' => 'Saisir les absences', 'groupe' => 'Pédagogie'],
        'inscrire_eleve' => ['libelle' => 'Inscrire un élève', 'groupe' => 'Administratif'],
        'modifier_dossier_eleve' => ['libelle' => 'Modifier un dossier élève', 'groupe' => 'Administratif'],
        'gerer_parents' => ['libelle' => 'Gérer les parents', 'groupe' => 'Administratif'],
        'imprimer_documents' => ['libelle' => 'Imprimer les documents', 'groupe' => 'Administratif'],
        'supprimer_classe' => ['libelle' => 'Supprimer une classe', 'groupe' => 'Administratif'],
        'gerer_utilisateurs' => ['libelle' => 'Gérer les utilisateurs', 'groupe' => 'Administration'],
        'gerer_roles' => ['libelle' => 'Gérer les rôles', 'groupe' => 'Administration'],
        'modifier_parametres_ecole' => ['libelle' => "Modifier les paramètres de l'école", 'groupe' => 'Administration'],
        'consulter_statistiques' => ['libelle' => 'Consulter les statistiques', 'groupe' => 'Administration'],
        'consulter_activites' => ["libelle" => "Consulter l'historique d'activité", 'groupe' => 'Administration'],
    ];

    public static function codes(): array
    {
        return array_keys(self::CATALOGUE);
    }

    public static function existe(string $code): bool
    {
        return array_key_exists($code, self::CATALOGUE);
    }
}
