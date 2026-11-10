<?php

declare(strict_types=1);

namespace MauticPlugin\MauticWallzaIdentityBundle\Security;

final readonly class WallzaSsoAssertionVerifier
{
    public function __construct(
        private string $secret,
        private string $issuer,
        private string $tenantId,
    ) {
    }

    /**
     * Verify a compact HS256 JWT issued by the WallzaOS server after a
     * one-time authorization-code exchange.
     *
     * @return array<string, mixed>
     */
    public function verify(string $assertion, string $expectedState, ?int $now = null): array
    {
        $now ??= time();

        if (1 !== preg_match('/\\A[a-f0-9]{64}\\z/i', $this->secret)
            || 1 !== preg_match('/\\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\\z/i', $this->tenantId)
            || '' === $this->issuer
        ) {
            throw new \RuntimeException('Wallza SSO is not configured.');
        }

        if (strlen($assertion) > 8192) {
            throw new \RuntimeException('Wallza SSO assertion is too large.');
        }

        $parts = explode('.', $assertion);
        if (3 !== count($parts) || '' === $parts[0] || '' === $parts[1] || '' === $parts[2]) {
            throw new \RuntimeException('Wallza SSO assertion is malformed.');
        }

        $header    = $this->decodeJsonPart($parts[0]);
        $payload   = $this->decodeJsonPart($parts[1]);
        $signature = $this->decodeBase64Url($parts[2]);

        if ('HS256' !== ($header['alg'] ?? null) || !is_string($header['typ'] ?? null) || 'JWT' !== $header['typ']) {
            throw new \RuntimeException('Wallza SSO assertion uses an unsupported signing algorithm.');
        }

        $expectedSignature = hash_hmac('sha256', $parts[0].'.'.$parts[1], $this->secret, true);
        if (!hash_equals($expectedSignature, $signature)) {
            throw new \RuntimeException('Wallza SSO assertion signature is invalid.');
        }

        if (($payload['iss'] ?? null) !== $this->issuer
            || ($payload['aud'] ?? null) !== $this->tenantId
            || ($payload['tenant_id'] ?? null) !== $this->tenantId
            || !is_string($payload['state'] ?? null)
            || !hash_equals($expectedState, $payload['state'])
        ) {
            throw new \RuntimeException('Wallza SSO assertion has an invalid issuer, audience, tenant or state.');
        }

        $issuedAt = $payload['iat'] ?? null;
        $expires  = $payload['exp'] ?? null;
        if (!is_int($issuedAt) || !is_int($expires)
            || $issuedAt > $now + 15
            || $issuedAt < $now - 120
            || $expires <= $now
            || $expires <= $issuedAt
            || ($expires - $issuedAt) > 120
        ) {
            throw new \RuntimeException('Wallza SSO assertion is expired or outside the permitted time window.');
        }

        $subject = $payload['sub'] ?? null;
        $email   = $payload['email'] ?? null;
        $first   = $payload['first_name'] ?? null;
        $last    = $payload['last_name'] ?? null;
        $jti     = $payload['jti'] ?? null;

        if (!is_string($subject) || 1 !== preg_match('/\\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\\z/i', $subject)
            || !is_string($email) || false === filter_var($email, FILTER_VALIDATE_EMAIL)
            || true !== ($payload['email_verified'] ?? false)
            || !is_string($first) || '' === trim($first) || strlen($first) > 191
            || !is_string($last) || '' === trim($last) || strlen($last) > 191
            || !is_string($jti) || '' === trim($jti) || strlen($jti) > 191
        ) {
            throw new \RuntimeException('Wallza SSO assertion is missing verified user identity fields.');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonPart(string $part): array
    {
        try {
            $decoded  = json_decode($this->decodeBase64Url($part), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Wallza SSO assertion contains invalid JSON.', 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Wallza SSO assertion contains an invalid JSON object.');
        }

        return $decoded;
    }

    private function decodeBase64Url(string $part): string
    {
        if (1 === preg_match('/[^A-Za-z0-9_-]/', $part)) {
            throw new \RuntimeException('Wallza SSO assertion contains invalid base64url data.');
        }

        $padded = strtr($part, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded  = base64_decode($padded, true);

        if (false === $decoded) {
            throw new \RuntimeException('Wallza SSO assertion contains invalid base64url data.');
        }

        return $decoded;
    }
}
