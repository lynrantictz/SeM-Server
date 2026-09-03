<?php

return [
    // Document type names, payment requirements, and reminder rules are
    // database-managed. Keep only technical upload safeguards in config.
    'upload' => [
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
        'max_kilobytes' => 10240,
    ],
];
