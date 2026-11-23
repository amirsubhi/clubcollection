<?php

// Only the rules the sign-in and password reset forms use; anything else falls back to English.
return [
    'required' => '请填写:attribute。',
    'email' => ':Attribute必须是有效的电邮地址。',
    'confirmed' => '两次输入的:attribute不一致。',
    'string' => ':Attribute必须是文字。',
    'min' => [
        'string' => ':Attribute至少需要 :min 个字符。',
    ],
    'attributes' => [
        'email' => '电邮',
        'password' => '密码',
        'code' => '验证码',
        'recovery_code' => '恢复码',
    ],
];
