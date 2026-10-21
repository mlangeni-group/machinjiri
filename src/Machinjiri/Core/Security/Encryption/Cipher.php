<?php
declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Security\Encryption;

use RuntimeException;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\EncryptionException;
use Mlangeni\Machinjiri\Core\Artisans\Events\Factory;

class Cipher
{
    private const DEFAULT_CIPHER = 'aes-256-cbc';
    private const DEFAULT_IV_SIZE = 16;
    private const DEFAULT_TAG_SIZE = 16;

    private Container $app;
    private array $config;

    private string $cipher;
    private string $secretKey;
    private int $ivSize;
    private int $tagSize;
    private bool $supportsAead = false;

    public function __construct(?Container $app = null)
    {
        $this->app    = $app ?? Container::getInstance();
        $this->config = $this->config();
        $this->setConfig();
        $this->validateConfig();
    }

    /**
     * Merge user config over defaults.
     */
    private function config(): array
    {
        return array_merge([
            'default' => self::DEFAULT_CIPHER,
            'ciphers' => [
                'aes-256-cbc' => [
                    'cipher'   => 'aes-256-cbc',
                    'secret'   => 'encryption-key',
                    'iv_size'  => self::DEFAULT_IV_SIZE,
                    'tag_size' => self::DEFAULT_TAG_SIZE,
                    'aead'     => false,
                ],
                'aes-256-gcm' => [
                    'cipher'   => 'aes-256-gcm',
                    'secret'   => 'encryption-key',
                    'iv_size'  => 12,
                    'tag_size' => 16,
                    'aead'     => true,
                ],
            ],
        ], $this->app->configurations['encryption'] ?? []);
    }

    private function setConfig(): void
    {
        $this->cipher = $this->config['default'] ?? self::DEFAULT_CIPHER;

        if (!isset($this->config['ciphers'][$this->cipher])) {
            throw new EncryptionException("Encryption Error: config for {$this->cipher} not set.");
        }

        $cfg = $this->config['ciphers'][$this->cipher];

        // Required: secret. cipher optional (falls back to name), sizes optional.
        if (!isset($cfg['secret'])) {
            throw new EncryptionException("Encryption Error: secret not set for {$this->cipher}.");
        }

        $this->secretKey    = $this->normalizeKey($cfg['secret']);
        $this->ivSize       = (int) ($cfg['iv_size']  ?? self::DEFAULT_IV_SIZE);
        $this->tagSize      = (int) ($cfg['tag_size'] ?? self::DEFAULT_TAG_SIZE);
        $this->supportsAead = (bool) ($cfg['aead']    ?? str_contains($this->cipher, '-gcm'));

        $this->validateConfig();
    }

    private function validateConfig(): void
    {
        if (!in_array($this->cipher, openssl_get_cipher_methods(), true)) {
            throw new EncryptionException("Encryption Error: unsupported cipher {$this->cipher}.");
        }

        $expectedIv = openssl_cipher_iv_length($this->cipher);
        if ($expectedIv > 0 && $this->ivSize !== $expectedIv) {
            throw new EncryptionException(
                "Encryption Error: iv_size ({$this->ivSize}) does not match {$this->cipher} (expected {$expectedIv})."
            );
        }

        if ($this->supportsAead && ($this->tagSize < 12 || $this->tagSize > 16)) {
            throw new EncryptionException(
                "Encryption Error: tag_size must be between 12 and 16 for AEAD ciphers."
            );
        }

        if ($this->secretKey === '') {
            throw new EncryptionException("Encryption Error: empty secret key.");
        }
    }

    /**
     * Derive a raw key of the correct length from the configured secret.
     * Keeps backwards-compat with raw keys while making short secrets safe.
     */
    private function normalizeKey(string $secret): string
    {
        // If the secret is already a base64-encoded 32-byte key, use it directly.
        $decoded = base64_decode($secret, true);
        if ($decoded !== false && strlen($decoded) === 32) {
            return $decoded;
        }

        // Otherwise derive a 32-byte key deterministically.
        return hash('sha256', $secret, true);
    }

