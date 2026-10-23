<?php

return [
    // UI languages. Add a language by creating lang/<code>/ui.php and listing it here.
    'locales' => ['pt', 'en'],

    // Only Hub accounts whose email is in one of these domains can sign in; their user is created on the first login (no invitations).
    // AUTH_ALLOWED_DOMAIN takes one or several domains separated by commas.
    'allowed_domains' => array_values(array_filter(array_map(
        fn ($d) => strtolower(trim($d, " @\t")),
        explode(',', (string) env('AUTH_ALLOWED_DOMAIN', 'proside.pt,empirepuzzle.com'))
    ))),
];
