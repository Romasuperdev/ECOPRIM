# Audit ECOPRIM — État des lieux

**Application de gestion d'école primaire** — Laravel 11 / React 19 / SQL Server
Audit réalisé le 30 août 2026 · Périmètre : dépôt `C:\ROMARIC\ECOPRIM`

---

## 1. Synthèse

ECOPRIM est un MVP full-stack **fonctionnel et bien structuré**, nettement au-delà du prototype. Le code est propre, les conventions Laravel et une architecture React *feature-based* sont respectées, et la traçabilité des décisions (`AVANCEMENT.md`) est exemplaire.

L'application couvre aujourd'hui deux couches :

- **Application pédagogique** (gestion d'un établissement) — large et opérationnelle.
- **Console Administrative** (gouvernance multi-société / multi-établissement) — c'est la partie techniquement la plus aboutie, avec un vrai contrôle d'accès par périmètre.

Le projet **n'est pas encore prêt pour la production**. Deux manques dominent : l'**absence totale de tests automatisés** et une **incohérence multi-tenant** (les données pédagogiques ne sont pas scopées par établissement alors que la Console gère plusieurs établissements).

**Chiffres clés**

| Indicateur | Valeur |
|---|---|
| Modèles Eloquent | 33 |
| Contrôleurs API (v1) | 35 |
| Migrations | 36 |
| Lignes PHP (app/) | ~4 500 |
| Pages React | 42 |
| Lignes JSX/JS (src/) | ~8 000 |
| Seeders | 7 |
| Tests automatisés | **0** |
| Commits Git | 4 |

---

## 2. Méthodologie

Audit statique du code source réel (modèles, contrôleurs, routes API, migrations, pages React, seeders, dépendances) croisé avec le journal d'avancement du projet. Les flux authentifiés n'ont pas été exécutés de bout en bout (le login exige de vraies informations d'identification `RH_USER`) : l'évaluation porte sur la présence et la structure du code, pas sur une validation runtime complète.

---

## 3. Ce qui a été fait

### 3.1 Environnement & fondations

- Stack installée et fonctionnelle : Laravel 11 (PHP 8.2.29), React 19 + Vite + Tailwind v4, SQL Server (`sqlsrv` / `pdo_sqlsrv`), base `ecoprim` créée.
- API REST versionnée sous `/v1`, `npm run build` et `php artisan migrate` utilisés comme garde-fous à chaque évolution.

### 3.2 Authentification & rôles

- **Sanctum SPA** (cookie de session), `statefulApi()`, CORS pour `:5173`/`:5174`.
- Login vérifié en lecture seule contre `dbmasterbacou.RH_USER`, puis **réplication du compte** dans `ecoprim.users` (`syncLocalUser`). Aucune autre lecture/écriture live sur les bases externes.
- Page de connexion à deux modes (Établissement / Console Admin), le mode Admin exigeant le rôle `Super Admin`.
- **10 rôles** seedés via `spatie/laravel-permission` : Super Admin, Admin Société, Admin Établissement, Direction, Directeur Adjoint, Enseignant, Secretaire, Surveillant, Parent, Élève.

### 3.3 Application pédagogique — modules livrés

| Domaine | Backend | Frontend | Statut |
|---|---|---|---|
| Années scolaires (statuts, périodes) | ✅ | ✅ | Opérationnel |
| Cycles / Niveaux | ✅ | ✅ | Opérationnel |
| Classes (enseignant principal, archivage, intervenants) | ✅ | ✅ | Opérationnel |
| Matières + coefficients par niveau | ✅ | ✅ | Opérationnel |
| Enseignants (fiche, désactivation) | ✅ | ✅ | Opérationnel |
| Élèves (wizard 4 étapes, champs alignés `T_ETUDIANT`) | ✅ | ✅ | Opérationnel |
| Parents / Tuteurs (auto-création à la saisie élève) | ✅ | ✅ | Opérationnel |
| Notes | ✅ | ✅ | Opérationnel |
| Absences / Retards / Sanctions | ✅ | ✅ | Opérationnel |
| Séances (cahier de textes) | ✅ | ✅ | Opérationnel |
| Programmes / Ressources pédagogiques | ✅ | ✅ | Opérationnel |
| Documents (upload, stockage polymorphe) + Documents établissement | ✅ | ✅ | Opérationnel |
| Inscriptions / Réinscriptions / Transferts | ✅ | ✅ | Opérationnel |
| Communication (Messages, Annonces) | ✅ | ✅ | Opérationnel |
| Conseil de classe / Délibérations | ✅ | ✅ | Opérationnel |
| Rapports (Moyennes, Classements, Assiduité, Évaluations) | ✅ | ✅ | Opérationnel |
| Bulletins PDF (`dompdf`) | ✅ | ✅ | Opérationnel |
| Tableau de bord (KPI effectifs, moyennes, assiduité) | ✅ | ✅ | Opérationnel |

**Règle transversale** : une année scolaire clôturée passe en lecture seule (`AnneeScolaireGuard::assertModifiable()`), forçable uniquement par un Super Admin (`?force=1`), action tracée.

### 3.4 Console Administrative

- **Sociétés** — CRUD complet aligné sur `dbmasterbacou.US_SOCIETE`, désactivation logique (suppression bloquée si établissements actifs), actions activer/désactiver journalisées. Réservé au Super Admin.
- **Établissements** — CRUD, rattachement obligatoire à une société, champs alignés sur `T_ETABLISSEMENT` (responsable pédagogique, rattachement DREN/IEP), activation bloquée sans directeur affecté.
- **Utilisateurs & Accès** — liste en lecture seule (comptes créés uniquement via sync RH_USER), gestion des **affectations** (société + établissement + rôle + dates), historique conservé (jamais de suppression de ligne).
- **Journal d'activité** — append-only, aucune route de modification/suppression, alimenté par `ActivityLogger`.
- **Contrôle d'accès par périmètre** — trait `BelongsToPerimetre` (scope global Eloquent), **fail-closed** (zéro affectation = zéro ligne, jamais de repli « tout montrer »), 3 paliers de rôle sur les routes, `FormRequest::authorize()` bloquant les créations hors périmètre. Échappatoire tracée `withoutPerimetre()`.
- **Import ponctuel** des sociétés réelles depuis `dbmasterbacou` (commande artisan idempotente `societes:importer-dbmasterbacou`, 3 sociétés importées).

### 3.5 Interface

Design entièrement aligné sur l'application sœur **ECONEW** : palette vert menthe officielle, système de thème par variables CSS, composants partagés (`Button`, `Input`, `Select`, `Textarea`), sidebar unie, page de connexion à panneau coulissant. Sidebar principale réorganisée en **7 groupes** (Paramètre / Traitement / Programme / Affectation / Évaluation & Résultats / Parents-Tuteurs / Communication).

---

## 4. Ce qui reste à faire

### 4.1 Priorité HAUTE — bloquants qualité / cohérence

1. **Tests automatisés — inexistants (0 test).** phpunit est configuré mais aucun test n'est écrit. À couvrir en priorité : le contrôle d'accès par périmètre (fail-closed, bypass Super Admin, isolation Admin Société/Établissement), la synchronisation RH_USER, le verrou année clôturée. C'est le risque de régression n°1 sur une base de cette taille.

2. **Scoping établissement des données pédagogiques.** Aujourd'hui élèves, classes, notes, etc. sont **mono-établissement** : ils ne sont pas isolés par établissement. Conséquence directe : la désactivation d'un établissement ne coupe pas l'accès à ses données métier (uniquement au périmètre de la Console). C'est le chantier structurant qui débloque plusieurs règles en attente.

3. **Rattachement des années scolaires à un établissement.** Prérequis du point précédent, et condition pour vérifier la règle « un établissement ne peut être activé sans année scolaire configurée » (aujourd'hui invérifiable).

### 4.2 Priorité MOYENNE — fonctionnalités annoncées « Bientôt »

Éléments déjà présents dans la sidebar mais non construits :

- **Paramètre** : Calendrier scolaire, Compétences, Barèmes, Salles & créneaux.
- **Traitement** : Préinscriptions.
- **Affectation** : Classe → Salle.
- **Évaluation & Résultats** : Statistiques / Effectifs agrégés dédiés.
- **Parents / Tuteurs** : Espace parent (portail dédié non construit).
- **Communication** : Réunions, Notifications temps réel.
- **Emplois du temps** : différé sur toute l'application.

### 4.3 Priorité BASSE — évolutions plateforme

- **Catalogue de rôles/permissions éditable** (actuellement fixe et seedé). Piste : le modèle de permissions par module/établissement d'ECONEW (`utilisateur_module_permissions`).
- **Filtrage du Journal d'activité** par société/établissement et recherche texte (aujourd'hui Super Admin uniquement, sans filtre).
- **Abonnements / quotas** (jugé prématuré tant qu'il n'y a pas de besoin de facturation).
- Référentiels pédagogiques niveau plateforme, modèles de documents, modèles de notification, configuration plateforme/établissement.

---

## 5. Risques & dette technique

- **Absence de filet de tests** : toute évolution est à risque de régression silencieuse, en particulier la logique de sécurité par périmètre.
- **Incohérence de granularité tenant** : la Console est multi-établissement, le métier est mono-établissement — écart à résorber avant d'ouvrir l'app à plusieurs établissements réels.
- **Historique Git grossier** : 4 commits seulement, l'essentiel du travail concentré dans le commit initial — traçabilité et retours arrière difficiles. Recommandation : commits plus fréquents et atomiques.
- **Import de données par snapshot** : les sociétés importées de `dbmasterbacou` ne sont pas synchronisées ; un changement à la source nécessite un ré-import manuel.
- **Dépendance à un environnement local spécifique** (chemin PHP `wamp64` codé) — à documenter/normaliser pour un déploiement.

---

## 6. Recommandations — feuille de route suggérée

**Court terme (fiabilisation).** Introduire une suite de tests Feature Laravel ciblant d'abord le contrôle d'accès par périmètre et l'authentification, puis les CRUD critiques. Adopter une discipline de commits plus fine.

**Moyen terme (cohérence multi-tenant).** Rattacher les années scolaires à l'établissement, puis étendre le trait de scoping de périmètre aux entités pédagogiques (élèves, classes, notes…). Ce chantier débloque d'un coup la règle d'activation d'établissement et l'isolation réelle des données.

**Long terme (montée en fonctionnalités).** Traiter les entrées « Bientôt » par valeur métier décroissante (Emplois du temps et Espace parent en tête si demande utilisateur), puis les évolutions plateforme (permissions éditables, journal filtrable).

---

*Audit statique — les flux authentifiés n'ont pas été exécutés (identifiants RH_USER requis). Les statuts « Opérationnel » attestent de la présence et de la structure du code, pas d'une validation fonctionnelle exhaustive.*
