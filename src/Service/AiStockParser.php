<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Parser IA (texte -> JSON structuré) avec 2 étapes :
 * 1) Classification d'action: add_stock | add_recipe | unknown
 * 2) Parsing selon action avec un schema dédié
 *
 * IngredientItem v2 ajoute:
 * - quantity_raw (string|null)
 * - unit_raw (string|null)
 * - notes (string|null)
 */
final class AiStockParser
{
    private const OPENAI_ENDPOINT = 'https://api.openai.com/v1/responses';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $openAiApiKey,
        private readonly string $model = 'gpt-4.1-mini',
    ) {}

    /**
     * Résultat unifié:
     * - action: add_stock | add_recipe | unknown
     * - payload: dépend de l'action
     *
     * @return array{action: 'add_stock'|'add_recipe'|'unknown', payload: array}
     */
    public function parse(string $userText): array
    {
        $userText = trim($userText);
        if ($userText === '') {
            return ['action' => 'unknown', 'payload' => ['reason' => 'empty_input']];
        }

        $action = $this->classifyAction($userText);

        return match ($action) {
            'add_stock'  => ['action' => 'add_stock',  'payload' => $this->parseStock($userText)],
            'add_recipe' => ['action' => 'add_recipe', 'payload' => $this->parseRecipe($userText)],
            default      => ['action' => 'unknown',    'payload' => ['reason' => 'ambiguous_or_unsupported']],
        };
    }

    /**
     * ---- STEP 1: CLASSIFICATION ----
     * @return 'add_stock'|'add_recipe'|'unknown'
     */
    private function classifyAction(string $userText): string
    {
        $schema = [
            'name' => 'receiplan_action_classifier_v1',
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['add_stock', 'add_recipe', 'unknown']],
                ],
                'required' => ['action'],
            ],
        ];

        $system = "Tu es un classifieur d'intention pour une application de cuisine.\n".
            "Tu dois retourner UNIQUEMENT un JSON conforme au schema.\n\n".
            "Règles:\n".
            "- add_stock si le texte parle d'achat/ajout au stock (\"j'ai acheté...\", \"ajoute au stock...\").\n".
            "- add_recipe si le texte demande de créer/ajouter une recette avec des ingrédients.\n".
            "- unknown si ambigu.\n";

        $result = $this->callOpenAiJsonSchema($userText, $system, $schema);

        $action = $result['action'] ?? null;
        if (!is_string($action)) {
            return 'unknown';
        }
        if (!in_array($action, ['add_stock', 'add_recipe', 'unknown'], true)) {
            return 'unknown';
        }

        return $action;
    }

    /**
     * ---- STEP 2a: PARSE STOCK ----
     * @return array{items: array<int, array{
     *   name_raw:string,
     *   name:string,
     *   quantity:float|null,
     *   quantity_raw:string|null,
     *   unit:('g'|'kg'|'ml'|'l'|'piece'|null),
     *   unit_raw:string|null,
     *   notes:string|null,
     *   confidence:float
     * }>}
     */
    private function parseStock(string $userText): array
    {
        $schema = [
            'name' => 'receiplan_add_stock_v2',
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'items' => $this->ingredientSchemaV2(),
                    ],
                ],
                'required' => ['items'],
            ],
        ];

        $system = $this->getCommonExtractionPrompt() .
            "\nContexte add_stock : l'utilisateur indique des achats / ajouts au stock.\n";

        $result = $this->callOpenAiJsonSchema($userText, $system, $schema);

        $this->assertStockPayloadV2($result);

        return $result;
    }

    /**
     * ---- STEP 2b: PARSE RECIPE ----
     * @return array{recipe: array{
     *   name:string,
     *   ingredients: array<int, array{
     *     name_raw:string,
     *     name:string,
     *     quantity:float|null,
     *     quantity_raw:string|null,
     *     unit:('g'|'kg'|'ml'|'l'|'piece'|null),
     *     unit_raw:string|null,
     *     notes:string|null,
     *     confidence:float
     *   }>
     * }}
     */
    private function parseRecipe(string $userText): array
    {
        $schema = [
            'name' => 'receiplan_add_recipe_v2',
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'recipe' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'ingredients' => [
                                'type' => 'array',
                                'items' => $this->ingredientSchemaV2(),
                            ],
                        ],
                        'required' => ['name', 'ingredients'],
                    ],
                ],
                'required' => ['recipe'],
            ],
        ];

        $system = $this->getCommonExtractionPrompt() .
            "\nContexte add_recipe : l'utilisateur décrit une recette à créer.\n" .
            "- Si le nom de recette est absent, mets \"Recette sans titre\".\n";

        $result = $this->callOpenAiJsonSchema($userText, $system, $schema);

        $this->assertRecipePayloadV2($result);

        return $result;
    }

    /**
     * Prompt commun extraction (stock + recette) :
     * ajoute unit_raw, quantity_raw, notes + règle de fallback.
     */
    private function getCommonExtractionPrompt(): string
    {
        return
            "Tu extrais des données structurées depuis un texte en français pour une application de cuisine.\n" .
            "Retourne UNIQUEMENT un JSON conforme au schema.\n\n" .
            "Règles générales :\n" .
            "- name_raw : extrait tel quel.\n" .
            "- name : normalisé (singulier si possible, sans marque si possible).\n" .
            "- confidence : entre 0 et 1.\n\n" .
            "Quantité :\n" .
            "- quantity doit être un nombre ou null.\n" .
            "- quantity_raw contient la forme brute (ex: \"une\", \"x4\", \"1,5\", \"2\").\n" .
            "- Si tu peux déduire un nombre fiable, mets-le dans quantity, sinon null.\n\n" .
            "Unités :\n" .
            "- unit doit être UNE des valeurs: g, kg, ml, l, piece, ou null.\n" .
            "- unit_raw contient l'unité d'origine (ex: \"grammes\", \"cuillère\", \"pincée\", \"paquet\") ou null si absente.\n" .
            "- Si l'unité d'origine n'est pas dans la liste (cuillère, pincée, paquet, sachet, tranche...), alors:\n" .
            "  - unit = null\n" .
            "  - unit_raw = unité d'origine\n" .
            "  - notes = contexte utile (ex: \"2 cuillères à soupe\", \"1 paquet\", \"lot de 6\")\n" .
            "- Si tu convertis (grammes->g, litres->l), garde unit_raw avec la forme d'origine.\n\n" .
            "Notes :\n" .
            "- notes peut contenir des infos de packaging / forme (ex: \"1 rouleau\", \"lot de 6\", \"bouteille\").\n" .
            "- Si rien d'utile, mets notes=null.\n";
    }

    /**
     * IngredientItem v2 schema.
     */
    private function ingredientSchemaV2(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'name_raw' => ['type' => 'string'],
                'name' => ['type' => 'string'],

                'quantity' => ['type' => ['number', 'null']],
                'quantity_raw' => ['type' => ['string', 'null']],

                'unit' => ['type' => ['string', 'null'], 'enum' => ['g', 'kg', 'ml', 'l', 'piece', null]],
                'unit_raw' => ['type' => ['string', 'null']],

                'notes' => ['type' => ['string', 'null']],

                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            ],
            'required' => ['name_raw', 'name', 'quantity', 'quantity_raw', 'unit', 'unit_raw', 'notes', 'confidence'],
        ];
    }

    /**
     * Appel OpenAI Responses API + Structured Outputs (json_schema).
     * @return array
     */
    private function callOpenAiJsonSchema(string $userText, string $systemPrompt, array $schema): array
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
                    'name' => $schema['name'],
                    'schema' => $schema['schema'],
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

        // Le plus simple si dispo
        if (isset($data['output_text']) && is_string($data['output_text'])) {
            $t = trim($data['output_text']);
            if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) {
                $jsonText = $t;
            }
        }

        // Fallback extraction
        if ($jsonText === null) {
            $jsonText = $this->extractStructuredJsonText($data);
        }

        if ($jsonText === null) {
            $hint = substr(json_encode($data), 0, 900);
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

    /**
     * Extraction tolérante du JSON dans output[].content[].
     */
    private function extractStructuredJsonText(array $data): ?string
    {
        if (!isset($data['output']) || !is_array($data['output'])) {
            return null;
        }

        foreach ($data['output'] as $out) {
            if (!is_array($out)) {
                continue;
            }
            if (!isset($out['content']) || !is_array($out['content'])) {
                continue;
            }

            foreach ($out['content'] as $chunk) {
                if (!is_array($chunk)) {
                    continue;
                }
                $type = $chunk['type'] ?? null;
                $text = $chunk['text'] ?? null;

                if (!is_string($text)) {
                    continue;
                }
                if (!in_array($type, ['output_text', 'text', 'json'], true)) {
                    continue;
                }

                $text = trim($text);
                if ($text !== '' && ($text[0] === '{' || $text[0] === '[')) {
                    return $text;
                }
            }
        }

        return null;
    }

    private function assertStockPayloadV2(array $payload): void
    {
        if (!isset($payload['items']) || !is_array($payload['items'])) {
            throw new \RuntimeException("OpenAI: payload stock invalide (items manquant).");
        }
        foreach ($payload['items'] as $i => $item) {
            $this->assertIngredientItemV2($item, "items[$i]");
        }
    }

    private function assertRecipePayloadV2(array $payload): void
    {
        if (!isset($payload['recipe']) || !is_array($payload['recipe'])) {
            throw new \RuntimeException("OpenAI: payload recette invalide (recipe manquant).");
        }
        $recipe = $payload['recipe'];

        if (!isset($recipe['name']) || !is_string($recipe['name']) || trim($recipe['name']) === '') {
            throw new \RuntimeException("OpenAI: recipe.name invalide.");
        }
        if (!isset($recipe['ingredients']) || !is_array($recipe['ingredients'])) {
            throw new \RuntimeException("OpenAI: recipe.ingredients invalide.");
        }
        foreach ($recipe['ingredients'] as $i => $item) {
            $this->assertIngredientItemV2($item, "recipe.ingredients[$i]");
        }
    }

    private function assertIngredientItemV2(mixed $item, string $path): void
    {
        if (!is_array($item)) {
            throw new \RuntimeException("OpenAI: $path invalide (doit être un objet).");
        }

        foreach (['name_raw', 'name', 'quantity', 'quantity_raw', 'unit', 'unit_raw', 'notes', 'confidence'] as $k) {
            if (!array_key_exists($k, $item)) {
                throw new \RuntimeException("OpenAI: $path.$k manquant.");
            }
        }

        if (!is_string($item['name_raw']) || trim($item['name_raw']) === '') {
            throw new \RuntimeException("OpenAI: $path.name_raw invalide.");
        }
        if (!is_string($item['name']) || trim($item['name']) === '') {
            throw new \RuntimeException("OpenAI: $path.name invalide.");
        }

        if (!is_null($item['quantity']) && !is_numeric($item['quantity'])) {
            throw new \RuntimeException("OpenAI: $path.quantity invalide.");
        }
        if (!is_null($item['quantity_raw']) && !is_string($item['quantity_raw'])) {
            throw new \RuntimeException("OpenAI: $path.quantity_raw invalide.");
        }

        $allowedUnits = ['g', 'kg', 'ml', 'l', 'piece', null];
        if (!in_array($item['unit'], $allowedUnits, true)) {
            throw new \RuntimeException("OpenAI: $path.unit invalide.");
        }
        if (!is_null($item['unit_raw']) && !is_string($item['unit_raw'])) {
            throw new \RuntimeException("OpenAI: $path.unit_raw invalide.");
        }
        if (!is_null($item['notes']) && !is_string($item['notes'])) {
            throw new \RuntimeException("OpenAI: $path.notes invalide.");
        }

        if (!is_numeric($item['confidence']) || $item['confidence'] < 0 || $item['confidence'] > 1) {
            throw new \RuntimeException("OpenAI: $path.confidence invalide.");
        }
    }
}
