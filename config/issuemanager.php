<?php

return [
    // UI languages. Add a language by creating lang/<code>/ui.php and listing it here.
    'locales' => ['pt', 'en'],

    // Only Hub accounts whose email is in this domain can sign in; their user is created on the first login (no invitations).
    'allowed_domain' => strtolower(trim((string) env('AUTH_ALLOWED_DOMAIN', 'proside.pt'), " @\t")),
];
