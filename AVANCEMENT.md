# ECOPRIM — Journal d'avancement

Suivi du travail réalisé sur le projet, du démarrage de l'environnement jusqu'à la Console
Administrative. Sert de mémoire de référence — voir aussi `README.md` pour l'installation.

## 1. Mise en place de l'environnement

- Résolution du blocage `composer install` (avisos de sécurité), reconstitution du squelette
  Laravel 11 manquant (`artisan`, `bootstrap/app.php`...).
- Résolution des soucis `npm install` / build du frontend.
- Connexion SQL Server (`sqlsrv` / `pdo_sqlsrv`), création de la base `ecoprim`, fiabilisation
  des migrations.
- Correction d'un désalignement entre la version PHP verrouillée par Composer et l'interpréteur
  local PHP 8.2.29 (invocation explicite via `C:\wamp64\bin\php\php8.2.29\php.exe`).

## 2. Décision d'architecture fondatrice

Une roadmap initiale proposait de brancher ECOPRIM en direct sur deux bases externes
(`dbmasterbacou` pour l'auth/rôles via `RH_USER` + Laratrust, `ECONOMAT` comme source de
données `T_*`). Après investigation, il s'est avéré qu'un projet complet et concurrent
(**ECONEW**) existait déjà sur ce schéma. Décision prise : **ECOPRIM reste un projet
indépendant et plus simple**, avec sa propre base `ecoprim` et son propre schéma.

Seule exception conservée : à la connexion, les identifiants sont vérifiés en lecture seule
contre `dbmasterbacou.RH_USER`, puis le compte est répliqué (synchronisé) dans la table locale
`ecoprim.users`. Aucune autre lecture/écriture live sur `dbmasterbacou`, `ECONOMAT` ou `ECONEW`.

## 3. Authentification

- Sanctum SPA (cookie de session), `statefulApi()`, CORS pour `:5173`/`:5174`.
- Page de connexion à deux modes : **Établissement** / **Console Admin** (le mode Admin exige
  le rôle `Super Admin`, sinon déconnexion immédiate côté client).
- `AuthController::login` vérifie contre `RH_USER`, `syncLocalUser` crée/actualise l'utilisateur
  local et lui attribue `Super Admin` à la création si `RH_USER.SuperAdmin` est vrai.
- Rôles via `spatie/laravel-permission` : Super Admin, Admin Société, Admin Établissement,
  Direction, Directeur Adjoint, Enseignant, Secretaire, Surveillant, Parent, Élève.

## 4. Modules pédagogiques et administratifs (application principale)

Construits initialement en suivant une arborescence de menu à 11 sections fournie par
l'utilisateur, à l'exclusion explicite du groupe ⚙️ Administration (traité en Console
Administrative séparée, voir §6) et des Emplois du temps (différé). **Réorganisée depuis** selon
une nouvelle taxonomie à 8 modules (voir §4bis) — le contenu ci-dessous reste la liste des
fonctionnalités réellement construites, indépendamment du regroupement affiché dans la sidebar :

- **Années scolaires** — statuts brouillon/active/clôturée/archivée, période (trimestre / etc.).
- **Cycles / Niveaux**.
- **Classes** — affectation enseignant principal, archivage si année clôturée.
- **Matières** — coefficients par niveau.
- **Enseignants** — fiche, désactivation, intervenants par classe.
- **Élèves** — champs alignés sur la vraie table source `ECONOMAT.T_ETUDIANT` (uniquement les
  champs pédagogiques/administratifs, hors finance/technique) ; formulaire de **création en
  wizard 4 étapes** (Identité → Coordonnées → Scolarité → Père/Tuteur, chaque étape validée
  avant de passer à la suivante), formulaire d'édition à plat ; saisie des parents/tuteurs
  directement à la création (auto-création des `Parent` liés).
- **Parents / Tuteurs**.
- **Notes**, **Absences**, **Retards**, **Sanctions**.
- **Cahier de textes (Séances)**, **Programmes**, **Ressources pédagogiques**.
- **Documents** (upload réel, stockage polymorphe `documents`), **Documents établissement**.
- **Inscriptions / Réinscriptions / Transferts**.
- **Communication** — Messages, Annonces.
- **Conseil de classe** — Conseils, Délibérations.
- **Rapports** — Moyennes/Classements, Assiduité, Évaluations agrégées.
- **Bulletins PDF** (`barryvdh/laravel-dompdf`).
- **Tableau de bord** — KPIs (effectifs, moyenne générale, assiduité, absences/retards/sanctions
  du mois), effectif et moyenne par classe.

