<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use MauticPlugin\MauticWallzaIdentityBundle\EventListener\WallzaSsoSubscriber;
use MauticPlugin\MauticWallzaIdentityBundle\Integration\WallzaIntegration;
use MauticPlugin\MauticWallzaIdentityBundle\Security\WallzaSsoAssertionVerifier;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $services->load('MauticPlugin\\MauticWallzaIdentityBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, ['Tests'])).'}');

    // IntegrationHelper discovers plugin SSO integrations through this legacy service alias.
    $services->alias('mautic.integration.wallza', WallzaIntegration::class);

    $services->set(WallzaSsoAssertionVerifier::class)
        ->arg('$secret', '%env(default:mautic.wallza_sso_hmac_secret:WALLZA_SSO_HMAC_SECRET)%')
        ->arg('$issuer', '%env(default:mautic.wallza_sso_issuer:WALLZA_SSO_ISSUER)%')
        ->arg('$tenantId', '%env(default:mautic.wallza_sso_tenant_id:WALLZA_MAUTIC_TENANT_ID)%');

    $services->set(WallzaSsoSubscriber::class)
        ->arg('$authorizeUrl', '%env(default:mautic.wallza_sso_authorize_url:WALLZA_SSO_AUTHORIZE_URL)%')
        ->arg('$tokenUrl', '%env(default:mautic.wallza_sso_token_url:WALLZA_SSO_TOKEN_URL)%')
        ->arg('$tenantId', '%env(default:mautic.wallza_sso_tenant_id:WALLZA_MAUTIC_TENANT_ID)%')
        ->arg('$baseUrl', '%env(default:mautic.wallza_sso_base_url:WALLZA_MAUTIC_BASE_URL)%')
        ->arg('$issuer', '%env(default:mautic.wallza_sso_issuer:WALLZA_SSO_ISSUER)%')
        ->arg('$hmacSecret', '%env(default:mautic.wallza_sso_hmac_secret:WALLZA_SSO_HMAC_SECRET)%');
};
