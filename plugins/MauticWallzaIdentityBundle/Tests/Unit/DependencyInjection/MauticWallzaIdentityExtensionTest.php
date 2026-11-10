<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWallzaIdentityBundle\Tests\Unit\DependencyInjection;

use MauticPlugin\MauticWallzaIdentityBundle\DependencyInjection\MauticWallzaIdentityExtension;
use MauticPlugin\MauticWallzaIdentityBundle\EventListener\WallzaSsoSubscriber;
use MauticPlugin\MauticWallzaIdentityBundle\Integration\WallzaIntegration;
use MauticPlugin\MauticWallzaIdentityBundle\Security\WallzaSsoAssertionVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MauticWallzaIdentityExtensionTest extends TestCase
{
    public function testRegistersTheWallzaIntegrationAndItsSsoServices(): void
    {
        $container = new ContainerBuilder();

        (new MauticWallzaIdentityExtension())->load([], $container);

        self::assertTrue($container->hasDefinition(WallzaIntegration::class));
        self::assertTrue($container->hasDefinition(WallzaSsoSubscriber::class));
        self::assertTrue($container->hasDefinition(WallzaSsoAssertionVerifier::class));
        self::assertTrue($container->hasAlias('mautic.integration.wallza'));
        self::assertSame(
            WallzaIntegration::class,
            (string) $container->getAlias('mautic.integration.wallza')
        );

        $subscriber = $container->getDefinition(WallzaSsoSubscriber::class);
        self::assertSame(
            '%env(default:mautic.wallza_sso_authorize_url:WALLZA_SSO_AUTHORIZE_URL)%',
            $subscriber->getArgument('$authorizeUrl')
        );
        self::assertSame(
            '%env(default:mautic.wallza_sso_token_url:WALLZA_SSO_TOKEN_URL)%',
            $subscriber->getArgument('$tokenUrl')
        );
        self::assertSame(
            '%env(default:mautic.wallza_sso_tenant_id:WALLZA_MAUTIC_TENANT_ID)%',
            $subscriber->getArgument('$tenantId')
        );
        self::assertSame(
            '%env(default:mautic.wallza_sso_base_url:WALLZA_MAUTIC_BASE_URL)%',
            $subscriber->getArgument('$baseUrl')
        );
        self::assertSame(
            '%env(default:mautic.wallza_sso_issuer:WALLZA_SSO_ISSUER)%',
            $subscriber->getArgument('$issuer')
        );
        self::assertSame(
            '%env(default:mautic.wallza_sso_hmac_secret:WALLZA_SSO_HMAC_SECRET)%',
            $subscriber->getArgument('$hmacSecret')
        );

        $verifier = $container->getDefinition(WallzaSsoAssertionVerifier::class);
        self::assertSame(
            '%env(default:mautic.wallza_sso_hmac_secret:WALLZA_SSO_HMAC_SECRET)%',
            $verifier->getArgument('$secret')
        );
        self::assertSame(
            '%env(default:mautic.wallza_sso_issuer:WALLZA_SSO_ISSUER)%',
            $verifier->getArgument('$issuer')
        );
        self::assertSame(
            '%env(default:mautic.wallza_sso_tenant_id:WALLZA_MAUTIC_TENANT_ID)%',
            $verifier->getArgument('$tenantId')
        );
    }

    public function testWallzaIntegrationAdvertisesMauticSsoServiceFeature(): void
    {
        $integration = (new \ReflectionClass(WallzaIntegration::class))->newInstanceWithoutConstructor();

        self::assertSame('Wallza', $integration->getName());
        self::assertSame('none', $integration->getAuthenticationType());
        self::assertContains('sso_service', $integration->getSupportedFeatures());
    }
}
