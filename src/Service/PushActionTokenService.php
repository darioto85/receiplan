<?php

namespace App\Service;

final class PushActionTokenService
{
    public function __construct(
        private readonly string $secret,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function sign(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $b64 = $this->base64UrlEncode($json);
        $sig = hash_hmac('sha256', $b64, $this->secret);
        return $b64 . '.' . $sig;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$b64, $sig] = $parts;

        $expected = hash_hmac('sha256', $b64, $this->secret);
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $json = $this->base64UrlDecode($b64);
        if ($json === null) {
            return null;
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        // exp optionnel mais recommandé
        if (isset($data['exp']) && is_int($data['exp'])) {
            if (time() > $data['exp']) {
                return null;
            }
        }

        return $data;
    }

    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $b64): ?string
    {
        $b64 = strtr($b64, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($b64, true);
        return $decoded === false ? null : $decoded;
    }
}
