<?php

declare(strict_types=1);

return [
    'phone' => 'Le champ :attribute doit être un numéro de téléphone valide.',
    'otp' => [
        'missing' => 'Le code de vérification a expiré. Demandez-en un nouveau.',
        'invalid_code' => 'Le champ :attribute est incorrect.',
        'invalid_code_attempts' => '{0} Le champ :attribute est incorrect. Plus aucune tentative.|{1} Le champ :attribute est incorrect. Encore 1 tentative.|[2,*] Le champ :attribute est incorrect. Encore :attempts tentatives.',
        'expired' => 'Le champ :attribute a expiré. Demandez un nouveau code.',
        'already_used' => 'Ce code a déjà été utilisé. Demandez-en un nouveau.',
        'max_attempts_reached' => 'Trop de tentatives incorrectes. Demandez un nouveau code.',
        'unknown' => 'Le champ :attribute n’a pas pu être vérifié.',
    ],
];
