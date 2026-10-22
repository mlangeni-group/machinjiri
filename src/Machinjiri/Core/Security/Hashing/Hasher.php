<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Security\Hashing;

use Throwable;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Exceptions\SecurityException;

/**
 * Password Hasher
 *
 * - Validates algorithm and cost.
 * - Supports Argon2id/Argon2i/Bcrypt.
 * - Optional pre-hashing (for bcrypt's 72-byte limit) and HMAC pepper.
 * - Verify-and-rehash helper to automate upgrades.
 * - Timing-safe handling of empty/malformed hashes.
 * - Cost auto-calibration helper.
 */
final class Hasher implements HasherInterface
{
    private const MIN_BCRYPT_COST = 4;
    private const MAX_BCRYPT_COST = 31;

    /** Hard cap for auto-calibration to avoid pathological runtimes. */
    private const CALIBRATION_CAP = 16;

    /** @var array<int, true> Allowed PASSWORD_* algorithm identifiers. */
    private const ALLOWED_ALGORITHMS = [
        PASSWORD_BCRYPT   => true,
        PASSWORD_ARGON2I  => true,
        PASSWORD_ARGON2ID => true,
        PASSWORD_DEFAULT  => true,
    ];

    /** Human-readable names for error messages. */
    private const ALGORITHM_NAMES = [
        PASSWORD_BCRYPT   => 'bcrypt',
        PASSWORD_ARGON2I  => 'argon2i',
        PASSWORD_ARGON2ID => 'argon2id',
        PASSWORD_DEFAULT  => 'default',
    ];

    /**
     * Valid bcrypt dummy hash used to burn CPU on timing-safe verification
     * when no real hash exists. Salt/hash segment is verified at boot.
     */
    private const TEST_HASH = '$2y$12$CX0hSs5ofsJPrl.he7bjOu/Tfk9rJn/CaXsL91eZFk43zya3A8W9e';

    private Container $app;
    private Logger $logger;

    private mixed $algorithm;
    private int $cost = 12;

    /** @var array<string, int> Argon2 tuning options. */
    private array $argonOptions = [
        'memory_cost' => 65536, // 64 MiB
        'time_cost'   => 4,
        'threads'     => 1,
    ];

    private bool $prehash = false;
    private ?string $pepper = null;

    public function __construct(
        ?Container $app = null,
        ?Logger $logger = null,
    ) {
        $this->app    = $app ?? Container::getInstance();
        $this->logger = $logger ?? $this->app->resolve(Logger::class);

        $this->validateConfig();
        $this->setFromConfig();

        // Enforce algorithm invariants (also toggles prehash for bcrypt/default).
        $this->setAlgorithm($this->algorithm);

        // Only bcrypt-family algorithms accept a cost.
        if ($this->isBcryptFamily($this->algorithm)) {
            $this->setCost($this->cost);
        }
    }

    // ---------------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------------

    private function setFromConfig(): void
    {
        $config = $this->config();

        $this->algorithm = $config['default'];

        // Only bcrypt/default have a meaningful cost here; Argon uses $argonOptions.
        if ($this->isBcryptFamily($this->algorithm)) {
            $this->cost = (int) ($config['algorithms'][$this->algorithm]['cost'] ?? 12);
        }

        // Apply any Argon options supplied in config without breaking defaults.
        if ($this->isArgonFamily($this->algorithm)) {
            $cfg = $config['algorithms'][$this->algorithm] ?? [];
            $this->argonOptions = [
                'memory_cost' => (int) ($cfg['memory_cost'] ?? $this->argonOptions['memory_cost']),
                'time_cost'   => (int) ($cfg['time_cost']   ?? $this->argonOptions['time_cost']),
                'threads'     => (int) ($cfg['threads']     ?? $this->argonOptions['threads']),
            ];
        }
    }

