<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBillionMailBundle\Tests\Unit\Transport;

use MauticPlugin\MauticBillionMailBundle\Transport\BillionMailTransportFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

final class BillionMailTransportFactoryTest extends TestCase
{
    public function testOnlyBillionMailSchemesAreSupported(): void
    {
        $factory = new BillionMailTransportFactory(new EventDispatcher(), null, new NullLogger());

        self::assertTrue($factory->supports(Dsn::fromString('billionmail+smtp://mailer:secret@mail.example.test:587')));
        self::assertTrue($factory->supports(Dsn::fromString('billionmail+smtps://mailer:secret@mail.example.test:465')));
        self::assertFalse($factory->supports(Dsn::fromString('smtp://mailer:secret@mail.example.test:587')));
    }

    public function testCreatesNativeEsmtpTransportWithConfiguredCredentials(): void
    {
        $factory = new BillionMailTransportFactory(new EventDispatcher(), null, new NullLogger());

        $transport = $factory->create(Dsn::fromString(
            'billionmail+smtp://mailer:secret@mail.example.test:587?auto_tls=true&require_tls=true&max_per_second=4'
        ));

        self::assertInstanceOf(EsmtpTransport::class, $transport);
        self::assertSame('mailer', $transport->getUsername());
        self::assertSame('secret', $transport->getPassword());
        self::assertTrue($transport->isTlsRequired());
    }

    public function testSmtpsSchemeCreatesNativeEsmtpTransport(): void
    {
        $factory = new BillionMailTransportFactory(new EventDispatcher(), null, new NullLogger());

        $transport = $factory->create(Dsn::fromString(
            'billionmail+smtps://mailer:secret@mail.example.test:465'
        ));

        self::assertInstanceOf(EsmtpTransport::class, $transport);
    }
}
