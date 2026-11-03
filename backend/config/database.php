<?php

return [
    // Repli sur la base PROPRE, jamais sur ECONOMAT : un modèle qui oublierait sa
    // connexion ne doit pas atterrir dans la base de production partagée. Le .env garde
    // le dernier mot — d'où le test SocleConnexionsTest, qui exige que chaque modèle
    // déclare explicitement où il vit.
    'default' => env('DB_CONNECTION', 'ecoprim'),

    'connections' => [

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'ecoprim'),
            'username' => env('DB_USERNAME', 'sa'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            // ODBC 18 chiffre la connexion par défaut et refuse un certificat auto-signé
            // (« La chaîne de certificats a été fournie par une autorité qui n'est pas
            // approuvée »). DB_TRUST_SERVER_CERTIFICATE=true dans .env l'accepte.
            // Laissés vides, le pilote garde son comportement par défaut.
            'encrypt' => env('DB_ENCRYPT'),
            'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE'),
        ],

        // Base de contrôle partagée (comptes RH_USER) — lecture seule pour ECOPRIM,
        // utilisée uniquement pour vérifier les identifiants à la connexion.
        'master' => [
            'driver' => 'sqlsrv',
            // Sur le serveur, dbmasterbacou peut vivre ailleurs qu'ATECH, avec ses propres
            // identifiants et sans chiffrement : DB_MASTER_* prime, DB_* sert de repli.
            'host' => env('DB_MASTER_HOST', env('DB_HOST', 'localhost')),
            'port' => env('DB_MASTER_PORT', env('DB_PORT', '1433')),
            'database' => env('DB_MASTER_DATABASE', 'dbmasterbacou'),
            'username' => env('DB_MASTER_USERNAME', env('DB_USERNAME', 'sa')),
            'password' => env('DB_MASTER_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'encrypt' => env('DB_MASTER_ENCRYPT', env('DB_ENCRYPT')),
            'trust_server_certificate' => env('DB_MASTER_TRUST_SERVER_CERTIFICATE', env('DB_TRUST_SERVER_CERTIFICATE')),
        ],

        // Base pédagogique ECONOMAT — LECTURE SEULE. L'application principale lit ses
        // données réelles (élèves, classes, notes, matières...) directement dans les
        // tables T_* d'ECONOMAT. Aucune écriture : ECONOMAT reste géré par son propre logiciel.
        'economat' => [
            'driver' => 'sqlsrv',
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_ECONOMAT_DATABASE', 'ECONOMAT'),
            'username' => env('DB_ECONOMAT_USERNAME', env('DB_USERNAME', 'sa')),
            'password' => env('DB_ECONOMAT_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            // ODBC 18 chiffre la connexion par défaut et refuse un certificat auto-signé
            // (« La chaîne de certificats a été fournie par une autorité qui n'est pas
            // approuvée »). DB_TRUST_SERVER_CERTIFICATE=true dans .env l'accepte.
            // Laissés vides, le pilote garde son comportement par défaut.
            'encrypt' => env('DB_ENCRYPT'),
            'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE'),
        ],

        // Base propre ECOPRIM (écrivable) : gouvernance Console (sociétés, établissements,
        // rôles, affectations utilisateur↔établissement↔rôle). Aucune écriture dans les bases
        // partagées ECONOMAT / dbmasterbacou.
        'ecoprim' => [
            'driver' => 'sqlsrv',
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_ECOPRIM_DATABASE', 'ecoprim'),
            'username' => env('DB_ECOPRIM_USERNAME', env('DB_USERNAME', 'sa')),
            'password' => env('DB_ECOPRIM_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            // ODBC 18 chiffre la connexion par défaut et refuse un certificat auto-signé
            // (« La chaîne de certificats a été fournie par une autorité qui n'est pas
            // approuvée »). DB_TRUST_SERVER_CERTIFICATE=true dans .env l'accepte.
            // Laissés vides, le pilote garde son comportement par défaut.
            'encrypt' => env('DB_ENCRYPT'),
            'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE'),
        ],

        // Base métier du serveur (ATECH) : même cible que `ecoprim` en production. Gardée
        // pour les migrations et scripts du serveur qui la nomment.
        'metier' => [
            'driver' => 'sqlsrv',
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_METIER_DATABASE', env('DB_ECOPRIM_DATABASE', 'ecoprim')),
            'username' => env('DB_USERNAME', 'sa'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'encrypt' => env('DB_ENCRYPT'),
            'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE'),
        ],

        // Connexion utilisée uniquement par la suite de tests automatisés
        // (phpunit force DB_CONNECTION=sqlite / DB_DATABASE=:memory:). Aucune
        // incidence en production, qui reste sur SQL Server (sqlsrv) par défaut.
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],

    ],

    'migrations' => [
        // Sur le serveur : EP_Migrations, l'historique propre à ECOPRIM dans ATECH.
        'table' => env('DB_MIGRATIONS_TABLE', 'migrations'),
        'update_date_on_publish' => true,
    ],
];
