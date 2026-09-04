# Migrations d'avant le pivot ECONOMAT — mises de côté

Ces 36 migrations créaient un schéma local complet : `users`, `classes`, `eleves`,
`niveaux`, `annees_scolaires`, `periodes`, `enseignants`, `matieres`, `notes`, `absences`,
`sanctions`, `seances`, `programmes`, `ressources`, `parents`, `conseils_classe`,
`documents`, `messages`, `annonces`, `inscriptions`, `retards`, `societes`,
`etablissements`, `affectations`, `journal_activite`…

Depuis le pivot, ces données vivent dans **ECONOMAT** (`T_ETUDIANT`, `T_CLASSE`,
`T_PROFESSEUR`, `T_MATIERE`, `T_NIVEAU`, `T_ABSENCEELEVE`…) et dans **dbmasterbacou**
(`RH_USER`, `US_SOCIETE`). Le schéma local n'a plus de raison d'être.

## Pourquoi les retirer, et pas seulement les ignorer

`DB_CONNECTION=economat` : la connexion **par défaut** de l'application est la base de
production partagée. Un `php artisan migrate` sans `--database` aurait donc créé ces 36
tables **dans ECONOMAT**, au milieu des tables de la suite. Les déplacer ici supprime la
charge : la commande ne trouve plus rien à créer.

Les seules migrations actives sont dans `database/migrations/console/`, et elles se
lancent explicitement :

    php artisan migrate --path=database/migrations/console --database=ecoprim

## À faire avant de reconstruire quoi que ce soit ici

Ces migrations posaient des clés étrangères vers les tables locales
(`programmes.niveau_id -> niveaux.id`). Une page reconstruite doit référencer ECONOMAT
**par code** (`niveau_code`, `classe_code`, `matiere_code`), comme le font déjà les tables
`console_*`. Ne pas réactiver ces fichiers tels quels.
