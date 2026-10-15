<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\Claims;
use App\Auth\InvalidTokenException;
use App\Auth\JwtVerifier;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class JwtVerifierTest extends TestCase
{
    private const string AUDIENCE = 'order-api';
    private const string ISSUER = 'https://idp.example';

    private static string $privateKey;

    private static string $publicKey;

    private static string $otherPrivateKey;

    private MockClock $clock;

    private JwtVerifier $verifier;

    public static function setUpBeforeClass(): void
    {
        [self::$privateKey, self::$publicKey] = self::newKeyPair();
        [self::$otherPrivateKey] = self::newKeyPair();
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-14 12:00:00');
        $this->verifier = new JwtVerifier(self::$publicKey, self::AUDIENCE, self::ISSUER, $this->clock);
    }

    /** @return array{string, string} */
    private static function newKeyPair(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key, 'openssl could not generate a key (is OPENSSL_CONF set?)');
        openssl_pkey_export($key, $private);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($private);
        self::assertIsString($details['key']);

        return [$private, $details['key']];
    }

    /**
     * Well-formed claims; a null override removes the claim.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function claims(array $overrides = []): array
    {
        $claims = [
            'sub' => 'user-1',
            'aud' => self::AUDIENCE,
            'iss' => self::ISSUER,
            'exp' => $this->clock->now()->getTimestamp() + 3600,
            'roles' => ['admin'],
        ];
        foreach ($overrides as $name => $value) {
            if ($value === null) {
                unset($claims[$name]);
            } else {
                $claims[$name] = $value;
            }
        }

        return $claims;
    }

    /** @param array<string, mixed> $claims */
    private function sign(array $claims, ?string $key = null, string $alg = 'RS256'): string
    {
        return JWT::encode($claims, $key ?? self::$privateKey, $alg);
    }

    public function test_accepts_a_well_formed_token(): void
    {
        $claims = $this->verifier->verify($this->sign($this->claims()));

        self::assertInstanceOf(Claims::class, $claims);
        self::assertSame('user-1', $claims->subject);
        self::assertTrue($claims->hasRole('admin'));
        self::assertFalse($claims->hasRole('user'));
    }

    public function test_roles_default_to_a_plain_user(): void
    {
        $claims = $this->verifier->verify($this->sign($this->claims(['roles' => null])));

        self::assertTrue($claims->hasRole('user'));
        self::assertFalse($claims->hasRole('admin'));
    }

    public function test_accepts_an_audience_given_as_a_list(): void
    {
        $claims = $this->verifier->verify($this->sign($this->claims(['aud' => ['other', self::AUDIENCE]])));

        self::assertSame('user-1', $claims->subject);
    }

    /** @return array<string, array{callable(self): string}> */
    public static function untrustworthyTokens(): array
    {
        return [
            'garbage' => [fn(self $t) => 'not-a-jwt'],
            'empty' => [fn(self $t) => ''],
            'no exp (valid forever)' => [fn(self $t) => $t->sign($t->claims(['exp' => null]))],
            'expired beyond the skew' => [fn(self $t) => $t->sign($t->claims(['exp' => $t->clock->now()->getTimestamp() - 120]))],
            'not valid yet' => [fn(self $t) => $t->sign($t->claims(['nbf' => $t->clock->now()->getTimestamp() + 120]))],
            'wrong audience' => [fn(self $t) => $t->sign($t->claims(['aud' => 'someone-else']))],
            'no audience' => [fn(self $t) => $t->sign($t->claims(['aud' => null]))],
            'wrong issuer' => [fn(self $t) => $t->sign($t->claims(['iss' => 'https://evil.example']))],
            'no issuer' => [fn(self $t) => $t->sign($t->claims(['iss' => null]))],
            'no subject' => [fn(self $t) => $t->sign($t->claims(['sub' => null]))],
            'blank subject' => [fn(self $t) => $t->sign($t->claims(['sub' => '   ']))],
            'subject too long' => [fn(self $t) => $t->sign($t->claims(['sub' => str_repeat('x', 201)]))],
            'numeric subject' => [fn(self $t) => $t->sign($t->claims(['sub' => 42]))],
            'roles as a string' => [fn(self $t) => $t->sign($t->claims(['roles' => 'admin']))],
            'roles with a number' => [fn(self $t) => $t->sign($t->claims(['roles' => ['user', 1]]))],
            'signed by another key' => [fn(self $t) => $t->sign($t->claims(), self::$otherPrivateKey)],
            'alg none' => [fn(self $t) => $t->unsigned($t->claims())],
            'HS256 signed with the public key (algorithm confusion)' => [fn(self $t) => $t->sign($t->claims(), self::$publicKey, 'HS256')],
        ];
    }

    /** @param callable(self): string $makeToken */
    #[\PHPUnit\Framework\Attributes\DataProvider('untrustworthyTokens')]
    public function test_rejects_untrustworthy_tokens(callable $makeToken): void
    {
        $this->expectException(InvalidTokenException::class);

        $this->verifier->verify($makeToken($this));
    }

    public function test_tolerates_a_clock_that_is_a_little_off(): void
    {
        $justExpired = $this->sign($this->claims(['exp' => $this->clock->now()->getTimestamp() - 10]));
        $almostValid = $this->sign($this->claims(['nbf' => $this->clock->now()->getTimestamp() + 10]));

        self::assertSame('user-1', $this->verifier->verify($justExpired)->subject);
        self::assertSame('user-1', $this->verifier->verify($almostValid)->subject);
    }

    public function test_accepts_a_subject_at_the_audit_column_limit(): void
    {
        $claims = $this->verifier->verify($this->sign($this->claims(['sub' => str_repeat('x', 200)])));

        self::assertSame(200, strlen($claims->subject));
    }

    public function test_the_failure_never_says_why(): void
    {
        try {
            $this->verifier->verify($this->sign($this->claims(['aud' => 'someone-else'])));
            self::fail('expected a rejection');
        } catch (InvalidTokenException $e) {
            self::assertSame('invalid token', $e->getMessage());
        }
    }

    public function test_does_not_leak_the_leeway_setting_to_the_rest_of_the_process(): void
    {
        $before = JWT::$leeway;

        $this->verifier->verify($this->sign($this->claims()));

        self::assertSame($before, JWT::$leeway);
        self::assertNull(JWT::$timestamp);
    }

    public function test_rejects_a_bad_configuration(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new JwtVerifier('not a key', self::AUDIENCE, self::ISSUER, $this->clock);
    }

    public function test_requires_an_audience_and_an_issuer(): void
    {
        foreach ([['', self::ISSUER], [self::AUDIENCE, '']] as [$audience, $issuer]) {
            try {
                new JwtVerifier(self::$publicKey, $audience, $issuer, $this->clock);
                self::fail('expected a configuration error');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * A token with alg=none: header and payload, empty signature.
     *
     * @param array<string, mixed> $claims
     */
    private function unsigned(array $claims): string
    {
        $encode = static fn(array $part): string => rtrim(strtr(base64_encode(json_encode($part, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $encode(['alg' => 'none', 'typ' => 'JWT']) . '.' . $encode($claims) . '.';
    }
}
