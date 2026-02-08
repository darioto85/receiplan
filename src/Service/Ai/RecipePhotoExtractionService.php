<?php

namespace App\Service\Ai;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class RecipePhotoExtractionService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $openAiApiKey,
        private readonly string $model,
    ) {}

    /**
     * Retour attendu:
     * [
     *   'name' => string,
     *   'ingredients' => [ ['name'=>string,'quantity'=>float|null,'unit'=>string|null], ... ],
     *   'steps' => [ ['position'=>int,'text'=>string], ... ],
     * ]
     */
    public function extractRecipeFromImage(string $absoluteImagePath): array
    {
        if (!is_file($absoluteImagePath)) {
            throw new \RuntimeException('Image introuvable.');
        }
        if ($this->openAiApiKey === '' || $this->openAiApiKey === '0') {
            throw new \RuntimeException('OPENAI_API_KEY manquant.');
        }
        if ($this->model === '') {
            throw new \RuntimeException('Modèle OpenAI manquant (configure OPENAI_RECIPE_VISION_MODEL).');
        }

        $bytes = file_get_contents($absoluteImagePath);
        if ($bytes === false) {
            throw new \RuntimeException('Impossible de lire le fichier image.');
        }

        $mime = $this->guessMimeFromPath($absoluteImagePath) ?? 'image/jpeg';
        $dataUrl = sprintf('data:%s;base64,%s', $mime, base64_encode($bytes));

        $schemaHint = <<<TXT
Tu dois extraire une recette à partir d'une photo.
Retourne UNIQUEMENT un JSON valide suivant ce schéma :

{
  "name": "string",
  "ingredients": [
    { "name": "string", "quantity": number|null, "unit": "string"|null }
  ],
  "steps": [
    { "position": number, "text": "string" }
  ]
}

Règles:
- Pas de Markdown, pas de ```json, pas d'explications : uniquement du JSON.
- Si une quantité/unité n'est pas lisible: quantity=null, unit=null.
- steps.position commence à 1 et incrémente sans trous.
- Normalise les unités en français si possible (g, kg, ml, l, c.à.s, c.à.c, pièce).
TXT;

        $payload = [
            'model' => $this->model,
            'input' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => $schemaHint],
                        ['type' => 'input_image', 'image_url' => $dataUrl],
                    ],
                ],
            ],
        ];

        $resp = $this->httpClient->request('POST', 'https://api.openai.com/v1/responses', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->openAiApiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
            'timeout' => 90,
        ]);

        $status = $resp->getStatusCode();
        $raw = $resp->getContent(false);

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('OpenAI error HTTP ' . $status . ': ' . $raw);
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new \RuntimeException('Réponse OpenAI non JSON.');
        }

        $outputText = $this->extractOutputText($json);
        $outputText = $this->sanitizeModelJson($outputText);

        $parsed = json_decode($outputText, true);
        if (!is_array($parsed)) {
            throw new \RuntimeException(
                "JSON de recette invalide (sortie modèle). Sortie brute: " . $outputText
            );
        }

        $name = trim((string) ($parsed['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Nom de recette introuvable dans le JSON.');
        }

        $ingredients = is_array($parsed['ingredients'] ?? null) ? $parsed['ingredients'] : [];
        $steps = is_array($parsed['steps'] ?? null) ? $parsed['steps'] : [];

        // Normalisation légère côté serveur (sécurité / stabilité)
        $ingredients = $this->normalizeIngredients($ingredients);
        $steps = $this->normalizeSteps($steps);

        return [
            'name' => $name,
            'ingredients' => $ingredients,
            'steps' => $steps,
        ];
    }

    private function guessMimeFromPath(string $path): ?string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
            default => null,
        };
    }

    /**
     * Nettoie une sortie type:
     * ```json
     * { ... }
     * ```
     * ou ``` ... ```
     */
    private function sanitizeModelJson(string $text): string
    {
        $t = trim($text);

        // Retire les fences ```json ... ``` ou ``` ... ```
        // On prend le contenu entre la première ouverture et la dernière fermeture si présent.
        if (preg_match('/^```[a-zA-Z0-9]*\s*(.*)\s*```$/s', $t, $m)) {
            $t = trim($m[1]);
        }

        // Retire d’éventuels préfixes/suffixes parasites (rare)
        // On tente de récupérer le premier objet JSON dans la chaîne.
        $firstBrace = strpos($t, '{');
        $lastBrace = strrpos($t, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $t = substr($t, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        return trim($t);
    }

    private function normalizeIngredients(array $ingredients): array
    {
        $out = [];
        foreach ($ingredients as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $quantity = $row['quantity'] ?? null;
            if ($quantity !== null && $quantity !== '') {
                $quantity = (float) $quantity;
            } else {
                $quantity = null;
            }

            $unit = $row['unit'] ?? null;
            $unit = is_string($unit) ? trim($unit) : null;
            if ($unit === '') {
                $unit = null;
            }

            $out[] = [
                'name' => $name,
                'quantity' => $quantity,
                'unit' => $unit,
            ];
        }
        return $out;
    }

    private function normalizeSteps(array $steps): array
    {
        $out = [];
        foreach ($steps as $row) {
            if (!is_array($row)) {
                continue;
            }
            $text = trim((string) ($row['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $pos = (int) ($row['position'] ?? 0);
            if ($pos <= 0) {
                $pos = count($out) + 1;
            }
            $out[] = [
                'position' => $pos,
                'text' => $text,
            ];
        }

        // Ré-ordonne et renumérote proprement 1..N
        usort($out, fn($a, $b) => ($a['position'] <=> $b['position']));
        $i = 1;
        foreach ($out as &$s) {
            $s['position'] = $i++;
        }
        unset($s);

        return $out;
    }

    /**
     * Extrait le texte final renvoyé par Responses API.
     */
    private function extractOutputText(array $response): string
    {
        $out = $response['output'] ?? null;
        if (is_array($out)) {
            foreach ($out as $item) {
                $content = $item['content'] ?? null;
                if (!is_array($content)) {
                    continue;
                }
                foreach ($content as $c) {
                    if (($c['type'] ?? null) === 'output_text' && isset($c['text']) && is_string($c['text'])) {
                        return $c['text'];
                    }
                }
            }
        }

        if (isset($response['output_text']) && is_string($response['output_text'])) {
            return $response['output_text'];
        }

        throw new \RuntimeException('Impossible d’extraire le texte de sortie OpenAI.');
    }
}
