<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWallzaIdentityBundle\Tests\Unit\EventListener;

use Mautic\UserBundle\Entity\User;
use Mautic\UserBundle\Entity\UserRepository;
use Mautic\UserBundle\Event\AuthenticationEvent;
use Mautic\UserBundle\Security\Authentication\Token\PluginToken;
use MauticPlugin\MauticWallzaIdentityBundle\EventListener\WallzaSsoSubscriber;
use MauticPlugin\MauticWallzaIdentityBundle\Security\WallzaSsoAssertionVerifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class WallzaSsoSubscriberTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const ISSUER = 'https://api.wallza.in';
    private const TENANT = 'e48b9f6f-9c4c-4c3d-aeab-c6ed3f0ef999';
    private const AUTHORIZE_URL = 'https://auth.wallza.in/mautic/authorize';
    private const TOKEN_URL = 'https://auth.wallza.in/mautic/token';
    private const BASE_URL = 'https://mautic.example.test';

    public function testStartsLoginWithRandomSessionStateAndTrustedCallbackUrl(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/s/sso_login/Wallza');
        $request->setSession($session);
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with('mautic_sso_login_check', ['integration' => 'Wallza'], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/s/sso_login_check/Wallza');

        $event = $this->createAuthenticationEvent($request, false);
        $subscriber = $this->subscriber($router, new MockHttpClient());

        $subscriber->onUserPreAuthentication($event);

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
        $location = $event->getResponse()->getTargetUrl();
        self::assertStringStartsWith(self::AUTHORIZE_URL.'?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame('code', $query['response_type']);
        self::assertSame(self::TENANT, $query['client_id']);
        self::assertSame(self::TENANT, $query['tenant_id']);
        self::assertSame(self::BASE_URL.'/s/sso_login_check/Wallza', $query['redirect_uri']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $query['state']);
        self::assertSame($query['state'], $session->get('wallza.sso.state'));
        self::assertFalse($event->isAuthenticated());
        self::assertFalse($event->isFailed());
    }

    public function testCallbackAuthenticatesOnlyAnExistingPublishedMauticUser(): void
    {
        $state = bin2hex(random_bytes(32));
        $session = new Session(new MockArraySessionStorage());
        $session->set('wallza.sso.state', $state);
        $request = Request::create('/s/sso_login_check/Wallza?code=one-time-code&state='.$state);
        $request->setSession($session);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('/s/sso_login_check/Wallza');

        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isPublished', 'getEmail'])
            ->getMock();
        $user->method('isPublished')->willReturn(true);
        $user->method('getEmail')->willReturn('faisal@example.test');

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects(self::once())
            ->method('findOneBy')
            ->with(['username' => '6f0f8f68-85d1-4e5f-93b7-9dfacabc14ba'])
            ->willReturn($user);

        $assertion = $this->createAssertion($state);
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use ($assertion): MockResponse {
            TestCase::assertSame('POST', $method);
            TestCase::assertSame(self::TOKEN_URL, $url);
            TestCase::assertSame([self::TENANT, self::SECRET], $options['auth_basic']);

            return new MockResponse(json_encode(['assertion' => $assertion], JSON_THROW_ON_ERROR), [
                'http_code' => 200,
                'response_headers' => ['content-type: application/json'],
            ]);
        });

        $event = $this->createAuthenticationEvent($request, true);
        $subscriber = $this->subscriber($router, $httpClient, $userRepository);

        $subscriber->onUserPreAuthentication($event);

        self::assertTrue($event->isAuthenticated());
        self::assertFalse($event->isFailed());
        self::assertSame($user, $event->getUser());
        self::assertFalse($session->has('wallza.sso.state'));
    }

    public function testCallbackFailsClosedWhenTheMauticUserHasNotBeenProvisioned(): void
    {
        $state = bin2hex(random_bytes(32));
        $session = new Session(new MockArraySessionStorage());
        $session->set('wallza.sso.state', $state);
        $request = Request::create('/s/sso_login_check/Wallza?code=one-time-code&state='.$state);
        $request->setSession($session);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('/s/sso_login_check/Wallza');

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects(self::once())
            ->method('findOneBy')
            ->with(['username' => '6f0f8f68-85d1-4e5f-93b7-9dfacabc14ba'])
            ->willReturn(null);

        $assertion = $this->createAssertion($state);
        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            json_encode(['assertion' => $assertion], JSON_THROW_ON_ERROR),
            ['http_code' => 200, 'response_headers' => ['content-type: application/json']]
        ));

        $event = $this->createAuthenticationEvent($request, true);
        $subscriber = $this->subscriber($router, $httpClient, $userRepository);

        $subscriber->onUserPreAuthentication($event);

        self::assertFalse($event->isAuthenticated());
        self::assertTrue($event->isFailed());
        self::assertFalse($session->has('wallza.sso.state'));
    }

    public function testCallbackFailsClosedWhenTheProvisionedSubjectHasAnotherEmail(): void
    {
        $state = bin2hex(random_bytes(32));
        $session = new Session(new MockArraySessionStorage());
        $session->set('wallza.sso.state', $state);
        $request = Request::create('/s/sso_login_check/Wallza?code=one-time-code&state='.$state);
        $request->setSession($session);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('/s/sso_login_check/Wallza');

        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isPublished', 'getEmail'])
            ->getMock();
        $user->method('isPublished')->willReturn(true);
        $user->method('getEmail')->willReturn('different@example.test');

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects(self::once())
            ->method('findOneBy')
            ->with(['username' => '6f0f8f68-85d1-4e5f-93b7-9dfacabc14ba'])
            ->willReturn($user);

        $assertion = $this->createAssertion($state);
        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            json_encode(['assertion' => $assertion], JSON_THROW_ON_ERROR),
            ['http_code' => 200, 'response_headers' => ['content-type: application/json']]
        ));

        $event = $this->createAuthenticationEvent($request, true);
        $subscriber = $this->subscriber($router, $httpClient, $userRepository);
        $subscriber->onUserPreAuthentication($event);

        self::assertFalse($event->isAuthenticated());
        self::assertTrue($event->isFailed());
        self::assertFalse($session->has('wallza.sso.state'));
    }

    public function testCallbackRejectsStateMismatchBeforeMakingTokenRequest(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('wallza.sso.state', 'expected-state');
        $request = Request::create('/s/sso_login_check/Wallza?code=one-time-code&state=attacker-state');
        $request->setSession($session);
        $router = $this->createMock(RouterInterface::class);
        $httpClient = new MockHttpClient();
        $event = $this->createAuthenticationEvent($request, true);

        $subscriber = $this->subscriber($router, $httpClient);
        $subscriber->onUserPreAuthentication($event);

        self::assertTrue($event->isFailed());
        self::assertFalse($event->isAuthenticated());
        self::assertSame(0, $httpClient->getRequestsCount());
        self::assertFalse($session->has('wallza.sso.state'));
    }

    public function testCallbackFailsClosedForAnUnpublishedMauticUser(): void
    {
        $state = bin2hex(random_bytes(32));
        $session = new Session(new MockArraySessionStorage());
        $session->set('wallza.sso.state', $state);
        $request = Request::create('/s/sso_login_check/Wallza?code=one-time-code&state='.$state);
        $request->setSession($session);
        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('/s/sso_login_check/Wallza');

        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isPublished', 'getEmail'])
            ->getMock();
        $user->method('isPublished')->willReturn(false);
        $user->method('getEmail')->willReturn('faisal@example.test');

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->expects(self::once())
            ->method('findOneBy')
            ->with(['username' => '6f0f8f68-85d1-4e5f-93b7-9dfacabc14ba'])
            ->willReturn($user);

        $assertion = $this->createAssertion($state);
        $httpClient = new MockHttpClient(static fn (string $method, string $url, array $options): MockResponse => new MockResponse(
            json_encode(['assertion' => $assertion], JSON_THROW_ON_ERROR),
            ['http_code' => 200, 'response_headers' => ['content-type: application/json']]
        ));

        $event = $this->createAuthenticationEvent($request, true);
        $subscriber = $this->subscriber($router, $httpClient, $userRepository);
        $subscriber->onUserPreAuthentication($event);

        self::assertFalse($event->isAuthenticated());
        self::assertTrue($event->isFailed());
        self::assertFalse($session->has('wallza.sso.state'));
    }

    public function testIgnoresOtherMauticSsoIntegrations(): void
    {
        $request = Request::create('/s/sso_login/Other');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $event = new AuthenticationEvent(
            null,
            new PluginToken(null, 'Other'),
            $this->createMock(UserProviderInterface::class),
            $request,
            false,
            'Other',
            ['Other' => new \stdClass()]
        );

        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::never())->method('generate');

        $subscriber = $this->subscriber($router, new MockHttpClient());
        $subscriber->onUserPreAuthentication($event);

        self::assertFalse($event->isAuthenticated());
        self::assertFalse($event->isFailed());
    }

    private function createAuthenticationEvent(Request $request, bool $loginCheck): AuthenticationEvent
    {
        return new AuthenticationEvent(
            null,
            new PluginToken(null, 'Wallza'),
            $this->createMock(UserProviderInterface::class),
            $request,
            $loginCheck,
            'Wallza',
            ['Wallza' => new \stdClass()]
        );
    }

    private function subscriber(
        RouterInterface $router,
        MockHttpClient $httpClient,
        ?UserRepository $userRepository = null,
    ): WallzaSsoSubscriber {
        return new WallzaSsoSubscriber(
            $router,
            $httpClient,
            $userRepository ?? $this->createMock(UserRepository::class),
            new WallzaSsoAssertionVerifier(self::SECRET, self::ISSUER, self::TENANT),
            new NullLogger(),
            self::AUTHORIZE_URL,
            self::TOKEN_URL,
            self::TENANT,
            self::BASE_URL,
            self::ISSUER,
            self::SECRET
        );
    }

    private function createAssertion(string $state): string
    {
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $now = time();
        $payload = rtrim(strtr(base64_encode(json_encode([
            'iss' => self::ISSUER,
            'aud' => self::TENANT,
            'tenant_id' => self::TENANT,
            'sub' => '6f0f8f68-85d1-4e5f-93b7-9dfacabc14ba',
            'email' => 'faisal@example.test',
            'email_verified' => true,
            'first_name' => 'Faisal',
            'last_name' => 'Qureshi',
            'iat' => $now,
            'exp' => $now + 60,
            'jti' => 'jti-'.bin2hex(random_bytes(8)),
            'state' => $state,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $signingInput = $header.'.'.$payload;
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $signingInput, self::SECRET, true)), '+/', '-_'), '=');

        return $signingInput.'.'.$signature;
    }
}