    // ----- Setters (validate afterwards) -----

    public function setSecret(string $secretKey): void
    {
        $this->secretKey = $this->normalizeKey($secretKey);
        $this->validateConfig();
    }

    public function setIvSize(int $iv): void
    {
        $this->ivSize = $iv;
        $this->validateConfig();
    }

    public function setTagSize(int $tag): void
    {
        $this->tagSize = $tag;
        $this->validateConfig();
    }

    public function setCipher(string $cipher): void
    {
        $this->cipher = $cipher;
        // Refresh derived settings from config if available
        if (isset($this->config['ciphers'][$cipher])) {
            $cfg = $this->config['ciphers'][$cipher];
            $this->ivSize       = (int) ($cfg['iv_size']  ?? self::DEFAULT_IV_SIZE);
            $this->tagSize      = (int) ($cfg['tag_size'] ?? self::DEFAULT_TAG_SIZE);
            $this->supportsAead = (bool) ($cfg['aead']    ?? str_contains($cipher, '-gcm'));
        } else {
            $this->supportsAead = str_contains($cipher, '-gcm');
            $expected = openssl_cipher_iv_length($cipher);
            if ($expected > 0) {
                $this->ivSize = $expected;
            }
        }
        $this->validateConfig();
    }

    // ----- Core -----

    public function encrypt(string $input): string
    {
        // Cryptographically secure IV
        $iv = random_bytes($this->ivSize);

        if ($this->supportsAead) {
            $tag        = '';
            $ciphertext = openssl_encrypt(
                $input,
                $this->cipher,
                $this->secretKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '',
                $this->tagSize
            );

            if ($ciphertext === false) {
                throw new EncryptionException('Encryption Error: AEAD encryption failed.');
            }

            // Framing: IV || Tag || Ciphertext  (tag length is fixed by config)
            $payload = $iv . $tag . $ciphertext;
        } else {
            $ciphertext = openssl_encrypt(
                $input,
                $this->cipher,
                $this->secretKey,
                OPENSSL_RAW_DATA,   // use raw bytes — do NOT mix base64 internally
                $iv
            );

            if ($ciphertext === false) {
                throw new EncryptionException('Encryption Error: encryption failed.');
            }

            // Framing: IV || Ciphertext
            $payload = $iv . $ciphertext;
        }

        $encoded = base64_encode($payload);

        try {
            Factory::security('data.encrypt', ['encrypted' => $encoded]);
        } catch (\Throwable) {
            // Never let telemetry break encryption
        }

        return $encoded;
    }

    public function decrypt(string $input): string
    {
        $decoded = base64_decode($input, true);

        if ($decoded === false) {
            throw new EncryptionException('Encryption Error: Malformed ciphertext (bad base64).');
        }

        $minLen = $this->ivSize + ($this->supportsAead ? $this->tagSize : 0);
        if (strlen($decoded) < $minLen) {
            throw new EncryptionException('Encryption Error: Malformed ciphertext (too short).');
        }

        $iv = substr($decoded, 0, $this->ivSize);

        if ($this->supportsAead) {
            $tag        = substr($decoded, $this->ivSize, $this->tagSize);
            $ciphertext = substr($decoded, $this->ivSize + $this->tagSize);

            $plain = openssl_decrypt(
                $ciphertext,
                $this->cipher,
                $this->secretKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
        } else {
            $ciphertext = substr($decoded, $this->ivSize);

            $plain = openssl_decrypt(
                $ciphertext,
                $this->cipher,
                $this->secretKey,
                OPENSSL_RAW_DATA,
                $iv
            );
        }

        if ($plain === false) {
            throw new EncryptionException(
                'Encryption Error: Decryption failed — data tampered or wrong key.'
            );
        }

        return $plain;
    }
}