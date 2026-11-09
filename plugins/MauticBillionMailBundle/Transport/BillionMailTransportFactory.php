<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBillionMailBundle\Transport;

use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Maps the explicit BillionMail DSN schemes to Symfony's native ESMTP transport.
 *
 * This keeps Mautic's normal message rendering, batching, queueing, metadata,
 * and transport callbacks intact; BillionMail is only the SMTP delivery hop.
 */
final class BillionMailTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {
        if (!$this->supports($dsn)) {
            throw new UnsupportedSchemeException($dsn, 'billionmail+smtp or billionmail+smtps', $this->getSupportedSchemes());
        }

        $scheme = $dsn->getScheme();
        $autoTls = filter_var($dsn->getOption('auto_tls', true), FILTER_VALIDATE_BOOL);
        $tls = 'billionmail+smtps' === $scheme ? true : ($autoTls ? null : false);
        $port = $dsn->getPort(0) ?? 0;

        $transport = new EsmtpTransport(
            $dsn->getHost(),
            $port,
            $tls,
            $this->dispatcher,
            $this->logger
        );

        $transport->setAutoTls($autoTls);
        // Require encryption unless an administrator explicitly opts out in the DSN.
        $transport->setRequireTls($dsn->getBooleanOption('require_tls', true));

        /** @var SocketStream $stream */
        $stream = $transport->getStream();
        if ('' !== ($sourceIp = (string) $dsn->getOption('source_ip', ''))) {
            $stream->setSourceIp($sourceIp);
        }

        // TLS peer verification remains enabled by default; this adapter does not
        // expose an insecure "disable certificate verification" shortcut.
        if ($user = $dsn->getUser()) {
            $transport->setUsername($user);
        }

        if (null !== ($password = $dsn->getPassword())) {
            $transport->setPassword($password);
        }

        if (null !== ($localDomain = $dsn->getOption('local_domain'))) {
            $transport->setLocalDomain((string) $localDomain);
        }

        if (null !== ($maxPerSecond = $dsn->getOption('max_per_second'))) {
            $transport->setMaxPerSecond((float) $maxPerSecond);
        }

        if (null !== ($restartThreshold = $dsn->getOption('restart_threshold'))) {
            $transport->setRestartThreshold(
                (int) $restartThreshold,
                (int) $dsn->getOption('restart_threshold_sleep', 0)
            );
        }

        if (null !== ($pingThreshold = $dsn->getOption('ping_threshold'))) {
            $transport->setPingThreshold((int) $pingThreshold);
        }

        return $transport;
    }

    protected function getSupportedSchemes(): array
    {
        return ['billionmail+smtp', 'billionmail+smtps'];
    }
}
