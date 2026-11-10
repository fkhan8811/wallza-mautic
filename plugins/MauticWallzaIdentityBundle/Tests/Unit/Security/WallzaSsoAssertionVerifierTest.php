<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWallzaIdentityBundle\Tests\Unit\Security;

use MauticPlugin\MauticWallzaIdentityBundle\Security\WallzaSsoAssertionVerifier;
use PHPUnit\Framework\TestCase;

final class WallzaSsoAssertionVerifierTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const ISSUER = 'https://api.wallza.in';
    private const TENANT = 'e48b9f6f-9c4c-4c3d-aeab-c6ed3f0ef999';
    private const STATE = '5f1c8cdb4c0b4a9b8bc1cfd8f71b01b2';
    private const NOW = 1_800_000_000;

    public function testAcceptsAValidShortLivedTenantAssertion(): void
    {
        $assertion = $this->createAssertion();

        $claims = $this->verifier()->verify($assertion, self::STATE, self::NOW);

        $this->assertSame('6f0f8f68-85d1-4e5f-93b7-9dfacabc14ba', $claims['sub']);
        $this->assertSame('faisal@example.test', $claims['email']);
        $this->assertSame(self::TENANT, $claims['tenant_id']);
    }

    public function testRejectsAnInvalidSignature(): void
    {
        $assertion = $this->createAssertion([], 'another-secret-that-is-at-least-32-bytes-long');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('signature is invalid');
        $this->verifier()->verify($assertion, self::STATE, self::NOW);
    }

    public function testRejectsAClaimForAnotherTenant(): void
    {
        $assertion = $this->createAssertion(['tenant_id' => '2a7b6df3-b79c-4d87-94d1-76d2eb0db160']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('issuer, audience, tenant or state');
        $this->verifier()->verify($assertion, self::STATE, self::NOW);
    }

    public function testRejectsAnIncorrectAudience(): void
    {
        $assertion = $this->createAssertion(['aud' => 'another-tenant']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('issuer, audience, tenant or state');
        $this->verifier()->verify($assertion, self::STATE, self::NOW);
    }

    public function testRejectsAStateMismatch(): void
    {
        $assertion = $this->createAssertion(['state' => 'different-state']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('issuer, audience, tenant or state');
        $this->verifier()->verify($assertion, self::STATE, self::NOW);
    }

    public function testRejectsAnExpiredAssertion(): void
    {
        $assertion = $this->createAssertion([
            'iat' => self::NOW - 180,
            'exp' => self::NOW - 60,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expired');
        $this->verifier()->verify($assertion, self::STATE, self::NOW);
    }

    public function testRejectsAnAssertionWithAnExcessiveLifetime(): void
    {
        $assertion = $this->createAssertion([
            'iat' => self::NOW,
            'exp' => self::NOW + 121,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('time window');
        $this->verifier()->verify($assertion, self::STATE, self::NOW);
    }

    public function testRejectsAnUnverifiedEmail(): void
    {
        $assertion = $this->createAssertion(['email_verified' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('verified user identity');
        $this->verifier()->verify($assertion, self::STATE, self::NOW);
    }

    public function testRejectsANonUuidSubject(): void
    {
        $assertion = $this->createAssertion(['sub' => 'not-a-supabase-uuid']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('verified user identity');
        $this->verifier()->verify($assertion, self::STATE, self::NOW);
    }

    public function testRejectsAnUnsupportedSigningAlgorithm(): void
    {
        $assertion = $this->createAssertion([], null, ['alg' => 'none']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsupported signing algorithm');
        $this->verifier()->verify($assertion, self::STATE, self::NOW);
    }

    private function verifier(?string $secret = null): WallzaSsoAssertionVerifier
    {
        return new WallzaSsoAssertionVerifier($secret ?? self::SECRET, self::ISSUER, self::TENANT);
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<string, mixed> $headerOverrides
     */
    private function createAssertion(
        array $overrides = [],
        ?string $secret = null,
        array $headerOverrides = [],
    ): string {
        $header = array_merge(['alg' => 'HS256', 'typ' => 'JWT'], $headerOverrides);
        $payload = array_merge([
            'iss'            => self::ISSUER,
            'aud'            => self::TENANT,
            'tenant_id'      => self::TENANT,
            'sub'            => '6f0f8f68-85d1-4e5f-93b7-9dfacabc14ba',
            'email'          => 'faisal@example.test',
            'email_verified' => true,
            'first_name'     => 'Faisal',
            'last_name'      => 'Qureshi',
            'iat'            => self::NOW,
            'exp'            => self::NOW + 60,
            'jti'            => 'one-time-token-id-123',
            'state'          => self::STATE,
        ], $overrides);

        $encodedHeader = $this->base64Url(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedPayload = $this->base64Url(json_encode($payload, JSON_THROW_ON_ERROR));
        $signingInput = $encodedHeader.'.'.$encodedPayload;
        $signature = hash_hmac('sha256', $signingInput, $secret ?? self::SECRET, true);

        return $signingInput.'.'.$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
