<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AiImageClient
{
    private const IMAGES_ENDPOINT = 'https://api.openai.com/v1/images/generations';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $openAiApiKey,
        private readonly string $imageModel = 'gpt-image-1',
    ) {}

    public function generatePng(string $prompt, string $size = '1024x1024'): string
    {
        $allowedSizes = ['1024x1024', '1024x1536', '1536x1024', 'auto'];
        if (!in_array($size, $allowedSizes, true)) {
            throw new \InvalidArgumentException("Image size not supported: $size");
        }

        $response = $this->http->request('POST', self::IMAGES_ENDPOINT, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->openAiApiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $this->imageModel,
                'prompt' => $prompt,
                'size' => $size,
                // pas de response_format ici
            ],
        ]);

        $data = $response->toArray(false);

        // ✅ 1) Priorité au base64 si présent
        if (isset($data['data'][0]['b64_json']) && is_string($data['data'][0]['b64_json'])) {
            $raw = base64_decode($data['data'][0]['b64_json'], true);
            if ($raw === false) {
                throw new \RuntimeException('OpenAI Images: base64 invalide.');
            }
            return $raw;
        }

        // ✅ 2) Sinon fallback URL (si l’API renvoie une URL temporaire)
        if (isset($data['data'][0]['url']) && is_string($data['data'][0]['url'])) {
            $imageUrl = $data['data'][0]['url'];
            $imgResponse = $this->http->request('GET', $imageUrl);
            return $imgResponse->getContent();
        }

        throw new \RuntimeException(
            'OpenAI Images: ni b64_json ni url. Réponse: ' . substr(json_encode($data), 0, 900)
        );
    }
}
