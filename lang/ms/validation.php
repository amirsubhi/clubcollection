<?php

// Only the rules the sign-in and password reset forms use; anything else falls back to English.
return [
    'required' => 'Sila isi :attribute.',
    'email' => ':Attribute mesti alamat e-mel yang sah.',
    'confirmed' => 'Pengesahan :attribute tidak sepadan.',
    'string' => ':Attribute mesti teks.',
    'min' => [
        'string' => ':Attribute mesti sekurang-kurangnya :min aksara.',
    ],
    'attributes' => [
        'email' => 'e-mel',
        'password' => 'kata laluan',
        'code' => 'kod',
        'recovery_code' => 'kod pemulihan',
    ],
];
