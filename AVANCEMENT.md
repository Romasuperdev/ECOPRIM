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

### Optimisation du bundle : code-splitting par route (React.lazy)

Toutes les pages sont désormais chargées à la demande via `React.lazy` + `Suspense` dans `App.jsx`
(les layouts restent eager). Vérifié par un build Vite complet : le chunk principal passe de ~622 kB
(gzip 165 kB) à ~272 kB (gzip 85 kB), chaque page devient un chunk séparé, et l'avertissement Vite
« chunk > 500 kB » a disparu.

## 13. Décision d'architecture majeure : ECOPRIM = visionneuse 100% lecture

Révision de la décision fondatrice : la base `ecoprim` n'existe pas et ne doit être liée à aucune
table. ECOPRIM devient une **visionneuse/reporting en lecture seule** — Application → `ECONOMAT`,
Console → `dbmasterbacou`, aucune écriture. Confirmé : Années scolaires = `ECONOMAT.dbo.T_ANNEEACADEMIQUE`
(CodeAnnee/LibelleAnnee/Activer/CloturePartielle/ClotureDefinitive/DEBUT/FIN/CODESOCIETE).
Conséquences : (1) refonte de l'auth en lecture seule (login RH_USER, rôles dérivés de RH_USER,
session cookie, plus de tables users/roles/permissions locales) comme préalable ; (2) tous les écrans
de création/édition/suppression deviennent des consultations ; (3) les modules purement « écriture
ECOPRIM » sans équivalent ECONOMAT sont à retirer. Correspondance page→table dans `MAPPING_ECONOMAT.md`.
Déblocage : `php artisan schema:introspect` pour le schéma exact des deux bases.

## 14. Auth 100% lecture seule (slice 1 de la bascule ECONOMAT)

Refonte de lauthentification sans aucune écriture, préalable à la bascule visionneuse :
- `RhUser` (dbmasterbacou.RH_USER) devient lutilisateur **authentifiable** (provider auth pointé
  dessus dans `config/auth.php`) ; identité, rôles et périmètre lus dans dbmasterbacou.
- Rôles dérivés : bit `SuperAdmin` → « Super Admin » + jointure `role_user`→`roles` via `RH_USER.user_id`.
  Périmètre sociétés via `societe_utilisateur`.
- `AuthController` : login contre RH_USER (Email/Login/Matricule), refus des comptes `Supprimer`,
  restriction `CodeApp` optionnelle ; **plus de `syncLocalUser`**, aucune table users/roles locale.
- Middleware `role:` remplacé par `RhRoleMiddleware` (lecture des rôles RhUser).
- Connexion par défaut = `economat`, session en **cookie** (aucune écriture DB).
- Tests ecoprim/spatie obsolètes déplacés dans `tests/_legacy_ecoprim/` ; nouveau `RhUserAuthTest`
  (8 tests, 23 assertions verts) : login, rôles bit + role_user, Login/Matricule, mot de passe,
  compte supprimé, CodeApp, /me.

À suivre : rebrancher les pages une à une sur les vraies tables (Années → T_ANNEEACADEMIQUE, etc.).

### Slice 2 : Années scolaires → ECONOMAT.T_ANNEEACADEMIQUE (read-only, pilote)

Premier écran rebranché sur sa vraie table. Modèle `AnneeScolaire` en lecture seule sur
`economat.T_ANNEEACADEMIQUE` (PK réelle `CODE`), avec attributs mappés (id←CODE, libelle←LibelleAnnee,
date_debut←DEBUT, date_fin←FIN, active←Activer, cloturee←ClotureDefinitive, cloture_partielle,
code_annee←CodeAnnee, societe_code←CODESOCIETE) pour garder lAPI stable. Contrôleur réduit à
index/show ; routes passées en GET seul (suppression du POST proposer-reinscriptions). Page front
refaite en consultation (formulaire/édition/suppression retirés, colonne Code + « clôture partielle »).
Test `AnneeScolaireReadTest` (mapping + tri). Suite : 10 tests, 35 assertions verts.

### Slice 3 : tables de référence → ECONOMAT (read-only)

Niveaux → `T_NIVEAU`, Matières → `T_MATIERE`, Cycles → `T_CYCLE`, sur le même patron (modèles
mappés, contrôleurs index/show, routes GET seules, pages front en consultation). Niveaux exposent
leur cycle (relation sur `CodeCycle`). Formulaires/CyclesPanel retirés côté front ; colonne Matières
« coefficient » remplacée par « type ». Test `ReferenceReadTest`. Suite : 13 tests, 55 assertions verts.

### Slice 4 : Classes, Enseignants, Élèves → ECONOMAT (read-only)

- **Classes** → `T_CLASSE` (relation niveau via `CodN`) ; « enseignant principal » et « capacité »
  retirés (absents de T_CLASSE, gérés via T_CORPROFCLASSE).
- **Enseignants** → `T_PROFESSEUR`, liste blanche stricte : jamais `SalaireMensuel` ni `Mdp`.
- **Élèves** → `T_ETUDIANT` : liste paginée + **fiche détail** (identité, scolarité, père/tuteur & mère
  depuis T_ETUDIANT) ; **financier exclu** (`Scolarite`, `TotalPaye`, `Rb_*`, `Remise`) et technique (`MDP`).
- Contrôleurs index/show paginés avec relations ; routes GET seules ; actions d\047écriture (archiver,
  desactiver, création/édition) retirées. Front : listes en consultation + `EleveDetailPage`, routeur
  adapté (`/eleves/:id` → fiche). Test `PedagogieReadTest` (mapping + exclusion financière). Suite :
  16 tests, 76 assertions verts ; build Vite OK.

### Slice 5 : Notes & Résultats → vues ECONOMAT (read-only)

- **Notes** (« Saisie des notes » → consultation) : modèle `NoteVue` sur la vue `V_NOTECLASSE`,
  contrôleur index filtré par classe/matière/session, route GET seule. Page front en consultation
  (sélection classe + matière). Formulaires de saisie retirés.
- **Résultats & bulletins** : `RapportController::moyennesClasse` lit désormais la vue
  `V_MOYENNE_ELEVE_CLASSE` (modèle `MoyenneClasseVue`) au lieu de calculer depuis ecoprim ;
  classement par moyenne, filtre par classe (code réel). Page front simplifiée (plus de période).
  Bulletins PDF différés (BulletinController encore sur ecoprim).
- Test `NotesResultatsReadTest`. Suite : 18 tests, 86 assertions verts ; build Vite OK.

### Slice 6 : Absences → ECONOMAT ; Retards/Discipline retirés

- **Absences** → `T_ABSENCEELEVE` (lecture seule) : modèle mappé (matricule, classe, date, motif←Cause,
  justifiee←Justifier, relation élève via CodeEleve), contrôleur index/show paginé filtrable par classe,
  routes GET. Page front en consultation (filtre classe).
- **Retards** et **Discipline/Sanctions** : aucune table ECONOMAT → retirés de la sidebar et du routeur
  (règle « pas de source = pas de page » du mode lecture seule). Test `AbsenceReadTest`. Suite : 19 tests,
  92 assertions verts ; build Vite OK.

### Slice 7 : Console → dbmasterbacou (lecture seule)

- Sociétés → US_SOCIETE ; statut dérivé de ECO_SOCIETE_SUSPENSION (SUSPENDU → inactif). Compteur
  d'établissements retiré (pas de lien société↔établissement dans dbmasterbacou : chaque société a
  sa propre base ECONOMAT).
- Établissements → T_ETABLISSEMENT (statut réel STATUT, DREN/IEP).
- Utilisateurs & Accès → RH_USER (liste) + fiche avec rôles (role_user/roles) et sociétés
  (societe_utilisateur). Mot de passe masqué à la sérialisation (RhUser hidden). Journal d'activité
  retiré (aucune source). Rôles lus dans dbmasterbacou.roles.
- Toutes les actions d'écriture (CRUD, activer/désactiver, affectations) retirées ; routes GET seules
  sous role:Super Admin. Pages front en consultation. Test ConsoleReadTest. Suite : 23 tests, 112
  assertions verts ; build Vite OK.

## 15. Console modifiable : fondation (base propre ECOPRIM)

Nouvelle exigence : la Console redevient PLEINEMENT modifiable (CRUD sociétés/établissements +
affectations utilisateur↔établissement↔rôles), avec la hiérarchie Société→Établissement→Utilisateur→Rôle.
Décision (validée) : NE PAS écrire dans les tables partagées (US_SOCIETE, ECONOMAT.BEtablissements,
RH_USER restent en lecture) ; ECOPRIM gère ces données dans sa PROPRE base `ecoprim` (écrivable),
via migrations + modèles. Les utilisateurs restent lus depuis RH_USER (pas de création de compte de
login) ; ECOPRIM gère leur identité + leurs affectations.

Fondation posée :
- Connexion `ecoprim` (écrivable) dans config/database.php (+ DB_ECOPRIM_DATABASE).
- Migrations isolées `database/migrations/console/` : `societes`, `etablissements` (societe_code,
  rattachement obligatoire à une seule société), `roles` (catalogue), `affectations`
  (rh_user_id + etablissement_code + role_id ; un utilisateur peut avoir plusieurs rôles et plusieurs
  établissements). À appliquer : `php artisan migrate --path=database/migrations/console --database=ecoprim`.
- Modèles `App\Models\Console\{Societe, Etablissement, Role, Affectation}` avec relations.
- Test `ConsoleFoundationTest` (hiérarchie + multi-rôle). Suite : 25 tests, 118 assertions verts.