### §4bis. Réorganisation de la sidebar principale (taxonomie à 7 modules)

L'utilisateur a partagé un « prompt maître » détaillé proposant une architecture cible pour
ECOPRIM en tant qu'ERP pédagogique complet (10 modules, Laravel 12/PHP 8.3+, année scolaire
comme pivot central, document de conception en 20 étapes). Décision explicite de l'utilisateur :
**ne pas tout reprendre** — garder la stack et l'architecture actuelles (Laravel 11/PHP 8.2.29,
schéma existant), et piocher les idées utiles progressivement. Il a ensuite précisé que ce
document concerne **uniquement l'application** (pas la Console Administrative, qui garde sa
structure Sociétés/Établissements/Utilisateurs & Accès/Journal d'activité inchangée).

Première application concrète : réorganisation de `Sidebar.jsx` (application principale
uniquement — `AdminSidebar.jsx`/Console non touchée) selon 8 des 10 modules proposés
(Administration et Traçabilité restent du ressort de la Console).

**Ajustement demandé juste après** : liste exacte des groupes resserrée à 7 (Paramètre /
Traitement / Programme / Affectation / Évaluation & Résultats / Parents-Tuteurs /
Communication) — le groupe « Rapports » séparé est retiré, son contenu (Résultats, Assiduité,
Statistiques, Effectifs, Rapports PDF) fusionné dans **Évaluation & Résultats**, dont le nom
correspond exactement au module 5 du document original (qui regroupait déjà évaluations,
résultats/moyennes et bulletins sous un seul intitulé) :

- **⚙️ Paramètre** — Années scolaires, Cycles/Niveaux, Classes, Matières (existants) +
  Calendrier scolaire, Compétences, Barèmes, Salles & créneaux (affichés en « Bientôt »).
- **📝 Traitement** — Élèves, Enseignants, Inscriptions/Réinscriptions/Transferts, Absences,
  Retards, Discipline, Documents (existants) + Préinscriptions (Bientôt).
- **📚 Programme** — Cours, Cahier de textes, Ressources pédagogiques (existants) + Emplois du
  temps (toujours différé).
