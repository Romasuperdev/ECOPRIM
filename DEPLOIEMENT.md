# Mise en ligne de NEXORA École Primaire sur WAMP

Écrit pour la personne qui installe et configure le serveur. Aucune connaissance de
Laravel ni de React n'est supposée.

Compter **30 à 45 minutes** la première fois.

---

## Ce que vous installez

Une seule application, servie par **une seule adresse**.

```
https://ecole.exemple.ci/            →  l'interface (React)
https://ecole.exemple.ci/api/v1/...  →  l'API (Laravel)
```

Les deux sortent du même dossier Apache. C'est délibéré : avec deux adresses
distinctes, il faudrait configurer CORS, autoriser des cookies inter-domaines et
réécrire l'URL de l'API à chaque changement de serveur. Ici, rien de tout cela.

**Trois bases de données** sont utilisées, sur le même serveur SQL Server :

| Base | Rôle | Accès requis |
|---|---|---|
| `ECONOMAT` | Données scolaires — élèves, classes, notes | **Lecture** et écriture limitée |
| `dbmasterbacou` | Comptes, sociétés, applications | **Lecture seule** |
| `ecoprim` | Tables propres à NEXORA | **Lecture et écriture** |

> `ECONOMAT` et `dbmasterbacou` sont **partagées avec les autres applications de la
> suite**. NEXORA n'y supprime jamais rien, et n'écrit que dans des colonnes connues.

---

## Avant de commencer

Sur le serveur, il vous faut :

- **WAMP** avec **PHP 8.2 ou plus**
- **Apache** avec `mod_rewrite` **activé**
- Les extensions PHP : `sqlsrv`, `pdo_sqlsrv`, `gd`, `zip`, `mbstring`, `openssl`
- Le **pilote ODBC 17 (ou 18) pour SQL Server**
- **Composer**
- Un **compte SQL Server** ayant les accès du tableau ci-dessus

Node.js **n'est pas nécessaire sur le serveur** : l'interface est construite avant le
dépôt (étape 1).

---

## Étape 1 — Préparer le dossier à déposer

**Sur le poste de développement**, à la racine du projet :

```
deployer.bat
```

Ce script construit l'interface, installe les dépendances PHP sans les outils de
développement, et vide les caches. Il ne touche ni à la base ni au `.env`.

À la fin, le dossier à déposer contient notamment :

```
backend/public/spa/      ← l'interface construite ; sans elle, le site rend du JSON
backend/vendor/          ← dépendances PHP
```

> `backend/public/spa` n'est **pas dans le dépôt Git** : c'est du généré. Si vous
> récupérez le projet par `git clone` plutôt qu'en copiant un dossier, lancez
> `deployer.bat` avant de déposer — sinon l'interface manquera.

---

## Étape 2 — Déposer le projet

Copiez le dossier du projet dans `www`, par exemple :

```
C:\wamp64\www\ecoprim\
    backend\
    frontend\
    DEPLOIEMENT.md
```

`frontend/` n'est pas servi par Apache ; il n'est là que pour une reconstruction
ultérieure. Vous pouvez l'omettre si Node n'existe pas sur le serveur.

---

## Étape 3 — Le VirtualHost Apache

Le point qui compte : **la racine du site est `backend/public`**, jamais le dossier du
projet. Tout le reste — `.env`, le code, les dépendances — doit rester hors d'atteinte
du web.

Dans `C:\wamp64\bin\apache\apache2.4.x\conf\extra\httpd-vhosts.conf` :

```apache
<VirtualHost *:80>
    ServerName ecole.exemple.ci
    DocumentRoot "C:/wamp64/www/ecoprim/backend/public"

    <Directory "C:/wamp64/www/ecoprim/backend/public">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog  "C:/wamp64/logs/ecoprim-error.log"
    CustomLog "C:/wamp64/logs/ecoprim-access.log" combined
</VirtualHost>
```

**`AllowOverride All` est indispensable** : sans lui, Apache ignore le `.htaccess` de
Laravel, aucune URL n'est réécrite, et **toute l'API répond 404**. C'est de loin
l'erreur la plus fréquente.

Puis `httpd.conf` doit contenir, décommenté :

```apache
LoadModule rewrite_module modules/mod_rewrite.so
```

Redémarrez Apache.

---

## Étape 4 — Les limites de PHP

Dans le `php.ini` utilisé par Apache (`C:\wamp64\bin\apache\apache2.4.x\bin\php.ini`) :

```ini
upload_max_filesize = 50M
post_max_size       = 52M
memory_limit        = 256M
```

**PHP tranche avant Laravel.** Si `upload_max_filesize` est plus bas que la limite
annoncée dans l'application, le fichier n'atteint même pas la validation : l'utilisateur
voit un refus sans cause. `post_max_size` doit rester au-dessus — le corps de la requête
porte plus que le fichier seul.

Vérifiez aussi que ces extensions sont décommentées :

```ini
extension=sqlsrv
extension=pdo_sqlsrv
extension=gd
extension=zip
extension=mbstring
```

Redémarrez Apache après toute modification.

---

## Étape 5 — La configuration de l'application

