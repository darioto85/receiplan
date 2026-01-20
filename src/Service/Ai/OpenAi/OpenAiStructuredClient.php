<?php

namespace App\Service\Ai\OpenAi;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAiStructuredClient
{
    private const OPENAI_ENDPOINT = 'https://api.openai.com/v1/responses';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $openAiApiKey,
        private readonly string $model = 'gpt-4.1-mini',
    ) {}

    /**
     * @param array{name:string, schema:array} $schemaWrapper
     */
    public function callJsonSchema(string $userText, string $systemPrompt, array $schemaWrapper): array
    {
        $payload = [
            'model' => $this->model,
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        ['type' => 'input_text', 'text' => $systemPrompt],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => $userText],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $schemaWrapper['name'],
                    'schema' => $schemaWrapper['schema'],
                ],
            ],
        ];

        $response = $this->http->request('POST', self::OPENAI_ENDPOINT, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->openAiApiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        $data = $response->toArray(false);
        if (!is_array($data)) {
            throw new \RuntimeException('OpenAI: réponse illisible (non-JSON).');
        }

        $jsonText = null;

        if (isset($data['output_text']) && is_string($data['output_text'])) {
            $t = trim($data['output_text']);
            if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) $jsonText = $t;
        }

        if ($jsonText === null) {
            $jsonText = $this->extractStructuredJsonText($data);
        }

        if ($jsonText === null) {
            $hint = substr((string)json_encode($data), 0, 900);
            throw new \RuntimeException("OpenAI: impossible d'extraire le JSON structuré. Extrait: " . $hint);
        }

        try {
            $parsed = json_decode($jsonText, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \RuntimeException('OpenAI: JSON invalide: ' . $e->getMessage() . '. Brut: ' . $jsonText);
        }

        if (!is_array($parsed)) {
            throw new \RuntimeException('OpenAI: réponse JSON inattendue (non objet).');
        }

        return $parsed;
    }

    private function extractStructuredJsonText(array $data): ?string
    {
        $out = $data['output'] ?? null;
        if (!is_array($out)) return null;

        foreach ($out as $block) {
            if (!is_array($block)) continue;
            $content = $block['content'] ?? null;
            if (!is_array($content)) continue;

            foreach ($content as $chunk) {
                if (!is_array($chunk)) continue;
                $type = $chunk['type'] ?? null;
                $text = $chunk['text'] ?? null;

                if (!is_string($text)) continue;
                if (!in_array($type, ['output_text', 'text', 'json'], true)) continue;

                $text = trim($text);
                if ($text !== '' && ($text[0] === '{' || $text[0] === '[')) {
                    return $text;
                }
            }
        }

        return null;
    }
}
