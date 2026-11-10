<?php

declare(strict_types=1);

return [
    'name'        => 'Wallza Identity',
    'description' => 'Membership-aware single sign-on from WallzaOS to an isolated Mautic tenant',
    'version'     => '1.0',
    'author'      => 'WallzaOS',

    // Empty defaults keep the plugin safe to install before SSO is configured.
    'parameters' => [
        'wallza_sso_authorize_url' => '',
        'wallza_sso_token_url'     => '',
        'wallza_sso_tenant_id'     => '',
        'wallza_sso_hmac_secret'   => '',
        'wallza_sso_base_url'      => '',
        'wallza_sso_issuer'        => 'https://api.wallza.in',
    ],
];
