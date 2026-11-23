<?php

// Only the rules the sign-in and password reset forms use; anything else falls back to English.
return [
    'required' => 'Indique :attribute.',
    'email' => ':Attribute debe ser una dirección de correo electrónico válida.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'string' => ':Attribute debe ser texto.',
    'min' => [
        'string' => ':Attribute debe tener al menos :min caracteres.',
    ],
    'attributes' => [
        'email' => 'correo electrónico',
        'password' => 'contraseña',
        'code' => 'código',
        'recovery_code' => 'código de recuperación',
    ],
];
