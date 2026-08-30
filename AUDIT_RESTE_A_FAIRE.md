# ECOPRIM — Audit de santé & reste à faire

*Établi le 30 août 2026, après compilation réelle de l'application (front + back).*

---

## 1. État de santé — vérifié, pas supposé

L'application a été **réellement compilée et testée** (pas seulement relue) :

| Contrôle | Résultat |
|---|---|
| Syntaxe backend (`php -l` sur app/database/routes/config) | ✅ 0 erreur |
| Tests automatisés (`phpunit`) | ✅ 21 tests, 40 assertions, tous verts |
| Build frontend (`vite build`) | ✅ 2084 modules, aucune erreur |
| Lint frontend (`oxlint src`) | ✅ propre (2 avis React sans impact) |
| Routes sidebar → page existante | ✅ 100 % (aucune entrée morte) |
| Appels API front → route backend | ✅ 100 % (aucun endpoint 404) |

**Conclusion : aucun problème de compilation, de test ou de câblage.** L'application est techniquement saine. Les « problèmes » restants ne sont pas des bugs de code mais des fonctionnalités à construire et de la dette d'architecture (section 3).

---

## 2. Problèmes résolus pendant la session

- **Login Console « Une erreur est survenue »** — cause : `DB_DATABASE` pointait par erreur sur `ECONOMAT`, la connexion par défaut ne trouvait plus la table `roles` de `ecoprim`. Corrigé (`DB_DATABASE=ecoprim`), ECONOMAT isolé sur sa connexion dédiée.
- **Absence totale de tests** (risque n°1 du 1er audit) — suite créée : contrôle d'accès par périmètre (fail-closed, bypass Super Admin, isolation Admin Société/Établissement), synchronisation RH_USER, verrou année clôturée.
- **Doublons de navigation** — Réinscriptions/Transferts, Moyennes/Classements/Résultats/Bulletins, Conseils/Délibérations, Annonces/Notifications, affectations classe : tous fusionnés/rationalisés.
- **Pages fusionnées à tort séparées** — « Documents élèves » et « Documents enseignants » ont désormais leur propre page/route (elles renvoyaient vers Élèves/Enseignants).
- **Page Inscriptions** — passée de lecture seule à saisie complète des quatre mouvements (inscription, réinscription, transfert entrant/sortant).
- **Fondations ECONOMAT** — connexion `economat` (lecture seule) + commande `php artisan schema:introspect`.

---

## 3. Reste à faire — priorisé

### Priorité HAUTE

1. **Intégration ECONOMAT live (chantier en cours).** Étape suivante concrète : lancer `php artisan schema:introspect` sur ta machine → je câble alors les modèles pédagogiques (Élèves, Classes, Niveaux, Matières, Enseignants, Notes, Moyennes…) sur les vraies tables `T_*` en **lecture seule**, et la Console sur `dbmasterbacou`. **Implication à valider** : les écrans concernés deviennent des consultations (plus de création/édition sur des données en lecture seule, alimentées par le logiciel d'origine d'ECONOMAT).

2. **Étendre la couverture de tests** au-delà des 3 zones actuelles : CRUD critiques (élèves, classes, notes), les `FormRequest` de périmètre (`Store/UpdateEtablissementRequest`, `StoreAffectationRequest`), la synchronisation et les rapports.

3. **Cohérence multi-tenant** (dette du 1er audit, toujours ouverte) : rattacher l'année scolaire à l'établissement, puis étendre le scoping de périmètre aux données pédagogiques (aujourd'hui mono-établissement). Débloque la règle « pas d'activation d'établissement sans année configurée ».

### Priorité MOYENNE

4. **Fonctionnalités « Bientôt » de la sidebar** (affichées, non construites) : Calendrier scolaire, Compétences, Barèmes, Salles & créneaux, Préinscriptions, Classe → Salle, Statistiques/Effectifs, Espace parent, Réunions, Emplois du temps, Rôles & permissions éditables, Paramètres plateforme. À traiter par valeur métier décroissante.

5. **Optimisation du bundle** : le JS de production fait ~622 kB (gzip 165 kB) en un seul chunk. Découper par route avec `React.lazy` / import dynamique pour accélérer le premier chargement (avertissement Vite « chunk > 500 kB »).

### Priorité BASSE

6. **Journal d'activité** filtrable par société/établissement + recherche.
7. **Raccourci Affectation → `/classes`** : doublon volontaire de route (deux intentions distinctes). À trancher si tu veux le retirer.
8. **Fichiers temporaires** (`_audit_*.tgz`, `_src_for_tests.tgz` vidé, `.env.bak-*`) : déjà en `.gitignore`, supprimables manuellement de ton dossier.

---

## 4. Décisions attendues de ta part

- **Confirmer** que la bascule ECONOMAT live signifie bien des écrans pédagogiques en **lecture seule** (sinon, préciser quelles saisies doivent rester possibles côté ECOPRIM).
- **Lancer `php artisan schema:introspect`** (après avoir ajouté `DB_ECONOMAT_DATABASE=ECONOMAT` à ton `.env`) : c'est le déblocage nécessaire pour câbler précisément les modèles sur le schéma réel.

---

*Méthode : compilation et exécution réelles dans un environnement jetable (SQLite pour les tests, build Vite complet). Les flux authentifiés live (SQL Server + RH_USER) restent à vérifier sur ta machine.*
