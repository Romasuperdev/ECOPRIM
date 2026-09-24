@echo off
REM ===========================================================================
REM  API NEXORA Ecole Primaire - serveur de developpement, port 8001
REM
REM  L'application web (Vite, http://localhost:5173) appelle cette API. Sans
REM  elle, l'ecran de connexion affiche :
REM      « Le serveur ne repond pas (http://localhost:8001). »
REM
REM  LAISSER CETTE FENETRE OUVERTE tant qu'on travaille : la fermer arrete
REM  l'API, et le message revient.
REM
REM  Port 8001 et non 8000 : le 8000 est pris par l'autre projet (ECONEW).
REM ===========================================================================
title API NEXORA - port 8001

cd /d "%~dp0backend"

REM On prend le php du PATH s'il existe, sinon celui de WAMP.
set "PHP=php"
where php >nul 2>&1 || set "PHP=C:\wamp64\bin\php\php8.2.29\php.exe"

if not exist "%PHP%" if "%PHP%" neq "php" (
    echo.
    echo   ERREUR : PHP est introuvable.
    echo   Attendu dans le PATH, ou ici : C:\wamp64\bin\php\php8.2.29\php.exe
    echo.
    pause
    exit /b 1
)

echo.
echo   API NEXORA -^> http://localhost:8001
echo.
echo   La PREMIERE requete peut prendre une quinzaine de secondes :
echo   c'est la connexion initiale a SQL Server. Ensuite tout est rapide.
echo.

"%PHP%" artisan serve --host=127.0.0.1 --port=8001

echo.
echo   L'API s'est arretee. Appuyez sur une touche pour fermer.
pause >nul
