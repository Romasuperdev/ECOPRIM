<?php

return [
    // RH_USER (dbmasterbacou) héberge les logins de plusieurs applications. Renseigner
    // ici le code applicatif d'ECOPRIM (via ECOPRIM_CODE_APP dans .env) restreint la
    // connexion aux comptes de cette application. Laisser vide = aucune restriction.
    'code_app' => env('ECOPRIM_CODE_APP'),
];
