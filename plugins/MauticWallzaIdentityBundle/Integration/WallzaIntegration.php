<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWallzaIdentityBundle\Integration;

use Mautic\PluginBundle\Integration\AbstractIntegration;

final class WallzaIntegration extends AbstractIntegration
{
    public function getName(): string
    {
        return 'Wallza';
    }

    public function getAuthenticationType(): string
    {
        return 'none';
    }

    public function getSupportedFeatures(): array
    {
        return ['sso_service'];
    }

    public function getDescription(): string
    {
        return 'Authenticate users through the trusted WallzaOS SSO service.';
    }
}
