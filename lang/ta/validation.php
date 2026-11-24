<?php

// Only the rules the sign-in and password reset forms use; anything else falls back to English.
return [
    'required' => ':attribute நிரப்பப்பட வேண்டும்.',
    'email' => ':Attribute சரியான மின்னஞ்சல் முகவரியாக இருக்க வேண்டும்.',
    'confirmed' => ':attribute உறுதிப்படுத்தல் பொருந்தவில்லை.',
    'string' => ':Attribute உரையாக இருக்க வேண்டும்.',
    'min' => [
        'string' => ':Attribute குறைந்தது :min எழுத்துகள் கொண்டிருக்க வேண்டும்.',
    ],
    'attributes' => [
        'email' => 'மின்னஞ்சல்',
        'password' => 'கடவுச்சொல்',
        'code' => 'குறியீடு',
        'recovery_code' => 'மீட்புக் குறியீடு',
    ],
];