- **🔗 Affectation** — nouveau groupe : Élève → Classe, Enseignant → Classe/Matière (renvoient
  vers `/classes`, où ces affectations sont réellement gérées aujourd'hui) + Classe → Salle
  (Bientôt).
- **📊 Évaluation & Résultats** — séparée du Programme comme recommandé, et absorbe l'ancien
  groupe Rapports : Évaluations, Saisie des notes, Moyennes, Classements, Résultats, Bulletins,
  Conseils de classe/Délibérations, Assiduité (existants) + Statistiques/Effectifs (Bientôt).
- **👨‍👩‍👧 Parents / Tuteurs** — Parents/Tuteurs (existant) + Espace parent (Bientôt, portail
  dédié non construit).
- **🔔 Communication** — Annonces, Messages, Notifications (existants) + Réunions (Bientôt).

Aucun changement de schéma de base de données, de routes API, ni de la Console Administrative —
uniquement le regroupement/libellé des entrées de la sidebar principale et l'ajout d'entrées
« Bientôt » reflétant les pièces manquantes de la vision cible.

### Règle transversale : année scolaire clôturée

Une année scolaire clôturée passe en lecture seule (notes, absences, retards, effectifs de
classe). Implémenté via `App\Support\AnneeScolaireGuard::assertModifiable()`, appelé depuis les
contrôleurs concernés ; seul un Super Admin peut forcer (`?force=1`), action tracée.

## 5. Interface / Design

Historique des itérations (login en carte scindée, sidebar sombre dégradée puis carte flottante
puis sidebar violette pleine, formulaires restylés en « icônes + sections ») — toutes remplacées
par la refonte ci-dessous.

### Refonte finale : même design system que l'application sœur ECONEW

Sur demande explicite (« on va utiliser le même design que ECONEW, ne change rien dans le style,
la couleur »), le design entier a été aligné sur le vrai code d'ECONEW
(`c:/ROMARIC/ECONEW/ECONEW/frontend`), lu directement plutôt que réinterprété depuis une
maquette :

- **Palette** — remplacement complet du bleu/violet par le **vert menthe** officiel d'ECONEW
  (`#00CC8E` et sa gamme `#E5FFF7`→`#05543C`), réinjecté dans les tokens `--color-primary-*`/
  `--color-secondary-*` existants d'ECOPRIM. Comme la quasi-totalité de l'app utilisait déjà ces
  noms de tokens (badges, boutons, `FormSection`), la nouvelle couleur s'est propagée partout
  sans avoir à toucher chaque fichier — seuls les quelques endroits avec des classes Tailwind
  codées en dur (`violet-*`, `indigo-*`) ont été corrigés à la main.
- **Système de thème par variables CSS** — port du système `:root { --bg, --surface, --sidebar,
  --sidebar-2, --accent, --border, --muted, --heading, --text, --shadow... }` d'ECONEW dans
  `index.css` (thème clair uniquement — pas de sélecteur de thème sombre/« sunny », non demandé),
  avec les classes utilitaires `.card`/`.field`/`.text-muted`/`.text-heading` reprises à
  l'identique (mêmes rayons, mêmes couleurs de focus, mêmes ombres).
- **Composants partagés** (`Button`, `Input`, `Select`, `Textarea`) réécrits pour utiliser ces
  classes/variables au lieu des utilitaires Tailwind bleus — `Button` reprend exactement les
  variantes d'ECONEW (`primary` = vert sidebar foncé, `gold`/`secondary` = accent menthe vif,
  `outline`/`ghost`, `danger`), tout en gardant les props ECOPRIM déjà utilisées partout
  (`icon`, `rightElement`, `error`).
- **Sidebar / Layout** — même recette qu'ECONEW : sidebar unie (`var(--sidebar)` pour
  l'application, `var(--sidebar-2)` légèrement plus sombre pour la Console Administrative,
  comme ECONEW distingue `DashboardLayout` de `SuperAdminLayout`), item actif en pastille
  `var(--accent)`, en-tête `sticky` flouté (`backdrop-filter: blur`) plutôt qu'un bandeau plein,
  contenu centré `max-w-6xl`.
- **Page de connexion** — reprise du panneau coulissant à deux faces d'ECONEW : un volet coloré
  glisse entre le formulaire « Établissement » (vert sidebar) et « Console Admin » (dégradé
  accent), bascule mobile en onglets. Adapté à ce qu'ECOPRIM a réellement (pas de 2FA/OTP, pas de
  sélecteur de thème comme ECONEW, pour ne pas ajouter d'éléments non fonctionnels) — même
  mécanique visuelle et mêmes couleurs, pas de fonctionnalité inventée.
- **Tableau de bord** (application + Console Administrative) — bandeau de bienvenue en dégradé
  `var(--sidebar)`→`var(--sidebar-2)`, cartes de stats passées en classe `.card`.
- Vérifié visuellement via Playwright sur la page de connexion (seule page accessible sans
  authentification) : panneau coulissant, bascule Établissement/Console, version mobile.

### Bug corrigé après coup : « L'email est requis » alors que les champs sont remplis

