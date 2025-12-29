<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AiTicketParser
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $openAiApiKey,
        private readonly string $model = 'gpt-4.1-mini',
    ) {}

    /**
     * @return array{
     *   items: array<int, array{
     *     name_raw: string,
     *     name: string,
     *     quantity: float|null,
     *     quantity_raw: string|null,
     *     unit: ('g'|'kg'|'ml'|'l'|'piece'|'pack'|null),
     *     unit_raw: string|null,
     *     notes: string|null,
     *     confidence: float
     *   }>,
     *   warnings: array<int, string>
     * }
     */
    public function parseImage(string $imageBinary, string $mimeType): array
    {
        if (trim($this->openAiApiKey) === '') {
            throw new \RuntimeException('OpenAI: API key manquante (OPENAI_API_KEY).');
        }

        $dataUrl = sprintf('data:%s;base64,%s', $mimeType, base64_encode($imageBinary));

        $payload = [
            'model' => $this->model,
            'temperature' => 0.2,
            'store' => false,
            'input' => [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $this->prompt(),
                    ],
                    [
                        'type' => 'input_image',
                        'image_url' => $dataUrl,
                        'detail' => 'auto',
                    ],
                ],
            ]],
            // ✅ Structured Outputs via Responses API: text.format
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'ticket_stock_extraction',
                    'strict' => true,
                    'schema' => $this->schema(),
                ],
            ],
        ];

        $res = $this->http->request('POST', 'https://api.openai.com/v1/responses', [
            'headers' => [
                'Authorization' => 'Bearer '.$this->openAiApiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        // ✅ Lire status + body brut pour remonter proprement les erreurs OpenAI
        $status = $res->getStatusCode();
        $rawBody = $res->getContent(false);
        $raw = json_decode($rawBody ?: 'null', true);

        if ($status >= 400 || (is_array($raw) && isset($raw['error']))) {
            $err = is_array($raw) ? ($raw['error'] ?? null) : null;

            $msg = 'OpenAI error';
            if (is_array($err)) {
                $parts = [];
                if (!empty($err['message'])) { $parts[] = (string) $err['message']; }
                if (!empty($err['type'])) { $parts[] = 'type='.$err['type']; }
                if (!empty($err['code'])) { $parts[] = 'code='.$err['code']; }
                if (!empty($err['param'])) { $parts[] = 'param='.$err['param']; }
                if ($parts) {
                    $msg .= ': '.implode(' | ', $parts);
                }
            } else {
                $msg .= ': '.substr((string)$rawBody, 0, 500);
            }

            throw new \RuntimeException($msg);
        }

        if (!is_array($raw)) {
            throw new \RuntimeException('OpenAI: réponse non JSON.');
        }

        // Extraire le JSON structuré renvoyé
        $jsonText = $this->extractOutputText($raw);
        if ($jsonText === null || trim($jsonText) === '') {
            $keys = implode(',', array_keys($raw));
            throw new \RuntimeException('OpenAI: sortie vide (keys='.$keys.').');
        }

        $data = json_decode($jsonText, true);
        if (!is_array($data)) {
            throw new \RuntimeException('OpenAI: sortie JSON illisible (excerpt='.substr($jsonText, 0, 300).').');
        }

        $items = $data['items'] ?? [];
        $warnings = $data['warnings'] ?? [];

        if (!is_array($items)) {
            $items = [];
        }
        if (!is_array($warnings)) {
            $warnings = [];
        }

        // Mini garde-fous côté serveur
        $cleanItems = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }

            $cleanItems[] = [
                'name_raw' => (string)($it['name_raw'] ?? ''),
                'name' => (string)($it['name'] ?? ''),
                'quantity' => array_key_exists('quantity', $it) && $it['quantity'] !== null ? (float) $it['quantity'] : null,
                'quantity_raw' => array_key_exists('quantity_raw', $it) ? ($it['quantity_raw'] === null ? null : (string) $it['quantity_raw']) : null,
                'unit' => $this->sanitizeUnit($it['unit'] ?? null),
                'unit_raw' => array_key_exists('unit_raw', $it) ? ($it['unit_raw'] === null ? null : (string) $it['unit_raw']) : null,
                'notes' => array_key_exists('notes', $it) ? ($it['notes'] === null ? null : (string) $it['notes']) : null,
                'confidence' => isset($it['confidence']) ? max(0.0, min(1.0, (float) $it['confidence'])) : 0.0,
            ];
        }

        return [
            'items' => $cleanItems,
            'warnings' => array_values(array_map('strval', $warnings)),
        ];
    }

    private function sanitizeUnit(mixed $unit): ?string
    {
        if (!is_string($unit)) {
            return 'piece'; // défaut
        }

        $u = strtolower(trim($unit));
        if ($u === '' || $u === 'unknown') {
            return 'piece'; // défaut
        }

        return match ($u) {
            'g', 'kg', 'ml', 'l', 'piece', 'pack' => $u,
            default => 'piece', // défaut si valeur non reconnue
        };
    }

    private function prompt(): string
    {
        return <<<TXT
Tu analyses une photo de ticket de caisse (courses).

Objectif: extraire une liste d'ingrédients/produits utiles pour alimenter un stock de cuisine.

Règles:
- Retourne UNIQUEMENT des produits alimentaires / ingrédients (ex: tomates, lait, pâtes, beurre).
- Ignore tout ce qui n'est pas un produit: TOTAL, TVA, CB, rendu, carte fidélité, coupons, promos non-produits, sacs, etc.
- Si une quantité est explicitement indiquée (ex: "1L", "500G", "x2"), calcule la quantité totale.
- Si la quantité ou l’unité est absente/ambiguë: mets quantity=null et unit=null (ne pas inventer).
- Normalise "name" au singulier si possible (ex: "tomates" -> "tomate").
- Mets une confidence entre 0 et 1.
- Mets "notes" si tu as un doute ou si tu expliques un choix.

Réponds STRICTEMENT selon le JSON Schema fourni.
TXT;
    }

    /**
     * JSON Schema “pur” attendu dans text.format.schema
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'name_raw' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                            'quantity' => ['type' => ['number', 'null']],
                            'quantity_raw' => ['type' => ['string', 'null']],
                            'unit' => [
                                'type' => ['string', 'null'],
                                'enum' => ['g', 'kg', 'ml', 'l', 'piece', 'pack', null],
                            ],
                            'unit_raw' => ['type' => ['string', 'null']],
                            'notes' => ['type' => ['string', 'null']],
                            'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        ],
                        'required' => ['name_raw', 'name', 'quantity', 'quantity_raw', 'unit', 'unit_raw', 'notes', 'confidence'],
                    ],
                ],
                'warnings' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['items', 'warnings'],
        ];
    }

    private function extractOutputText(array $raw): ?string
    {
        if (isset($raw['output_text']) && is_string($raw['output_text']) && trim($raw['output_text']) !== '') {
            return trim($raw['output_text']);
        }

        $output = $raw['output'] ?? null;
        if (!is_array($output)) {
            return null;
        }

        $chunks = [];

        foreach ($output as $out) {
            if (!is_array($out)) {
                continue;
            }

            if (isset($out['refusal']) && is_string($out['refusal']) && trim($out['refusal']) !== '') {
                $chunks[] = trim($out['refusal']);
                continue;
            }

            $content = $out['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $c) {
                if (!is_array($c)) {
                    continue;
                }

                $type = $c['type'] ?? null;

                if ($type === 'output_text' && isset($c['text']) && is_string($c['text'])) {
                    $chunks[] = $c['text'];
                    continue;
                }

                if ($type === 'text' && isset($c['text']) && is_string($c['text'])) {
                    $chunks[] = $c['text'];
                    continue;
                }

                if ($type === 'output_json') {
                    if (isset($c['json']) && (is_array($c['json']) || is_object($c['json']))) {
                        $chunks[] = json_encode($c['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        continue;
                    }
                }
            }
        }

        return $chunks ? trim(implode("\n", $chunks)) : null;
    }
}