```
cd C:\wamp64\www\ecoprim\backend
copy .env.production.example .env
```

Ouvrez `.env` et remplacez chaque valeur entre `< >`. Le fichier explique chaque ligne.

Puis :

```
php artisan key:generate
```

**Trois réglages méritent votre attention :**

| Réglage | Valeur | Pourquoi |
|---|---|---|
| `APP_DEBUG` | `false` | Activé, une erreur expose le code, les requêtes SQL et les mots de passe de base |
| `SESSION_DOMAIN` | **vide** | Renseigné, le cookie porte ce domaine et le navigateur le refuse ailleurs : la connexion ne tient pas |
| `NEXORA_PHOTOS_ELEVES` | le dossier partagé | Sans lui, les photos restent chez NEXORA et ECONOMAT ne les voit jamais |

---

## Étape 6 — La base de données

Créez la base `ecoprim` si elle n'existe pas, puis :

```
php artisan migrate --database=ecoprim --path=database/migrations/console --force
```

Cette commande ne crée que les tables **propres à NEXORA**. Elle ne touche ni à
`ECONOMAT` ni à `dbmasterbacou`.

**Désactivez l'expiration du mot de passe** du compte SQL, dans SSMS :

```sql
ALTER LOGIN <compte-sql> WITH CHECK_EXPIRATION = OFF;
```

Sans cela, l'application s'arrête du jour au lendemain, et le message d'erreur ne parle
que de connexion refusée.

---

## Étape 7 — Les droits d'écriture

Apache doit pouvoir écrire dans :

```
backend\storage\          (sessions, journaux, fichiers d'import déposés)
backend\bootstrap\cache\  (caches compilés)
```

et dans le dossier des photos désigné par `NEXORA_PHOTOS_ELEVES`.

---

## Étape 8 — Le contrôle

C'est l'étape qui vous fera gagner le plus de temps :

```
php artisan nexora:verifier-deploiement
```

Elle contrôle **tout ce qui précède** — extensions, connexions aux trois bases, tables
attendues, droits d'écriture, limites de téléversement, interface construite,
`.htaccess` présent — et, pour chaque anomalie, **dit quoi faire**.

Elle distingue ce qui empêche de démarrer de ce qui peut attendre. Tant qu'il reste une
ligne « À corriger », ne mettez pas en ligne.

Elle ne modifie rien : vous pouvez la relancer autant de fois que nécessaire.

---

## Étape 9 — Mettre les caches en place

**Une fois le `.env` définitif en place, et pas avant :**

```
php artisan config:cache
php artisan route:cache
```

Ces deux commandes figent la configuration et les routes dans des fichiers compilés.
L'application démarre plus vite.

> **À relancer après toute modification du `.env`.** Sans cela, votre changement n'aura
> aucun effet : c'est le cache qui fait foi, et l'on cherche longtemps pourquoi une
> valeur corrigée reste ignorée.

Pour revenir en arrière : `php artisan config:clear` et `php artisan route:clear`.

---

## Étape 10 — Vérifier à l'écran

1. Ouvrez `https://ecole.exemple.ci/` → l'écran de connexion doit apparaître
2. Connectez-vous avec un compte administrateur
3. **Rafraîchissez la page (F5) sur un écran interne**, par exemple `/eleves`

Ce troisième point n'est pas accessoire : il vérifie que les adresses profondes
fonctionnent. Si vous obtenez un 404 d'Apache, c'est que `AllowOverride All` manque
ou que `mod_rewrite` n'est pas activé.

---

## Mettre à jour l'application plus tard

```
cd C:\wamp64\www\ecoprim
deployer.bat                  (sur le poste de développement, puis recopier)

cd backend
php artisan migrate --database=ecoprim --path=database/migrations/console --force
php artisan config:cache
php artisan route:cache
php artisan nexora:verifier-deploiement
```

---

## En cas de problème

| Ce que vous voyez | Cause la plus probable |
|---|---|
| Toute l'API répond **404** | `AllowOverride All` absent, ou `mod_rewrite` désactivé |
| La racine rend du **JSON** au lieu du site | `backend/public/spa` manque — relancez `deployer.bat` |
| **404 d'Apache** après un F5 sur `/eleves` | Même cause que le premier cas |
| **« Le serveur ne répond pas »** à la connexion | Apache arrêté, ou mauvaise racine de VirtualHost |
| **« Session store not set on request »** | Le domaine utilisé n'est pas dans `SANCTUM_STATEFUL_DOMAINS` |
| Connexion acceptée puis **immédiatement perdue** | `SESSION_DOMAIN` est renseigné — videz-le |
| **« Login failed … password has expired »** | Mot de passe SQL expiré — voir l'étape 6 |
| Une **photo refusée** sans raison | `upload_max_filesize` trop bas — voir l'étape 4 |
| Messages d'erreur **en anglais** | `APP_LOCALE=fr` manque, ou le cache de configuration est périmé |

Les journaux utiles :

```
backend\storage\logs\laravel.log     erreurs de l'application
C:\wamp64\logs\ecoprim-error.log     erreurs d'Apache
```
