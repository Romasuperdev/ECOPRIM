@echo off
setlocal enabledelayedexpansion
chcp 65001 >nul

REM ===========================================================================
REM  NEXORA Ecole Primaire — preparation d'une mise en ligne
REM
REM  A lancer sur le poste de DEVELOPPEMENT, avant de deposer le projet dans www.
REM  Le script construit l'interface, installe les dependances PHP en mode
REM  production et met les caches a jour. Il n'ecrit RIEN dans la base et ne
REM  touche pas au .env : ces deux-la restent la main de l'administrateur.
REM
REM  Ensuite, cote serveur : voir DEPLOIEMENT.md
REM ===========================================================================

cd /d "%~dp0"
set "ERREUR="

echo.
echo   ================================================
echo    NEXORA — preparation de la mise en ligne
echo   ================================================
echo.

REM --- 1. Interface React ----------------------------------------------------
echo   [1/4] Construction de l'interface...
if not exist "frontend\package.json" (
  echo         ECHEC : frontend\package.json introuvable.
  set "ERREUR=1"
  goto :fin
)
pushd frontend
call npm ci || call npm install
if errorlevel 1 (
  echo         ECHEC : installation des dependances npm.
  set "ERREUR=1"
  popd
  goto :fin
)
call npm run build
if errorlevel 1 (
  echo         ECHEC : la construction de l'interface a echoue.
  set "ERREUR=1"
  popd
  goto :fin
)
popd
echo         OK — interface construite dans backend\public\spa
echo.

REM --- 2. Dependances PHP ----------------------------------------------------
echo   [2/4] Dependances PHP (sans les outils de developpement)...
pushd backend
call composer install --no-dev --optimize-autoloader --no-interaction
if errorlevel 1 (
  echo         ECHEC : composer install. Composer est-il installe ?
  set "ERREUR=1"
  popd
  goto :fin
)
echo         OK
echo.

REM --- 3. Caches -------------------------------------------------------------
REM  On VIDE plutot qu'on ne met en cache : un cache de configuration fige les
REM  valeurs du .env du poste de developpement, et elles partiraient en
REM  production. C'est sur le SERVEUR, apres avoir pose le bon .env, qu'il faut
REM  lancer « php artisan config:cache » (voir DEPLOIEMENT.md).
echo   [3/4] Nettoyage des caches...
php artisan config:clear
php artisan route:clear
php artisan view:clear
echo         OK — caches vides (ils seront reconstruits sur le serveur)
echo.

REM --- 4. Controle -----------------------------------------------------------
echo   [4/4] Controle de l'installation locale...
echo.
php artisan nexora:verifier-deploiement
popd

echo.
echo   Les anomalies ci-dessus concernent CE POSTE (APP_ENV=local, APP_DEBUG=true).
echo   Relancez la meme commande SUR LE SERVEUR, avec le .env de production :
echo       php artisan nexora:verifier-deploiement
echo.

:fin
if defined ERREUR (
  echo.
  echo   Preparation INTERROMPUE. Corrigez l'erreur ci-dessus et relancez.
  exit /b 1
)

echo   A deposer dans www : le dossier complet, backend\public\spa compris.
echo   Puis suivez DEPLOIEMENT.md.
echo.
exit /b 0
