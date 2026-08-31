# ECOPRIM — Correspondance pages → vraies tables (mode 100 % lecture)

*Décision : ECOPRIM devient une visionneuse en lecture seule. Application → `ECONOMAT`, Console → `dbmasterbacou`. La base `ecoprim` n'existe pas et ne doit être liée à aucune table.*

---

## Confirmé

**Années scolaires** → `ECONOMAT.dbo.T_ANNEEACADEMIQUE`

| App (attendu par le front) | Colonne réelle | Note |
|---|---|---|
| `id` | `CodeAnnee` | clé primaire |
| `libelle` | `LibelleAnnee` | |
| `active` | `Activer` | |
| `cloturee` | `ClotureDefinitive` | + `CloturePartielle` (2ᵉ niveau, à exposer) |
| `date_debut` | `DEBUT` | |
| `date_fin` | `FIN` | |
| — | `CODE`, `CODESOCIETE` | rattachement société (multi-tenant porté ici) |

---

## À confirmer par l'introspection (`php artisan schema:introspect`)

### Application → ECONOMAT (lecture seule)

| Page | Table/vue cible probable | Statut |
|---|---|---|
| Années scolaires | `T_ANNEEACADEMIQUE` | ✅ confirmé |
| Élèves | `T_ETUDIANT` (+ `T_HISTETUDIANT`) | à confirmer colonnes |
| Classes | `T_CLASSE` | à confirmer |
| Cycles / Niveaux | `T_CYCLE` / `T_NIVEAU` | à confirmer |
| Matières | `T_MATIERE` | à confirmer |
| Enseignants | `T_PROFESSEUR` | à confirmer |
| Affectation prof ↔ classe | `T_CORPROFCLASSE` / `T_CORPROFMAT` | à confirmer |
| Coefficients | `T_CORMATNIVEAUCOEFF` | à confirmer |
| Saisie des notes | `T_NOTEENTETE` / `T_NOTEDETAILS` | à confirmer |
| Résultats & bulletins | `T_MOYENNES`, `T_MOYENNECLASSE`, `T_MOYENNEGEN`, `V_MOYENNE_*` | à confirmer |
| Cahier de textes | `T_CAHIER_JOURNAL` / `T_ENTETE_JOURNAL` | à confirmer |
| Emplois du temps | `T_EMPLOIDUTEMPS` | à confirmer |
| Inscriptions | `V_INSCRIPTION` | à confirmer (vue = lecture) |
| Absences | `V_ABSENCE` / `T_ABSENCEPROF` | à confirmer |
| Examens / sessions | `T_SESSION`, `T_RESULTATEXAMENSN` | à confirmer |

### Console → dbmasterbacou (lecture seule)

| Page | Table cible | Statut |
|---|---|---|
| Sociétés | `US_SOCIETE` | connu |
| Établissements | `T_ETABLISSEMENT` | connu |
| Utilisateurs & accès | `RH_USER` | connu |
| Rôles | dérivés de `RH_USER` (`Profil`, `SuperAdmin`, `IdGroupe`) + tables `roles`/`permissions` de dbmasterbacou si présentes | à confirmer |

### Pages sans table ECONOMAT identifiée (à trancher : retirer ou trouver une vue)

Retards, Discipline/Sanctions, Conseils de classe / Délibérations, Ressources pédagogiques, Documents (upload), Messages / Annonces (sauf si `T_MSG`/`T_CHAT`/`T_SMS` conviennent), Parents/Tuteurs (probablement des champs dans `T_ETUDIANT`).

---

## Ordre de bascule proposé

1. **Auth en lecture seule** (préalable) : login contre `RH_USER`, rôles dérivés de `RH_USER`, session cookie, plus aucune écriture ni table `users`/`roles`/`permissions` locale.
2. **Connexions** : `economat` (app) et `dbmasterbacou`/`master` (console) comme seules connexions ; suppression de toute référence à `ecoprim`.
3. **Page par page** : chaque modèle repointé sur sa vraie table + couche de correspondance (Resource/accessors) pour garder l'affichage, et retrait des actions d'écriture.
4. **Nettoyage** : suppression des modules purement « écriture ECOPRIM » sans équivalent ECONOMAT.

## Le déclencheur

**`php artisan schema:introspect`** (après `DB_ECONOMAT_DATABASE=ECONOMAT` dans `.env`) écrit le schéma exact des deux bases dans `backend/storage/app/schema/`. C'est ce qui permet de remplir toutes les lignes « à confirmer » et de câbler juste, sans deviner.
