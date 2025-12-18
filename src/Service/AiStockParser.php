<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Parse un texte d'achat (ex: "J’ai acheté 2 kg de pommes")
 * en JSON structuré (action + items) via OpenAI.
 */
final class AiStockParser
{
    private const OPENAI_ENDPOINT = 'https://api.openai.com/v1/responses';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $openAiApiKey,
        // Tu peux overrider dans services.yaml si tu veux
        private readonly string $model = 'gpt-4.1-mini',
    ) {}

    /**
     * @return array{
     *   action: 'add_to_stock'|'unknown',
     *   items: array<int, array{
     *     name_raw: string,
     *     name: string,
     *     quantity: float|null,
     *     unit: 'g'|'kg'|'ml'|'l'|'piece'|null,
     *     confidence: float
     *   }>
     * }
     */
    public function parsePurchaseText(string $userText): array
    {
        $userText = trim($userText);
        if ($userText === '') {
            return ['action' => 'unknown', 'items' => []];
        }

        $schema = $this->getJsonSchema();

        $payload = [
            'model' => $this->model,
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' =>
                                "Tu es un parseur d'achats pour une application de stock de cuisine.\n".
                                "Objectif: extraire des lignes d'achat en items (ingrédient, quantité, unité).\n".
                                "Règles:\n".
                                "- Réponds STRICTEMENT au format JSON demandé (schema) et rien d'autre.\n".
                                "- Texte en français.\n".
                                "- Si quantité/unité absente, mets null.\n".
                                "- Normalise le nom au singulier si possible (ex: 'pommes' -> 'pomme').\n".
                                "- confidence entre 0 et 1.\n".
                                "- action: add_to_stock si ça ressemble à un achat, ou si c'est une demande d'ajout au stock, sinon unknown.\n"
                        ],
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
                    'name' => $schema['name'],
                    'schema' => $schema['schema'],
                    // optionnel si supporté:
                    // 'strict' => true,
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

        $jsonText = $this->extractStructuredJsonText($data);
        if ($jsonText === null) {
            // On inclut un petit extrait utile pour debug, sans tout afficher
            $hint = substr(json_encode($data), 0, 500);
            throw new \RuntimeException("OpenAI: impossible d'extraire le JSON structuré. Extrait: ".$hint);
        }

        try {
            /** @var array $parsed */
            $parsed = json_decode($jsonText, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \RuntimeException('OpenAI: JSON invalide renvoyé: '.$e->getMessage().'. Brut: '.$jsonText);
        }

        // Validation minimale côté backend (tu peux remplacer par un DTO + Symfony Validator)
        $this->assertShape($parsed);

        return $parsed;
    }

    /**
     * Schema JSON "choisi" (adaptable).
     */
    private function getJsonSchema(): array
    {
        return [
            'name' => 'stock_intake_v1',
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['add_to_stock', 'unknown']],
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'name_raw' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'quantity' => ['type' => ['number', 'null']],
                                'unit' => ['type' => ['string', 'null'], 'enum' => ['g','kg','ml','l','piece', null]],
                                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                            ],
                            'required' => ['name_raw','name','quantity','unit','confidence'],
                        ],
                    ],
                ],
                'required' => ['action','items'],
            ],
        ];
    }


    /**
     * Extrait le texte JSON renvoyé par l'API Responses.
     * On est tolérant sur les variations de forme.
     */
    private function extractStructuredJsonText(array $data): ?string
    {
        // Cas fréquent: output[0].content[0].text
        if (isset($data['output']) && is_array($data['output'])) {
            foreach ($data['output'] as $out) {
                if (!is_array($out)) {
                    continue;
                }

                // content: [{type: "output_text", text: "..."}] ou [{type:"text", text:"..."}]
                if (isset($out['content']) && is_array($out['content'])) {
                    foreach ($out['content'] as $chunk) {
                        if (!is_array($chunk)) {
                            continue;
                        }
                        $type = $chunk['type'] ?? null;
                        $text = $chunk['text'] ?? null;

                        if (is_string($text) && ($type === 'output_text' || $type === 'text' || $type === 'json')) {
                            $text = trim($text);
                            // Heuristique: doit ressembler à un objet JSON
                            if ($text !== '' && ($text[0] === '{' || $text[0] === '[')) {
                                return $text;
                            }
                        }
                    }
                }
            }
        }

        // Fallback: certaines formes peuvent renvoyer output_text directement
        if (isset($data['output_text']) && is_string($data['output_text'])) {
            $t = trim($data['output_text']);
            if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) {
                return $t;
            }
        }

        return null;
    }

    /**
     * Garde-fou minimal. Remplace-le par un DTO + Validator Symfony ensuite.
     */
    private function assertShape(array $parsed): void
    {
        if (!isset($parsed['action']) || !is_string($parsed['action'])) {
            throw new \RuntimeException("OpenAI: champ 'action' manquant ou invalide.");
        }
        if (!in_array($parsed['action'], ['add_to_stock', 'unknown'], true)) {
            throw new \RuntimeException("OpenAI: champ 'action' invalide: ".$parsed['action']);
        }
        if (!isset($parsed['items']) || !is_array($parsed['items'])) {
            throw new \RuntimeException("OpenAI: champ 'items' manquant ou invalide.");
        }

        foreach ($parsed['items'] as $i => $item) {
            if (!is_array($item)) {
                throw new \RuntimeException("OpenAI: item[$i] invalide.");
            }
            foreach (['name_raw','name','quantity','unit','confidence'] as $k) {
                if (!array_key_exists($k, $item)) {
                    throw new \RuntimeException("OpenAI: item[$i].$k manquant.");
                }
            }
            if (!is_string($item['name_raw']) || trim($item['name_raw']) === '') {
                throw new \RuntimeException("OpenAI: item[$i].name_raw invalide.");
            }
            if (!is_string($item['name']) || trim($item['name']) === '') {
                throw new \RuntimeException("OpenAI: item[$i].name invalide.");
            }
            if (!is_null($item['quantity']) && !is_numeric($item['quantity'])) {
                throw new \RuntimeException("OpenAI: item[$i].quantity invalide.");
            }
            $allowedUnits = ['g','kg','ml','l','piece', null];
            if (!in_array($item['unit'], $allowedUnits, true)) {
                throw new \RuntimeException("OpenAI: item[$i].unit invalide.");
            }
            if (!is_numeric($item['confidence']) || $item['confidence'] < 0 || $item['confidence'] > 1) {
                throw new \RuntimeException("OpenAI: item[$i].confidence invalide.");
            }
        }
    }
}
