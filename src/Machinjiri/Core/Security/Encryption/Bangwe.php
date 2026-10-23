<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Security\Encryption;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\SecurityException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use DomainException;
use Exception;
use UnexpectedValueException;

/**
 * Bangwe - Security Encryption & JWT Manager
 *
 * Provides symmetric AEAD encryption/decryption and JWT token handling
 * using configuration from the Container (env/config files).
 *
 * Key format contract:
 *   - APP_KEY / app.encryption_key MUST be a base64-encoded 32-byte key
 *     (as produced by Bangwe::generateKey()).
 *   - A raw 32-byte string is also accepted for backwards compatibility.
 *   - Any other length/format throws — never silently padded or truncated.
 */
class Bangwe
{
    private const DEFAULT_CIPHER          = 'aes-256-gcm';
    private const DEFAULT_JWT_ALGO        = 'HS256';
    private const DEFAULT_JWT_EXPIRATION  = 3600;
    private const GCM_TAG_LENGTH          = 16;
    private const AES_256_KEY_BYTES       = 32;
    private const JWT_MIN_SECRET_LENGTH   = 5;
    private const JWT_LEEWAY_SECONDS      = 30;
    private const ENCRYPTION_VERSION      = 'v1';

    private Container $container;
    private string $encryptionKey;        // decoded RAW key (32 bytes)
    private string $cipher;
    private string $jwtSecret;
    private string $jwtAlgo;
    private int $jwtExpiration;
    private string $jwtIssuer;
    private string $jwtAudience;

    /** @var array<string,string> Previous encryption keys for rotation (raw bytes) */
    private array $previousEncryptionKeys = [];

    public function __construct(?Container $container = null)
    {
        if (!class_exists(JWT::class)) {
            throw new SecurityException("Bangwe: Firebase JWT library not installed. Install using 'composer require firebase/php-jwt' in terminal");
        }
        
        $this->container = $container ?? Container::getInstance();

        // Global clock-skew tolerance for JWT exp/nbf/iat validation
        JWT::$leeway = self::JWT_LEEWAY_SECONDS;

        $this->loadConfiguration();
        $this->validateConfiguration();
    }

    // ---------------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------------

    private function loadConfiguration(): void
    {
        $config = $this->getConfiguration();

        // Normalize + validate the current key
        $this->encryptionKey = $this->normalizeEncryptionKey(
            $config['app_encryption_key'] ?? '',
            'APP_KEY'
        );

        $jwtCfg = $config['jwt'] ?? [];

        if (empty($jwtCfg)) throw new SecurityException("Bangwe: JWT config not set in config/encryption.php");

        $this->cipher        = $jwtCfg['jwt_cipher'] ?? self::DEFAULT_CIPHER;
        $this->jwtSecret     = $jwtCfg['jwt_secret'] ?? '';
        $this->jwtAlgo       = $jwtCfg['jwt_algo'] ?? self::DEFAULT_JWT_ALGO;
        $this->jwtExpiration = (int)($jwtCfg['jwt_expiration'] ?? self::DEFAULT_JWT_EXPIRATION);
        $this->jwtIssuer     = $jwtCfg['jwt_issuer'] ?? $this->getDefaultIssuer();
        $this->jwtAudience   = $jwtCfg['jwt_audience'] ?? $this->getDefaultAudience();

        

        // Load previous keys for rotation (comma-separated)
        if (!empty($config['encryption_key_previous'])) {
            $previous = $config['encryption_key_previous'];
            if (is_string($previous)) {
                $previous = array_filter(array_map('trim', explode(',', $previous)));
            }
            foreach ((array)$previous as $idx => $oldKey) {
                try {
                    $this->previousEncryptionKeys["prev_{$idx}"] =
                        $this->normalizeEncryptionKey($oldKey, "APP_KEY_PREVIOUS[{$idx}]");
                } catch (SecurityException $e) {
                    // Skip invalid previous keys — they'd be useless anyway
                }
            }
        }
    }