À suivre : contrôleurs CRUD + règles (établissement→1 société, affectations dans la société de
l'utilisateur), commande d'import depuis US_SOCIETE/BEtablissements, écrans front, branchement des routes.

## Console modifiable — CRUD complet (1er sept. 2026)

Console pleinement écrivable, dans la base propre `ecoprim` (tables `console_*`), les bases partagées
restant en lecture seule.

Backend :
- Contrôleurs CRUD : `SocieteController` (index withCount établissements, store/update, activer/désactiver),
  `EtablissementController` (rattachement obligatoire à une société existante, activer/désactiver),
  `RoleController` (catalogue : index/store/destroy), `UserController` (identité RH_USER lue + affectations),
  `AffectationController` (un utilisateur = une seule société ; anti-doublon utilisateur+établissement+rôle).
- Routes `role:Super Admin` : GET/POST/PUT + activer/désactiver pour sociétés & établissements,
  utilisateurs (lecture), rôles (index/store/destroy), affectations (store/destroy).
- Commande `console:importer` (idempotente) : peuple `console_societes` depuis US_SOCIETE, `console_etablissements`
  depuis ECONOMAT.BEtablissements (CodeSociete→societe_code, établissements sans société connue ignorés),
  et sème le catalogue de rôles par défaut. Remplace l'ancienne `societes:importer-dbmasterbacou`.

Frontend :
- Sociétés & Établissements : bouton Créer, formulaire modal (création/édition), activer/désactiver.
  Le formulaire établissement impose le choix d'une société (liste déroulante).
- Fiche utilisateur : gestion des rôles par établissement (ajout/retrait d'affectations), établissements
  limités à la société de rattachement de l'utilisateur.

Vérifié : `ConsoleCrudTest` (10 cas : CRUD, unicité, activer/désactiver, règle mono-société, anti-doublon,
import). Suite complète : 29 tests, 133 assertions verts. Build Vite OK.

À appliquer sur la machine : `php artisan migrate --path=database/migrations/console --database=ecoprim`
puis `php artisan console:importer` (nécessite `DB_ECOPRIM_DATABASE=ecoprim` dans backend/.env).

## Sociétés : bouton « Importer depuis US_SOCIETE » (1er sept. 2026)

La page Sociétés reste modifiable (console_societes) et reçoit un bouton « Importer depuis US_SOCIETE ».
`SocieteController::importer()` lit dbmasterbacou.US_SOCIETE (jamais d'écriture dessus) et n'ajoute que
les sociétés absentes : les enregistrements déjà présents/édités dans ECOPRIM ne sont pas réécrits.
Route POST /societes/importer (Super Admin). Test `test_import_societes_depuis_us_societe_sans_ecraser`.
Suite : 30 tests, 138 assertions verts. Build OK.

## Sociétés : vue fusionnée US_SOCIETE + ECOPRIM (1er sept. 2026)

La page Sociétés affiche désormais les sociétés RÉELLES lues en direct dans
`dbmasterbacou.US_SOCIETE` (lecture seule, jamais modifiée), avec par-dessus la surcouche
ECOPRIM `console_societes` quand elle existe. Conséquences :
- La page montre les vraies sociétés même si les tables Console ne sont pas encore migrées
  (lecture de la surcouche protégée par try/catch) ou si l'import n'a pas été lancé.
- Colonne « Source » : `US_SOCIETE` (pas encore reprise), `US_SOCIETE + ECOPRIM` (reprise et
  complétée), `ECOPRIM` (créée uniquement ici).
- Bouton « Reprendre » sur une société non reprise : ouvre le formulaire prérempli et crée la
  surcouche ECOPRIM (même code) — activer/désactiver et édition ne sont proposés qu'ensuite.
- Le bouton « Importer depuis US_SOCIETE » reprend toutes les sociétés absentes d'un coup.

Tests : affichage sans surcouche, fusion avec surcouche, import additif. Suite : 32 tests,
148 assertions verts. Build Vite OK.

## Sociétés : création enregistrée dans US_SOCIETE (1er sept. 2026)

Décision (revient sur la règle précédente, à la demande explicite) : une société créée dans ECOPRIM
est désormais **insérée dans `dbmasterbacou.US_SOCIETE`**. Périmètre volontairement restreint :

- **INSERT uniquement.** `App\Services\UsSocieteCreateur` ne fait jamais d'UPDATE ni de DELETE sur
  US_SOCIETE : les sociétés existantes, partagées avec les autres applications, ne sont jamais
  modifiées par ECOPRIM. Les éditions/compléments restent dans la surcouche `console_societes`.
- **`NUMAUTO` détecté à l'exécution** : si la colonne est IDENTITY on ne l'alimente pas, sinon on
  calcule `MAX(NUMAUTO)+1`. Le code fonctionne dans les deux configurations.
- **Validation alignée sur les largeurs réelles** de US_SOCIETE (CODESOCIETE varchar(17),
  NOMSOCIETE/AD1SOCIETE/VILLESOCIETE varchar(50), TELSOCIETE varchar(20)…) pour qu'un INSERT ne
  puisse pas échouer en troncature.
- **`NOMBASE`** (base ECONOMAT rattachée) exposé dans le formulaire de création uniquement.
- `store()` distingue automatiquement création (code absent de US_SOCIETE → INSERT + surcouche) et
  reprise (code déjà présent → surcouche seule, US_SOCIETE intacte).

Tests ajoutés : insertion dans US_SOCIETE, reprise sans écriture, édition sans écriture, calcul de
NUMAUTO, refus d'un code > 17 caractères. Suite : 36 tests, 162 assertions verts. Build Vite OK.

## Établissements : parité fonctionnelle BACOU (1er sept. 2026)

Inspiré du code de BACOU GESTION LOCATIVE (fourni par l'utilisateur), **fonctionnalités reprises,
design ECOPRIM conservé**. Décisions : source = `ECONOMAT.dbo.BEtablissements` (et non
T_ETABLISSEMENT) ; écriture = **création + modification, jamais de suppression**.

- Vue fusionnée : lecture directe de `BEtablissements` + surcouche `console_etablissements`.
  La page affiche les vrais établissements même sans surcouche migrée. Colonne « Source »
  (`BEtablissements` / `BEtablissements + ECOPRIM` / `ECOPRIM`) et bouton « Reprendre ».
- `App\Services\BEtablissementEcrivain` : INSERT + UPDATE seulement, jamais de DELETE.
  Largeurs réelles respectées ; `Intitule`, `Adresse1`, `Pays`, `CodeSociete` sont NOT NULL
  et donc obligatoires au formulaire.
- Désactivation logique (surcouche `actif`) à la place de la suppression.
- Fiche détaillée `/admin/etablissements/:code` en sections (Identification, Localisation,
  Coordonnées, Rattachement), adressée par CODE donc consultable même sans surcouche.
- Filtres liste : recherche texte (intitulé/code/ville) + filtre par société ; formulaire en
  sections avec type Privé/Public/Confessionnel.

Tests : création répercutée dans BEtablissements, champs NOT NULL exigés, liste sans surcouche,
fiche par code, modification répercutée, désactivation sans suppression.
Suite : 41 tests, 183 assertions verts. Build Vite OK.

## Sélecteur d'établissement — contexte de travail (1er sept. 2026)

Fonctionnalité reprise de BACOU (`choisir.blade.php`), au design ECOPRIM. C'était le manque
principal de la console.

- `ContexteController` : `GET /contexte` (contexte courant + établissements accessibles),
  `POST /contexte/etablissement`, `DELETE /contexte/etablissement`. Choix conservé en session
  (`etablissement_code`, `etablissement_nom`), comme dans BACOU.
- Périmètre : un Super Admin voit tous les établissements ; les autres uniquement ceux auxquels
  ils sont affectés. Les établissements désactivés dans ECOPRIM ne sont jamais proposables —
  refus en 422 même si le code est forcé côté client.
- Routes ouvertes à tout utilisateur connecté (hors bloc Super Admin).
- Front : page `/choisir-etablissement` (grille de cartes, établissement actif mis en avant,
  bouton Désélectionner) et `ContexteBadge` dans l'en-tête, rappel permanent + accès rapide au
  changement d'établissement.

Tests `ContexteTest` (5 cas) : visibilité Super Admin, limitation aux affectations, choix puis
désélection, refus d'un établissement non accessible, refus d'un établissement désactivé.
Note : les requêtes de test doivent être « stateful » (en-têtes Origin/Referer) pour que la
session Sanctum SPA soit démarrée.
Suite : 46 tests, 204 assertions verts. Build Vite OK.

## Utilisateurs : création et modification dans RH_USER (1er sept. 2026)

Dernier bloc de parité BACOU. Périmètre validé : **création + modification, jamais de suppression**.

- `App\Services\RhUserEcrivain` : INSERT/UPDATE sur `dbmasterbacou.RH_USER`, aucun DELETE.
  `Id` détecté à l'exécution (IDENTITY ou `MAX(Id)+1`), largeurs réelles respectées.
- Mot de passe haché avec `Hash::make` (bcrypt) — exactement ce que vérifie `AuthController`
  via `Hash::check`, donc un compte créé ici peut se connecter. En modification, un mot de
  passe laissé vide est conservé.
- Retirer un compte = **désactivation logique** `Supprimer = 1` (la connexion refuse déjà les
  comptes marqués supprimés). Réactivation possible. La ligne n'est jamais effacée.
- Champs gérés : Login, Nom, Prénom, Email, Matricule, Contact, Etab, Profil, CodeApp, SuperAdmin.
- Front : liste avec recherche, badge Super Admin, formulaire en sections
  (Connexion / Identité / Rattachement), boutons Désactiver / Réactiver.

Tests : création avec hachage bcrypt vérifié, unicité du login, modification sans changer le mot
de passe, désactivation qui ne supprime pas la ligne.
Suite : 50 tests, 223 assertions verts. Build Vite OK.

## NEXORA École Primaire — marque, nettoyage, paramètres, communication (2 sept. 2026)

### Marque
Renommage complet en **NEXORA École Primaire** : nouveau logo SVG (monogramme N doré sur fond
brun, aligné sur le thème existant) dans `components/ui/Logo.jsx`, favicon `public/favicon.svg`,
sidebar application, sidebar Console, page de connexion, titre du navigateur, `lang="fr"`.
Aucun changement de design : palette et composants inchangés.

### Nettoyage de la navigation
- Placeholders retirés : Calendrier scolaire, Compétences, Barèmes, Salles & créneaux,
  Préinscriptions, Classe → Salle, Statistiques, Effectifs, Réunions.
- **Gardés en « Bientôt » sur décision explicite** : Emplois du temps, Espace parent.
- **Pages mortes supprimées** : Documents élèves/enseignants/établissement, Annonces, Messages.
  Motif : leurs modèles (`Document`, `Annonce`, `Message`) n'avaient aucune connexion déclarée et
  tapaient donc sur `economat.documents/annonces/messages`, tables inexistantes. `DocumentsPanel`
  a été retiré des fiches élève et enseignant. Fichiers rangés dans `features/_retires/`.
- Lien mort « Journal d'activité » retiré (sa route avait déjà disparu).

### Console : page Rôles & permissions activée
`/admin/roles` branchée sur l'API existante (catalogue `console_roles`) : liste, création,
retrait du catalogue. Ajoutée aux deux sidebars.

### Paramètres adossés à ECONOMAT
Helper commun `App\Services\EconomatTable` : INSERT/UPDATE seulement, jamais de DELETE, avec
détection à l'exécution des clés primaires IDENTITY vs compteur manuel.
- **Documents élèves** → `T_PREREQUIS` : catalogue par niveau et année (libellé, type, montant,
  quantité, exigé à l'inscription / à la scolarité), filtres année/niveau/recherche.
- **Passerelle SMS** → `ECO_SMS_CONFIG` (et non `T_SMS`, qui est le journal des envois) :
  fournisseur, environnement, URL/clé/secret API, expéditeur, options. **Clé et secret jamais
  réaffichés** ; un enregistrement sans clé conserve la clé existante.
- **Messagerie SMTP** → `T_MAIL_DIFFUSION` : adresse, serveur, port, mot de passe (jamais renvoyé).
Les deux configurations sont rattachées à l'établissement du contexte de travail.

### Communication
Annonces et Messages remplacés par deux pages : **Envoi SMS / Mail** (choix du canal,
destinataires multiples, compteur 250 caractères pour le SMS) et **Historique des envois**.
- SMS : un enregistrement par destinataire dans `T_SMS` (file d'envoi reprise par la passerelle) ;
  refusé en 422 si la passerelle n'est pas active.
- Mail : envoi SMTP réel avec les paramètres de `T_MAIL_DIFFUSION`.

Tests : `ParametresCommunicationTest` (11 cas) — documents élèves, secrets non exposés et
préservés, refus si passerelle inactive, dépôt par destinataire, historique, borne de 250
caractères. Suite : **61 tests, 274 assertions verts**. Build Vite OK.

Reste du programme : formulaire d'inscription depuis `T_ETUDIANT`, enseignants depuis
`T_PROFESSEUR`.

## Inscriptions et Enseignants : saisie réelle dans ECONOMAT (2 sept. 2026)

Deux services d'écriture, mêmes règles que le reste : création + modification, jamais de
suppression, liste blanche stricte des colonnes.

### Inscriptions → `T_ETUDIANT` (74 colonnes)
- `App\Services\EtudiantEcrivain` : écrit uniquement identité, coordonnées, scolarité et
  filiation. Le **financier** (`Scolarite`, `TotalPaye`, `Rb_PC`, `Rb_SCO`, `Remise`, `PC`) et le
  **technique** (`MDP`, `MDP2`, `NUM`) ne sont jamais touchés — un test le vérifie en posant une
  valeur financière côté ECONOMAT puis en modifiant l'élève.
- Une inscription EST un élève. Les **quatre mouvements** (inscription, réinscription, transfert
  entrant, transfert sortant) sont portés par les indicateurs `Inscription` / `Reinscription` /
  `Transfert`, avec filtre par mouvement dans la liste.
- `InscriptionController` remplace l'ancien, qui écrivait dans une table `inscriptions`
  inexistante (page morte). Route DELETE supprimée. `InscriptionsPanel` retiré de la fiche élève :
  il faisait doublon puisque l'inscription est l'élève lui-même.
- Formulaire en cinq sections (mouvement, identité, coordonnées, scolarité, père/tuteur, mère),
  listes déroulantes alimentées par les référentiels (années, cycles, niveaux, classes) avec
  cascade cycle → niveau → classe. Champs d'origine affichés seulement pour les transferts.

### Enseignants → `T_PROFESSEUR` (63 colonnes)
- `App\Services\ProfesseurEcrivain` : état civil, coordonnées, carrière. Le **salaire**
  (`SalaireMensuel`) et le **mot de passe** (`Mdp`) ne sont jamais écrits — testé en tentant de
  les passer dans la requête.
- `NomComplet` est dérivé automatiquement de prénom + nom, car les listes ECONOMAT s'en servent.
- Pas de suppression : un départ se renseigne par `DateDepart`, `Motif`, `EtabAccueil`.
- Le formulaire existait mais n'avait **aucune route** : `/enseignants/nouveau` et
  `/enseignants/:id/modifier` ajoutées, bouton de création et lien Éditer dans la liste,
  recherche par nom/prénom/matricule. Les appels `deleteEnseignant`/`desactiverEnseignant`
  (sans route backend) ont été retirés de l'API front.

Tests `SaisieEconomatTest` (11 cas) : écriture en base, champs obligatoires, les quatre
mouvements et leurs indicateurs, filtre par mouvement, unicité des matricules, financier
préservé à la modification, absence de route de suppression (405), départ enseignant.
Suite : **72 tests, 322 assertions verts**. Build Vite OK.

Programme du jour terminé.

## Inscriptions : saisie en assistant (2 sept. 2026)

Le formulaire d'inscription se remplit désormais étape par étape :
**Identité de l'élève → Coordonnées → Scolarité → Père / Tuteur → Mère**, avec fil d'étapes,
boutons Précédent / Suivant et compteur « Étape n sur 5 ».

- `components/ui/StepIndicator.jsx` : fil d'étapes extrait en composant partagé (le formulaire
  élève avait le même en local), avec retour possible sur une étape déjà franchie en cliquant
  dessus.
- « Suivant » contrôle les champs bloquants de l'étape : nom et prénom à l'étape 1, type de
  mouvement et année scolaire à l'étape 3. Rien n'est perdu entre les étapes.
- Si le serveur refuse un champ d'une étape précédente, l'assistant y ramène automatiquement.
- La touche Entrée fait avancer d'une étape au lieu d'enregistrer prématurément.
- **L'assistant s'applique aussi en modification** : même parcours en cinq étapes pour créer et
  pour corriger un dossier, avec retour direct sur une étape via le fil d'étapes.

## Inscriptions : étape Photo (2 sept. 2026)

Sixième étape de l'assistant : **Photo**, avec aperçu, choix du fichier et remplacement.

- `ECONOMAT.T_ETUDIANT.Photo` ne stocke qu'un **nom de fichier** : les images vivent dans un
  dossier partagé lu par ECONOMAT. Ce dossier est paramétrable —
  `NEXORA_PHOTOS_ELEVES` dans `.env` (voir `config/nexora.php`). Sans configuration, les photos
  restent dans le stockage local de NEXORA et ECONOMAT ne les voit pas.
- `App\Services\PhotoEleveStockage` : nommage déterministe d'après le matricule (sinon le code
  élève), donc **une seule photo par élève** — les variantes d'extension de l'ancienne photo sont
  retirées, pas d'accumulation de fichiers orphelins.
- Routes `GET|POST /inscriptions/{id}/photo`. Le dossier partagé n'étant pas exposé par le serveur
  web, la photo est renvoyée en flux par l'API. **Sécurité** : la valeur lue en base est réduite à
  son `basename`, donc une valeur hostile du type `../../etc/passwd` ne permet pas de sortir du
  dossier (testé).
- Validation : image JPG/PNG/WebP, 4 Mo maximum (seuil configurable).
- Front : la photo est récupérée en **blob via axios** et non par `<img src>`, car l'API est sur un
  autre port et une balise `img` n'enverrait pas le cookie de session. À la création, le fichier est
  gardé en mémoire puis envoyé juste après l'enregistrement de l'élève — si l'envoi de la photo
  échoue, l'élève reste enregistré et l'assistant revient sur l'étape Photo avec le message.

Tests : écriture du fichier et du nom en base, remplacement sans accumulation, refus d'un non-image,
service du fichier et 404 si absent, tentative de remontée d'arborescence.
Suite : **77 tests, 344 assertions verts**. Build et lint propres.

## Connexion : établissement du compte affiché avant le mot de passe (2 sept. 2026)

Dès que l'identifiant est saisi (login, email ou matricule), le nom de l'établissement rattaché
au compte apparaît dans un champ en lecture seule, placé **avant** le mot de passe.

- Endpoint `POST /etablissement-du-compte`, public par nécessité (l'utilisateur n'est pas encore
  connecté), avec plusieurs garde-fous :
  - **il ne renvoie QUE le libellé de l'établissement** — la réponse ne contient rien d'autre,
    vérifié par un test sur les clés du corps ;
  - identifiant inconnu, compte désactivé ou compte sans établissement renvoient tous `null`,
    donc aucune de ces situations n'est distinguable ;
  - `throttle:10,1` pour freiner l'énumération d'identifiants ;
  - le filtre `CodeApp` de la connexion s'applique aussi ici.
- Le libellé vient de `BEtablissements`, à défaut de la surcouche `console_etablissements`, à
  défaut le code brut est affiché.
- Front : recherche déclenchée après 500 ms d'inactivité et à partir de 3 caractères ; le résultat
  est conservé avec l'identifiant interrogé, donc le champ ne montre jamais un libellé qui ne
  correspond plus à la saisie en cours. Échec réseau silencieux : ce n'est qu'un confort
  d'affichage, il ne doit jamais bloquer la connexion.

**Réserve assumée** : afficher un établissement confirme, pour un compte qui en a un, que
l'identifiant existe. C'est inhérent à la fonctionnalité demandée ; l'exposition est réduite au
minimum (un libellé, débit limité, aucune autre donnée).

Tests : reconnaissance par login/email/matricule, repli sur le code, absence de fuite pour les
trois cas nuls, forme de la réponse, identifiant obligatoire.
Suite : **82 tests, 363 assertions verts**. Build et lint propres.

## Année scolaire clôturée : consultation seule (2 sept. 2026)

Règle appliquée sans exception : sur une année clôturée (`T_ANNEEACADEMIQUE.ClotureDefinitive`),
**aucun ajout, aucune modification, aucune suppression** — la lecture reste entière.

`App\Support\AnneeScolaireGuard` réécrit. L'ancien guard était **mort** (branché uniquement sur
`RetardController`, dont la page avait été retirée) et raisonnait sur la clé numérique de l'année,
alors que les écritures NEXORA désignent l'année par son libellé ou son code. Il cherche
désormais la correspondance sur `CodeAnnee`, `LibelleAnnee` et `CODE`, avec un cache par requête.
La réponse est un **423 Locked** (la donnée existe mais est verrouillée), avec un message nommant
l'année. L'échappatoire Super Admin `?force=1` de l'ancienne version n'a pas été reprise.

Verrous posés sur les trois écritures qui portent une année :
- **Inscriptions** (`T_ETUDIANT`) : création, modification, ajout de photo. À la modification,
  l'année ACTUELLE du dossier verrouille, et on refuse aussi de le déplacer VERS une année
  clôturée.
- **Documents élèves** (`T_PREREQUIS`) : création et modification, mêmes deux contrôles.
- **Enseignants** (`T_PROFESSEUR`) : création et modification sur `CodeAnnee`.
Une année inconnue du référentiel ne verrouille pas, et une base injoignable non plus : on ne
bloque pas une saisie sur une incertitude technique.

Côté écran : bandeau d'avertissement quand le filtre porte sur une année clôturée, bouton
« Nouvelle inscription » désactivé, années clôturées signalées « (clôturée) » et non sélectionnables
dans le formulaire, bandeau et bouton Enregistrer désactivé sur un dossier verrouillé.

### Corrections découvertes au passage
- **Page Retards** : morte (modèle sur une table `retards` inexistante), pourtant encore routée en
  `apiResource`. Contrôleur, modèle et pages front retirés.
- **Tableau de bord** : il renvoyait une erreur 500. Il filtrait sur `active`, qui est un accesseur
  et non une colonne (`Activer`), lisait `T_ABSENCEELEVE` par `date_absence` au lieu de `Date`, et
  comptait des `notes`, `retards` et `sanctions` — trois tables inexistantes. Réécrit sur ECONOMAT :
  moyennes via `V_MOYENNE_ELEVE_CLASSE`, effectifs groupés depuis `T_ETUDIANT`, indicateurs
  « retards » et « sanctions » retirés (sans source). Chaque indicateur est isolé : une vue absente
  renvoie null pour ce seul chiffre au lieu de faire tomber la page.

Tests `AnneeClotureeTest` (13 cas) : refus à la création, à la modification, au déplacement vers une
année clôturée, à la photo ; consultation préservée ; documents et enseignants ; reconnaissance du
code comme du libellé ; année inconnue non bloquante ; message nommant l'année.
Suite : **95 tests, 396 assertions verts**. Build et lint propres.

## En-tête : utilisateur, établissement et année de consultation (2 sept. 2026)

L'en-tête porte désormais les trois repères du contexte de travail : **nom de l'utilisateur
connecté** (avec ses rôles), **établissement** et **année scolaire** sous forme de liste
déroulante pour consulter les années précédentes.

- **L'établissement s'affiche sans action de l'utilisateur** : à défaut de choix explicite en
  session, c'est le rattachement du compte (`RH_USER.Etab`) qui est présenté, avec l'indicateur
  `etablissement_par_defaut`. L'ancien badge « Aucun établissement sélectionné » ne s'affiche
  donc plus que pour un compte réellement sans rattachement. Un choix explicite prime toujours.
- **Année de consultation** : `POST /contexte/annee`, conservée en session
  (`annee_travail`), par défaut l'année active du référentiel. Une année **clôturée est
  sélectionnable** — c'est le but : on consulte le passé. Elle est alors signalée dans l'en-tête
  (cadre ambre + cadenas), et les verrous d'écriture déjà en place s'appliquent.
- `GET /contexte` renvoie aussi la liste des années (la plus récente d'abord) avec, pour chacune,
  son état `active` et `cloturee` — de quoi étiqueter « en cours » et « clôturée » dans la liste.
- Changer d'année invalide les écrans rattachés à une année (inscriptions, documents élèves) : la
  liste des inscriptions suit l'année de l'en-tête par défaut, tout en gardant son filtre local
  pour un choix ponctuel.
- `ContexteBadge` remplacé par `ContexteBarre`.

Tests ajoutés à `ContexteTest` : rattachement proposé par défaut, choix explicite prioritaire,
année active par défaut, consultation d'une année précédente clôturée et persistance du choix,
refus d'une année inconnue, états exposés par le référentiel.
Suite : **101 tests, 420 assertions verts**. Build et lint propres.

## Correctif : la page Inscriptions ne s'affichait plus (2 sept. 2026)

**Cause.** Dans `InscriptionListPage`, la photo existante était dérivée ainsi :

```js
const photoExistante = photoBlob?.id === form?.id ? photoBlob.url : null
```

Au premier rendu, `photoBlob` et `form` valent tous deux `null` : les deux membres de la
comparaison valent donc `undefined`, la condition est **vraie**, et le code lit `photoBlob.url`
sur `null`. La page plantait dès le montage, d'où l'écran vide. Le `npm run build` passait
puisqu'il s'agit d'une erreur d'exécution et non de syntaxe — c'est ce qui l'a laissée passer.

**Correction.** `form?.id && photoBlob?.id === form.id ? … : null` : plus de comparaison entre
deux `undefined`. Vérifié par un rendu hors navigateur (`react-dom/server`, requêtes désactivées
pour reproduire le premier rendu) : l'ancienne ligne fait échouer le rendu, la nouvelle le passe.
Le reste du fichier a été audité, aucune autre comparaison de ce type dans le front.

**Garde-fou ajouté.** `components/ErrorBoundary.jsx`, branché autour de l'`<Outlet />` des deux
layouts : une erreur d'affichage montre désormais un message lisible et laisse la navigation
utilisable, au lieu d'une page blanche sans indice. Le détail technique reste dépliable, et le
garde-fou se réarme au changement de route (`resetKey`).

## Inscriptions : restrictions métier (2 sept. 2026)

Constat de départ : les seuls contrôles en place étaient les champs obligatoires, les longueurs et
le verrou d'année clôturée. Les règles de gestion, elles, n'étaient pas tenues.

**Modèle de données tranché avec l'utilisateur** : `T_ETUDIANT` ne garde qu'**une ligne par élève**
(ECONOMAT archive l'historique dans `T_HISTETUDIANT`, une copie quasi identique de la table). Cela
change la nature des mouvements :

- `inscription` et `transfert_entrant` → l'élève est **nouveau** : la ligne est créée, le matricule
  doit être libre. Si le matricule existe, le message renvoie le nom du titulaire et invite à
  choisir « Réinscription ».
- `reinscription` et `transfert_sortant` → l'élève **existe** : sa ligne est **mise à jour** (201
  devient 200), jamais dupliquée. Un matricule inconnu est refusé en invitant à choisir
  « Inscription ».

**Un élève ne peut être inscrit qu'une fois par an** : une réinscription vers l'année que l'élève
occupe déjà est refusée, en le nommant. C'était le trou principal.

Autres restrictions ajoutées :
- **Matricule obligatoire et unique** (il était optionnel, ce qui laissait passer deux homonymes
  sans matricule).
- **Doublon d'identité** : même nom, même prénom et même date de naissance → refus citant le
  matricule du dossier existant. Deux homonymes de dates de naissance différentes restent
  possibles. Sans date de naissance, on ne bloque pas : l'homonymie est plausible.
- **Cohérence du rattachement** (`App\Support\CoherenceScolaire`) : la classe doit exister, relever
  du niveau annoncé et appartenir à l'année visée ; le niveau doit relever du cycle annoncé.
- **Dates** : date de naissance obligatoirement passée ; date d'inscription comprise dans la
  période `DEBUT`–`FIN` de l'année scolaire.
- **Transfert entrant** : établissement d'origine exigé.
- **Sexe** borné à M ou F.

Principe appliqué partout : on ne bloque que sur une incohérence **constatée**. Référentiel
injoignable ou valeur absente → on laisse passer, pour ne pas refuser une saisie sur une
incertitude technique.

Tests `RestrictionsInscriptionTest` (15 cas) couvrant chacune de ces règles, plus la mise à jour
des tests antérieurs qui supposaient qu'une réinscription créait une ligne.
Suite : **116 tests, 476 assertions verts**. Build propre.

Reste ouvert : `T_PROFESSEUR` et `T_PREREQUIS` n'ont pas encore de contrôles de cohérence
équivalents.

## L'année sélectionnée borne toute l'application (2 sept. 2026)

Le filtre est posé **côté serveur**, pas page par page : `App\Support\ContexteScolaire` lit
l'année de travail (session, à défaut l'année active) et restreint les requêtes.

**Difficulté traitée** : ECONOMAT n'a pas de convention unique — certaines tables stockent le
libellé (`T_ETUDIANT.AnneeAcad`, `T_CLASSE.ANNEE`, `T_NIVEAU.ANNEE`, `T_PREREQUIS.ANNEE`),
d'autres le code (`T_PROFESSEUR.CodeAnnee`, `V_NOTECLASSE.CodeAnnee`,
`V_MOYENNE_ELEVE_CLASSE.CodeAnnee`, `T_ABSENCEELEVE.AnneeCour`). Le filtre porte donc sur les
**deux formes** : pas de devinette table par table, et le comportement reste juste si la
convention diffère d'une installation à l'autre.

Bornés à l'année : élèves, inscriptions, classes, niveaux, enseignants, absences, notes,
documents élèves, moyennes, assiduité, évaluations, bulletins et tableau de bord. Cycles et
matières ne portent pas d'année et restent globaux. Un filtre explicite (`?annee=`) prime
toujours sur l'année de l'en-tête, pour une consultation ponctuelle.

Sans année connue (référentiel vide ou injoignable), les requêtes ne sont **pas** filtrées : on
ne masque pas l'applicatif entier sur une incertitude technique.

Côté écran, changer d'année invalide tout le cache de requêtes plutôt qu'une liste d'écrans
énumérée — sinon chaque nouvelle page serait à ajouter à la main.

### Corrections découvertes au passage
- **`RapportController` était mort** : `assiduiteClasse` appelait `Classe::eleves()` et
  `Eleve::absences`, `evaluations` et `calculerMoyennes` tapaient sur la table locale `notes` —
  aucune de ces relations ni de cette table n'existe. Les trois endpoints renvoyaient 500.
  Réécrits sur ECONOMAT : moyennes et rangs sont **lus** dans `V_MOYENNE_ELEVE_CLASSE`
  (ECONOMAT les calcule déjà, NEXORA ne recalcule rien), assiduité depuis `T_ABSENCEELEVE`,
  évaluations reconstituées en regroupant `V_NOTECLASSE` par classe, matière, session et type.
- **Bulletins PDF** : ils dépendaient de `calculerMoyennes`, de `Periode` et de `Deliberation`,
  tous morts. Reconstruits sur les mêmes vues ; la « période » devient la **session** ECONOMAT
  (`CodeSession`), seule notion existante. La vue PDF affiche coefficient, nombre de notes et
  rang sur effectif, et porte la marque NEXORA. **Les délibérations ne figurent plus au
  bulletin** : elles n'ont aucun équivalent dans ECONOMAT.
- **Défaut de mon propre cache** : `ContexteScolaire` met l'année en cache le temps d'une
  requête, mais changer d'année ne l'invalidait pas — un test l'a révélé. `definirAnnee()`
  appelle désormais `oublier()`.

Tests `PorteeAnneeTest` (7 cas, jeu de données complet sur deux années) : par défaut seule
l'année active est visible, changer d'année déplace **toutes** les listes, les niveaux suivent,
le tableau de bord suit (effectifs, moyennes, agrégats par classe), les rapports suivent, un
filtre explicite prime, et le choix persiste entre les appels.
Suite : **123 tests, 553 assertions verts**. Build et lint propres.

## Enseignants : formulaire en assistant, calqué sur les inscriptions (2 sept. 2026)

`/enseignants/nouveau` et `/enseignants/:id/modifier` reprennent la mécanique de la page
Inscriptions : fil d'étapes partagé (`StepIndicator`), navigation Précédent / Suivant, compteur
« Étape n sur N », contrôle des champs bloquants avant de passer, retour automatique sur l'étape
refusée par le serveur, et touche Entrée qui avance au lieu d'enregistrer.

Étapes : **État civil → Coordonnées → Carrière → Administration**, plus **Départ** uniquement en
modification (on ne renseigne pas le départ d'un enseignant qu'on recrute).

**Pas d'étape Photo** : contrairement à `T_ETUDIANT`, la table `T_PROFESSEUR` n'a aucune colonne
photo.

Le formulaire n'exposait que 6 champs alors que `T_PROFESSEUR` porte toute une carrière
administrative. Champs ajoutés, tous déjà présents en base : situation matrimoniale, date et lieu
de naissance, ville, cellulaire, **corps**, **échelon**, formation professionnelle (`FormaProf`),
volume horaire (`VHORAIRE`, borné à 60 h), fonction, emploi, service, **DREN**, **DDEN**,
première prise de service et son école, années de service, arrivée au poste. Le salaire
(`SalaireMensuel`) et les identifiants de connexion (`LOGIN`, `Mdp`) restent hors liste blanche.

Détail technique : le préremplissage ne passe plus par un effet qui recopie la fiche dans l'état.
Le formulaire est **dérivé** de la fiche chargée plus les modifications en cours — pas de course
entre le chargement et la saisie, et le lint ne signale plus de `setState` dans un effet.

Vérifié aussi par un rendu hors navigateur des deux modes (création et modification), qui
confirme que l'étape Départ n'apparaît qu'en modification.
Suite : **125 tests, 558 assertions verts**. Build et lint propres.

## Emplois du temps (2 sept. 2026)

Entrée « Bientôt » devenue une vraie page : `/emplois-du-temps`. Tout est adossé à ECONOMAT,
aucune table NEXORA créée.

**Modèle**. Un créneau est `T_EMPLOIDUTEMPS` (jour, heure, classe, matière, salle, année).
Trame : jours dans `T_EMPJOUR`, plages dans `T_HORAIRE` (début, fin, durée), salles dans
`T_SALLESCLASSE` (avec nombre de places). Fait notable : **l'enseignant n'est pas stocké** — il
est déduit de `T_CORPROFCLASSE`, qui dit qui enseigne telle matière dans telle classe pour une
année, exactement comme le fait la vue `V_EMPLOIDUTEMPS_SIMPLE`.

**Écran**. Grille jour × heure pour une classe choisie, une case par créneau affichant matière,
enseignant déduit et salle. Clic sur une case pour la remplir, la modifier ou la vider. Trame
horaire vide → message explicite invitant à renseigner `T_EMPJOUR` et `T_HORAIRE` dans ECONOMAT,
plutôt qu'une grille vide inexplicable.

**Contraintes, les trois bloquantes** (`App\Support\ConflitsEmploiDuTemps`) :
1. une classe ne peut avoir deux cours au même créneau ;
2. une salle ne peut accueillir deux classes à la fois ;
3. un enseignant ne peut être dans deux classes à la fois — déduit via l'affectation.
Chaque refus nomme la cause : la matière déjà posée, la classe qui occupe la salle, ou
l'enseignant et la classe où il est déjà retenu. Une modification ne se déclare jamais en conflit
avec elle-même. Une matière sans professeur affecté ne déclenche pas le contrôle enseignant.

**Suppression réelle — exception assumée** à la règle « jamais de DELETE » qui vaut partout
ailleurs dans NEXORA : vider une case efface la ligne. Un emploi du temps est de la planification
qu'on réorganise, pas un historique à conserver ; sans cela une grille mal saisie resterait
définitivement encombrée. Le verrou d'année clôturée s'applique en revanche pleinement, y compris
à la suppression, et la grille est bornée à l'année de travail de l'en-tête.

Tests `EmploiDuTempsTest` (11 cas) : trame exposée, pose et lecture d'un créneau avec enseignant
déduit, les trois conflits refusés avec leur message, absence de conflit à une autre heure,
modification sans auto-conflit, suppression effective, matière et salle inconnues refusées,
verrou d'année clôturée, cloisonnement par année.
Suite : **136 tests, 610 assertions verts**. Build et lint propres.

Au passage : le préremplissage par effet des pages Passerelle SMS et Messagerie SMTP a été
remplacé par une dérivation (configuration chargée + modifications en cours), supprimant les
derniers avertissements de lint sur mes fichiers.

## Impression des documents

Quatre documents PDF, tous bâtis sur une mise en page commune
(`resources/views/pdf/layout.blade.php`) : entête NEXORA, nom de l'établissement du contexte
de travail, année, pied de page daté. Les cases vides sortent en « Non renseigné » plutôt qu'en
blanc, pour qu'un champ oublié se voie sur le papier.

- **Fiche de l'élève** — identité, coordonnées, scolarité, père/tuteur, mère, et la photo. La
  photo vit dans un dossier partagé hors du serveur web : dompdf ne pouvant pas l'atteindre par
  URL, elle est incorporée en base64.
- **Fiche de l'enseignant** — état civil, coordonnées, carrière, administration, et le bloc
  départ seulement s'il est renseigné.
- **Emploi du temps** — la grille jour × heure de la classe, en paysage.
- **Liste de la classe** — liste nominative numérotée avec le contact du tuteur, pour l'appel.

Les boutons sont posés là où le document se demande : ligne par ligne sur Inscriptions et
Enseignants, et sur Emplois du temps pour la grille comme pour la liste de la classe. Le PDF est
récupéré par `apiClient` en blob puis ouvert dans un onglet — un `window.open` direct perdrait
le cookie de session ; si le navigateur bloque l'onglet, on retombe sur un téléchargement.

**Imprimer est une lecture** : aucune écriture dans ECONOMAT, donc une année clôturée s'imprime
normalement. En revanche l'année de l'en-tête est respectée : la liste d'une classe change avec
l'année choisie.

Tests `ImpressionTest` (7 cas) : les quatre documents renvoient bien un `application/pdf`, la
classe est obligatoire pour les documents de classe, la liste suit l'année de travail (vérifié
sur les données passées à la vue, le PDF étant binaire), la photo part en base64, et une année
clôturée reste imprimable.
Suite : **143 tests, 642 assertions verts**. Build et lint propres, et les trois pages modifiées
passent le rendu à blanc hors navigateur.

## Connexion par nom d'utilisateur ou par email

La saisie est résolue par une seule méthode, partagée par la connexion et par la recherche
de l'établissement affiché avant le mot de passe : `Login`, `Email` ou `Matricule`, au choix
de l'utilisateur. Le champ de la page de connexion est désormais intitulé
« Nom d'utilisateur ou email » avec un exemple en filigrane, pour que la possibilité se voie.

La comparaison est explicitement insensible à la casse et aux espaces de bord
(`LOWER(LTRIM(RTRIM(colonne)))`) : un email recopié depuis un courrier arrive souvent avec
une majuscule ou une espace traînante. Une collation SQL Server insensible à la casse le
ferait déjà, mais la connexion ne dépend plus de la configuration du serveur.

**Le nom de famille (`Nom`) n'est volontairement pas un identifiant** : il n'est pas unique
dans `RH_USER`. L'accepter ferait entrer un homonyme sur le compte d'un autre — c'est testé
explicitement, avec deux comptes « Kone », pour que personne ne l'ajoute plus tard par
inadvertance.

Tests `RhUserAuthTest` (16 cas) : connexion par nom d'utilisateur et par email dans toutes
les casses et avec des espaces de bord, refus du nom de famille, et l'établissement retrouvé
aussi par le nom d'utilisateur.
Suite : **146 tests, 657 assertions verts**. Build et lint propres, page de connexion vérifiée
au rendu à blanc.

## Console : deux sortes d'administrateur

**Super Admin** — la console générale (sociétés, rôles) *et* la console de chaque société.
**Admin Société** — la console de sa seule société : ses établissements, ses utilisateurs,
leurs affectations. La console générale lui est fermée.

Le rôle et la société se lisent dans `console_affectations` + `console_roles`, tables
propres à NEXORA et réglables depuis `/admin/utilisateurs` : aucune écriture dans les
tables partagées. Une affectation ne compte que si elle est active et non échue, pour
qu'un accès retiré ou daté cesse de lui-même.

Trois choix laissés à mon appréciation, notés ici pour qu'on puisse en changer en
connaissance de cause : (1) la source du rôle est `console_affectations` plutôt que
`role_user`/`societe_utilisateur` de dbmasterbacou ; (2) la console générale se limite aux
sociétés et aux rôles ; (3) la société courante vit dans la session, avec un sélecteur dans
l'en-tête, comme l'année scolaire — plutôt qu'une URL par société.

**Fail closed partout.** Un compte sans affectation d'Admin Société ne voit rien, jamais
« périmètre vide, donc tout montrer ». Un utilisateur hors périmètre répond 404 et non 403 :
son existence même n'a pas à être confirmée. Un Admin Société ne peut pas déplacer un
établissement vers une autre société — ce serait agir dessus tout en le perdant de vue — ni
créer un compte sans l'affecter chez lui, ce compte lui serait invisible ; l'établissement
et le rôle sont donc exigés à la création, et l'affectation est posée dans le même geste.

À la connexion, la société **et** l'établissement de rattachement s'affichent avant le mot
de passe, sur les deux faces de la page. L'endpoint reste public et avare : uniquement des
libellés de rattachement, jamais le nom du titulaire ni son rôle, la même réponse vide pour
un identifiant inconnu, désactivé ou non rattaché, et un débit limité contre l'énumération.
Un Super Admin s'y annonce « Toutes les sociétés » plutôt qu'avec un champ vide, qui se
lirait comme un compte mal configuré.

Côté écran : le menu de la console masque Sociétés et Rôles à un Admin Société plutôt que
de le laisser cliquer vers un 403, l'en-tête annonce sa qualité réelle (« Super Admin » ou
« Admin Société ») au lieu du « Super Admin » qui était écrit en dur, et le sélecteur de
société devient un libellé cadenassé quand il n'y a rien à choisir. Ces indicateurs ne sont
qu'un affichage : l'autorisation est décidée par le serveur à chaque appel.