    /** @throws SecurityException */
    private function validateConfig(): void
    {
        $config = $this->config();

        if (!isset($config['default']) || $config['default'] === null) {
            throw new SecurityException('Password Hasher: default algorithm not set in config/hashing.php');
        }

        if (!isset($config['algorithms']) || !is_array($config['algorithms']) || $config['algorithms'] === []) {
            throw new SecurityException('Password Hasher: algorithms not set in config/hashing.php');
        }

        // Every declared algorithm must be well-formed.
        foreach ($config['algorithms'] as $algo => $algoCfg) {
            $name = self::ALGORITHM_NAMES[$algo] ?? (string) $algo;

            if (!isset(self::ALLOWED_ALGORITHMS[$algo])) {
                throw new SecurityException("Password Hasher: unsupported algorithm '{$name}' in config/hashing.php");
            }

            if (!is_array($algoCfg)) {
                throw new SecurityException("Password Hasher: incorrect configuration for {$name} in config/hashing.php");
            }

            if ($this->isBcryptFamily($algo) && !array_key_exists('cost', $algoCfg)) {
                throw new SecurityException("Password Hasher: cost not found for {$name} in config/hashing.php");
            }
        }

        $default = $config['default'];
        $defaultName = self::ALGORITHM_NAMES[$default] ?? (string) $default;

        if (!isset($config['algorithms'][$default])) {
            throw new SecurityException("Password Hasher: default algorithm '{$defaultName}' has no configuration entry in config/hashing.php");
        }
    }

    private function config(): array
    {
        $defaults = [
            'default' => defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT,
            'algorithms' => [
                PASSWORD_ARGON2ID => [
                    'algorithm' => PASSWORD_ARGON2ID,
                    'cost'      => 12,
                ],
                PASSWORD_ARGON2I => [
                    'algorithm' => PASSWORD_ARGON2I,
                    'cost'      => 12,
                ],
                PASSWORD_BCRYPT => [
                    'algorithm' => PASSWORD_BCRYPT,
                    'cost'      => 12,
                ],
                PASSWORD_DEFAULT => [
                    'algorithm' => PASSWORD_DEFAULT,
                    'cost'      => 12, // was 0 → crashed setCost()
                ],
            ],
        ];

        $user = $this->app->configurations['hashing'] ?? [];

        // Deep merge so partial user config (e.g. only 'default') keeps algorithm entries.
        return array_replace_recursive($defaults, $user);
    }

    // ---------------------------------------------------------------------
    // Fluent setters
    // ---------------------------------------------------------------------

    public function setAlgorithm(mixed $algorithm): self
    {
        if (!isset(self::ALLOWED_ALGORITHMS[$algorithm])) {
            $name = is_int($algorithm) ? (string) $algorithm : get_debug_type($algorithm);
            throw new SecurityException("Password Hasher: Unsupported password algorithm identifier: {$name}.");
        }

        $this->algorithm = $algorithm;

        // Bcrypt (and PASSWORD_DEFAULT which historically maps to bcrypt)
        // MUST pre-hash to defeat the 72-byte truncation bug.
        if ($this->isBcryptFamily($algorithm)) {
            $this->prehash = true;
        }

        return $this;
    }

    public function setCost(int $cost): self
    {
        if (!$this->isBcryptFamily($this->algorithm)) {
            throw new SecurityException('Password Hasher: Cost applies only to bcrypt/PASSWORD_DEFAULT.');
        }

        if ($cost < self::MIN_BCRYPT_COST || $cost > self::MAX_BCRYPT_COST) {
            throw new SecurityException(sprintf(
                'Password Hasher: Bcrypt cost must be between %d and %d, got %d.',
                self::MIN_BCRYPT_COST,
                self::MAX_BCRYPT_COST,
                $cost
            ));
        }

        $this->cost = $cost;
        return $this;
    }

    public function setArgon2Options(int $memoryCost, int $timeCost, int $threads): self
    {
        if (!$this->isArgonFamily($this->algorithm)) {
            throw new SecurityException('Password Hasher: Argon2 options apply only to Argon2i/Argon2id.');
        }

        if ($memoryCost < 8 * 1024) {
            throw new SecurityException('Password Hasher: Argon2 memory_cost must be at least 8 KiB.');
        }
        if ($timeCost < 1 || $threads < 1) {
            throw new SecurityException('Password Hasher: Argon2 time_cost and threads must be >= 1.');
        }

        $this->argonOptions = [
            'memory_cost' => $memoryCost,
            'time_cost'   => $timeCost,
            'threads'     => $threads,
        ];

        return $this;
    }

    public function enablePrehash(bool $enabled = true): self
    {
        // Do not allow disabling prehash for bcrypt-family — it reintroduces
        // the 72-byte truncation vulnerability silently.
        if (!$enabled && $this->isBcryptFamily($this->algorithm)) {
            throw new SecurityException(
                'Password Hasher: Pre-hashing cannot be disabled for bcrypt/PASSWORD_DEFAULT.'
            );
        }

        $this->prehash = $enabled;
        return $this;
    }

    public function setPepper(?string $pepper): self
    {
        if ($pepper !== null && strlen($pepper) < 32) {
            throw new SecurityException('Password Hasher: Pepper must be at least 32 bytes of entropy.');
        }
        $this->pepper = $pepper;
        return $this;
    }