    /**
     * Normalize a key that may be base64 (preferred) or raw 32 bytes.
     * Throws if it can't produce exactly the required key size.
     */
    private function normalizeEncryptionKey(string $key, string $label = 'key'): string
    {
        if ($key === '') {
            return ''; // validated later
        }

        // Strip optional "base64:" prefix
        if (str_starts_with($key, 'base64:')) {
            $key = substr($key, 7);
            $decoded = base64_decode($key, true);
            if ($decoded === false || strlen($decoded) !== self::AES_256_KEY_BYTES) {
                throw new SecurityException(
                    "{$label} is not a valid base64-encoded 32-byte key.",
                    20120
                );
            }
            return $decoded;
        }

        // Try strict base64 decode
        $decoded = base64_decode($key, true);
        if ($decoded !== false && strlen($decoded) === self::AES_256_KEY_BYTES) {
            return $decoded;
        }

        // Accept raw keys of exact required length
        if (strlen($key) === self::AES_256_KEY_BYTES) {
            return $key;
        }

        throw new SecurityException(
            "{$label} must be either a base64-encoded 32-byte key or a raw 32-byte string.",
            20102
        );
    }

    private function getConfiguration(): array
    {
        $config = $this->container->configurations['encryption'] ?? [];

        if (count($config) <= 0) {
            throw new SecurityException("Bangwe: encryption configuration not set");
        } 

        return $config;
    }

    private function validateConfiguration(): void
    {
        if ($this->encryptionKey === '') {
            throw new SecurityException(
                'Encryption key is not configured. Set APP_KEY in .env or app.encryption_key in config.',
                20101
            );
        }

        if (!in_array($this->cipher, openssl_get_cipher_methods(), true)) {
            throw new SecurityException(
                "Unsupported encryption cipher: {$this->cipher}",
                20104
            );
        }

        // Only AEAD ciphers (GCM/CCM) are supported by encrypt()/decrypt()
        if (!str_contains($this->cipher, 'gcm') && !str_contains($this->cipher, 'ccm')) {
            throw new SecurityException(
                "Only AEAD ciphers (GCM/CCM) are supported; got: {$this->cipher}",
                20113
            );
        }

        if ($this->jwtSecret === '') {
            throw new SecurityException(
                'JWT secret is not configured. Set JWT_SECRET in .env or app.jwt_secret in config.',
                20103
            );
        }

        if (strlen($this->jwtSecret) < self::JWT_MIN_SECRET_LENGTH) {
            throw new SecurityException(
                'JWT_SECRET must be at least ' . self::JWT_MIN_SECRET_LENGTH . ' characters.',
                20121
            );
        }
    }

    private function getDefaultIssuer(): string
    {
        try {
            $appConfig = $this->container->getConfigurations()['app'] ?? [];
            if (!empty($appConfig['app_name'])) {
                return $appConfig['app_name'];
            }
            if (!empty($appConfig['app_url'])) {
                return parse_url($appConfig['app_url'], PHP_URL_HOST) ?? 'machinjiri';
            }
        } catch (\Exception $e) {
            // fall through
        }
        return 'machinjiri';
    }

    private function getDefaultAudience(): string
    {
        return 'machinjiri_api';
    }

    // ---------------------------------------------------------------------
    // Symmetric encryption
    // ---------------------------------------------------------------------

    /**
     * Encrypt data. Output is versioned so future format changes are backward-safe.
     *
     * @return array{data:string,iv:string,tag:string,v:string}
     */
    public function encrypt(string $data): array
    {
        $ivLength = openssl_cipher_iv_length($this->cipher);
        if ($ivLength === false) {
            throw new SecurityException(
                "Failed to get IV length for cipher: {$this->cipher}", 20105
            );
        }

        $iv  = random_bytes($ivLength);
        $tag = '';

        $encrypted = openssl_encrypt(
            $data,
            $this->cipher,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::GCM_TAG_LENGTH
        );

        if ($encrypted === false) {
            throw new SecurityException(
                'Encryption failed: ' . (openssl_error_string() ?: 'unknown'),
                20106
            );
        }

        return [
            'data' => base64_encode($encrypted),
            'iv'   => base64_encode($iv),
            'tag'  => base64_encode($tag),
            'v'    => self::ENCRYPTION_VERSION,
        ];
    }