**Au passage** — encore un vestige d'avant le pivot ECONOMAT : toute une couche de périmètre
(`App\Models\User`, `App\Models\Affectation`, `BelongsToPerimetre`, `StoreEtablissementRequest`,
`StoreAffectationRequest`, `UpdateEtablissementRequest`) visait des tables
`societes`/`etablissements`/`affectations` à clés numériques qui n'existent plus, et n'était
appelée par aucune route. Elle est remplacée par `App\Support\PerimetreConsole` et
`ConsoleMiddleware`, alignés sur les tables réelles `console_*` à clés textuelles.

Tests `PerimetreConsoleTest` (18 cas) : qui est quoi, une affectation sans le rôle ou échue
ne donne pas la console, la console générale fermée à l'Admin Société, cloisonnement des
établissements et des utilisateurs, refus de déplacer ou d'affecter hors périmètre, bascule
de société du Super Admin, et la société annoncée sur la page de connexion. Les cas de
cloisonnement ont été vérifiés par mutation : en retirant le filtre, puis en ouvrant le
middleware, les tests concernés tombent bien.
Suite : **165 tests, 730 assertions verts**. Build et lint propres ; page de connexion et
console rendues à blanc pour les deux profils.

### L'interface de console de l'Admin Société

Avoir le droit d'entrer ne suffisait pas : sa console était en partie inutilisable, pour
trois raisons dont une bloquante.

