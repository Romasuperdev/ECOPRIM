<?php

namespace App\Models\Metier;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Socle des tables métier appartenant à NEXORA — base `ecoprim`, préfixe `EP_`.
 *
 * Pourquoi une base propre : NEXORA écrit dans dix tables d'ECONOMAT qu'elle ne possède
 * pas, d'où les listes blanches de colonnes et l'interdiction de supprimer. Surtout, on ne
 * peut y ajouter ni colonne ni table — donc ni compétences, ni devoirs, ni cantine, ni
 * transport, ni santé. Tout ce qui n'existe pas déjà dans ECONOMAT vit ici.
 *
 * Partage des rôles :
 *   - ECONOMAT garde ce qu'elle possède déjà (élèves, professeurs, référentiels, notes,
 *     finances) et reste la source de vérité, lue et écrite sous liste blanche ;
 *   - `EP_*` porte tout le reste, et s'y comporte normalement — y compris la suppression.
 *
 * Conventions reprises de NEXORA LYCOLL, qui a fait ce choix dès l'origine :
 *   - clé primaire UUID, pour ne pas dépendre d'un compteur partagé entre établissements ;
 *   - dates au format ISO 8601 avec T — `datetime` SQL Server n'accepte pas les
 *     microsecondes du format Laravel, et le format ISO lève toute ambiguïté de locale.
 *
 * Les tables `console_*` antérieures gardent leur clé auto-incrémentée : elles sont déjà
 * en service et rien ne justifie de les convertir.
 */
abstract class BaseMetierModel extends Model
{
    use HasUuids;

    protected $connection = 'ecoprim';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = true;

    protected $dateFormat = 'Y-m-d\TH:i:s';
}
