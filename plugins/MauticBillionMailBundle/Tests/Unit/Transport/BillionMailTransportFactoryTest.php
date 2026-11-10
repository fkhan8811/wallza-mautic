<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBillionMailBundle\Tests\Unit\Transport;

use Mautic\CoreBundle\Helper\Dsn\Dsn as MauticDsn;
use Mautic\EmailBundle\DependencyInjection\EnvProcessor\MailerDsnEnvVarProcessor;
use Mautic\EmailBundle\Mailer\Transport\TransportFactory as MauticTransportFactory;
use MauticPlugin\MauticBillionMailBundle\Transport\BillionMailTransportFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport as SymfonyTransport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

final class BillionMailTransportFactoryTest extends TestCase
{
    private function factory(): BillionMailTransportFactory
    {
        return new BillionMailTransportFactory(new EventDispatcher(), null, new NullLogger());
    }

    public function testMauticMailerDsnEnvironmentProcessorAcceptsTheCustomScheme(): void
    {
        $dsn = 'billionmail+smtp://marketing%40example.com:secret@mail.example.test:587?auto_tls=true&require_tls=true';
        $processor = new MailerDsnEnvVarProcessor();

        $processed = $processor->getEnv(
            'mailer',
            'MAUTIC_MAILER_DSN',
            static fn (string $name): string => $dsn
        );

        $this->assertSame($dsn, $processed);
        $parsed = MauticDsn::fromString($processed);
        $this->assertSame('billionmail+smtp', $parsed->getScheme());
        $this->assertSame('marketing@example.com', $parsed->getUser());

        $transport = $this->factory()->create(Dsn::fromString($processed));
        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame('marketing@example.com', $transport->getUsername());
    }

    public function testMauticTransportFactoryResolvesBillionMailDsn(): void
    {
        $transportFactory = new MauticTransportFactory(
            new SymfonyTransport([$this->factory()])
        );

        $transport = $transportFactory->fromDsnObject(Dsn::fromString(
            'billionmail+smtp://mailer:secret@mail.example.test:587'
        ));

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame('mailer', $transport->getUsername());
        $this->assertTrue($transport->isTlsRequired());
    }

    public function testOnlyBillionMailSchemesAreSupported(): void
    {
        $factory = $this->factory();

        $this->assertTrue($factory->supports(Dsn::fromString('billionmail+smtp://mailer:secret@mail.example.test:587')));
        $this->assertTrue($factory->supports(Dsn::fromString('billionmail+smtps://mailer:secret@mail.example.test:465')));
        $this->assertFalse($factory->supports(Dsn::fromString('smtp://mailer:secret@mail.example.test:587')));
    }

    public function testRejectsUnsupportedTransportSchemes(): void
    {
        $this->expectException(UnsupportedSchemeException::class);
        $this->factory()->create(Dsn::fromString('smtp://mailer:secret@mail.example.test:587'));
    }

    public function testCreatesStartTlsTransportWithConfiguredCredentialsAndSecureDefaults(): void
    {
        $transport = $this->factory()->create(Dsn::fromString(
            'billionmail+smtp://mailer:secret@mail.example.test:587?max_per_second=4&local_domain=wallza.example&source_ip=192.0.2.10&restart_threshold=50&restart_threshold_sleep=5&ping_threshold=30'
        ));

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame('mailer', $transport->getUsername());
        $this->assertSame('secret', $transport->getPassword());
        $this->assertTrue($transport->isTlsRequired());
        $this->assertTrue($transport->isAutoTls());
        $this->assertSame('wallza.example', $transport->getLocalDomain());

        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);
        $this->assertSame('mail.example.test', $stream->getHost());
        $this->assertSame(587, $stream->getPort());
        $this->assertFalse($stream->isTLS());
        $this->assertSame('192.0.2.10', $stream->getSourceIp());
    }

    public function testSmtpsSchemeCreatesImplicitTlsTransport(): void
    {
        $transport = $this->factory()->create(Dsn::fromString(
            'billionmail+smtps://mailer:secret@mail.example.test:465'
        ));

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertTrue($transport->isTlsRequired());
        $this->assertFalse($transport->isAutoTls());

        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);
        $this->assertSame(465, $stream->getPort());
        $this->assertTrue($stream->isTLS());
    }

    public function testUsesSchemeSpecificDefaultPorts(): void
    {
        $startTls = $this->factory()->create(Dsn::fromString('billionmail+smtp://mailer:secret@mail.example.test'));
        $implicitTls = $this->factory()->create(Dsn::fromString('billionmail+smtps://mailer:secret@mail.example.test'));

        $this->assertInstanceOf(EsmtpTransport::class, $startTls);
        $this->assertInstanceOf(EsmtpTransport::class, $implicitTls);

        $startTlsStream = $startTls->getStream();
        $implicitTlsStream = $implicitTls->getStream();

        $this->assertInstanceOf(SocketStream::class, $startTlsStream);
        $this->assertInstanceOf(SocketStream::class, $implicitTlsStream);
        $this->assertSame(587, $startTlsStream->getPort());
        $this->assertFalse($startTlsStream->isTLS());
        $this->assertSame(465, $implicitTlsStream->getPort());
        $this->assertTrue($implicitTlsStream->isTLS());
    }

    public function testTlsRequirementCanOnlyBeDisabledExplicitly(): void
    {
        $transport = $this->factory()->create(Dsn::fromString(
            'billionmail+smtp://mailer:secret@mail.example.test:25?auto_tls=false&require_tls=false'
        ));

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertFalse($transport->isTlsRequired());
    }
}