1. **Son accueil restait vide.** La page appelait `/societes`, `/etablissements` et
   `/utilisateurs` ; la première est réservée au Super Admin et répondait 403. Remplacé par
   un seul appel `console/tableau-de-bord`, borné au périmètre. Le nombre de sociétés n'est
   renvoyé qu'au Super Admin en vue générale ; l'Admin Société voit à la place ses
   affectations, et le titre nomme sa société (« Console — Groupe ABN ») au lieu du
   générique « Console Administrative ».
2. **Il ne pouvait pas créer d'établissement** : la liste déroulante des sociétés du
   formulaire venait de `/societes`, interdite — donc vide, donc le bouton restait
   désactivé. Elle vient maintenant du contexte de la console, qui ne propose que les
   sociétés autorisées.
3. **Il ne pouvait affecter personne** : la fiche utilisateur a besoin du catalogue de
   rôles, et `/roles` était entièrement fermée. La **lecture** du catalogue est désormais
   ouverte à la console société — il faut bien nommer les rôles qu'on affecte — tandis que
   sa **modification** reste à la console générale : ce catalogue est commun à toutes les
   sociétés.

Les pages Sociétés et Rôles sont en plus gardées côté écran (`exigeSuperAdmin`), qui renvoie
l'Admin Société à son accueil de console au lieu de lui afficher une page vouée au 403 —
le menu les masquait déjà, mais l'URL restait atteignable à la main.

**Encore un vestige** : `JournalActivitePage` interrogeait `/journal-activite`, une route
qui n'existe pas et un modèle pointant une table disparue ; la page n'était routée nulle
part. Retirée dans `features/_retires/`, comme les précédentes.

Tests : `PerimetreConsoleTest` passe à 21 cas (accueil borné à la société, accueil général
qui suit la société choisie, catalogue de rôles lisible mais non modifiable).
Suite : **168 tests, 750 assertions verts**. Build et lint propres ; accueil, établissements
et fiche utilisateur rendus à blanc pour les deux profils.

## Affectation enseignant – classe

Nouvelle page `/affectations-enseignants`, adossée à `ECONOMAT.dbo.T_CORPROFCLASSE` :
qui enseigne quelle matière, dans quelle classe, pour l'année de travail. Deux lectures,
parce que ce sont les deux questions qu'on se pose : la grille d'une classe (son équipe
pédagogique) et le service d'un enseignant (où il intervient).

**Cette page est en amont de l'emploi du temps**, et c'est ce qui dicte ses règles :
le planning ne stocke pas l'enseignant, il le *déduit* de cette table, et c'est encore elle
qui sert à refuser qu'un professeur soit dans deux classes à la même heure.

- Une matière n'a **qu'un** enseignant par classe et par année : réaffecter, c'est
  remplacer. Une seconde affectation est refusée en nommant celui qui occupe déjà la place
  — sans cela l'emploi du temps ne saurait pas quel professeur déduire.
- **Un seul titulaire par classe** (colonne `Principale`) : désigner un nouveau titulaire
  retire la marque au précédent, dans la même opération.
- Le **retrait est une suppression réelle** — deuxième exception assumée à la règle
  « jamais de DELETE », après `T_EMPLOIDUTEMPS`, pour la même raison : c'est de
  l'organisation qu'on réajuste, pas un historique. Mais il est **refusé si des créneaux
  d'emploi du temps ou des notes en dépendent**, avec le décompte exact dans le message,
  et la ligne l'annonce *avant* le clic (colonne « Dépendances », bouton désactivé). Le
  geste correct quand un professeur change en cours d'année est de **remplacer**
  l'enseignant : les créneaux suivent, puisqu'ils le déduisent d'ici.
- Année clôturée : consultation seule, comme partout. Le verrou porte sur l'année de la
  ligne, pas sur celle qu'on regarde.
- Seules les colonnes métier sont écrites (`CodeClasse`, `CodeMatiere`, `CodeProfesseur`,
  `ANNEE`, `Principale`) ; `LOGIN`, qui trace la saisie côté ECONOMAT, est laissée intacte.
- Une classe, une matière ou un enseignant inconnu d'ECONOMAT est refusé.

Modifier une affectation invalide aussi le cache de l'emploi du temps : sa grille afficherait
sinon l'ancien enseignant.

**Encore un vestige, remplacé plutôt que réanimé** : la route `classes/{classe}/intervenants`
existait, mais `ClasseMatiereEnseignant` visait une table `classe_matiere_enseignant`
inexistante et `Classe` n'avait même pas la relation — l'appel plantait en 500. Route,
contrôleur et modèle retirés dans `app/_retires/`.

Tests `AffectationEnseignantTest` (15 cas) : référentiels bornés à l'année, affectation et
double lecture (par classe, par enseignant), cloisonnement par année, unicité matière+classe
avec le nom du titulaire actuel dans le message, un même enseignant sur plusieurs matières et
classes, références inconnues refusées, remplacement, titulaire unique par classe sans
toucher aux autres classes, retrait effectif, retrait refusé sur créneaux puis sur notes,
remplacement toujours possible malgré des créneaux, année clôturée. Les trois règles
sensibles (refus de retrait, exclusivité du titulaire) ont été vérifiées par mutation.
Suite : **183 tests, 826 assertions verts**. Build et lint propres, page rendue à blanc.

Le groupe « 🔗 Affectation » du menu a été retiré : sa seule entrée renvoyait vers
`/classes`, déjà listée sous Paramètre, et l'affectation enseignant-classe a désormais sa
vraie page sous Programme.

## Paramètres : création, modification, suppression

Les pages de Paramètres étaient en **consultation seule** — et pire, leurs fichiers `*Api.js`
exposaient déjà `create`/`update`/`delete` vers des routes qui n'existaient pas : du code
mort, et deux formulaires (`/matieres/nouvelle`, `/matieres/:id/modifier`) accessibles par
URL mais voués au 404. Tout est branché.

| Page | Table | Créer | Modifier | Supprimer |
|---|---|---|---|---|
| Années scolaires | `T_ANNEEACADEMIQUE` | oui | oui | si rien n'y est rattaché |
| Cycles | `T_CYCLE` | oui | oui | si aucun niveau ni matière |
| Niveaux | `T_NIVEAU` | oui | oui | si aucune classe ni document |
| Classes | `T_CLASSE` | oui | oui | si aucun élève, créneau, note, absence, affectation |
| Matières | `T_MATIERE` | oui | oui | si aucune affectation, créneau ni note |
| Documents élèves | `T_PREREQUIS` | déjà | déjà | si aucun élève ne l'a au dossier |

**La règle de suppression, généralisée.** Vous avez tranché pour les affectations
enseignant-classe : « suppression seulement si rien n'en dépend ». C'est la même ici, et
c'est nécessaire — les référentiels d'ECONOMAT ne portent **aucune clé étrangère**. Rien
n'empêcherait techniquement de supprimer une classe de trente élèves : la base ne dirait
rien, et les dossiers pointeraient vers un code inexistant. Chaque refus (409) nomme ce qui
bloque et combien : « 12 élèves y sont rattachés ». Le message s'affiche dans un bandeau, à
la place de l'`alert()` du navigateur qui tronquait et bloquait la page.

Quelques décisions à connaître :
- **Une année clôturée reste modifiable.** Le verrou de clôture protège les données DANS une
  année, pas sa définition : s'il s'appliquait aussi à la fiche, une clôture faite par erreur
  serait irréversible. Rouvrir une année est donc possible, et c'est voulu.
- **Activer une année désactive les autres** : sans cela le contexte de travail ne saurait
  laquelle prendre par défaut.
- **Codes uniques dans l'année** pour les classes et les niveaux, pas au global : le même
  CP1 se recrée chaque année.
- **Cycles et matières ignorent la clôture** : ils traversent les années scolaires.
- Seules les colonnes métier sont écrites. `CODESOCIETE`, `CODEETABLISSEMENT`, `CodeEtab` et
  tout ce que les autres applications de la suite déposent dans ces tables restent intacts.

**Un piège Laravel rencontré en route**, qui vaut d'être noté : `Matiere::getAttribute('Code')`
renvoyait `CodeMatiere` — donc « MATH » au lieu de `1`, et un 404 à chaque écriture. Laravel
dérive le même nom d'accesseur (`getCodeAttribute`) pour la colonne `Code` et pour l'alias
`code` que ces modèles exposent. Tous les accès aux colonnes réelles passent désormais par
`getRawOriginal()`, qui contourne les accesseurs. Le cas se reproduira sur tout modèle
mêlant colonnes réelles et alias différant seulement par la casse.

