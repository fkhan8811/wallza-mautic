<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBillionMailBundle\Tests\Unit\Transport;

use Mautic\CoreBundle\Helper\Dsn\Dsn as MauticDsn;
use Mautic\EmailBundle\DependencyInjection\EnvProcessor\MailerDsnEnvVarProcessor;
use MauticPlugin\MauticBillionMailBundle\Transport\BillionMailTransportFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
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
        $this->assertSame('marketing@example.com', $transport->getUsername());
    }

    public function testOnlyBillionMailSchemesAreSupported(): void
    {
        $factory = $this->factory();

        $this->assertTrue($factory->supports(Dsn::fromString('billionmail+smtp://mailer:secret@mail.example.test:587')));
        $this->assertTrue($factory->supports(Dsn::fromString('billionmail+smtps://mailer:secret@mail.example.test:465')));
        $this->assertFalse($factory->supports(Dsn::fromString('smtp://mailer:secret@mail.example.test:587')));
    }

    public function testCreatesStartTlsTransportWithConfiguredCredentialsAndSecureDefaults(): void
    {
        $transport = $this->factory()->create(Dsn::fromString(
            'billionmail+smtp://mailer:secret@mail.example.test:587?auto_tls=true&max_per_second=4'
        ));

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame('mailer', $transport->getUsername());
        $this->assertSame('secret', $transport->getPassword());
        $this->assertTrue($transport->isTlsRequired());

        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);
        $this->assertSame('mail.example.test', $stream->getHost());
        $this->assertSame(587, $stream->getPort());
    }

    public function testSmtpsSchemeCreatesImplicitTlsTransport(): void
    {
        $transport = $this->factory()->create(Dsn::fromString(
            'billionmail+smtps://mailer:secret@mail.example.test:465'
        ));

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertTrue($transport->isTlsRequired());

        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);
        $this->assertSame(465, $stream->getPort());
        $this->assertTrue($stream->isTLS());
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
