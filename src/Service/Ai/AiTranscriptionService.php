<?php

namespace App\Service\Ai;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;


final class AiTranscriptionService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $openAiApiKey,
        private readonly string $model = 'whisper-1', // valeur safe
    ) {}

    public function transcribe(string $filePath, string $locale = 'fr-FR'): string
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException('Audio file not found: '.$filePath);
        }

        $language = $this->normalizeLanguage($locale); // fr-FR -> fr

        $filename = 'voice.webm'; // fallback

        $formFields = [
            'model' => $this->model,
            'language' => $language,
            'file' => DataPart::fromPath($filePath, $filename),
        ];

        $formData = new FormDataPart($formFields);

        $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/audio/transcriptions', [
            'headers' => array_merge(
                $formData->getPreparedHeaders()->toArray(),
                ['Authorization' => 'Bearer '.$this->openAiApiKey]
            ),
            'body' => $formData->bodyToIterable(),
        ]);

        $status = $response->getStatusCode();
        $data = $response->toArray(false);

        if ($status < 200 || $status >= 300) {
            $msg = $data['error']['message'] ?? 'Unknown OpenAI error';
            throw new \RuntimeException(sprintf('OpenAI transcription failed (%d): %s', $status, $msg));
        }

        $text = $data['text'] ?? null;
        if (!is_string($text)) {
            throw new \RuntimeException('OpenAI response missing "text".');
        }

        return trim($text);
    }

    private function normalizeLanguage(string $locale): string
    {
        $locale = trim($locale);
        if ($locale === '') return 'fr';
        $parts = preg_split('/[-_]/', $locale);
        return strtolower($parts[0] ?? 'fr');
    }
}
