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
    |   NEXORA_PHOTOS_ELEVES='\\SERVEUR\ECONOMAT\Photos'   <- guillemets SIMPLES
    |   NEXORA_PHOTOS_ELEVES="//SERVEUR/ECONOMAT/Photos"
    |   NEXORA_PHOTOS_ELEVES="C:/ECONOMAT/Photos"
    |
    | Attention : entre guillemets DOUBLES, dotenv interprète les antislashs comme des
    | séquences d'échappement et refuse de démarrer. Utilisez des guillemets simples,
    | ou des slashs (PHP les accepte sous Windows, y compris pour les chemins UNC).
    |
    | Sans configuration, les photos restent dans le stockage local de NEXORA
    | (ECONOMAT ne les verra pas) — utile pour tester avant de brancher le partage.
    |
    */

    'photos_eleves' => [
        'chemin' => env('NEXORA_PHOTOS_ELEVES') ?: storage_path('app/photos-eleves'),
        // 5 Mo : largement assez pour un portrait, même pris au téléphone ; au-delà, on
        // remplit le dossier partagé pour rien. Attention : PHP tranche AVANT Laravel — si
        // `upload_max_filesize` ou `post_max_size` du php.ini sont plus bas, c'est eux qui
        // font loi et le fichier n'atteint même pas la validation.
        'taille_max_ko' => (int) env('NEXORA_PHOTOS_TAILLE_MAX_KO', 5120),
        'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
    ],

    /*
    | Tables propres à NEXORA (connexion `ecoprim`, ATECH sur le serveur) : créées d'elles-
    | mêmes au premier appel de l'API si elles manquent (voir App\Support\TablesPropres).
    | NEXORA_INSTALLATION_AUTO=false pour s'en tenir à `php artisan migrate`.
    */
    'installation_auto' => filter_var(env('NEXORA_INSTALLATION_AUTO', true), FILTER_VALIDATE_BOOL),

];
