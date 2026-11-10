<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBillionMailBundle\Tests\Integration;

use Mautic\EmailBundle\Mailer\Transport\TransportFactory as MauticTransportFactory;
use MauticPlugin\MauticBillionMailBundle\Transport\BillionMailTransportFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Transport as SymfonyTransport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mime\Email;

final class BillionMailTransportSmokeTest extends TestCase
{
    public function testSendsARealSmtpMessageThroughTheBillionMailTransport(): void
    {
        $dsn = getenv('WALLZA_BILLIONMAIL_TEST_DSN');
        if (false === $dsn || '' === $dsn) {
            $this->markTestSkipped('Set WALLZA_BILLIONMAIL_TEST_DSN to run the SMTP sink integration test.');
        }

        $factory = new BillionMailTransportFactory(new EventDispatcher(), null, new NullLogger());
        $mauticTransportFactory = new MauticTransportFactory(new SymfonyTransport([$factory]));
        $transport = $mauticTransportFactory->fromDsnObject(Dsn::fromString($dsn));

        $sentMessage = $transport->send(
            (new Email())
                ->from('wallza-smoke@example.test')
                ->to('recipient@example.test')
                ->subject('Wallza BillionMail transport smoke test')
                ->text('The BillionMail SMTP transport delivered this test message.')
        );

        $this->assertNotNull($sentMessage, 'The SMTP transport should return a sent-message result.');
    }
}
