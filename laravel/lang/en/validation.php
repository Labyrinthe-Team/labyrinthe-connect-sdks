<?php

declare(strict_types=1);

return [
    'phone' => 'The :attribute field must be a valid phone number.',
    'otp' => [
        'missing' => 'The verification code has expired. Please request a new one.',
        'invalid_code' => 'The :attribute field is incorrect.',
        'expired' => 'The :attribute field has expired. Please request a new code.',
        'already_used' => 'This code has already been used. Please request a new one.',
        'max_attempts_reached' => 'Too many incorrect attempts. Please request a new code.',
        'unknown' => 'The :attribute field could not be verified.',
    ],
];