Tests `ReferentielsCrudTest` (21 cas) : les cinq référentiels créés / modifiés / supprimés,
unicité des codes, dépendances refusant chaque suppression avec le bon libellé, année
clôturée rouvrable, activation exclusive, codes réutilisables d'une année sur l'autre,
verrou de clôture sur classes et niveaux mais pas sur les matières, retrait d'un document
et refus quand un élève l'a au dossier.
Suite : **204 tests, 910 assertions verts**. Build et lint propres, les sept écrans touchés
rendus à blanc.

## Saisie des absences

C'était le manque le plus sérieux relevé par l'audit : un suivi quotidien entièrement en
lecture seule, avec un formulaire orphelin (`AbsenceFormPage`) et un `absencesApi.js`
pointant des routes inexistantes.

La saisie **part de l'effectif d'une classe** : on choisit l'élève dans la liste, jamais en
tapant un matricule — c'est ce qui évite d'inscrire une absence au mauvais dossier. La
classe et l'année ne se saisissent pas : elles sont déduites de l'élève et de l'année de
travail, sinon les relevés par classe deviendraient faux.

Règles posées : l'élève doit être inscrit **pour l'année de travail** ; pas deux absences
pour le même élève le même jour à la même heure (c'est une double saisie, pas deux
absences) ; pas d'absence dans le futur ; année clôturée en consultation seule.

**Suppression réelle — troisième exception assumée** après `T_EMPLOIDUTEMPS` et
`T_CORPROFCLASSE` : une absence saisie par erreur (mauvais élève, mauvais jour) n'a aucune
autre voie de correction, et rien ne dépend d'une ligne d'absence en aval. Elle reste
bornée par le verrou de clôture.

Tests `AbsenceSaisieTest` (10 cas) : saisie avec classe et année déduites, élève non
inscrit refusé, doublon jour+heure refusé mais autre heure acceptée, date future refusée,
correction et justification, retrait, cloisonnement par année, verrou de clôture, filtres
classe et jour, effectif d'une classe exposé pour la saisie. La déduction de la classe a
été vérifiée par mutation.
Suite : **214 tests, 952 assertions verts**. Build et lint propres, écran rendu à blanc.

### Ce qui reste sans écriture, et pourquoi

- **Saisie des notes** — le menu promet une saisie, la page ne fait que consulter
  `V_NOTECLASSE`, **qui est une vue** : on ne peut pas y écrire. Les vraies tables sont
  `T_NOTEENTETE` / `T_NOTEDETAILS`, dont je n'ai pas les colonnes. À faire, mais il me faut
  d'abord un `SELECT TOP 5 *` de ces deux tables — je ne veux pas inventer la structure
  d'une table de notes.
- **Élèves** — pas de création directe, et c'est volontaire : la fiche élève s'écrit par
  Inscriptions, qui porte les règles métier (une inscription par an, doublons d'identité,
  cohérence classe/niveau). Deux portes d'écriture sur `T_ETUDIANT` seraient un risque, pas
  un service. `EleveFormPage` et `elevesApi.js` restent du code mort à retirer.
- **Évaluations, Résultats & bulletins, Assiduité** — ce sont des restitutions d'agrégats
  calculés par ECONOMAT (`V_MOYENNE_ELEVE_CLASSE`, vues d'absences). Rien à y écrire.

Sur demande, la page des niveaux ne montre plus ni **Cycle** ni **Ordre** : colonnes du
tableau, champs du formulaire et panneau « Cycles » retirés, et l'entrée de menu devient
simplement « Niveaux ». Les colonnes `CodeCycle` et `Ordre` existent toujours dans
`T_NIVEAU` et leurs valeurs sont préservées — la page ne les affiche plus et ne les écrit
plus. Les routes `/cycles` restent en place côté serveur (elles sont testées) : seul
l'écran disparaît, `CyclesPanel` rejoint `features/_retires/`.

## Traçabilité des utilisateurs

Nouvelle page `/admin/tracabilite` dans la console, adossée à `ECONOMAT.dbo.T_TRACABILITE`,
en **lecture seule** : NEXORA restitue ce qu'ECONOMAT y a écrit, il n'y trace rien. Une
seconde entrée, `/admin/utilisateurs/:id/tracabilite`, ouvre la traçabilité d'un compte
depuis sa fiche.

**La structure de cette table n'a pas été relevée sur votre base, et je ne l'ai pas
inventée.** La lecture découvre les colonnes réelles à l'exécution
(`Schema::getColumnListing`) et les rapproche de rôles métier — quand, qui, quoi, sur quoi,
détail, où — par motifs de noms. Conséquences : une colonne absente n'apparaît pas, les
filtres proposés à l'écran sont ceux que la table permet réellement, et ce qu'on ne sait
pas nommer reste visible sous « autres » plutôt que d'être perdu. L'écran expose la
correspondance retenue, pour qu'un rapprochement faux se voie.

**Le cloisonnement est le point délicat**, puisque rien ne garantit que cette table porte
une colonne société. Trois stratégies, de la plus fiable à la plus indirecte :

1. une colonne société → filtre direct ;
2. une colonne établissement → restreinte aux établissements de la société ;
3. une colonne utilisateur → restreinte aux identifiants (login, matricule, email) des
   comptes affectés dans la société — l'écran annonce alors que l'activité enregistrée sous
   un identifiant inconnu de la console n'y apparaît pas.

**Si aucune ne s'applique, l'Admin Société ne voit RIEN**, et la réponse dit pourquoi.
Jamais « on ne sait pas cloisonner, donc on montre tout ». Le Super Admin voit toute la
table en vue générale, et la société choisie sinon. La traçabilité d'un compte hors
périmètre répond 404, pas 403 : son existence n'a pas à être confirmée.

Tests `TracabiliteTest` (15 cas) : reconnaissance des colonnes sur **deux nommages
différents** — précisément pour ne pas dépendre de ce qu'on avait imaginé —, table absente
sans casser la page, colonnes non reconnues conservées, filtres utilisateur / action /
période, les trois stratégies de cloisonnement, le fail closed quand aucune ne s'applique,
la bascule de société du Super Admin, la traçabilité d'un compte et son refus hors
périmètre, l'absence d'accès pour un compte sans droit console, et la lecture seule. Le
cloisonnement par société et le fail closed ont été vérifiés par mutation.
Suite : **229 tests, 1007 assertions verts**. Build et lint propres.

**Limite de validation à connaître** : mon rendu à blanc hors navigateur ne peut pas
exercer les branches qui dépendent du store zustand — en rendu serveur, zustand sert l'état
*initial*, donc un `superAdmin` posé avant le rendu n'est pas lu par le sélecteur. Le
masquage des entrées de menu réservées à la console générale n'est donc vérifié que par le
serveur (403) et par `ProtectedRoute`, pas par ce harnais.

## Nettoyage de l'avant-pivot, et un risque écarté

En reprenant le registre d'audit, j'ai trouvé pire que ce qu'il annonçait. Le fichier `.env`
porte **`DB_CONNECTION=economat`** : la connexion **par défaut** de l'application est votre
base de production partagée. Deux conséquences, l'une constatée, l'autre évitée de peu.

**Constatée.** Tout modèle sans `$connection` explicite interroge ECONOMAT. Dix-huit
modèles étaient dans ce cas, visant des tables du schéma d'avant le pivot (`programmes`,
`seances`, `parents`, `conseils_classe`, `periodes`, `sanctions`…) qui n'ont jamais été
créées là. Vérifié par sonde sur les endpoints réels : **dix répondent 500** —
`programmes`, `ressources`, `seances`, `parents`, `conseils-classe`, `periodes`,
`coefficients`, `sanctions`, `annonces`, `messages`. Les cinq pages que l'audit classait
« complètes mais non testées » ne fonctionnent pas du tout.

**Évitée.** Les 36 migrations d'avant le pivot créaient tout ce schéma local. Un
`php artisan migrate` sans `--database` les aurait créées **dans ECONOMAT**, au milieu des
tables de la suite. Elles sont déplacées dans `database/migrations/_retires/` avec un
LISEZ-MOI qui explique pourquoi : la commande ne trouve plus rien à créer. Seules restent
actives les migrations de `console/`, qui se lancent explicitement.

Retirés dans `app/_retires/` : cinq contrôleurs sans page (Messages, Annonces, Documents,
Documents établissement, Journal d'activité — ce dernier n'avait même pas de route), onze
modèles orphelins dont la chaîne de périmètre morte (`User`, `Affectation`, `Societe`,
`Etablissement`, `BelongsToPerimetre`, cinq `FormRequest` jamais type-hintées),
`ActivityLogger` appelé nulle part, et les modèles `Note` et `Inscription` que rien ne
référençait. Les routes correspondantes ont disparu de `api.php`.

Côté écran, dans `features/_retires/` : six formulaires jamais routés
(`ClasseFormPage`, `ClasseIntervenantsPanel`, `EleveFormPage`, `EleveParentsPanel`,
`NoteFormPage`, `AbsenceFormPage`), et les fonctions d'API qui appelaient des routes
inexistantes (`fetchIntervenants`, `archiverClasse`, `deleteSociete`,
`deleteEtablissement`, `fetchJournalActivite`, `proposerReinscriptions`).

**Contrôle ajouté à la validation** : un script compare désormais tous les
`apiClient.<verbe>('…')` du front aux routes déclarées dans `api.php`. Résultat après
nettoyage : aucun appel orphelin. La suite reste à **229 tests, 1007 assertions verts** —
22 fichiers et 36 migrations retirés sans qu'un seul test bouge, ce qui confirme qu'ils
étaient bien morts.

## Parents / Tuteurs : un annuaire, pas une table

Premier des sept endpoints morts remis en service — et pas de la façon prévue. En cherchant
comment reconstruire la table `parents`, j'ai constaté que **la donnée existe déjà** :
`NomPereTuteur`, `TelephoneMere`, `ProfessionPereTuteur`… sont des colonnes de `T_ETUDIANT`,
saisies par les étapes « Père / Tuteur » et « Mère » du formulaire d'inscription.

Une table de parents aurait donc dupliqué ces colonnes, avec la synchronisation à tenir —
pour un résultat moins fiable que la source. La page devient un **annuaire dérivé** : une
ligne par parent, ses coordonnées, ses enfants inscrits, filtrable par classe et par lien,
recherchable par nom, téléphone ou email. En **lecture seule** : on corrige une coordonnée
là où elle vit, dans Inscriptions. C'est la règle déjà retenue pour la page Élèves — une
seule porte d'écriture sur le dossier élève.

**Le regroupement, et ce qu'il refuse de faire.** La clé est nom + prénom + téléphone. Deux
parents homonymes sans téléphone restent donc **deux lignes** : on préfère scinder à tort
que fusionner deux familles. Inversement, deux enfants d'un même parent donnent une seule
ligne, et une coordonnée absente d'une fiche est reprise de l'autre. Un même adulte père
d'un élève et tuteur d'un autre garde les deux libellés.

Retirés au passage : `ParentFormPage` et `ParentElevesPanel`, qui écrivaient dans la table
disparue, ainsi que les routes `POST/PUT/DELETE parents` et l'attachement d'élève — ce
dernier validait `exists:eleves,id` contre une table locale vide, donc refusait toujours.

Tests `ParentsAnnuaireTest` (7 cas) : fusion des fratries, refus de fusionner deux
homonymes sans téléphone, père et mère tous deux listés, pas de ligne vide quand le parent
n'est pas renseigné, portée par année et filtre par classe, recherche sur les trois champs,
lecture seule. Le regroupement a été vérifié par mutation.
Suite : **236 tests, 1027 assertions verts**. Build et lint propres, écran rendu à blanc,
et aucun appel du front sans route serveur.

## Retrait des pages d'avant le pivot

Décision prise après constat : sur les sept endpoints qui répondaient 500, **un seul est
conservé au programme** — le Cahier de textes — et les autres sont retirés.

Retirés : **Cours** (programmes), **Ressources pédagogiques**, **Conseils &
délibérations**, **Périodes** et **Coefficients**, plus **Sanctions** dont les écrans
n'avaient jamais été routés. Tous reposaient sur le schéma local d'avant le pivot, avec des
clés étrangères vers des tables (`niveaux`, `classes`, `eleves`, `periodes`) qui n'existent
plus. Les réparer aurait voulu dire reconstruire cinq tables et leurs formulaires ; le menu
ne promet plus ce qui ne répond pas.

Ce que ça retire, en volume : 8 contrôleurs, 9 modèles, **34 FormRequest** (aucune n'était
plus type-hintée par un contrôleur vivant), 14 fichiers React et 5 entrées de menu — tout
dans `_retires/`, rien d'effacé. Deux d'entre elles avaient un équivalent ECONOMAT déjà
identifié dans le mapping (`T_SESSION` pour les périodes, `T_CORMATNIVEAUCOEFF` pour les
coefficients) : elles pourront revenir sur cette base, avec la structure réelle sous les
yeux.

**Cahier de textes reste à faire**, sur `ECONOMAT.T_ENTETE_JOURNAL` / `T_CAHIER_JOURNAL`.
Je ne l'ai pas reconstruit à l'aveugle : contrairement à la traçabilité, il faut y **écrire**
(le titulaire consigne ce qui a été enseigné), et une liste blanche de colonnes suppose de
connaître les colonnes. Deux `SELECT TOP 5 *` suffisent à le débloquer.

**Trois contrôles ajoutés à la validation**, en plus des tests : les appels
`apiClient.<verbe>()` du front comparés aux routes déclarées, les entrées de menu comparées
aux routes React, et les pages routées vérifiées présentes sur le disque. Les trois sont à
zéro. Le code retiré est exclu du lint (`ignorePatterns` sur `**/_retires/**`), qui ne
laisse plus qu'un avertissement connu sur `ErrorBoundary`.
Suite : **236 tests, 1027 assertions verts** — 65 fichiers retirés sans qu'un test bouge.

## Bulletin PDF : dernier point du registre couvert

`GET eleves/{eleve}/bulletin` n'avait aucun test — le seul document imprimable qui n'en
avait pas. `BulletinTest` (7 cas) couvre ce qui est réellement de notre ressort : NEXORA
ne calcule rien, il restitue les moyennes, coefficients et rangs d'ECONOMAT. Les tests
portent donc sur la restitution, pas sur l'arithmétique.

Vérifiés : le PDF sort ; le rang, la moyenne et l'effectif viennent bien des vues ECONOMAT ;
**les sessions ne se mélangent pas** (une note de S2 ne pèse pas sur la moyenne de S1) ;
les coefficients sont restitués tels quels ; un élève sans classe est refusé en 422 ; un
élève sans note donne un bulletin vide plutôt qu'une erreur ; et le bulletin suit l'année
de travail. Le cloisonnement par session a été vérifié par mutation.

Deux erreurs de ma part corrigées en route, toutes deux dans le test et non dans le code :
le piège SQLite des colonnes hétérogènes dans un insert groupé, déjà rencontré en début de
projet, et la clé `matiere_code` que j'avais écrite `code`.
Suite : **243 tests, 1066 assertions verts**.

## Rôles & permissions : un troisième niveau d'admin, et gérables sans passer par le Super Admin

Jusqu'ici la console n'avait que deux niveaux (Super Admin / Admin Société), et le
catalogue de rôles (`console_roles`) ne se modifiait que depuis la console générale — donc
en pratique par personne d'autre que le Super Admin, malgré une API `console:societe` déjà
prête à en laisser lire le contenu. Décision : les rôles et leur affectation doivent se
gérer **dans l'application**, par l'Admin Société ou l'Admin Établissement, pas seulement
par le Super Admin.

