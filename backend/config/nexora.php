<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Photos des élèves
    |--------------------------------------------------------------------------
    |
    | ECONOMAT ne stocke qu'un NOM DE FICHIER dans T_ETUDIANT.Photo : les images
    | vivent dans un dossier partagé, lu aussi bien par ECONOMAT que par NEXORA.
    | Renseignez ce dossier dans .env (NEXORA_PHOTOS_ELEVES), par exemple :
    |
    |   NEXORA_PHOTOS_ELEVES="\\\\SERVEUR\\ECONOMAT\\Photos"
    |   NEXORA_PHOTOS_ELEVES="C:/ECONOMAT/Photos"
    |
    | Sans configuration, les photos restent dans le stockage local de NEXORA
    | (ECONOMAT ne les verra pas) — utile pour tester avant de brancher le partage.
    |
    */

    'photos_eleves' => [
        'chemin' => env('NEXORA_PHOTOS_ELEVES') ?: storage_path('app/photos-eleves'),
        'taille_max_ko' => (int) env('NEXORA_PHOTOS_TAILLE_MAX_KO', 4096),
        'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
    ],

];
