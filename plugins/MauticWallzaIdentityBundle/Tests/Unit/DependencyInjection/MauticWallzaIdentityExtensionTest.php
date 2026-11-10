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
    }

    public function testWallzaIntegrationAdvertisesMauticSsoServiceFeature(): void
    {
        $integration = (new \ReflectionClass(WallzaIntegration::class))->newInstanceWithoutConstructor();

        self::assertSame('Wallza', $integration->getName());
        self::assertSame('none', $integration->getAuthenticationType());
        self::assertContains('sso_service', $integration->getSupportedFeatures());
    }
}