**`console_roles` gagne un `societe_code` nullable** : NULL = catalogue général (Super
Admin, vue générale), renseigné = rôle propre à une société, que son Admin Société crée et
supprime lui-même (`RoleController`). La lecture suit `PerimetreConsole::codeCourant()` :
vue générale = tout le catalogue, société choisie = le général plus le propre à cette
société — même logique que partout ailleurs dans la console, aucune nouvelle branche.

**Troisième niveau : Admin Établissement**, un cran sous l'Admin Société — borné à un ou
plusieurs établissements précis (`console_affectations` avec le rôle `admin-etablissement`),
pas à toute une société. `RhUser::etablissementsAdministres()` / `estAdminEtablissement()`
/ `peutAdministrerEtablissement()` complètent le trio déjà en place pour la société.
`PerimetreConsole::codeCourant()` sait maintenant déduire la société courante depuis les
établissements administrés, pour qu'un Admin Établissement profite des mêmes filtres que le
reste de la console sans code dupliqué. `ConsoleMiddleware` gagne le niveau `etablissement`
(le socle commun aux trois : lire les rôles, lister ses utilisateurs, poser/retirer une
affectation), gardé sous `console:societe` pour tout ce qui reste du ressort de l'Admin
Société (établissements en écriture, comptes, traçabilité, catalogue).

Un Admin Établissement ne peut ni créer de compte ni conférer le rôle Admin Société à
quelqu'un — il assigne un rôle existant à un utilisateur déjà connu, dans son propre
établissement, et rien de plus (`AffectationController`, garde-fou explicite sur le rôle
Admin Société).

Côté écran : `/admin/roles` s'ouvre à l'Admin Société (`exigeNiveauSociete` remplace
`exigeSuperAdmin`), `/admin/etablissements` et `/admin/tracabilite` restent au niveau
société, et `UtilisateurListPage` masque création/édition/désactivation de compte pour qui
n'a que le niveau établissement — l'affectation de rôle se fait depuis la fiche utilisateur
(`UtilisateurDetailPage`, déjà là, inchangée).

`PerimetreConsoleTest` : 2 tests réécrits (l'ancien interdit à l'Admin Société de créer un
rôle — exactement l'inverse de la décision prise), 6 tests ajoutés pour l'Admin
Établissement (périmètre, lecture cloisonnée, affectation dans son seul établissement,
refus de conférer Admin Société).
Suite : **249 tests** (247 verts, 1093 assertions ; 2 échecs préexistants et sans rapport,
upload de photo, confirmés par `git stash`).

## Sociétés : la création ne fait plus qu'à partir de US_SOCIETE

`/admin/societes` fusionnait déjà toutes les sociétés de US_SOCIETE (reprises ou non), mais
le formulaire « + Nouvelle société » laissait taper un code inédit — auquel cas ECOPRIM
l'INSERTait dans US_SOCIETE, table partagée avec les autres applications. Décision : US_SOCIETE
reste la seule source, gérée ailleurs ; ECOPRIM n'en écrit jamais de nouvelle ligne, il ne
fait que **reprendre** (surcouche `console_societes`) une société qui y existe déjà.

`UsSocieteCreateur` (le service qui faisait cet INSERT, avec sa gestion du NUMAUTO
IDENTITY-ou-compteur-manuel) est retiré : plus aucun appelant. `SocieteController::store()`
exige désormais que `code` existe dans `master.US_SOCIETE` (`Rule::exists`), et ne fait plus
que `Societe::create()` — la branche « créer aussi dans US_SOCIETE » a disparu avec sa
distinction `cree_dans_us_societe`. Les champs `pays` et `nombase` disparaissent du
formulaire : ils n'ont jamais été persistés que pour cette INSERT désormais impossible.

Écran : le champ Code devient un menu déroulant des sociétés de US_SOCIETE pas encore
reprises (nom, ville, adresse… préremplis au choix) — impossible d'inventer un code. Le
bouton « Reprendre » par ligne, déjà là, couvre le même besoin depuis la liste.

`ConsoleCrudTest` : le test qui vérifiait la création dans US_SOCIETE est remplacé par son
inverse (code absent de US_SOCIETE → refusé), le test du calcul de NUMAUTO disparaît avec le
code qu'il couvrait, `test_code_societe_unique` insère désormais la ligne US_SOCIETE pour
tester la seule règle qu'il prétend tester.
Suite : **248 tests** (246 verts ; les 2 mêmes échecs préexistants, sans rapport).

## Sociétés : plus de colonne Source, et la liste exclut les orphelines ECOPRIM

Deux demandes liées à l'écran `/admin/societes` : retirer la colonne « Source » (devenue
sans intérêt une fois la création restreinte à US_SOCIETE), et ne plus lister que les
sociétés qui existent réellement dans `US_SOCIETE` — jamais une ligne qui ne vivrait que
dans la surcouche `console_societes` (résidu possible d'avant la restriction du commit
précédent, ou base de test mal nettoyée). `SocieteController::index()` ne boucle plus que
sur `US_SOCIETE` ; le champ `source` disparaît de la réponse, désormais inutile hors de ce
tri déjà fait côté serveur.
Test ajouté : une société ECOPRIM sans ligne US_SOCIETE n'apparaît plus dans `GET /societes`.
Suite : **248 tests, 1089 assertions** (246 verts ; mêmes 2 échecs préexistants).

## Cahier de textes : dernière page manquante du programme

Seul écran resté « à faire » depuis le retrait des pages d'avant le pivot (§ précédentes) :
il fallait d'abord voir les vraies colonnes de `T_ENTETE_JOURNAL` et `T_CAHIER_JOURNAL`, plutôt
que de les inventer. Un `SELECT TOP 5` sur les deux tables (schéma déjà introspecté) a montré
la structure réelle : une semaine par classe (`T_ENTETE_JOURNAL`), une ligne par matière avec
ce qui a été vu chaque jour (`T_CAHIER_JOURNAL` : `Lundi`…`Vendredi`, `Matiere`, liée par
`CodeEntete`).

- **Une ligne par matière affectée à la classe**, jamais une saisie libre : la liste vient de
  `T_CORPROFCLASSE` (comme pour l'emploi du temps et l'affectation enseignant-classe), donc on
  ne consigne pas une matière qui n'est pas enseignée là. Une ligne déjà écrite reste
  modifiable même si l'affectation est retirée après coup — la donnée passée n'est jamais
  invalidée par un changement d'organisation ultérieur.
- **Enregistrer une ligne est un upsert** : une seule ligne par (semaine, matière), on ne
  duplique jamais en resaisissant.
- **`NumSem`** (rang de la semaine dans le mois, pour la classe) est **calculé**, pas saisi :
  compté à partir des cahiers déjà enregistrés ce mois-là pour cette classe. Un doublon
  classe + mois + semaine est refusé, en le nommant.
- **QUATRIÈME exception assumée** à la règle « jamais de DELETE », après les emplois du temps,
  l'affectation enseignant-classe et les absences : rien ne dépend en aval d'une semaine ou
  d'une matière du cahier de textes, et une saisie mal placée n'a pas d'autre voie de
  correction. Supprimer une semaine efface aussi ses lignes. Le verrou porte sur l'année de la
  semaine elle-même, pas sur l'année actuellement affichée dans l'en-tête — même principe que
  partout ailleurs.
- Champ **Prof** de l'entête laissé simple (liste des enseignants affectés à la classe,
  optionnel) : aucun usage historique dans les données réelles (`Prof` toujours vide), et rien
  n'associe aujourd'hui un compte RH_USER à une fiche `T_PROFESSEUR` pour le déduire de la
  connexion — noté ici au cas où ce rapprochement deviendrait possible plus tard.
- Front : classe → semaine (menu déroulant des cahiers déjà créés, plus récente par défaut) →
  grille matière × jours, une cellule texte par jour (50 caractères, comme la colonne),
  bouton Enregistrer par ligne (n'apparaît actif que si la ligne a changé) et retrait par ligne.
  Ajouté sous 📚 Programme, à la suite d'Emplois du temps.
- Pas d'impression ajoutée cette fois (les quatre PDF existants n'ont pas été touchés) : à
  faire si le besoin se confirme, sur le même patron que les autres documents.

Tests `CahierTextesTest` (13 cas) : référentiels bornés à la classe (matières affectées
seulement), création avec niveau déduit et `NumSem` calculé, doublon classe/mois/semaine,
upsert d'une ligne (pas de duplication), matière non affectée refusée à la création d'une
ligne, ligne déjà écrite modifiable après retrait de l'affectation, retrait réel d'une ligne,
suppression d'une semaine et de ses lignes, modification de l'entête, verrou de clôture sur la
création et sur les lignes d'une semaine déjà clôturée, cloisonnement par année.
Suite : **262 tests, 1143 assertions** (260 verts ; mêmes 2 échecs préexistants et sans rapport,
upload de photo). Build et lint frontend propres.

## Portails restreints Enseignant et Parent

Demande de l'utilisateur : les parents et les enseignants doivent avoir leur propre
interface, avec des comptes créés par l'administrateur depuis la console — et ces comptes
doivent être des RH_USER, pas un système à part. Deux choix ont été confirmés avec
l'utilisateur avant de coder (aucun champ n'existe aujourd'hui pour rattacher un compte
Parent à un élève, et rien ne restreignait jusqu'ici l'API par rôle) :

1. le rattachement Parent → élève(s) se fait par **affectation explicite de
   l'administrateur** (pas de rapprochement automatique par email/téléphone, trop fragile
   et rien ne garantit l'unicité) ;
2. le cloisonnement est **réel côté serveur**, pas un simple habillage d'écran — sans quoi
   un compte Enseignant/Parent resterait techniquement capable d'appeler les routes du
   personnel.

### Le mécanisme retenu : réutiliser les rôles de la Console, pas en inventer un nouveau

La Console avait déjà tout ce qu'il fallait pour « Enseignant » : un catalogue de rôles
(`console_roles`) et des affectations utilisateur↔établissement↔rôle (`console_affectations`)
— seul « Parent » manquait au catalogue, ajouté dans `ConsoleImporter::semerRoles()`.
Aucune nouvelle notion de rôle : create/gérer un compte Parent ou Enseignant se fait
exactement comme créer un compte Secrétaire ou Direction, depuis `/admin/utilisateurs`.

- `RhUser::typePortail()` : **'staff'** (application complète, comportement historique) sauf
  si TOUS les rôles actifs de l'utilisateur sont EXACTEMENT `{enseignant}` ou `{parent}` —
  auquel cas il devient enseignant ou parent respectivement. Un compte sans aucune
  affectation (tous les comptes historiques du reste de la suite) reste **staff par défaut** :
  rien ne se restreint sans un geste explicite de l'administrateur, et aucun compte existant
  n'a pu perdre l'accès qu'il avait déjà.
- `PortailMiddleware` (`portail:staff`, `portail:enseignant`, `portail:parent`) applique ce
  verdict : les routes pédagogiques existantes (`eleves`, `classes`, `absences`,
  `cahier-textes`…) sont maintenant sous `portail:staff`, deux nouveaux groupes
  `mon-espace/enseignant/*` et `mon-espace/parent/*` sous les deux autres. La Console
  elle-même n'a rien eu à changer : un compte enseignant/parent n'a par construction aucun
  rôle admin, donc `peutAccederConsole()` le refuse déjà.

### Portail Enseignant — ses classes, rien d'autre

Les classes d'un enseignant se déduisent de `T_PROFESSEUR.LOGIN = RH_USER.Login` puis de
`T_CORPROFCLASSE` — exactement ce que fait déjà l'emploi du temps pour en déduire
l'enseignant d'un créneau. `PortailEnseignantController` ne duplique aucune règle métier :
il vérifie que la classe visée est la sienne, puis **délègue** aux contrôleurs existants
(`CahierTextesController`, `AbsenceController`, `EmploiDuTempsController::index`).

- Cahier de textes : lecture et écriture complètes, scopées à ses classes — c'est
  naturellement lui qui remplit ce qu'il a enseigné.
- Absences : saisie/correction/retrait sur ses classes. Point de vigilance traité : changer
  le matricule d'une absence existante pourrait viser un élève d'une AUTRE classe — revérifié
  explicitement sur la nouvelle valeur, sinon ce champ aurait été une sortie de périmètre.
- Emploi du temps et liste d'élèves : consultation seule (créer un créneau reste une tâche
  d'organisation, pas de saisie enseignant).
- Écran : accueil « Mes classes » (une carte par classe/matière), puis une page par classe à
  onglets (Cahier de textes / Absences / Élèves / Emploi du temps), sous `/mon-espace`.

### Portail Parent — ses enfants, rien d'autre

`console_affectation_eleves` (nouvelle table, migration `2026_10_03_000001`) : un ou
plusieurs matricules rattachés à une affectation du rôle Parent — une ligne par enfant,
pas une colonne sur `console_affectations` (dont la contrainte d'unicité utilisateur +
établissement + rôle resterait vraie pour un parent de plusieurs enfants dans le même
établissement). `AffectationController::store` accepte un tableau `eleves` (matricules
vérifiés dans `T_ETUDIANT`) quand le rôle est Parent ; `PUT affectations/{id}/eleves`
remplace la liste complète depuis la fiche utilisateur.

- Tout est en **lecture**. Point le plus sensible : les moyennes d'un enfant ne renvoient
  JAMAIS le classement de sa classe — `PortailParentController::moyennes()` extrait la seule
  ligne de cet enfant plus des agrégats (rang, effectif), contrairement à
  `RapportController::moyennesClasse` qui restitue tout le classement pour le personnel.
  Le bulletin PDF est réutilisé tel quel : il ne porte déjà que sur un seul élève.
- Le cahier de textes consulté est celui de la CLASSE de l'enfant (pas une donnée
  personnelle : normal qu'il montre toute la classe, comme un cahier de textes physique).
- Écran : accueil « Mes enfants » (une carte par enfant), puis une fiche à onglets (Fiche /
  Absences / Cahier de textes / Résultats avec téléchargement du bulletin), sous
  `/mon-espace`.
- Admin (`UtilisateurDetailPage`) : un sélecteur recherche un élève par nom ou matricule
  (`EleveController::index?q=`) pour composer la liste à la création d'une affectation
  Parent, et chaque affectation Parent déjà posée affiche ses enfants avec un « Gérer »
  pour la corriger après coup.

### Nettoyage de dérive trouvé en route

`php artisan migrate --path=database/migrations/console --database=ecoprim` échouait
(« societe_code déjà présent ») : la migration qui l'ajoute à `console_roles` n'était
jamais tracée alors que la colonne existait déjà en base — dérive de bookkeeping antérieure
à cette session, corrigée en insérant la ligne manquante dans `migrations` (aucune donnée
touchée). Le catalogue de rôles lui-même n'avait jamais été semé sur la vraie base : les 9
rôles (dont le nouveau Parent) y ont été créés au premier `console:importer --roles-seulement`.

Tests `PortailTest` (18 cas) : détection du type de portail (sans affectation, seul rôle
enseignant, seul rôle parent, rôle supplémentaire qui annule le portail restreint), portes
fermées (staff hors de l'app complète — sens interdit dans les deux sens entre les deux
portails), enseignant borné à ses classes (cahier de textes, absences, y compris le
changement de matricule vers une classe hors périmètre), parent borné à ses enfants
rattachés (fiche, absences, cahier de textes de la classe), non-fuite du classement dans les
moyennes d'un enfant, création d'une affectation Parent avec ses enfants en un seul appel.
Suite : **280 tests, 1176 assertions** (278 verts ; mêmes 2 échecs préexistants et sans
rapport, upload de photo). Build et lint frontend propres.