Le panneau coulissant garde en permanence les deux faces (Établissement/Console) montées dans
le DOM (desktop et mobile compris) pour l'animation — au départ géré avec React Hook Form, qui
ne suit qu'**une seule ref par nom de champ** : deux `<input>` montés en même temps avec le même
`register('email')` se marchent dessus, donc taper dans le champ visible ne remontait pas
forcément dans le champ que RHF lisait à la validation → « requis » même rempli. Remplacé par
des champs **contrôlés** (`useState` partagé par face, comme le fait réellement ECONEW pour son
propre login — il n'utilise pas RHF non plus sur cette page), ce qui n'a pas ce problème :
plusieurs copies montées simultanément peuvent partager la même valeur sans conflit. Revérifié
via Playwright (valeur qui persiste jusqu'à la soumission, vraie requête `POST /login` envoyée,
message serveur correct sur identifiants invalides).

## 6. Console Administrative

Nouvelle couche de gouvernance plateforme, distincte de la gestion pédagogique d'un
établissement, construite à partir d'un cahier des charges détaillé fourni par l'utilisateur.
**Décision de portée** : schéma indépendant dans la base `ecoprim`, inspiré de la structure
réelle de `dbmasterbacou` (`US_SOCIETE`, `T_ETABLISSEMENT`, `roles`, `permissions`,
`societe_utilisateur`...) mais sans connexion live — aucune lecture/écriture sur
`dbmasterbacou` en dehors de l'exception login déjà en place.

### Construit

- **Sociétés** — CRUD complet, champs alignés sur la vraie table `dbmasterbacou.US_SOCIETE`
  (identité, coordonnées, informations légales, représentant), désactivation logique
  uniquement (suppression bloquée si établissements actifs rattachés). Activation/désactivation
  via des actions dédiées (`POST /societes/{id}/activer|desactiver`), journalisées séparément
  d'une simple modification. Réservé au Super Admin (gouvernance plateforme).
- **Établissements** — CRUD, rattachement obligatoire à une société, champs étendus alignés sur
  la vraie table `dbmasterbacou.T_ETABLISSEMENT` (bloc responsable pédagogique distinct du
  compte Admin Établissement : nom/prénom/fonction/contact ; rattachement administratif ivoirien
  DREN/IEP pour les rapports officiels). Activation via action dédiée (`POST .../activer`),
  bloquée sans Directeur/Admin Établissement affecté (la condition « année scolaire configurée »
  reste non vérifiable, voir §7).
- **Utilisateurs & Accès** — liste en lecture seule (les comptes se créent uniquement via la
  synchronisation RH_USER à la connexion, pas de formulaire de création manuelle), fiche
  utilisateur avec gestion des **affectations** (société + établissement + rôle, avec dates).
  Terminer une affectation ne supprime jamais la ligne (historique conservé), seulement
  `actif = false` + `date_fin`.
- **Rôles** — catalogue étendu (Super Admin, Admin Société, Admin Établissement, Direction,
  Directeur Adjoint, Enseignant, Secretaire, Surveillant, Parent, Élève).
- **Journal d'activité** — append-only, aucune route de modification/suppression exposée même
  pour le Super Admin ; alimenté automatiquement via `App\Support\ActivityLogger` (user_id,
  action, module, objet, avant/après, IP, user-agent) sur chaque création/modification/
  suppression/activation sensible.
- Sidebar propre à la console (`AdminLayout` / `AdminSidebar`, identité violette), accessible
  depuis la sidebar principale (groupe ⚙️ Administration, visible uniquement pour Super Admin).

### Périmètre (Admin Société / Admin Établissement) — inspiré d'ECONEW

Après exploration du projet sibling **ECONEW** (multi-société/multi-établissement en
production) pour s'en inspirer, mise en place d'un vrai contrôle d'accès par périmètre, plus
riche que le blocage "tout au Super Admin" initial :

- `User::allowedSocieteIds()` / `allowedEtablissementIds()` — résolus depuis les affectations
  **actives et non expirées** de l'utilisateur, en excluant explicitement toute société/
  établissement lui-même désactivé (suspendre une société/un établissement coupe l'accès de ses
  affectés, pas seulement l'affichage).
- Trait `App\Models\Concerns\BelongsToPerimetre` (scope global Eloquent) appliqué à
  `Etablissement` et `Affectation` : un Super Admin voit tout, les autres rôles ne voient que
  leur périmètre. **Fail closed** — zéro affectation résolue = zéro ligne visible, jamais un
  repli "si vide, tout montrer" (piège identifié dans la doc `MULTITENANT.md` d'ECONEW).
  Échappatoire explicite et traçable : `withoutPerimetre()`.
- Routes de la Console repensées en 3 paliers de rôle (`role:Super Admin` seul pour la
  gouvernance pure — sociétés, suppression d'établissement, journal global ; `role:Super
  Admin|Admin Société` pour la création/activation d'établissement et la gestion des
  affectations ; `role:Super Admin|Admin Société|Admin Établissement` pour la consultation/mise
  à jour scopée), au lieu du précédent `role:Super Admin` unique sur tout.
- `StoreEtablissementRequest`/`UpdateEtablissementRequest`/`StoreAffectationRequest::authorize()`
  bloquent en plus les tentatives de créer/transférer une ressource vers une société hors
  périmètre (au-delà de ce que le scope global empêche déjà pour la ressource elle-même).
- Point de vigilance corrigé pendant l'implémentation : `User::affectationsActives()` doit
  explicitement retirer le scope `perimetre` d'`Affectation` (`withoutGlobalScope('perimetre')`),
  sans quoi le calcul du périmètre d'un utilisateur rebouclerait indéfiniment sur lui-même
  (le scope appelle `allowedSocieteIds()`/`allowedEtablissementIds()`, qui interrogent
  `Affectation`, qui redéclenche le scope...). Vérifié par test direct (pas de dépassement de
  pile, scoping correct pour Admin Société/Établissement, bypass Super Admin, fail-closed sans
  affectation) puis données de test nettoyées.

### Bug corrigé après coup : middleware `role:` planté en production

Le séparateur utilisé pour lister plusieurs rôles dans `role:...` est `|` (pipe), pas `,` —
Laravel découpe les paramètres de middleware sur la virgule, donc `role:Super Admin,Admin
Société` était compris comme *rôle = "Super Admin"*, *guard = "Admin Société"* (2ᵉ paramètre du
`RoleMiddleware` de spatie), et plantait dès qu'un utilisateur connecté touchait une route
scopée (`Auth guard [Admin Société] is not defined`). Repéré via les logs Laravel après un
signalement de connexion impossible à la console ; les trois groupes de routes corrigés en
`role:Super Admin|Admin Société|Admin Établissement`, revérifié via tinker (résolution du guard,
parsing des rôles) et cache de routes vidé.

### Règles de gestion confirmées/renforcées (société → établissements → utilisateur → rôles)

Rappel explicite de l'utilisateur, vérifié par test direct puis renforcé côté formulaire :

- Une société peut avoir plusieurs établissements (déjà modélisé — `Societe::etablissements()`).
- Un utilisateur peut être affecté à **plusieurs établissements** (plusieurs lignes
  `affectations`, une par établissement) — déjà supporté nativement, confirmé par test
  (utilisateur affecté à 2 établissements de la même société simultanément).
- Un utilisateur peut cumuler **plusieurs rôles** (chaque affectation attribue son propre rôle
  via `assignRole`, additif chez spatie) — confirmé par test (Direction + Enseignant cumulés sur
  le même compte).
- Renforcement ajouté : le formulaire « Nouvelle affectation » (fiche utilisateur) filtre
  désormais la liste des établissements selon la société choisie, et auto-remplit la société
  quand un établissement est sélectionné directement — pour rendre visible la hiérarchie
  société → établissements plutôt que deux menus déroulants indépendants pouvant se contredire.
  Côté backend, `StoreAffectationRequest` rejette maintenant explicitement toute incohérence
  (établissement fourni qui n'appartient pas à la société fournie), vérifié par test.

### Import ponctuel des vraies sociétés depuis dbmasterbacou

L'utilisateur a demandé à ce que les sociétés déjà réelles dans
`dbmasterbacou.dbo.US_SOCIETE` apparaissent sur la page Sociétés d'ECOPRIM. Plutôt qu'une
connexion live (exclue par la décision d'architecture), import ponctuel :

- Modèle `App\Models\UsSociete` (connexion `master`, lecture seule, même schéma que `RhUser`)
  pointant sur `US_SOCIETE`, clé primaire `CODESOCIETE`.
- Commande artisan `societes:importer-dbmasterbacou` : upsert par `code` dans `ecoprim.societes`
  (idempotente — ré-exécutable sans dupliquer, testé deux fois de suite : 3 créées puis 3 mises
  à jour). Mappe les colonnes déjà alignées lors du travail précédent (`US_SOCIETE` → schéma
  `societes`), avec repli sur les variantes redondantes de la table source (`AD1SOCIETE`/
  `ADRESSE`, `NOMPRENOMREPRESENTANT`/`REPRESENTANT`, `FONCTIONREPRESENTANT`/
  `FONCTIONREPESENTANT` — coquille présente dans le schéma legacy).
- Exécutée une fois : 3 sociétés réelles importées (ABN, AURIAK, SOC63859), visibles sur
  `/admin/societes`. Aucune connexion permanente ouverte — un nouvel export/import manuel serait
  nécessaire si la table source change (pas de synchronisation automatique).

### Explicitement hors scope pour l'instant

- Rôles & Permissions comme catalogue éditable (actuellement fixe, seedé) — ECONEW a un système
  de modules/permissions par établissement (`utilisateur_module_permissions`) qui serait la
  suite logique si on veut aller au-delà d'un rôle en tout-ou-rien.
- Rattachement des années scolaires à un établissement — donc contrôle complet de la règle
  d'activation d'établissement, et scoping établissement des données pédagogiques existantes
  (élèves, classes, notes...) qui restent aujourd'hui mono-établissement.
- Modules Référentiels pédagogiques (au niveau plateforme), Documents (modèles), Communication
  (modèles de notification), Rapports administratifs, Configuration (paramètres
  plateforme/établissement), abonnements/quotas (ECONEW a un vrai modèle `subscriptions`/
  `subscription_plans`, jugé prématuré tant qu'il n'y a pas de besoin commercial/facturation).
- Emplois du temps (toute l'application).

## 7. Points de vigilance identifiés (non résolus)

- La règle « un établissement ne peut être activé sans année scolaire configurée » ne peut pas
  encore être vérifiée : les années scolaires ne sont pas rattachées à un établissement dans le
  schéma actuel.
- Le blocage d'accès pour société/établissement désactivé ne couvre que le périmètre de la
  Console Administrative elle-même (Établissements/Affectations/Utilisateurs) ; il ne s'étend
  pas encore aux données pédagogiques (élèves, classes, notes...), qui ne sont pas
  établissement-scopées — cf. point précédent.
- Le Journal d'activité reste réservé au Super Admin sans filtre par société/établissement (pas
  de colonne dédiée) ; ECONEW filtre son équivalent par action/recherche texte, à reproduire si
  le volume devient difficile à parcourir.

## 8. Vérification et tests

Le login exige de vraies informations d'identification `RH_USER` (connues uniquement de
l'utilisateur) : les flux authentifiés n'ont pas pu être testés de bout en bout par l'assistant.
Vérifications systématiquement effectuées à la place :
- `npm run build` (frontend) après chaque changement UI.
- `php artisan migrate` sur la base `ecoprim` pour chaque nouvelle migration.
- Tests ponctuels via `php artisan tinker` (création/lecture/suppression de données de test
  dans `ecoprim`, toujours nettoyées après coup — jamais dans `dbmasterbacou`).
- La page de connexion (seule page ne nécessitant pas d'authentification préalable) a pu être
  vérifiée visuellement via Playwright.
  
## 9. Suite de tests automatisés (première couverture)

Comblement du principal manque identifié par l'audit : le projet n'avait **aucun test**. Mise
en place d'une suite PHPUnit ciblant en priorité la logique la plus sensible — le contrôle
d'accès par périmètre, l'authentification RH_USER et le verrou d'année clôturée.

### Environnement de test

- Les tests s'exécutent sur **SQLite en mémoire** (`RefreshDatabase`), indépendamment de SQL
  Server : `phpunit.xml` force `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`,
  `DB_FOREIGN_KEYS=false`. Une connexion `sqlite` a été ajoutée à `config/database.php` (aucune
  incidence en production, qui reste sur `sqlsrv` par défaut). Le jeu complet des 36 migrations
  s'applique sans erreur sous SQLite.
- Prérequis local : l'extension PHP `pdo_sqlite` doit être active (standard sous WAMP).
- `tests/TestCase.php` fournit deux utilitaires : `seedRoles()` (réplique le RoleSeeder sans
  dépendre de SQL Server) et `fakeMasterRhUser()` (rebranche la connexion `master` sur une
  base SQLite en mémoire et y crée une table `RH_USER` minimale reproduisant les colonnes
  réellement lues à la connexion).
- Ajout de factories (`Societe`, `Etablissement`, `Affectation`, `AnneeScolaire`) et du trait
  `HasFactory` sur ces modèles.

### Couverture (21 tests, 40 assertions — tous verts)

- **`PerimetreScopeTest`** (Console) — bypass Super Admin (voit tout), isolation Admin Société
  (ne voit que ses établissements), isolation Admin Établissement (ne voit que le sien),
  **fail-closed** (aucune affectation = zéro ligne), société désactivée qui retire le périmètre,
  établissement désactivé idem, affectation inactive/expirée ignorée, `withoutPerimetre()` qui
  contourne le scope, isolation du scope sur `Affectation`, et non-régression du **garde
  anti-récursion** de `User::allowedSocieteIds()`/`allowedEtablissementIds()`.
- **`RhUserSyncTest`** (Auth) — login valide qui crée le compte local et attribue `Super Admin`
  quand `RH_USER.SuperAdmin` est vrai, utilisateur non-superadmin sans rôle, mot de passe
  incorrect rejeté (422), email inconnu rejeté (422), login répété qui ne duplique pas le compte
  local (`updateOrCreate` idempotent). Requêtes émises avec l'en-tête `Origin` du frontend Vite
  (`localhost:5173`) pour passer par le mode SPA « stateful » de Sanctum.
- **`AnneeScolaireGuardTest`** (Pédagogie) — `id` nul non bloquant, année ouverte modifiable,
  année clôturée qui bloque un utilisateur standard (HTTP 423), Super Admin sans `force` toujours
  bloqué, Super Admin avec `?force=1` autorisé, non-Super Admin avec `force` toujours bloqué.

### Exécution

```bash
cd backend
php artisan test            # ou : php vendor/bin/phpunit --testdox
```

Vérifié : `php vendor/bin/phpunit` → `OK (21 tests, 40 assertions)`. La suite ne touche jamais
SQL Server ni `dbmasterbacou` (tout est en mémoire), elle est donc rejouable sans risque pour
les données réelles.

## 10. Intégration ECONOMAT (préparation) et correctifs

### Décision d'architecture révisée

Sur demande de l'utilisateur, changement de cap par rapport au §2 : l'**application principale**
sera branchée en **lecture seule live** sur la vraie base pédagogique **ECONOMAT** (tables `T_*`),
et la **Console** sur **dbmasterbacou**. Mise en place des fondations :

- Connexion `economat` (sqlsrv, lecture seule) ajoutée à `config/database.php` ; variables
  `DB_ECONOMAT_DATABASE/USERNAME/PASSWORD` documentées dans `.env.example`.
- Commande `php artisan schema:introspect` : exporte tables/colonnes/clés réelles d'ECONOMAT et
  dbmasterbacou vers `storage/app/schema/*.json` + `.md`, pour câbler ensuite les modèles Eloquent
  sur le schéma réel (les colonnes exactes ne sont pas extractibles d'un `.bak` sans moteur SQL).
- Tables ECONOMAT repérées : `T_ETUDIANT`, `T_CLASSE`, `T_NIVEAU`, `T_CYCLE`, `T_MATIERE`,
  `T_PROFESSEUR`, `T_NOTEENTETE`/`T_NOTEDETAILS`, `T_MOYENNES`/`T_MOYENNECLASSE`/`T_MOYENNEGEN`,
  `T_CORPROFCLASSE`, `T_CORMATNIVEAUCOEFF`, `T_CAHIER_JOURNAL`, `T_EMPLOIDUTEMPS`, `V_INSCRIPTION`…

### Correctif login : « Une erreur est survenue »

La connexion à la Console échouait (`Invalid object name 'roles'`, connexion sqlsrv). Cause :
`DB_DATABASE` avait été positionné à `ECONOMAT` dans `.env`, faisant pointer la connexion par
défaut de l'app sur ECONOMAT au lieu de `ecoprim` (où vivent `users`/`roles`/permissions). Corrigé :
`DB_DATABASE=ecoprim` (la base propre reste la connexion par défaut ; ECONOMAT ne se lit que via la
connexion dédiée `economat`).

### Page « Inscriptions, réinscriptions & transferts » — saisie complète

La page globale était en lecture seule (liste + filtre). Ajout d'un formulaire de **saisie des
quatre mouvements** (inscription, réinscription, transfert entrant, transfert sortant) directement
sur la page : sélection de l'élève, type, date, année scolaire, classe (masquée pour un transfert
sortant), et champ établissement d'origine (transfert entrant) / destination (transfert sortant)
affiché selon le type, plus observation. Suppression par ligne ajoutée. Le backend gérait déjà les
quatre types (`InscriptionController::store`) ; seule l'interface globale a été complétée.

### Sidebar : une seule entrée « Inscriptions »

Retrait des entrées séparées « Réinscriptions » et « Transferts » du menu 📝 Traitement (elles
pointaient déjà sur `/inscriptions`). On y accède désormais via le « Filtrer par type » de la page
unique « Inscriptions, réinscriptions & transferts ».

### Page « Documents élèves » distincte de « Élèves »

Dans la sidebar, « Documents élèves » pointait sur la même route `/eleves` que « Élèves ». Création
dune page autonome `DocumentsElevesPage` (route `/documents-eleves`) : sélecteur d\047élève puis
gestion de ses documents (liste, upload, téléchargement, suppression) via `DocumentsPanel`
(`documentable_type=eleve`). La sidebar pointe désormais « Documents élèves » sur cette page.
NB : « Documents enseignants » pointe encore sur `/eleves` (même défaut, hors périmètre de cette demande).

### Page « Documents enseignants » distincte de « Enseignants »

Même traitement que pour les élèves : page autonome `DocumentsEnseignantsPage` (route
`/documents-enseignants`, `documentable_type=enseignant`) avec sélecteur denseignant puis gestion
de ses documents via `DocumentsPanel`. La sidebar pointe « Documents enseignants » sur cette page
(au lieu de `/enseignants`).

### Sidebar : Moyennes/Classements/Résultats/Bulletins fusionnés en une entrée

Ces quatre entrées du menu 📊 Évaluation & Résultats pointaient déjà toutes sur la même page
`/moyennes` (qui affiche moyenne de classe, classement, résultats par élève et téléchargement des
bulletins). Fusion en une seule entrée « Résultats & bulletins » ; titre de la page aligné.

### Sidebar : suppression des doublons de navigation

Entrées multiples pointant sur la même route fusionnées : « Conseils de classe » + « Délibérations »
→ « Conseils & délibérations » (`/conseils-classe`) ; « Annonces » + « Notifications » → « Annonces &
notifications » (`/annonces`) ; « Élève → Classe » + « Enseignant → Classe / Matière » → « Élève /
Enseignant → Classe » (`/classes`). Reste volontairement « Classes » (Paramètre) et le raccourci
Affectation vers `/classes` : deux intentions distinctes vers la même page (affectations gérées là).

## 11. Contrôle de santé global (build + tests) et audit du reste à faire

Compilation réelle de l'application dans un environnement jetable : backend `php -l` (0 erreur)
+ `phpunit` (21 tests, 40 assertions verts) ; frontend `vite build` (2084 modules, OK) + `oxlint`
(propre). Cohérence vérifiée : toutes les routes sidebar ont une page, tous les appels API du
front correspondent à une route backend (aucun 404 de câblage). Aucun problème de compilation,
de test ou de câblage. Audit du reste à faire livré dans `AUDIT_RESTE_A_FAIRE.md`.

## 12. Connexion RH_USER : identifiant multiple, comptes supprimés, CodeApp

À partir de la vraie structure de `dbmasterbacou.RH_USER` : la connexion accepte désormais
comme identifiant l'`Email`, le `Login` **ou** le `Matricule` (formulaire assoupli côté front :
`type=text`, libellé « Identifiant ou email », validation email stricte retirée). Un compte marqué
`Supprimer` est refusé même avec le bon mot de passe. Restriction optionnelle par `CodeApp`
(RH_USER héberge plusieurs applications) via `config/ecoprim.php` + `ECOPRIM_CODE_APP`, désactivée
tant que la variable est vide. Tests étendus (25 tests, 50 assertions verts) : connexion par Login,
par Matricule, refus compte supprimé, restriction CodeApp.

### Couverture de tests étendue (périmètre HTTP + CRUD)

Ajout de `ConsolePerimetreHttpTest` (autorisation HTTP : un Admin Société ne peut créer un
établissement ni une affectation hors de sa société → 403 ; Super Admin partout ; rôle sans
gouvernance refusé) et de `NiveauCrudTest` (création, validation, unicité, mise à jour, suppression
douce — patron de test HTTP authentifié via Sanctum::actingAs). Total : 36 tests, 76 assertions verts.
