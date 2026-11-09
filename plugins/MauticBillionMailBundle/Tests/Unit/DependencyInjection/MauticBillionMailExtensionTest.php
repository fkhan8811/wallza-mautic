<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBillionMailBundle\Tests\Unit\DependencyInjection;

use MauticPlugin\MauticBillionMailBundle\DependencyInjection\MauticBillionMailExtension;
use MauticPlugin\MauticBillionMailBundle\Transport\BillionMailTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MauticBillionMailExtensionTest extends TestCase
{
    public function testRegistersTheTransportFactoryWithSymfonyMailer(): void
    {
        $container = new ContainerBuilder();
        (new MauticBillionMailExtension())->load([], $container);

        self::assertTrue($container->hasDefinition(BillionMailTransportFactory::class));
        self::assertArrayHasKey(
            'mailer.transport_factory',
            $container->getDefinition(BillionMailTransportFactory::class)->getTags()
        );
    }
}
