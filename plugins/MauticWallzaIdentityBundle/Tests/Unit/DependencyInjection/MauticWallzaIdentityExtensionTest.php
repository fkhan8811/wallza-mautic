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
    public function testRegistersTheWallzaIntegrationAndItsSsoServicesWithSafeDefaults(): void
    {
        $container = new ContainerBuilder();

        (new MauticWallzaIdentityExtension())->load([], $container);

        $this->assertTrue($container->hasDefinition(WallzaIntegration::class));
        $this->assertTrue($container->hasDefinition(WallzaSsoSubscriber::class));
        $this->assertTrue($container->hasDefinition(WallzaSsoAssertionVerifier::class));
        $this->assertTrue($container->hasAlias('mautic.integration.wallza'));
        $this->assertSame(
            WallzaIntegration::class,
            (string) $container->getAlias('mautic.integration.wallza')
        );

        $this->assertSame('', $container->getParameter('wallza_sso_authorize_url'));
        $this->assertSame('', $container->getParameter('wallza_sso_token_url'));
        $this->assertSame('', $container->getParameter('wallza_sso_tenant_id'));
        $this->assertSame('', $container->getParameter('wallza_sso_hmac_secret'));
        $this->assertSame('', $container->getParameter('wallza_sso_base_url'));
        $this->assertSame('https://api.wallza.in', $container->getParameter('wallza_sso_issuer'));

        $subscriber = $container->getDefinition(WallzaSsoSubscriber::class);
        $this->assertSame(
            '%env(default:wallza_sso_authorize_url:WALLZA_SSO_AUTHORIZE_URL)%',
            $subscriber->getArgument('$authorizeUrl')
        );
        $this->assertSame(
            '%env(default:wallza_sso_token_url:WALLZA_SSO_TOKEN_URL)%',
            $subscriber->getArgument('$tokenUrl')
        );
        $this->assertSame(
            '%env(default:wallza_sso_tenant_id:WALLZA_MAUTIC_TENANT_ID)%',
            $subscriber->getArgument('$tenantId')
        );
        $this->assertSame(
            '%env(default:wallza_sso_base_url:WALLZA_MAUTIC_BASE_URL)%',
            $subscriber->getArgument('$baseUrl')
        );
        $this->assertSame(
            '%env(default:wallza_sso_issuer:WALLZA_SSO_ISSUER)%',
            $subscriber->getArgument('$issuer')
        );
        $this->assertSame(
            '%env(default:wallza_sso_hmac_secret:WALLZA_SSO_HMAC_SECRET)%',
            $subscriber->getArgument('$hmacSecret')
        );

        $verifier = $container->getDefinition(WallzaSsoAssertionVerifier::class);
        $this->assertSame(
            '%env(default:wallza_sso_hmac_secret:WALLZA_SSO_HMAC_SECRET)%',
            $verifier->getArgument('$secret')
        );
        $this->assertSame(
            '%env(default:wallza_sso_issuer:WALLZA_SSO_ISSUER)%',
            $verifier->getArgument('$issuer')
        );
        $this->assertSame(
            '%env(default:wallza_sso_tenant_id:WALLZA_MAUTIC_TENANT_ID)%',
            $verifier->getArgument('$tenantId')
        );
    }

    public function testWallzaIntegrationAdvertisesMauticSsoServiceFeature(): void
    {
        $integration = (new \ReflectionClass(WallzaIntegration::class))->newInstanceWithoutConstructor();

        $this->assertSame('Wallza', $integration->getName());
        $this->assertSame('none', $integration->getAuthenticationType());
        $this->assertContains('sso_service', $integration->getSupportedFeatures());
    }
}