    // ---------------------------------------------------------------------
    // Hashing / verification
    // ---------------------------------------------------------------------

    public function make(string $password): string
    {
        $this->assertPassword($password);

        $input = $this->prepare($password);

        try {
            $hashed = password_hash($input, $this->algorithm, $this->options());
        } catch (\ValueError $e) {
            throw new SecurityException('Password Hasher: Password hashing failed: ' . $e->getMessage(), 0, $e);
        }

        if (!is_string($hashed) || $hashed === '') {
            throw new SecurityException('Password Hasher: Password hashing returned an empty result.');
        }

        return $hashed;
    }

    public function verify(string $password, string $hashed): bool
    {
        // Timing-safe path for empty / missing hashes.
        if ($hashed === '') {
            password_verify($password, self::TEST_HASH);
            $this->logger->warning('Password verification attempted against empty hash.');
            return false;
        }

        $input = $this->prepare($password);
        $result = password_verify($input, $hashed);

        if ($result === false) {
            $info = password_get_info($hashed);
            if (($info['algoName'] ?? 'unknown') === 'unknown') {
                $this->logger->warning('Password verification used malformed hash.');
            }
        }

        return $result;
    }

    /**
     * Verify password, and transparently upgrade the hash if needed.
     *
     * @param callable(string):void $persist Receives the new hash if rehashing occurred.
     */
    public function verifyAndRehash(string $password, string $hashed, callable $persist): bool
    {
        if (!$this->verify($password, $hashed)) {
            return false;
        }

        if ($this->needsRehash($hashed)) {
            // Rehash failure must never fail a successful login.
            try {
                $persist($this->make($password));
                $this->logger->info('Password hash upgraded.');
            } catch (Throwable $e) {
                $this->logger->error('Password rehash failed: ' . $e->getMessage());
            }
        }

        return true;
    }

    public function needsRehash(string $hashed): bool
    {
        if ($hashed === '') {
            return true;
        }

        $info = password_get_info($hashed);
        if (($info['algoName'] ?? 'unknown') === 'unknown') {
            return true;
        }

        return password_needs_rehash($hashed, $this->algorithm, $this->options());
    }

    // ---------------------------------------------------------------------
    // Calibration
    // ---------------------------------------------------------------------

    /**
     * Calibrate bcrypt cost to hit ~$targetMs on the current machine.
     */
    public static function calibrateBcryptCost(int $targetMs = 250): int
    {
        if ($targetMs <= 0) {
            throw new SecurityException('Password Hasher: Target milliseconds must be positive.');
        }

        $password = bin2hex(random_bytes(16));

        // Warm up opcache / allocator so the first measurement isn't skewed.
        password_hash($password, PASSWORD_BCRYPT, ['cost' => self::MIN_BCRYPT_COST]);

        for ($cost = self::MIN_BCRYPT_COST; $cost <= self::CALIBRATION_CAP; $cost++) {
            $start = hrtime(true);
            password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;

            if ($elapsedMs >= $targetMs) {
                return $cost;
            }
        }

        return self::CALIBRATION_CAP;
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private function options(): array
    {
        return match ($this->algorithm) {
            PASSWORD_BCRYPT, PASSWORD_DEFAULT => ['cost' => $this->cost],
            PASSWORD_ARGON2I, PASSWORD_ARGON2ID => $this->argonOptions,
            default => [],
        };
    }

    private function prepare(string $password): string
    {
        $input = $password;

        // HMAC pepper first (defense in depth).
        if ($this->pepper !== null) {
            $input = hash_hmac('sha256', $input, $this->pepper, true);
        }

        // Pre-hash last; base64 keeps the result printable and free of nulls.
        if ($this->prehash) {
            $input = base64_encode(hash('sha256', $input, true));
        }

        return $input;
    }

    private function assertPassword(string $password): void
    {
        if ($password === '') {
            throw new SecurityException('Password Hasher: Password must not be empty.');
        }
        if (str_contains($password, "\0")) {
            throw new SecurityException('Password Hasher: Password must not contain null bytes.');
        }
    }

    private function isBcryptFamily(mixed $algorithm): bool
    {
        return $algorithm === PASSWORD_BCRYPT || $algorithm === PASSWORD_DEFAULT;
    }

    private function isArgonFamily(mixed $algorithm): bool
    {
        return $algorithm === PASSWORD_ARGON2I || $algorithm === PASSWORD_ARGON2ID;
    }
}