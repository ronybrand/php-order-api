<?php

declare(strict_types=1);

namespace App\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use stdClass;
use Throwable;

/**
 * Verifies RS256 tokens against one public key, one audience and one issuer. The service only verifies tokens,
 * it never issues them: it holds the public half of the issuer's key pair, so leaking it grants no ability to
 * mint one (ADR 0003).
 */
final readonly class JwtVerifier
{
    private const int CLOCK_SKEW_SECONDS = 30;
    private const int MAX_SUBJECT_LENGTH = 200; // created_by/updated_by/deleted_by are varchar(200)

    private Key $key;

    public function __construct(
        #[\SensitiveParameter]
        string $publicKeyPem,
        private string $audience,
        private string $issuer,
        private ClockInterface $clock,
    ) {
        if ($audience === '' || $issuer === '') {
            throw new InvalidArgumentException('audience and issuer are required');
        }
        if (openssl_pkey_get_public($publicKeyPem) === false) {
            throw new InvalidArgumentException('the JWT public key is not a valid PEM');
        }
        // The algorithm is pinned by the Key: an HS256 token "signed" with the (public) key, or alg=none, never validates.
        $this->key = new Key($publicKeyPem, 'RS256');
    }

    public static function fromFile(string $path, string $audience, string $issuer, ClockInterface $clock): self
    {
        $pem = is_file($path) ? file_get_contents($path) : false;
        if ($pem === false) {
            throw new InvalidArgumentException('cannot read the JWT public key file');
        }

        return new self($pem, $audience, $issuer, $clock);
    }

    /** @throws InvalidTokenException for every token that cannot be trusted */
    public function verify(string $token): Claims
    {
        $payload = $this->decode($token);

        // exp is optional for the library; without it a token would be valid forever.
        if (!isset($payload->exp) || !isset($payload->sub) || !is_string($payload->sub)) {
            throw new InvalidTokenException();
        }
        if (trim($payload->sub) === '' || strlen($payload->sub) > self::MAX_SUBJECT_LENGTH) {
            throw new InvalidTokenException();
        }
        if (!$this->hasExpectedAudience($payload) || ($payload->iss ?? null) !== $this->issuer) {
            throw new InvalidTokenException();
        }

        return new Claims($payload->sub, $this->roles($payload));
    }

    private function decode(string $token): stdClass
    {
        $previousLeeway = JWT::$leeway;
        $previousTimestamp = JWT::$timestamp;
        JWT::$leeway = self::CLOCK_SKEW_SECONDS;
        JWT::$timestamp = $this->clock->now()->getTimestamp();
        try {
            return JWT::decode($token, $this->key);
        } catch (Throwable) {
            throw new InvalidTokenException();
        } finally {
            JWT::$leeway = $previousLeeway;
            JWT::$timestamp = $previousTimestamp;
        }
    }

    private function hasExpectedAudience(stdClass $payload): bool
    {
        $audience = $payload->aud ?? null;

        return is_string($audience) ? $audience === $this->audience : (is_array($audience) && in_array($this->audience, $audience, true));
    }

    /** @return list<string> */
    private function roles(stdClass $payload): array
    {
        if (!property_exists($payload, 'roles')) {
            return [Claims::ROLE_USER];
        }
        $roles = $payload->roles;
        if (!is_array($roles)) {
            throw new InvalidTokenException();
        }
        foreach ($roles as $role) {
            if (!is_string($role)) {
                throw new InvalidTokenException();
            }
        }

        return array_values($roles);
    }
}