    public function encryptToJson(string $data): string
    {
        $json = json_encode($this->encrypt($data), JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new SecurityException('Bangwe: Failed to JSON-encode encrypted payload.', 20114);
        }
        return $json;
    }

    /**
     * Decrypt data. Tries current key first, then previous keys (rotation).
     */
    public function decrypt(string $encryptedData, string $iv, string $tag): string
    {
        $decodedData = base64_decode($encryptedData, true);
        $decodedIv   = base64_decode($iv, true);
        $decodedTag  = base64_decode($tag, true);

        if ($decodedData === false || $decodedIv === false || $decodedTag === false) {
            throw new SecurityException('Bangwe: Invalid base64 in encrypted payload.', 20115);
        }

        // Attempt current key
        $result = $this->tryDecryptWithKey($this->encryptionKey, $decodedData, $decodedIv, $decodedTag);
        if ($result !== null) {
            return $result;
        }

        // Attempt previous keys (rotation support)
        foreach ($this->previousEncryptionKeys as $oldKey) {
            $result = $this->tryDecryptWithKey($oldKey, $decodedData, $decodedIv, $decodedTag);
            if ($result !== null) {
                return $result;
            }
        }

        throw new SecurityException(
            'Decryption failed: ' . (openssl_error_string() ?: 'authentication failed'),
            20107
        );
    }

    private function tryDecryptWithKey(string $key, string $data, string $iv, string $tag): ?string
    {
        $decrypted = openssl_decrypt(
            $data,
            $this->cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $decrypted === false ? null : $decrypted;
    }

    public function decryptFromJson(string $jsonString): string
    {
        $data = json_decode($jsonString, true);
        if (!is_array($data) || !isset($data['data'], $data['iv'], $data['tag'])) {
            throw new SecurityException('Bangwe: Invalid encrypted data format', 20108);
        }
        return $this->decrypt($data['data'], $data['iv'], $data['tag']);
    }

    // ---------------------------------------------------------------------
    // JWT
    // ---------------------------------------------------------------------

    public function createToken(array $payload, array $headers = [], ?int $expiration = null): string
    {
        $now        = time();
        $expiration = $expiration ?? $this->jwtExpiration;

        $defaultPayload = [
            'iss' => $this->jwtIssuer,
            'aud' => $this->jwtAudience,
            'iat' => $now,
            'exp' => $now + $expiration,
            'jti' => bin2hex(random_bytes(16)),
        ];

        $fullPayload = array_merge($defaultPayload, $payload);
        // Force correct exp even if caller passed one
        $fullPayload['exp'] = $now + $expiration;

        return JWT::encode($fullPayload, $this->jwtSecret, $this->jwtAlgo, null, $headers);
    }

    /**
     * Decode and fully validate a JWT (signature, exp, iss, aud).
     */
    public function decodeToken(string $token): object
    {
        try {
            $payload = JWT::decode($token, new Key($this->jwtSecret, $this->jwtAlgo));
        } catch (ExpiredException $e) {
            throw new SecurityException('Bangwe: Token has expired', 20109, $e);
        } catch (SignatureInvalidException $e) {
            throw new SecurityException('Bangwe: Invalid token signature', 20110, $e);
        } catch (DomainException | UnexpectedValueException $e) {
            throw new SecurityException('Bangwe: Invalid token: ' . $e->getMessage(), 20111, $e);
        }

        // iss/aud are NOT validated by firebase/php-jwt — check them here.
        if (($payload->iss ?? null) !== $this->jwtIssuer) {
            throw new SecurityException('Bangwe: Token issuer mismatch', 20118);
        }

        $aud = $payload->aud ?? null;
        $audMatches = is_array($aud)
            ? in_array($this->jwtAudience, $aud, true)
            : $aud === $this->jwtAudience;

        if (!$audMatches) {
            throw new SecurityException('Bangwe: Token audience mismatch', 20119);
        }

        return $payload;
    }

    /**
     * Convenience boolean validator. Never prints or throws.
     */
    public function validateToken(string $token): bool
    {
        try {
            $this->decodeToken($token);
            return true;
        } catch (MachinjiriException) {
            return false;
        }
    }

    /**
     * Throwing validator — use when you want a specific error surfaced.
     */
    public function validateTokenOrFail(string $token): object
    {
        return $this->decodeToken($token);
    }

    public function refreshToken(string $token, ?int $newExpiration = null): string
    {
        $decoded = $this->decodeToken($token);
        $payload = (array)$decoded;

        // Strip claims we regenerate
        unset(
            $payload['iat'], $payload['exp'], $payload['nbf'], $payload['jti'],
            $payload['iss'], $payload['aud']
        );

        return $this->createToken($payload, [], $newExpiration);
    }

    /**
     * Return token claims WITHOUT verifying signature/expiration.
     * NEVER use for authorization decisions — inspection only.
     */
    public function getTokenClaimsUnverified(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new SecurityException('Bangwe: Invalid token structure', 20112);
        }

        // Re-pad base64url
        $b64 = strtr($parts[1], '-_', '+/');
        $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);