Reste ouvert, noté pour plus tard : pas de saisie de notes dans le portail Enseignant (la
saisie des notes elle-même reste en lecture seule pour tout le monde, cf. plus haut) ; un
compte cumulant Enseignant ET Parent tombe côté portail Enseignant plutôt que de proposer
les deux — cas non rencontré en pratique, à revoir s'il se présente.

## Correctif : le rôle ne se posait nulle part à la création d'un compte

Signalé par l'utilisateur : « je ne vois pas la page sur laquelle l'admin doit créer ces
comptes ». En regardant l'écran, le trou était réel — `UserController::store` accepte déjà
`etablissement_code` + `role_id` dans la MÊME requête que la création (obligatoire pour un
Admin Société, l'affectation le rend visible dans sa propre liste), mais le formulaire
« Nouvel utilisateur » (`UtilisateurListPage`) ne les proposait tout simplement pas : il ne
posait que l'identité et le champ `Etab` de RH_USER (un texte informatif, sans rapport avec
l'affectation Console). Seule la fiche utilisateur, une fois le compte déjà créé, permettait
d'ajouter un rôle — un détour peu visible, et carrément bloquant pour un Admin Société
puisque ces deux champs lui étaient obligatoires sans jamais lui être proposés : la création
d'un compte par quiconque n'est pas Super Admin échouait donc systématiquement en 422 avant
ce correctif, sans lien avec les portails de cette session.

- Le formulaire de création affiche désormais une section « Rôle (Enseignant, Parent,
  Secrétaire…) » — Établissement + Rôle, posés dans le même geste que le compte — suivie du
  sélecteur d'élèves (`SelecteurEleves`, extrait de la fiche utilisateur pour être partagé)
  quand le rôle choisi est Parent. Le bouton Enregistrer se désactive tant qu'un Admin
  Société/Établissement n'a pas rempli les deux, pour ne plus découvrir l'erreur après coup.
- `UserController::store` gagne le même traitement `eleves` qu'`AffectationController::store`
  (le rôle Parent seul en tient compte) : la création pose le compte, son affectation ET ses
  enfants en une seule requête.
- Cette section reste masquée en modification : corriger le rôle ou les enfants d'un compte
  déjà créé continue de se faire depuis sa fiche, où c'était déjà possible.

Test ajouté (`ConsoleCrudTest`) : créer un utilisateur avec établissement + rôle Parent +
élèves en un seul appel pose bien le compte RH_USER, son affectation et ses enfants.
Suite : **281 tests, 1180 assertions** (279 verts ; mêmes 2 échecs préexistants et sans
rapport). Build et lint frontend propres.

## Créer un compte Enseignant/Parent est le quotidien d'un établissement, pas de la société

Retour explicite de l'utilisateur après le correctif précédent : « je ne veux pas que la
console du Super Admin gère ça, c'est l'admin établissement ou société qui doit faire ça ».
Constat : `POST /utilisateurs` (création) était réservé à `console:societe` — Super Admin et
Admin Société seulement. Un **Admin Établissement ne pouvait pas créer de compte du tout**,
seulement affecter un rôle à un compte déjà créé par quelqu'un au-dessus de lui — une règle
posée volontairement plus tôt dans le projet, mais qui ne correspond pas à l'usage réel :
inscrire ses propres enseignants et parents est une tâche d'établissement, pas de société.

- `POST/PUT/activer/desactiver utilisateurs` déplacés de `console:societe` vers
  `console:etablissement` : les trois niveaux d'administrateur y ont maintenant accès. Le
  cloisonnement fin reste dans le contrôleur, pas dans le nom du groupe de routes.
- `UserController::store()` vérifiait l'autorisation avec `PerimetreConsole::assertAutorisee`
  (société uniquement) — remplacé par `assertAutoriseeEtablissement`, la même vérification
  élargie déjà utilisée par `AffectationController::store` (autorisé si on administre la
  société DE cet établissement, ou l'établissement lui-même).
- **Garde-fou ajouté**, qui n'existait pas du tout à la création : un Admin Établissement ne
  peut pas se créer un compte affecté du rôle Admin Société, même en un seul geste — même
  règle que celle déjà posée pour `AffectationController::store`, désormais aussi vérifiée
  ici (elle ne l'était pas, faute d'y être jamais parvenu avant ce changement).
- Front (`UtilisateurListPage`) : le bouton « + Nouvel utilisateur », les actions Éditer/
  Désactiver et le caractère obligatoire des champs Établissement/Rôle à la création
  passent de la condition `niveauSociete` (Super Admin/Admin Société) à `peutConsole` (les
  trois niveaux) — l'autorisation réelle reste décidée par le serveur, ceci n'est qu'un
  affichage cohérent avec ce qui est maintenant permis.
- Restent au niveau société (inchangé, l'utilisateur ne demandait que la création de
  comptes) : CRUD des établissements, catalogue général de rôles, traçabilité.

Test `PerimetreConsoleTest` mis à jour (l'ancien attendait un 403 sur la création par un
Admin Établissement — exactement l'inverse de la décision prise) et deux tests ajoutés : un
Admin Établissement crée un compte dans SON établissement, et se voit refuser à la fois un
compte hors de son établissement et un compte avec le rôle Admin Société.
Suite : **284 tests, 1184 assertions** (282 verts ; mêmes 2 échecs préexistants et sans
rapport). Build et lint frontend propres.

## Le catalogue de rôles suit le même mouvement que les comptes

Question posée par l'utilisateur sur `/admin/roles` (jusque-là fermée à l'Admin
Établissement, ni en lecture d'écran ni en écriture), puis précisée : « il peut créer des
utilisateurs, les rôles, permissions, mais tout sauf [le rôle] super admin ». Même logique
que le correctif précédent, appliquée cette fois au catalogue de rôles plutôt qu'aux comptes :

- `POST/DELETE roles` déplacés de `console:societe` vers `console:etablissement`. `GET roles`
  y était déjà.
- `RoleController::store()` n'a eu besoin d'AUCUN changement : il calcule déjà la société du
  rôle via `PerimetreConsole::codeCourant()`, qui sait depuis le début résoudre la société
  d'un Admin Établissement à partir de ses propres établissements administrés (c'est ce même
  mécanisme qui fait déjà fonctionner son accueil de console). Seul `destroy()` vérifiait
  l'autorisation avec `assertAutorisee()` (société uniquement, donc toujours faux pour un
  Admin Établissement) — remplacé par une comparaison directe avec `codeCourant()`, qui
  couvre les deux cas.
- Le catalogue GÉNÉRAL (`societe_code` NULL — les rôles socles comme Super Admin,
  Admin Société, Admin Établissement) reste réservé au Super Admin, création et
  suppression : c'est le « sauf super admin » de la demande, déjà en place et inchangé. Un
  Admin Établissement ne gère donc que les rôles propres à sa société — les mêmes qu'un
  Admin Société gérerait, pas un troisième niveau de rôles « établissement » qu'il aurait
  fallu ajouter au schéma.
- Front : route `/admin/roles` et entrée de sidebar « Rôles & permissions » n'exigent plus
  `niveauSociete`, comme `/admin/utilisateurs` déjà.

Tests `PerimetreConsoleTest` : un Admin Établissement crée et retire un rôle de sa propre
société (résolue depuis son établissement, sans jamais toucher au catalogue général),
refus d'un rôle appartenant à une AUTRE société.
Suite : **286 tests, 1189 assertions** (284 verts ; mêmes 2 échecs préexistants et sans
rapport). Build et lint frontend propres.

## Correctif : l'étape Photo de l'assistant Inscriptions était invisible

Signalé par l'utilisateur : « dans le formulaire d'inscription je ne peux pas ajouter de
photo ». Cause trouvée dans `InscriptionListPage.jsx` : le `<div>` de l'étape « Mère »
(`visible(4)`) n'avait jamais sa balise fermante — l'étape « Photo » (`visible(5)`) se
retrouvait donc **imbriquée à l'intérieur** du bloc Mère au lieu d'en être la sœur suivante.
Tant qu'on est sur l'étape Mère, ça ne se voit pas (les deux sont visibles ensemble). Mais
en avançant sur l'étape Photo, l'étape Mère redevient masquée (`hidden`, donc
`display:none`) — et comme Photo vit maintenant DANS ce bloc, elle disparaissait avec lui,
quelle que soit sa propre classe `visible(5)`. L'utilisateur atterrissait sur une étape
vide : rien à cliquer, aucune erreur, juste rien.

Les deux tests d'upload de photo qui échouaient depuis plusieurs sessions
(`SaisieEconomatTest::test_une_nouvelle_photo_remplace_la_precedente` et
`test_la_photo_est_servie_et_404_si_absente`, classés « préexistants et sans rapport » à
chaque fois faute de temps pour creuser) sont en réalité un symptôme de fuite d'état entre
tests (ils passent seuls, échouent dans la suite complète) — **sans lien avec ce bug-ci**,
qui est purement un défaut de balisage JSX invisible aux tests (ils appellent l'API
directement, jamais le rendu du formulaire). Cette fuite d'état reste donc ouverte, à
reprendre séparément.

Correctif : la balise fermante manquante est ajoutée à la fin de l'étape Mère, et la
fermeture surnuméraire qu'elle laissait à la fin de l'étape Photo est retirée en
contrepartie — six étapes, désormais six blocs frères comme partout ailleurs dans
l'assistant. Vérifié par `npm run build` (JSX rééquilibré, aucune erreur de parsing) et
`npm run lint` (propre, même avertissement connu sur `ErrorBoundary`).

## Le matricule devient obligatoire pour les enseignants (il l'était déjà à l'inscription)

Demande de l'utilisateur : « le matricule à l'inscription et pour les enseignants sont
obligatoire ». Vérification faite : côté inscriptions, `matricule` était déjà `required`
au serveur (`InscriptionController::regles()`) — mais l'assistant ne le faisait pas
respecter en le passant : le champ porte un `Matricule *` depuis toujours, mais le tableau
`REQUIS` qui bloque le bouton Suivant à l'étape 0 ne le listait pas, laissant avancer
jusqu'à l'envoi final avant de se faire refuser par le serveur. Côté enseignants, en
revanche, `matricule` était réellement `nullable` — un vrai trou.

- `EnseignantController::regles()` : `matricule` passe de `nullable` à `required`, même
  patron que `InscriptionController`. L'unicité était déjà vérifiée quand un matricule
  était fourni ; elle s'applique maintenant systématiquement.
- Front `EnseignantFormPage.jsx` : label `Matricule *`, et `matricule` ajouté au tableau
  `REQUIS` de l'étape 0 (État civil) — l'assistant bloque désormais avant l'envoi, comme il
  aurait toujours dû le faire côté inscriptions.
- Front `InscriptionListPage.jsx` : même correctif du tableau `REQUIS`, pour que le `*`
  déjà affiché corresponde enfin à un blocage réel plutôt qu'à un refus serveur tardif.

**Trouvaille en creusant l'échec de photo signalé plus haut** : le message d'erreur exact
est apparu — `rename(...): Accès refusé (code: 5)`, une erreur Windows classique quand un
antivirus/l'indexeur verrouille brièvement un fichier fraîchement écrit. `PhotoEleveStockage
::enregistrer()` retentait zéro fois ; il retente maintenant jusqu'à 4 fois avec un délai
croissant (`deplacerAvecReprise()`) avant d'abandonner — une vraie robustesse pour ce cas
côté production. Cela n'a cependant pas suffi à stabiliser les deux tests historiquement
flous : ils échouent encore dans la suite complète (mais jamais isolés), même avec ce délai.
Le verrou semble donc tenir plus longtemps qu'un simple scan transitoire dans cet
environnement de test précis — reste ouvert, sans impact sur l'application réelle (le même
code, exercé isolément ou par un vrai utilisateur, fonctionne).

Tests ajoutés/corrigés : `test_matricule_enseignant_obligatoire` (nouveau) ; matricule
ajouté aux payloads de `SaisieEconomatTest` et `AnneeClotureeTest` qui créaient un
enseignant sans en fournir (sans quoi le verrou d'année clôturée qu'ils testent n'aurait
plus été atteint, masqué par le 422 de validation).
Suite : **287 tests, 1192 assertions** (285 verts ; mêmes 2 échecs de longue date, cause
identifiée mais non éliminée). Build et lint frontend propres.

## Correctif : la photo « s'enregistrait automatiquement »

Retour de l'utilisateur juste après le correctif de l'étape masquée : « quand je veux
mettre la photo elle s'enregistre automatiquement ». Cause : dans les deux assistants
(Inscriptions, Enseignants), le gestionnaire `onSubmit` du `<form>` avait deux branches —
« pas la dernière étape → avancer », « dernière étape → enregistrer ». Comme la Photo est
la DERNIÈRE étape des deux assistants, tout évènement `submit` survenant une fois dessus
déclenchait l'enregistrement immédiat. Or un évènement `submit` ne vient pas que d'un clic
sur le bouton : la touche **Entrée**, pressée dans n'importe quel champ du formulaire (ou
juste après le retour du sélecteur de fichier natif), le déclenche aussi — et sur toutes
les étapes PRÉCÉDENTES elle se contentait d'avancer, ce qui la rendait anodine. Seule
l'étape Photo la transformait en enregistrement immédiat, un comportement incohérent avec
le reste de l'assistant et surprenant : rien n'indiquait que la Photo se comportait
différemment des cinq étapes avant elle.

Correctif, dans les deux formulaires : la touche Entrée est interceptée en amont
(`onKeyDown`) et n'a plus jamais le droit de déclencher un `submit` natif — elle avance
seulement dans l'assistant, quelle que soit l'étape (y compris Photo, où elle ne fait
maintenant rien). `onSubmit` ne fait plus qu'enregistrer, sans condition d'étape : il n'est
plus atteignable que par un clic explicite sur le bouton « Enregistrer », qui reste
`type="submit"`. Les zones de texte multiligne (`<textarea>`, s'il y en avait) sont
explicitement épargnées pour ne pas empêcher un retour à la ligne.
Vérifié par `npm run build` et `npm run lint` (propres).

## Correctif : le matricule obligatoire bloquait la modification des fiches enseignant existantes

Retour de l'utilisateur : « ici on ne peut enregistrer pas » sur `/enseignants`. Le
correctif précédent (matricule obligatoire) avait été rendu obligatoire **sans distinguer
création et modification** — `EnseignantController::regles()` était partagée telle quelle
entre `store()` et `update()`. Conséquence : toute fiche déjà présente dans
`T_PROFESSEUR` **sans matricule** (les comptes enseignants antérieurs à cette règle, très
plausibles vu que ce champ n'a jamais été suivi rigoureusement côté personnel,
contrairement aux élèves) devenait impossible à modifier — le moindre changement, même sans
toucher au matricule, se heurtait à un 422 silencieux pour l'utilisateur. Reproduit
directement en rejouant l'exact payload envoyé par le formulaire contre le serveur (succès
en création), puis en modification sur une fiche sans matricule (échec confirmé).

- `EnseignantController::regles(bool $creation = true)` : le matricule reste `required` à
  la création, redevient `nullable` en modification (`update()` appelle désormais
  `regles(creation: false)`). L'unicité, elle, continue de s'appliquer dès qu'un matricule
  est fourni, à la création comme en modification.
- Front `EnseignantFormPage.jsx` : `REQUIS` de l'étape 0 n'exige `matricule` qu'en création
  (`!enEdition`) — modifier une fiche existante sans matricule n'est plus bloqué à l'étape
  « Suivant ».
- Les inscriptions ne sont PAS concernées : leur matricule obligatoire est une règle plus
  ancienne, déjà partagée entre création et modification avant cette session — non touchée,
  aucun signalement dessus.

Test ajouté : une fiche `T_PROFESSEUR` sans matricule (simulant un compte antérieur à la
règle) reste modifiable — vérifié en changeant un autre champ sans jamais fournir de
matricule, la valeur `null` en base n'est pas altérée.
Suite : **288 tests, 1195 assertions** (286 verts ; mêmes 2 échecs de longue date, sans
rapport). Build et lint frontend propres.

## Simulation de bout en bout dans ECONOMAT réel (données DEMO-)

Demande explicite de l'utilisateur, confirmée après l'avoir prévenu de la portée : « fais
des simulations en créant des élèves, enseignants, des affectations, des emplois du temps,
des devoirs, des bulletins... qui seront dans la base de données ». Décision assumée avec
l'utilisateur : dans ECONOMAT/dbmasterbacou **réels** (pas un sandbox), tout préfixé
`DEMO-` pour rester identifiable — sachant qu'élèves et enseignants ne pourront jamais être
supprimés ensuite, seulement désactivés, comme le veut la règle du projet.

Reconnaissance d'abord (lecture seule) : année active retenue **« Année Scolaire
2026-2027 »** (deux années étaient marquées actives dans `T_ANNEEACADEMIQUE`, avec des
conventions `CodeAnnee` différentes — celle-ci est celle que portent réellement les classes
CE1 A/CM2 A déjà en place), classes CE1 A et CM2 A vides de tout élève pour cette année,
2 matières (`MAT`, `Géo`), trame horaire et 1 salle déjà renseignées, 4 enseignants déjà
réels dans `T_PROFESSEUR`.

Écrit via une commande Artisan à usage unique (`demo:simuler`, retirée une fois le travail
fait — ce n'était pas une fonctionnalité à garder), en passant par les VRAIS services
d'écriture de l'application (`EtudiantEcrivain`, `ProfesseurEcrivain`,
`CorProfClasseEcrivain`) pour tout ce qui est du ressort de NEXORA :

- **2 enseignants** (`DEMO-ENS1` Adjoua KONE, `DEMO-ENS2` Kouadio YAO).
- **8 élèves**, 4 par classe (CE1 A, CM2 A), avec filiation (père/mère) renseignée.
- **4 affectations** enseignant↔classe↔matière, croisées entre les deux classes et les
  deux matières pour exercer réellement la déduction d'enseignant qu'en fait l'emploi du
  temps.
- **4 créneaux d'emploi du temps**, sans conflit (classe/salle/enseignant vérifiés par
  construction), un lundi.
- **2 cahiers de textes** (un par classe), avec devoirs consignés sur les lignes de
  matière.
- **2 absences**, une justifiée, une non justifiée.

**Notes et bulletins : hors du périmètre d'écriture habituel de NEXORA**, signalé comme
tel. `T_MOYENNECLASSE` (moyenne, rang) n'est normalement jamais calculé par NEXORA — c'est
le travail propre d'ECONOMAT (aucun trigger SQL ne le fait automatiquement, vérifié : la
vue `V_MOYENNE_ELEVE_CLASSE` lit directement `T_MOYENNECLASSE`, sans recalcul). Pour que
les Résultats & bulletins affichent de vrais chiffres, la moyenne et le rang ont donc été
calculés à la main pour cette simulation et écrits directement (une session `T_SESSION`
« DEMO Composition 1 » créée pour cette année, des notes dans `T_NOTEENTETE`/
`T_NOTEDETAILS`, puis moyenne/rang dans `T_MOYENNECLASSE`) — une exception ponctuelle à
« on ne fait que ce que l'application ferait réellement », faite consciemment pour ce
besoin de démonstration.

**Point technique rencontré** : `T_NOTEENTETE.CodeAnnee` et `T_NOTEDETAILS.CodeAnnee` sont
`varchar(20)` — trop étroit pour le libellé complet « Année Scolaire 2026-2027 » (troncature
SQL Server refusée). Repris avec la forme courte du code d'année (« 2026 », celle de
`T_ANNEEACADEMIQUE.CodeAnnee`), qui est aussi ce que `ContexteScolaire::variantes()`
accepterait en plus du libellé — cohérent avec la façon dont l'application filtre déjà par
année ailleurs.

**Vérifié en rejouant le vrai code de l'application** (pas seulement des requêtes SQL) :
`EleveController`/`Eleve`, `RapportController::moyennes()`/`notesParMatiere()`,
`EmploiDuTempsController::index()` (déduit bien Adjoua KONE / Kouadio YAO selon la
matière), `CahierTextesController::index()`, et un vrai PDF de bulletin généré via
`BulletinController::show()` (`%PDF-1.7`, classement/moyenne/rang corrects) — tout lit et
affiche correctement les données simulées, aucune anomalie trouvée dans l'application
elle-même à l'occasion de cet exercice.

Aucun changement de code n'a été laissé dans le dépôt (la commande `demo:simuler` a été
retirée après usage) : cette entrée documente une action sur les données, pas un
changement applicatif.

### Correctif : les 2 enseignants DEMO n'apparaissaient pas dans la liste

Signalé par l'utilisateur : « je vois pas les enseignants créés » sur `/enseignants`.
Cause : les deux enseignants DEMO avaient été créés sans `annee_code` (colonne
`CodeAnnee` restée `NULL`), alors qu'`EnseignantController::index()` — vérification que je
n'avais pas faite lors de la simulation, contrairement à `EleveController` et aux autres —
filtre la liste par `ContexteScolaire::appliquer($q, 'CodeAnnee')`, un `whereIn` qui
n'inclut jamais les lignes `NULL`. Corrigé directement en base : `CodeAnnee` des deux
comptes DEMO mis à « Année Scolaire 2026-2027 », revérifié en rejouant
`EnseignantController::index()` — les deux apparaissent maintenant. Pas de changement de
code : la colonne était simplement restée vide dans les données simulées.

## Bulletin : mis à la même mise en page que les autres documents, vraiment détaillé

Demande de l'utilisateur : « le bulletin de chaque élève doit pouvoir être imprimé et bien
détaillé avec les différentes matières ». Deux causes distinctes trouvées en creusant :

1. **La simulation DEMO n'avait de notes que dans UNE matière** (Mathématiques) — le
   bulletin d'un élève DEMO ne montrait donc qu'une ligne, pas « les différentes
   matières ». Complété : notes de Géographie ajoutées pour les 8 élèves DEMO, moyennes et
   rangs recalculés sur les deux matières (coefficient 1 chacune) dans `T_MOYENNECLASSE`.
2. **Le gabarit `pdf.bulletin.blade.php` était resté un HTML autonome**, jamais aligné sur
   la mise en page commune (`pdf.layout.blade.php`) introduite plus tard pour les trois
   autres documents imprimables (fiche élève, fiche enseignant, emploi du temps, liste de
   classe) — sans l'entête NEXORA/établissement/année ni le pied de page daté qu'ont ces
   trois-là, et sans appréciation ni zone de signature.

Repris pour étendre `@extends('pdf.layout')` comme les autres, avec un vrai détail par
matière : matière, coefficient, nombre de notes, moyenne sur 20 **et une appréciation**
(Très bien / Bien / Assez bien / Passable / Insuffisant, dérivée de la moyenne — purement
indicative, NEXORA ne juge rien, c'est un repère de lecture) ; une section « Synthèse »
avec moyenne générale mise en avant et rang/effectif ; deux zones de signature (Direction /
Parent) pour un document réellement imprimable et remis en main propre.
`BulletinController::show()` passe désormais `etablissement` à la vue, comme
`ImpressionController::commun()` le fait déjà pour les trois autres documents.

Aucune donnée ni logique de calcul n'a changé : NEXORA continue de restituer ce
qu'ECONOMAT calcule (`V_MOYENNE_ELEVE_CLASSE`, `V_NOTECLASSE`), jamais de recalcul propre —
seuls l'habillage du document et le fait que la démo couvre maintenant deux matières ont
changé. `BulletinTest` (7 cas, dont les cas limites — élève sans note, sans classe, session
qui ne se mélange pas) n'a nécessité aucune adaptation : il vérifie les données passées à
la vue, pas le rendu HTML.
Suite inchangée : **288 tests, 1195 assertions** (286 verts ; mêmes 2 échecs de longue date,
sans rapport).

## Bulletin : nulle part où le voir ni l'imprimer depuis l'application — corrigé

Retour de l'utilisateur : « je ne vois pas [...] je veux qu'il ait un champ fiche pour
pouvoir voir le bulletin et l'imprimer ». Vérification faite, le constat était pire que le
signalement : **aucune page du personnel n'exposait de bulletin, nulle part**. Le PDF
retravaillé dans le correctif précédent était correct, mais rien dans l'écran ne permettait
de le déclencher — seul le portail Parent (construit plus tôt) en avait un. La page
« Résultats & bulletins » elle-même, qui porte ce nom depuis le début, n'avait pas de
bouton bulletin ; `rapportsApi.js` gardait une fonction `bulletinDownloadUrl()` jamais
appelée nulle part, avec un paramètre `periode_id` qui ne correspond même plus au `session`
qu'attend le serveur — un reste de l'époque d'avant le pivot, jamais raccordé depuis.

- **`BulletinController`** : la construction des données (moyenne générale, rang,
  effectif, détail par matière) était mélangée à la génération du PDF — extraite dans
  `donneesBulletin()`, réutilisée par une nouvelle méthode `donnees()` qui renvoie ces
  mêmes données en JSON, pour un aperçu à l'écran avant impression. Nouvelle route
  `GET eleves/{eleve}/bulletin/donnees`.
- **Fiche élève** (`EleveDetailPage.jsx`) — la demande explicite de l'utilisateur : une
  section « Bulletin » avec moyenne générale, rang et détail par matière (matière, coef.,
  nombre de notes, moyenne), et un bouton « Imprimer le bulletin » qui télécharge le vrai
  PDF (même patron blob que les autres impressions — un lien direct perdrait le cookie de
  session).
- **Page Résultats & bulletins** (`MoyennesPage.jsx`) — un bouton d'impression par ligne du
  classement, pour que la page porte enfin ce que son nom promet. Au passage, la clé
  React de chaque ligne utilisait `row.eleve_id`, un champ qui n'existe pas dans la réponse
  du serveur (`code_eleve`) — corrigée en même temps, puisque le bouton en avait besoin
  pour cibler le bon élève.
- `bulletinDownloadUrl()`, la fonction morte de `rapportsApi.js`, retirée : remplacée par
  `imprimerBulletin()` dans `elevesApi.js`, réutilisée par les deux écrans.

Test ajouté : `test_les_donnees_du_bulletin_sont_exposees_en_json` (nouvelle route,
mêmes chiffres que le PDF). Suite : **289 tests, 1200 assertions** (287 verts ; mêmes 2
échecs de longue date, sans rapport). Build et lint frontend propres.

## Évaluations : planifier un devoir, avant toute note

Demande de l'utilisateur sur `/evaluations`, avec un exemple précis (titre, classe, matière,
enseignant, type, date, horaire, coefficient, note maximale). La page n'était jusqu'ici
qu'une restitution en lecture seule des évaluations DÉJÀ notées (`RapportController::
evaluations()`, un agrégat de `V_NOTECLASSE`) — rien ne permettait d'en ANNONCER une avant
que les notes existent. Et ECONOMAT n'a pas de table pour ça : `T_NOTEENTETE` (l'entête
d'une saisie de notes) ne porte ni titre, ni horaire, ni note maximale, ni enseignant
explicite — seulement classe, matière, coefficient, date, session.

**Décision d'architecture, dans la continuité de ce qui existe déjà** (`console_*`,
`console_affectation_eleves`) : une table propre à NEXORA plutôt que de forcer ces champs
dans une table partagée qui ne les prévoit pas.

- **Migration `evaluations`** dans `database/migrations/console/` (comme toutes les tables
  propres à NEXORA — ce dossier n'est jamais joué par un `migrate` sans `--database`
  explicite, la sécurité qui a évité `ECONOMAT` par accident lors du nettoyage
  post-pivot). `App\Models\Evaluation` (connexion `ecoprim`) : titre, classe_code,
  matiere_code, enseignant_code, type, date, heure_debut, heure_fin, coefficient,
  note_maximale, annee. `classe_code`/`matiere_code`/`enseignant_code` pointent vers
  ECONOMAT en lecture seule (vérifiés par `Rule::exists` à la saisie, faute de contrainte
  inter-base).
- **`EvaluationController`** (nouveau, distinct de `RapportController::evaluations()`) :
  CRUD complet — création, modification, **suppression réelle sans retenue** (donnée
  propre à NEXORA, rien n'en dépend en aval, contrairement à ce qui vaut pour les tables
  partagées). Verrou d'année clôturée comme partout : porte sur l'année de l'évaluation
  elle-même, pas sur l'année de travail affichée. Référentiels (classes, matières,
  enseignants, types) bornés à l'année comme les autres écrans.
- Type : liste fermée (Devoir, Devoir surveillé, Interrogation écrite, Interrogation orale,
  Composition) plutôt qu'un champ libre — cohérent avec ce que l'utilisateur a demandé
  (« Type : Devoir surveillé » via une liste déroulante).
- Front : nouvelle section « Évaluations planifiées » en haut de `/evaluations`, avant la
  restitution existante des notes déjà saisies (renommée « Notes déjà saisies » pour que
  les deux sections se distinguent clairement) — formulaire complet, tableau avec édition
  et retrait par ligne.

Vérifié en rejouant le vrai contrôleur avec l'exemple exact de l'utilisateur (CM2 A,
Mathématiques, Jocelyn Kouassi, Devoir surveillé, 15/10/2026, 08h00–09h00, coefficient 2,
note maximale 20) contre la vraie base ECONOMAT/ecoprim — créé, retrouvé via la liste,
libellés classe/matière/enseignant correctement résolus.

Tests `EvaluationTest` (8 cas) : référentiels, création, champs obligatoires, classe/matière
inconnue refusée, enseignant facultatif, filtre par classe, modification puis suppression,
verrou d'année clôturée (création bloquée, modification d'une évaluation d'une année non
clôturée toujours possible même en consultant une autre année).
Suite : **297 tests, 1249 assertions** (296 verts ; le même échec de longue date, sans
rapport, apparaît de façon intermittente — 0 à 2 selon l'exécution). Build et lint frontend
propres. Migration appliquée sur la vraie base `ecoprim`.

### Correctif : la fenêtre du formulaire débordait de l'écran

Signalé par l'utilisateur, capture à l'appui, sur le formulaire d'évaluation : « je ne vois
pas le formulaire ». Cause générale, pas propre à ce formulaire : `ModaleFormulaire.jsx` —
la coquille commune à sept écrans (années, cycles, niveaux, classes, matières, absences,
portail Enseignant, et maintenant évaluations) — n'imposait aucune hauteur maximale ni
défilement à la carte blanche. Elle reste centrée verticalement quel que soit son contenu ;
tant que le formulaire tenait dans la fenêtre ça ne se voyait pas, mais celui des
évaluations (8 champs) est le premier assez long pour dépasser la hauteur visible sur un
petit écran — le bouton « Enregistrer » se retrouvait hors champ, sans aucun moyen de
défiler jusqu'à lui.

Corrigé une fois pour toutes dans le composant partagé : `max-h-[90vh]` et
`overflow-y-auto` sur la carte, comme le fait déjà la fenêtre (non partagée) de
`/admin/utilisateurs`. Purement correctif — aucun changement visuel sur les formulaires
déjà assez courts pour tenir à l'écran, et les sept écrans qui utilisent cette coquille en
bénéficient tous sans modification de leur côté.
