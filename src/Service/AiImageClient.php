<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AiImageClient
{
    private const IMAGES_ENDPOINT = 'https://api.openai.com/v1/images/generations';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $openAiApiKey,
        private readonly string $imageModel = 'gpt-image-1-mini',
    ) {}

    /**
     * @param array{
     *   model?: string,
     *   size?: string,
     *   quality?: string,
     *   output_format?: string,
     *   output_compression?: int,
     *   background?: string
     * } $options
     */
    public function generateImageBytes(string $prompt, array $options = []): string
    {
        $model = (string)($options['model'] ?? $this->imageModel);

        // Sizes: GPT image models => 1024x1024 | 1536x1024 | 1024x1536 | auto
        // dall-e-2 => 256/512/1024 ; dall-e-3 => 1024/1792/1024x1792 (cf docs)
        $size = (string)($options['size'] ?? '1024x1024');

        $allowedSizesGpt = ['1024x1024', '1024x1536', '1536x1024', 'auto'];
        $allowedSizesDalle2 = ['256x256', '512x512', '1024x1024'];
        $allowedSizesDalle3 = ['1024x1024', '1792x1024', '1024x1792'];

        // Qualité (GPT image models: auto|low|medium|high)
        $quality = (string)($options['quality'] ?? 'low');
        $allowedQualities = ['auto', 'low', 'medium', 'high', 'standard', 'hd'];

        if (!in_array($quality, $allowedQualities, true)) {
            throw new \InvalidArgumentException("Image quality not supported: $quality");
        }

        // output_format (GPT image models only): png|jpeg|webp
        $outputFormat = (string)($options['output_format'] ?? 'png');
        $allowedOutputFormats = ['png', 'jpeg', 'webp'];
        if (!in_array($outputFormat, $allowedOutputFormats, true)) {
            throw new \InvalidArgumentException("output_format not supported: $outputFormat");
        }

        // output_compression (GPT image models + jpeg/webp)
        $outputCompression = $options['output_compression'] ?? null;
        if ($outputCompression !== null) {
            if (!is_int($outputCompression) || $outputCompression < 0 || $outputCompression > 100) {
                throw new \InvalidArgumentException('output_compression must be an int between 0 and 100');
            }
        }

        // background (GPT image models): transparent|opaque|auto
        $background = (string)($options['background'] ?? 'auto');
        $allowedBackground = ['transparent', 'opaque', 'auto'];
        if (!in_array($background, $allowedBackground, true)) {
            throw new \InvalidArgumentException("background not supported: $background");
        }

        // Validate size depending on model family (simple heuristic)
        $isDalle2 = $model === 'dall-e-2';
        $isDalle3 = $model === 'dall-e-3';
        $isGptImage = !$isDalle2 && !$isDalle3; // gpt-image-1 / mini / 1.5

        if ($isGptImage && !in_array($size, $allowedSizesGpt, true)) {
            throw new \InvalidArgumentException("Image size not supported for GPT image models: $size");
        }
        if ($isDalle2 && !in_array($size, $allowedSizesDalle2, true)) {
            throw new \InvalidArgumentException("Image size not supported for dall-e-2: $size");
        }
        if ($isDalle3 && !in_array($size, $allowedSizesDalle3, true)) {
            throw new \InvalidArgumentException("Image size not supported for dall-e-3: $size");
        }

        $json = [
            'model' => $model,
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
        ];

        // Paramètres spécifiques GPT image models (docs)
        if ($isGptImage) {
            $json['output_format'] = $outputFormat;
            $json['background'] = $background;

            if (($outputFormat === 'webp' || $outputFormat === 'jpeg') && $outputCompression !== null) {
                $json['output_compression'] = $outputCompression;
            }
        }

        // Pour dalle-e-2 / dalle-e-3 on peut demander b64_json, mais pour GPT image models ce n'est pas supporté
        // (ils renvoient toujours du base64)
        if (!$isGptImage) {
            $json['response_format'] = 'b64_json';
        }

        $response = $this->http->request('POST', self::IMAGES_ENDPOINT, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->openAiApiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $json,
        ]);

        $data = $response->toArray(false);

        // GPT image models: toujours base64 dans data[0].b64_json (d’après la doc)
        if (isset($data['data'][0]['b64_json']) && is_string($data['data'][0]['b64_json'])) {
            $raw = base64_decode($data['data'][0]['b64_json'], true);
            if ($raw === false) {
                throw new \RuntimeException('OpenAI Images: base64 invalide.');
            }
            return $raw;
        }

        // Fallback URL (rare / anciens comportements)
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
