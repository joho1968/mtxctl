<?php

declare(strict_types=1);

// Copy this file to config.php and fill in your values.

return [
    // Base URL of your Synapse homeserver (no trailing slash).
    'homeserver_url' => 'https://matrix.domain.eu',

    // Access token for a Synapse admin user (not the AS token).
    // Obtain with:
    //   curl -XPOST 'https://matrix.example.com/_matrix/client/v3/login' \
    //     -H 'Content-Type: application/json' \
    //     -d '{"type":"m.login.password","user":"admin","password":"..."}'
    'admin_token' => 'syt...',
];
