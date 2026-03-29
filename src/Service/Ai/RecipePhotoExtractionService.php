<?php

namespace App\Service\Ai;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class RecipePhotoExtractionService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly RecipeScanPromptBuilder $promptBuilder,
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
        return $this->extractRecipeFromImages([$absoluteImagePath]);
    }

    /**
     * @param string[] $absoluteImagePaths
     *
     * Retour attendu:
     * [
     *   'name' => string,
     *   'ingredients' => [ ['name'=>string,'quantity'=>float|null,'unit'=>string|null], ... ],
     *   'steps' => [ ['position'=>int,'text'=>string], ... ],
     * ]
     */
    public function extractRecipeFromImages(array $absoluteImagePaths): array
    {
        $absoluteImagePaths = array_values(array_filter(
            $absoluteImagePaths,
            static fn ($path): bool => is_string($path) && trim($path) !== ''
        ));

        if ($absoluteImagePaths === []) {
            throw new \RuntimeException('Aucune image fournie.');
        }

        if ($this->openAiApiKey === '' || $this->openAiApiKey === '0') {
            throw new \RuntimeException('OPENAI_API_KEY manquant.');
        }

        if ($this->model === '') {
            throw new \RuntimeException('Modèle OpenAI manquant (configure OPENAI_RECIPE_VISION_MODEL).');
        }

        $content = [
            [
                'type' => 'input_text',
                'text' => $this->promptBuilder->buildPrompt(count($absoluteImagePaths)),
            ],
        ];

        foreach ($absoluteImagePaths as $index => $absoluteImagePath) {
            if (!is_file($absoluteImagePath)) {
                throw new \RuntimeException(sprintf('Image introuvable : %s', $absoluteImagePath));
            }

            $bytes = file_get_contents($absoluteImagePath);
            if ($bytes === false) {
                throw new \RuntimeException(sprintf('Impossible de lire le fichier image : %s', $absoluteImagePath));
            }

            $mime = $this->guessMimeFromPath($absoluteImagePath) ?? 'image/jpeg';
            $dataUrl = sprintf('data:%s;base64,%s', $mime, base64_encode($bytes));

            $content[] = [
                'type' => 'input_text',
                'text' => sprintf('Photo %d', $index + 1),
            ];

            $content[] = [
                'type' => 'input_image',
                'image_url' => $dataUrl,
            ];
        }

        $payload = [
            'model' => $this->model,
            'input' => [
                [
                    'role' => 'user',
                    'content' => $content,
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
                'JSON de recette invalide (sortie modèle). Sortie brute: ' . $outputText
            );
        }

        $name = trim((string) ($parsed['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('Nom de recette introuvable dans le JSON.');
        }

        $ingredients = is_array($parsed['ingredients'] ?? null) ? $parsed['ingredients'] : [];
        $steps = is_array($parsed['steps'] ?? null) ? $parsed['steps'] : [];

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

    private function sanitizeModelJson(string $text): string
    {
        $t = trim($text);

        if (preg_match('/^```[a-zA-Z0-9]*\s*(.*)\s*```$/s', $t, $m)) {
            $t = trim($m[1]);
        }

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

            $rawName = trim((string) ($row['name'] ?? ''));
            if ($rawName === '') {
                continue;
            }

            $quantity = $this->parseQuantityValue($row['quantity'] ?? null);

            [$rawName, $quantityFromName] = $this->extractLeadingFractionFromName($rawName);
            if ($quantity === null && $quantityFromName !== null) {
                $quantity = $quantityFromName;
            }

            $name = $this->cleanupIngredientName($rawName);
            if ($name === '') {
                continue;
            }

            $unit = $row['unit'] ?? null;
            $unit = is_string($unit) ? trim($unit) : null;
            $unit = $this->normalizeUnitString($unit);

            [$quantity, $unit] = $this->normalizeIngredientMeasurement($name, $quantity, $unit);

            $out[] = [
                'name' => $name,
                'quantity' => $quantity,
                'unit' => $unit,
            ];
        }

        return $out;
    }

    private function parseQuantityValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $value = str_replace(',', '.', $value);

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (preg_match('/^(\d+)\s+(\d+)\/(\d+)$/', $value, $m)) {
            $whole = (float) $m[1];
            $num = (float) $m[2];
            $den = (float) $m[3];

            if ($den > 0) {
                return $whole + ($num / $den);
            }
        }

        if (preg_match('/^(\d+)\/(\d+)$/', $value, $m)) {
            $num = (float) $m[1];
            $den = (float) $m[2];

            if ($den > 0) {
                return $num / $den;
            }
        }

        return match ($value) {
            '½' => 0.5,
            '¼' => 0.25,
            '¾' => 0.75,
            default => null,
        };
    }

    private function extractLeadingFractionFromName(string $name): array
    {
        $name = trim($name);

        if (preg_match('/^(\d+)\s+(\d+)\/(\d+)\s+(.+)$/u', $name, $m)) {
            $whole = (float) $m[1];
            $num = (float) $m[2];
            $den = (float) $m[3];
            if ($den > 0) {
                return [trim($m[4]), $whole + ($num / $den)];
            }
        }

        if (preg_match('/^(\d+)\/(\d+)\s+(.+)$/u', $name, $m)) {
            $num = (float) $m[1];
            $den = (float) $m[2];
            if ($den > 0) {
                return [trim($m[3]), $num / $den];
            }
        }

        if (preg_match('/^(½|¼|¾)\s+(.+)$/u', $name, $m)) {
            $quantity = match ($m[1]) {
                '½' => 0.5,
                '¼' => 0.25,
                '¾' => 0.75,
                default => null,
            };

            return [trim($m[2]), $quantity];
        }

        return [$name, null];
    }

    private function cleanupIngredientName(string $name): string
    {
        $name = trim($name);

        $name = preg_replace('/\s*\([^)]*\)/u', '', $name) ?? $name;

        $name = preg_replace(
            '/^(?:\d+(?:[.,]\d+)?|\d+\s+\d+\/\d+|\d+\/\d+|½|¼|¾)\s*(kg|g|mg|l|cl|ml|c\.?\s*à\.?\s*s\.?|c\.?\s*à\.?\s*c\.?|cuillère[s]?\s+à\s+soupe|cuillère[s]?\s+à\s+café|pi[eè]ce[s]?|tranche[s]?|pincée[s]?|sachet[s]?|pot[s]?|bo[iî]te[s]?|paquet[s]?)\s*/ui',
            '',
            $name
        ) ?? $name;

        $name = preg_replace('/\s*(?:,?\s+(?:ou|\/)\s+.*)$/ui', '', $name) ?? $name;

        if (preg_match('/^([[:alpha:]\-\s]+?)(?:\s+(vert|verts|verte|vertes|jaune|jaunes|rouge|rouges|orange|oranges))(?:\s*,.*)?$/ui', $name, $m)) {
            $name = trim($m[1]);
        }

        $name = preg_replace('/^(de|d’|d\'|du|des|la|le|les)\s+/ui', '', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = trim($name, " \t\n\r\0\x0B,;-");

        return $name;
    }

    private function normalizeUnitString(?string $unit): ?string
    {
        if ($unit === null) {
            return null;
        }

        $unit = mb_strtolower(trim($unit));
        if ($unit === '') {
            return null;
        }

        return match ($unit) {
            'gramme', 'grammes', 'gr', 'g.' => 'g',
            'kilogramme', 'kilogrammes', 'kg.' => 'kg',
            'millilitre', 'millilitres', 'ml.' => 'ml',
            'centilitre', 'centilitres', 'cl.' => 'cl',
            'litre', 'litres', 'l.' => 'l',
            'piece', 'pièce', 'pièces', 'unité', 'unites', 'unités' => 'pièce',
            'c.à.s', 'cas', 'cuillère à soupe', 'cuilleres à soupe', 'cuillères à soupe' => 'c.à.s',
            'c.à.c', 'cac', 'cuillère à café', 'cuilleres à café', 'cuillères à café' => 'c.à.c',
            default => $unit,
        };
    }

    private function normalizeIngredientMeasurement(string $name, ?float $quantity, ?string $unit): array
    {
        $lowerName = mb_strtolower($name);

        if (in_array($lowerName, ['sel', 'poivre'], true)) {
            if ($quantity === null) {
                return [0.0, 'g'];
            }

            if ($unit === null) {
                return [$quantity, 'g'];
            }

            if ($unit === 'pièce') {
                if ($quantity <= 1.0) {
                    return [0.0, 'g'];
                }

                return [$quantity, 'g'];
            }
        }

        if ($quantity !== null && $unit === 'cl') {
            return [$quantity * 10, 'ml'];
        }

        if ($quantity !== null && $unit === 'c.à.s') {
            return [$quantity * 15, 'g'];
        }

        if ($quantity !== null && $unit === 'c.à.c') {
            return [$quantity * 5, 'g'];
        }

        if ($quantity !== null && $unit === null && $this->isCountableIngredient($lowerName)) {
            return [$quantity, 'pièce'];
        }

        return [$quantity, $unit];
    }

    private function isCountableIngredient(string $lowerName): bool
    {
        $patterns = [
            'filet',
            'branche',
            'branches',
            'brin',
            'brins',
            'oignon',
            'oignons',
            'tomate',
            'tomates',
            'œuf',
            'œufs',
            'oeuf',
            'oeufs',
            'gousse',
            'gousses',
            'citron',
            'citrons',
            'courgette',
            'courgettes',
            'carotte',
            'carottes',
            'pomme de terre',
            'pommes de terre',
            'échalote',
            'échalotes',
            'echalote',
            'echalotes',
            'escalope',
            'escalopes',
            'blanc de poulet',
            'poulet',
            'poivron',
            'poivrons',
            'chou',
            'chou chinois',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($lowerName, $pattern)) {
                return true;
            }
        }

        return false;
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

        usort($out, fn ($a, $b) => ($a['position'] <=> $b['position']));

        $i = 1;
        foreach ($out as &$s) {
            $s['position'] = $i++;
        }
        unset($s);

        return $out;
    }

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