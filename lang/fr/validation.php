<?php

// Only the rules the sign-in and password reset forms use; anything else falls back to English.
return [
    'required' => 'Veuillez renseigner le champ :attribute.',
    'email' => ':Attribute : adresse e-mail non valide.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'string' => ':Attribute : veuillez saisir du texte.',
    'min' => [
        'string' => ':Attribute : au moins :min caractères requis.',
    ],
    'attributes' => [
        'email' => 'e-mail',
        'password' => 'mot de passe',
        'code' => 'code',
        'recovery_code' => 'code de récupération',
    ],
];
