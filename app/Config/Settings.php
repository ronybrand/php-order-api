<?php

declare(strict_types=1);

namespace App\Config;

/**
 * The service settings read from environment variables, with the production rules that make an unsafe
 * combination fail at startup. Parsing and validation are pure so they are tested without the framework.
 */
final readonly class Settings
{
    public const string DEVELOPMENT = 'development';
    public const string PRODUCTION = 'production';

    /**
     * @param list<string> $corsOrigins
     */
    private function __construct(
        public string $environment,
        public string $jwtPublicKeyPath,
        public string $jwtAudience,
        public string $jwtIssuer,
        public array $corsOrigins,
        public int $maxBodyBytes,
        public int $rateLimitPerUser,
        public int $rateLimitWrites,
        public int $rateLimitPerIp,
        public string $brokerUrl,
        public string $smtpHost,
        public string $smtpFrom,
    ) {}

    /** @param array<string, string|null> $env */
    public static function fromEnvironment(array $env): self
    {
        $errors = [];
        $string = static fn(string $key, string $default = ''): string => isset($env[$key]) && trim($env[$key]) !== '' ? trim($env[$key]) : $default;
        $integer = static function (string $key, int $default) use ($string, &$errors): int {
            $raw = $string($key);
            if ($raw === '') {
                return $default;
            }
            if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
                $errors[] = "{$key} must be an integer";

                return $default;
            }

            return (int) $raw;
        };

        $origins = array_values(array_filter(array_map('trim', explode(',', $string('CORS_ORIGINS'))), static fn(string $o): bool => $o !== ''));

        $settings = new self(
            $string('APP_ENV', self::DEVELOPMENT),
            $string('JWT_PUBLIC_KEY_PATH'),
            $string('JWT_AUDIENCE'),
            $string('JWT_ISSUER'),
            $origins,
            $integer('MAX_BODY_BYTES', 1_048_576),
            $integer('RATE_LIMIT_PER_USER', 120),
            $integer('RATE_LIMIT_WRITES', 30),
            $integer('RATE_LIMIT_PER_IP', 600),
            $string('BROKER_URL'),
            $string('SMTP_HOST'),
            $string('SMTP_FROM'),
        );
        self::refuse($errors);

        return $settings;
    }

    public function isProduction(): bool
    {
        return $this->environment === self::PRODUCTION;
    }

    /** STARTTLS is mandatory in production: credentials and mail never travel in clear text. */
    public function smtpRequireTls(): bool
    {
        return $this->isProduction();
    }

    /** What both processes need. @throws InvalidConfiguration */
    public function assertValid(): void
    {
        $errors = [];
        if (!in_array($this->environment, [self::DEVELOPMENT, self::PRODUCTION], true)) {
            $errors[] = 'APP_ENV must be "development" or "production"';
        }
        if ($this->maxBodyBytes < 1) {
            $errors[] = 'MAX_BODY_BYTES must be positive';
        }
        if ($this->isProduction()) {
            array_push($errors, ...$this->productionErrors());
        }
        self::refuse($errors);
    }

    /** What only the HTTP API needs. @throws InvalidConfiguration */
    public function assertValidForApi(): void
    {
        $errors = [];
        if ($this->jwtPublicKeyPath === '') {
            $errors[] = 'JWT_PUBLIC_KEY_PATH is required';
        }
        if ($this->jwtAudience === '' || $this->jwtIssuer === '') {
            $errors[] = 'JWT_AUDIENCE and JWT_ISSUER are required';
        }
        if ($this->rateLimitPerUser < 1 || $this->rateLimitWrites < 1 || $this->rateLimitPerIp < 1) {
            $errors[] = 'rate limits must be positive';
        }
        self::refuse($errors);
    }

    /** What only the notification worker needs. @throws InvalidConfiguration */
    public function assertValidForWorker(): void
    {
        $errors = [];
        if ($this->brokerUrl === '') {
            $errors[] = 'BROKER_URL is required';
        }
        if ($this->smtpHost === '' || $this->smtpFrom === '') {
            $errors[] = 'SMTP_HOST and SMTP_FROM are required';
        }
        self::refuse($errors);
    }

    /** @return list<string> */
    private function productionErrors(): array
    {
        $errors = [];
        if ($this->jwtAudience === '') {
            $errors[] = 'JWT_AUDIENCE is required in production';
        }
        if ($this->jwtIssuer === '') {
            $errors[] = 'JWT_ISSUER is required in production';
        }
        if ($this->corsOrigins === []) {
            $errors[] = 'CORS_ORIGINS must list the allowed origins in production';
        }
        if (in_array('*', $this->corsOrigins, true)) {
            $errors[] = 'CORS_ORIGINS must not contain * in production';
        }
        if ($this->brokerUrl !== '' && parse_url($this->brokerUrl, PHP_URL_SCHEME) !== 'amqps') {
            $errors[] = 'BROKER_URL must use amqps:// in production';
        }

        return $errors;
    }

    /** @param list<string> $errors */
    private static function refuse(array $errors): void
    {
        if ($errors !== []) {
            throw new InvalidConfiguration(implode('; ', $errors));
        }
    }
}
