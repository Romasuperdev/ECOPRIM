<?php

return [
    'default' => env('DB_CONNECTION', 'sqlsrv'),

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
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

        // Base de contrôle partagée (comptes RH_USER) — lecture seule pour ECOPRIM,
        // utilisée uniquement pour vérifier les identifiants à la connexion.
        'master' => [
            'driver' => 'sqlsrv',
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_MASTER_DATABASE', 'dbmasterbacou'),
            'username' => env('DB_USERNAME', 'sa'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
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
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],
];
