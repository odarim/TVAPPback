<?php

declare(strict_types=1);

namespace App\Service;

/**
 * CipherService
 *
 * Thin AES-256-GCM encryption/decryption layer shared between the VOD
 * sources endpoint and the embed-proxy endpoint.
 *
 * The key is derived once from the STREAM_CIPHER_KEY environment variable
 * via SHA-256, giving a stable 32-byte key regardless of input length.
 *
 * Wire format (URL-safe base64, no padding):
 *   <12-byte nonce>.<ciphertext + 16-byte GCM tag>
 */
final class CipherService
{
    private string $key;

    public function __construct(string $cipherKey)
    {
        $this->key = hash('sha256', $cipherKey, true); // 32 raw bytes
    }

    public function encrypt(string $plaintext): string
    {
        $iv  = random_bytes(12); // GCM standard nonce length
        $tag = null;

        $ciphertext = openssl_encrypt(
            $plaintext,
            'AES-256-GCM',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false || $tag === null) {
            throw new \RuntimeException('Encryption failed');
        }

        return $this->b64url($iv) . '.' . $this->b64url($ciphertext . $tag);
    }

    public function decrypt(string $token): ?string
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $iv      = $this->b64urlDecode($parts[0]);
        $payload = $this->b64urlDecode($parts[1]);

        if ($iv === false || $payload === false || strlen($iv) !== 12 || strlen($payload) <= 16) {
            return null;
        }

        $tag        = substr($payload, -16);
        $ciphertext = substr($payload, 0, -16);

        $result = openssl_decrypt(
            $ciphertext,
            'AES-256-GCM',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $result === false ? null : $result;
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $data): string|false
    {
        $padded = strtr($data, '-_', '+/');
        $mod    = strlen($padded) % 4;
        if ($mod !== 0) {
            $padded .= str_repeat('=', 4 - $mod);
        }
        return base64_decode($padded, true);
    }
}
