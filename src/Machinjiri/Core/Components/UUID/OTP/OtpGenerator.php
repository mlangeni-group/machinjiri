<?php

namespace Mlangeni\Machinjiri\Core\Components\UUID\OTP;

use InvalidArgumentException;
use RuntimeException;

/**
 * OTP (One-Time Password) Generator
 *
 * Features:
 * - Secure random generation using cryptographically strong pseudo-random numbers
 * - Numeric, alphanumeric, and fully customizable character-based OTPs
 * - Expiry timestamps and safe verification (constant-time comparison)
 * - Optional TOTP (Time-based OTP) support (RFC 6238)
 * - Full input validation and sensible defaults
 */
final class OtpGenerator
{
    // Default character sets
    private const CHARS_NUMERIC = '0123456789';
    private const CHARS_ALPHANUMERIC = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    private const CHARS_ALPHABETIC = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    // Maximum allowed OTP length to avoid excessive memory usage
    private const MAX_LENGTH = 256;

    /**
     * Generates a numeric OTP (digits only)
     *
     * @param int $length Length of the OTP (1-256)
     * @return string Numeric OTP (may include leading zeros)
     * @throws InvalidArgumentException If length is invalid
     */
    public static function numeric(int $length): string
    {
        return self::generateFromCharset($length, self::CHARS_NUMERIC);
    }

    /**
     * Generates an alphanumeric OTP (A-Z, a-z, 0-9)
     *
     * @param int $length Length of the OTP (1-256)
     * @return string Alphanumeric OTP
     * @throws InvalidArgumentException If length is invalid
     */
    public static function alphanumeric(int $length): string
    {
        return self::generateFromCharset($length, self::CHARS_ALPHANUMERIC);
    }

    /**
     * Generates an alphabetic OTP (A-Z, a-z)
     *
     * @param int $length Length of the OTP (1-256)
     * @return string Alphabetic OTP
     * @throws InvalidArgumentException If length is invalid
     */
    public static function alphabetic(int $length): string
    {
        return self::generateFromCharset($length, self::CHARS_ALPHABETIC);
    }

    /**
     * Generates an OTP from a custom character set
     *
     * @param int $length Length of the OTP (1-256)
     * @param string|null $customChars Allowed characters (null = uppercase alphabetic)
     * @return string Custom character-based OTP
     * @throws InvalidArgumentException If length or charset is invalid
     */
    public static function characterBased(int $length, ?string $customChars = null): string
    {
        $charset = $customChars ?? self::CHARS_ALPHABETIC;
        return self::generateFromCharset($length, $charset);
    }

    /**
     * Core generation logic using a given character set
     *
     * @param int $length Desired OTP length
     * @param string $charset String containing allowed characters (must not be empty)
     * @return string Randomly generated OTP
     */
    private static function generateFromCharset(int $length, string $charset): string
    {
        self::validateLength($length);
        $charsetLength = strlen($charset);

        if ($charsetLength === 0) {
            throw new InvalidArgumentException('Character set cannot be empty');
        }

        $otp = '';
        $maxIndex = $charsetLength - 1;

        for ($i = 0; $i < $length; ++$i) {
            $randomIndex = random_int(0, $maxIndex);
            $otp .= $charset[$randomIndex];
        }

        return $otp;
    }

    /**
     * Generates an OTP together with an expiration timestamp
     *
     * @param int $length OTP length
     * @param string $type One of: 'numeric', 'alphanumeric', 'alphabetic', 'characterBased'
     * @param int $ttlSeconds Time-to-live in seconds (default 300 = 5 minutes)
     * @param string|null $customChars Optional custom charset (used only when $type = 'characterBased')
     * @return array{otp: string, expires_at: int}
     * @throws InvalidArgumentException On invalid type or parameters
     */
    public static function generateWithExpiry(
        int $length,
        string $type = 'numeric',
        int $ttlSeconds = 300,
        ?string $customChars = null
    ): array {
        if ($ttlSeconds <= 0) {
            throw new InvalidArgumentException('TTL must be greater than zero');
        }

        $otp = match ($type) {
            'numeric' => self::numeric($length),
            'alphanumeric' => self::alphanumeric($length),
            'alphabetic' => self::alphabetic($length),
            'characterBased' => self::characterBased($length, $customChars),
            default => throw new InvalidArgumentException("Invalid OTP type: {$type}"),
        };

        return [
            'otp' => $otp,
            'expires_at' => time() + $ttlSeconds,
        ];
    }

    /**
     * Safely verifies an OTP against a stored value, with optional expiry check
     *
     * @param string $input OTP provided by the user
     * @param string $storedOtp The originally generated OTP
     * @param int|null $expiresAt Unix timestamp when the OTP expires (null = never expires)
     * @return bool True if the OTP matches and is not expired
     */
    public static function verify(string $input, string $storedOtp, ?int $expiresAt = null): bool
    {
        // Expiry check first (constant time not required, but early rejection)
        if ($expiresAt !== null && time() > $expiresAt) {
            return false;
        }

        // Constant-time comparison to prevent timing attacks
        return hash_equals($storedOtp, $input);
    }

