<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWallzaIdentityBundle\EventListener;

use Mautic\UserBundle\Entity\User;
use Mautic\UserBundle\Entity\UserRepository;
use Mautic\UserBundle\Event\AuthenticationEvent;
use Mautic\UserBundle\UserEvents;
use MauticPlugin\MauticWallzaIdentityBundle\Security\WallzaSsoAssertionVerifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Throwable;

final readonly class WallzaSsoSubscriber implements EventSubscriberInterface
{
    private const INTEGRATION_NAME = 'Wallza';
    private const SESSION_STATE_KEY = 'wallza.sso.state';

    public function __construct(
        private RouterInterface $router,
        private HttpClientInterface $httpClient,
        private UserRepository $userRepository,
        private WallzaSsoAssertionVerifier $assertionVerifier,
        private LoggerInterface $logger,
        private string $authorizeUrl,
        private string $tokenUrl,
        private string $tenantId,
        private string $baseUrl,
        private string $hmacSecret,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            UserEvents::USER_PRE_AUTHENTICATION => ['onUserPreAuthentication', 500],
        ];
    }

    public function onUserPreAuthentication(AuthenticationEvent $event): void
    {
        if (self::INTEGRATION_NAME !== $event->getAuthenticatingService()) {
            return;
        }

        if (!$event->getIntegration(self::INTEGRATION_NAME)) {
            $this->fail($event);

            return;
        }

        try {
            if (!$event->isLoginCheck()) {
                $this->startLogin($event);

                return;
            }

            $this->completeLogin($event);
        } catch (Throwable $exception) {
            // Never log authorization codes, JWT assertions, or secret values.
            $this->logger->warning('Wallza SSO authentication failed.', ['exception' => $exception::class]);
            $this->fail($event);
        }
    }

    private function startLogin(AuthenticationEvent $event): void
    {
        $this->assertConfigured();
        $request = $event->getRequest();
        $state   = bin2hex(random_bytes(32));
        $request->getSession()->set(self::SESSION_STATE_KEY, $state);

        $callbackPath = $this->router->generate(
            'mautic_sso_login_check',
            ['integration' => self::INTEGRATION_NAME],
            UrlGeneratorInterface::ABSOLUTE_PATH
        );
        $callbackUrl = rtrim($this->baseUrl, '/').$callbackPath;

        $query = http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->tenantId,
            'tenant_id'     => $this->tenantId,
            'redirect_uri'  => $callbackUrl,
            'state'         => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        $separator = str_contains($this->authorizeUrl, '?') ? '&' : '?';
        $event->setResponse(new RedirectResponse($this->authorizeUrl.$separator.$query));
    }

    private function completeLogin(AuthenticationEvent $event): void
    {
        $this->assertConfigured();

        $request = $event->getRequest();
        $session = $request->getSession();
        $expectedState = $session->get(self::SESSION_STATE_KEY);
        $actualState   = $request->query->get('state');
        $code          = $request->query->get('code');

        // Consume the state before making an outbound request so the callback cannot be replayed.
        $session->remove(self::SESSION_STATE_KEY);

        if (!is_string($expectedState) || '' === $expectedState
            || !is_string($actualState) || !hash_equals($expectedState, $actualState)
            || !is_string($code) || '' === $code || strlen($code) > 512
        ) {
            throw new \RuntimeException('Wallza SSO callback state or code is invalid.');
        }

        $callbackPath = $this->router->generate(
            'mautic_sso_login_check',
            ['integration' => self::INTEGRATION_NAME],
            UrlGeneratorInterface::ABSOLUTE_PATH
        );
        $callbackUrl = rtrim($this->baseUrl, '/').$callbackPath;

        $response = $this->httpClient->request('POST', $this->tokenUrl, [
            'headers'      => ['Accept' => 'application/json'],
            'auth_basic'   => [$this->tenantId, $this->hmacSecret],
            'json'         => [
                'grant_type'  => 'authorization_code',
                'code'        => $code,
                'client_id'   => $this->tenantId,
                'redirect_uri'=> $callbackUrl,
                'state'       => $expectedState,
            ],
            'timeout'      => 8,
            'max_redirects'=> 0,
        ]);

        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException('Wallza SSO token exchange was rejected.');
        }

        $body = $response->toArray(false);
        $assertion = $body['assertion'] ?? null;
        if (!is_string($assertion) || '' === $assertion) {
            throw new \RuntimeException('Wallza SSO token response did not contain an assertion.');
        }

        $claims = $this->assertionVerifier->verify($assertion, $expectedState);
        $email  = strtolower(trim($claims['email']));

        // User accounts are provisioned through the tenant's protected Mautic API credential.
        // A browser SSO assertion cannot create users or assign Mautic roles.
        $user = $this->userRepository->findOneBy(['email' => $email]);
        if (!$user instanceof User || !$user->isPublished()) {
            throw new \RuntimeException('The Wallza user is not provisioned or enabled in this Mautic tenant.');
        }

        // Keep the Mautic role assigned by the tenant administrator. Never elevate from an IdP role claim.
        $event->setIsAuthenticated(self::INTEGRATION_NAME, $user, false);
    }

    private function assertConfigured(): void
    {
        $this->assertHttpsUrl($this->authorizeUrl);
        $this->assertHttpsUrl($this->tokenUrl);
        $this->assertHttpsUrl($this->baseUrl);

        if (1 !== preg_match('/\\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\\z/i', $this->tenantId)
            || 1 !== preg_match('/\\A[a-f0-9]{64}\\z/i', $this->hmacSecret)
        ) {
            throw new \RuntimeException('Wallza SSO tenant configuration is incomplete.');
        }
    }

    private function assertHttpsUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || 'https' !== strtolower((string) ($parts['scheme'] ?? ''))
            || '' === (string) ($parts['host'] ?? '')
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        ) {
            throw new \RuntimeException('Wallza SSO endpoints and Mautic base URL must be trusted HTTPS URLs.');
        }
    }

    private function fail(AuthenticationEvent $event): void
    {
        $event->setFailedAuthenticationMessage('Wallza SSO authentication failed. Please sign in again.');
        $event->setIsFailedAuthentication();
    }
}