        $decoded = base64_decode($b64, true);
        if ($decoded === false) {
            throw new SecurityException('Bangwe: Invalid token payload encoding', 20116);
        }

        $json = json_decode($decoded, true);
        if (!is_array($json)) {
            throw new SecurityException('Bangwe: Invalid token payload JSON', 20117);
        }

        return $json;
    }

    /** @deprecated Use getTokenClaimsUnverified() — clearer intent. */
    public function inspectToken(string $token): array
    {
        return $this->getTokenClaimsUnverified($token);
    }

    // ---------------------------------------------------------------------
    // Utilities
    // ---------------------------------------------------------------------

    /**
     * Generate a base64-encoded encryption key.
     * Output is directly usable as APP_KEY.
     */
    public static function generateKey(int $length = self::AES_256_KEY_BYTES): string
    {
        if ($length < 16) {
            throw new \InvalidArgumentException('Key length must be at least 16 bytes.');
        }
        return 'base64:' . base64_encode(random_bytes($length));
    }

    public function hashPassword(string $password, array $options = []): string
    {
        $cost = $options['cost'] ?? 12;
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);
    }

    public function verifyPassword(string $password, #[\SensitiveParameter] string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Non-sensitive config summary. Deliberately omits key material,
     * JWT secret, and their lengths.
     */
    public function getConfigSummary(): array
    {
        return [
            'cipher'                  => $this->cipher,
            'jwt_algo'                => $this->jwtAlgo,
            'jwt_expiration'          => $this->jwtExpiration,
            'jwt_issuer'              => $this->jwtIssuer,
            'jwt_audience'            => $this->jwtAudience,
            'key_rotation_keys'       => count($this->previousEncryptionKeys),
            'encryption_version'      => self::ENCRYPTION_VERSION,
        ];
    }

    /**
     * Cryptographically strong, fixed-length (in characters) random string.
     * Returns hex — entropy is $length * 4 bits.
     * For maximum entropy, use $length = 64 (256 bits).
     */
    public static function randomString(int $length = 32): string
    {
        if ($length < 1) {
            throw new \InvalidArgumentException('Length must be positive.');
        }
        $bytes = (int)ceil($length / 2);
        return substr(bin2hex(random_bytes($bytes)), 0, $length);
    }

    /**
     * Cryptographically strong random string with EXACT byte entropy.
     * Returns base64url (no padding), length in characters may be slightly
     * shorter than $bytes due to encoding — entropy is guaranteed at
     * least $bytes * 8 bits.
     */
    public static function randomToken(int $bytes = 32): string
    {
        if ($bytes < 1) {
            throw new \InvalidArgumentException('Byte length must be positive.');
        }
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}