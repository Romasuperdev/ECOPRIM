<?php

/*
|--------------------------------------------------------------------------
| Messages de validation
|--------------------------------------------------------------------------
|
| L'application est entièrement en français, mais Laravel répondait en anglais :
| aucun fichier de langue n'existait et la locale était restée sur `en`. Un
| utilisateur lisait donc « The photo failed to upload. » au milieu d'un écran
| français, sans savoir quoi corriger.
|
| Les règles réellement utilisées par l'application sont traduites ici. Celles qui
| manqueraient retombent sur l'anglais du framework (fallback_locale) : mieux vaut
| une phrase anglaise isolée qu'une clé brute affichée à l'écran.
|
*/

return [

    'accepted' => 'Le champ :attribute doit être accepté.',
    'active_url' => "Le champ :attribute n'est pas une URL valide.",
    'after' => 'Le champ :attribute doit être une date postérieure au :date.',
    'after_or_equal' => 'Le champ :attribute doit être une date postérieure ou égale au :date.',
    'array' => 'Le champ :attribute doit être une liste.',
    'before' => 'Le champ :attribute doit être une date antérieure au :date.',
    'before_or_equal' => 'Le champ :attribute doit être une date antérieure ou égale au :date.',
    'boolean' => 'Le champ :attribute doit être vrai ou faux.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'date' => "Le champ :attribute n'est pas une date valide.",
    'date_format' => 'Le champ :attribute ne respecte pas le format :format.',
    'different' => 'Les champs :attribute et :other doivent être différents.',
    'digits' => 'Le champ :attribute doit comporter :digits chiffres.',
    'email' => 'Le champ :attribute doit être une adresse email valide.',
    'exists' => 'La valeur du champ :attribute est inconnue.',
    'file' => 'Le champ :attribute doit être un fichier.',
    'filled' => 'Le champ :attribute doit avoir une valeur.',
    'image' => 'Le champ :attribute doit être une image.',
    'in' => 'La valeur du champ :attribute ne fait pas partie des choix possibles.',
    'integer' => 'Le champ :attribute doit être un nombre entier.',
    'mimes' => 'Le champ :attribute doit être un fichier de type : :values.',
    'mimetypes' => 'Le champ :attribute doit être un fichier de type : :values.',
    'not_in' => "La valeur du champ :attribute n'est pas autorisée.",
    'numeric' => 'Le champ :attribute doit être un nombre.',
    'present' => 'Le champ :attribute doit être présent.',
    'prohibited' => "Le champ :attribute n'est pas autorisé.",
    'regex' => 'Le format du champ :attribute est invalide.',
    'required' => 'Le champ :attribute est obligatoire.',
    'required_if' => 'Le champ :attribute est obligatoire quand :other vaut :value.',
    'required_with' => 'Le champ :attribute est obligatoire quand :values est renseigné.',
    'same' => 'Les champs :attribute et :other doivent être identiques.',
    'string' => 'Le champ :attribute doit être du texte.',
    'unique' => 'La valeur du champ :attribute est déjà utilisée.',
    // Déclenchée quand PHP a écarté le fichier AVANT Laravel : au-delà de
    // `upload_max_filesize`, l'envoi n'aboutit jamais et la taille est la première
    // cause à regarder. La règle `max` ne peut rien dire dans ce cas : elle n'est
    // même pas atteinte.
    'uploaded' => "L'envoi du fichier :attribute a échoué. Il dépasse probablement ce que le serveur accepte.",
    'url' => 'Le champ :attribute doit être une URL valide.',

    'max' => [
        'array' => 'Le champ :attribute ne peut pas contenir plus de :max éléments.',
        'file' => 'Le fichier :attribute ne doit pas dépasser :max kilo-octets.',
        'numeric' => 'Le champ :attribute ne peut pas être supérieur à :max.',
        'string' => 'Le champ :attribute ne peut pas dépasser :max caractères.',
    ],

    'min' => [
        'array' => 'Le champ :attribute doit contenir au moins :min éléments.',
        'file' => 'Le fichier :attribute doit faire au moins :min kilo-octets.',
        'numeric' => 'Le champ :attribute doit être au moins :min.',
        'string' => 'Le champ :attribute doit comporter au moins :min caractères.',
    ],

    'size' => [
        'array' => 'Le champ :attribute doit contenir :size éléments.',
        'file' => 'Le fichier :attribute doit faire :size kilo-octets.',
        'numeric' => 'Le champ :attribute doit valoir :size.',
        'string' => 'Le champ :attribute doit comporter :size caractères.',
    ],

    'between' => [
        'array' => 'Le champ :attribute doit contenir entre :min et :max éléments.',
        'file' => 'Le fichier :attribute doit faire entre :min et :max kilo-octets.',
        'numeric' => 'Le champ :attribute doit être compris entre :min et :max.',
        'string' => 'Le champ :attribute doit comporter entre :min et :max caractères.',
    ],

    /*
    | Noms affichés des champs. Sans eux, le message rendrait le nom technique —
    | « Le champ classe_code est obligatoire » — que personne ne reconnaît à l'écran.
    */
    'attributes' => [
        'matricule' => 'matricule',
        'nom' => 'nom',
        'prenom' => 'prénom',
        'sexe' => 'sexe',
        'date_naissance' => 'date de naissance',
        'lieu_naissance' => 'lieu de naissance',
        'nationalite' => 'nationalité',
        'adresse' => 'adresse',
        'ville' => 'ville',
        'commune' => 'commune',
        'quartier' => 'quartier',
        'telephone' => 'téléphone',
        'email' => 'email',
        'annee' => 'année scolaire',
        'niveau_code' => 'niveau',
        'classe_code' => 'classe',
        'classe' => 'classe',
        'matiere' => 'matière',
        'matieres' => 'matières',
        'mouvement' => 'type de mouvement',
        'redoublant' => 'redoublant',
        'date_inscription' => "date d'inscription",
        'etab_origine' => "établissement d'origine",
        'niveau_origine' => "niveau d'origine",
        'photo' => 'photo',
        'pere_nom' => 'nom du père ou tuteur',
        'pere_prenom' => 'prénom du père ou tuteur',
        'pere_telephone' => 'téléphone du père ou tuteur',
        'pere_email' => 'email du père ou tuteur',
        'mere_nom' => 'nom de la mère',
        'mere_prenom' => 'prénom de la mère',
        'mere_telephone' => 'téléphone de la mère',
        'mere_email' => 'email de la mère',
        'note' => 'note',
        'bareme' => 'barème',
        'coefficient' => 'coefficient',
        'note_maximale' => 'note maximale',
        'session' => 'session',
        'type' => 'type',
        'titre' => 'titre',
        'date' => 'date',
        'heure_debut' => 'heure de début',
        'heure_fin' => 'heure de fin',
        'libelle' => 'libellé',
        'code' => 'code',
        'fichier' => 'fichier',
        'jeton' => 'jeton',
        'lignes' => 'lignes',
        'enseignant' => 'enseignant',
        'etablissement' => 'établissement',
    ],

];