    /**
     * Generates a TOTP secret (Base32 encoded, RFC 4648)
     *
     * @param int $length Secret length in bytes (recommended: 20-32)
     * @return string Base32 encoded secret
     * @throws RuntimeException If random bytes generation fails
     */
    public static function generateTotpSecret(int $length = 20): string
    {
        if ($length < 10 || $length > 64) {
            throw new InvalidArgumentException('TOTP secret length should be between 10 and 64 bytes');
        }

        $bytes = random_bytes($length);
        return self::base32Encode($bytes);
    }

    /**
     * Computes a TOTP (Time-based One-Time Password) according to RFC 6238
     *
     * @param string $secret Base32 encoded secret
     * @param int $timeWindow Time step in seconds (default 30)
     * @param int $digits Output digits (6-8, default 6)
     * @return string TOTP value (zero-padded)
     * @throws InvalidArgumentException On invalid parameters
     */
    public static function getTotp(string $secret, int $timeWindow = 30, int $digits = 6): string
    {
        if ($digits < 6 || $digits > 8) {
            throw new InvalidArgumentException('TOTP digits must be between 6 and 8');
        }
        if ($timeWindow <= 0) {
            throw new InvalidArgumentException('Time window must be positive');
        }

        $counter = floor(time() / $timeWindow);
        $secretBinary = self::base32Decode($secret);
        $hmac = hash_hmac('sha1', pack('J*', $counter), $secretBinary, true);

        // Dynamic truncation (RFC 4226)
        $offset = ord($hmac[19]) & 0x0F;
        $code = unpack('N', substr($hmac, $offset, 4))[1] & 0x7FFFFFFF;

        // Modulo for required digits
        $modulus = 10 ** $digits;
        $otp = $code % $modulus;

        return str_pad((string)$otp, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Verifies a TOTP (allows one window drift for usability)
     *
     * @param string $input OTP provided by the user
     * @param string $secret Base32 encoded secret
     * @param int $timeWindow Time step in seconds (default 30)
     * @param int $digits Digits length of the TOTP
     * @param int $drift Windows to check before/after (default 1)
     * @return bool True if valid
     */
    public static function verifyTotp(
        string $input,
        string $secret,
        int $timeWindow = 30,
        int $digits = 6,
        int $drift = 1
    ): bool {
        $currentWindow = floor(time() / $timeWindow);
        for ($i = -$drift; $i <= $drift; ++$i) {
            $counter = $currentWindow + $i;
            $expected = self::getTotpForCounter($secret, $counter, $timeWindow, $digits);
            if (hash_equals($expected, $input)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Internal TOTP calculation for a specific counter
     */
    private static function getTotpForCounter(string $secret, int $counter, int $timeWindow, int $digits): string
    {
        $secretBinary = self::base32Decode($secret);
        $hmac = hash_hmac('sha1', pack('J*', $counter), $secretBinary, true);
        $offset = ord($hmac[19]) & 0x0F;
        $code = unpack('N', substr($hmac, $offset, 4))[1] & 0x7FFFFFFF;
        $otp = $code % (10 ** $digits);
        return str_pad((string)$otp, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Validates OTP length
     *
     * @param int $length
     * @throws InvalidArgumentException
     */
    private static function validateLength(int $length): void
    {
        if ($length < 1) {
            throw new InvalidArgumentException('OTP length must be at least 1');
        }
        if ($length > self::MAX_LENGTH) {
            throw new InvalidArgumentException('OTP length cannot exceed ' . self::MAX_LENGTH);
        }
    }

    /**
     * RFC 4648 Base32 encoding (without padding)
     *
     * @param string $data Binary data
     * @return string Base32 encoded string
     */
    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $result = '';
        $buffer = 0;
        $bitsLeft = 0;

        foreach (str_split($data) as $char) {
            $buffer = ($buffer << 8) | ord($char);
            $bitsLeft += 8;

            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $result .= $alphabet[($buffer >> $bitsLeft) & 0x1F];
            }
        }

        if ($bitsLeft > 0) {
            $result .= $alphabet[($buffer << (5 - $bitsLeft)) & 0x1F];
        }

        return $result;
    }

    /**
     * RFC 4648 Base32 decoding
     *
     * @param string $data Base32 encoded string
     * @return string Binary data
     * @throws InvalidArgumentException If input contains invalid characters
     */
    private static function base32Decode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $map = array_flip(str_split($alphabet));

        $data = rtrim(strtoupper($data), '=');
        $result = '';
        $buffer = 0;
        $bitsLeft = 0;

        foreach (str_split($data) as $char) {
            if (!isset($map[$char])) {
                throw new InvalidArgumentException('Invalid Base32 character: ' . $char);
            }

            $buffer = ($buffer << 5) | $map[$char];
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $result .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $result;
    }
}