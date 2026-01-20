<?php

namespace App\Service\Ai\Action;

use App\Entity\User;
use App\Service\Ai\AiAddRecipeHandler;
use App\Service\Ai\OpenAi\OpenAiStructuredClient;

final class AddRecipeAiAction implements AiActionInterface
{
    public function __construct(
        private readonly OpenAiStructuredClient $client,
        private readonly AiAddRecipeHandler $handler,
    ) {}

    public function name(): string
    {
        return 'add_recipe';
    }

    public function extractDraft(string $text, AiContext $ctx): array
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

        $system = $this->commonExtractionPrompt() .
            "\nContexte add_recipe : l'utilisateur décrit une recette à créer.\n" .
            "- Si le nom de recette est absent, mets \"Recette sans titre\".\n";

        $result = $this->client->callJsonSchema($text, $system, $schema);
        $this->assertRecipePayloadV2($result);

        return $result;
    }

    public function normalizeDraft(array $draft, AiContext $ctx): array
    {
        return $draft;
    }

    public function buildClarifyQuestions(array $draft, AiContext $ctx): array
    {
        // Pour l’instant pas de clarify recette (comportement actuel)
        return [];
    }

    public function buildConfirmText(array $draft, AiContext $ctx): string
    {
        $name = trim((string)($draft['recipe']['name'] ?? ''));
        if ($name !== '') {
            return "Je peux ajouter la recette « ".$name." ». Tu confirmes ?";
        }
        return "Je peux ajouter une recette. Tu confirmes ?";
    }

    public function apply(User $user, array $draft): array
    {
        return $this->handler->handle($user, $draft);
    }

    private function commonExtractionPrompt(): string
    {
        return
            "Tu extrais des données structurées depuis un texte en français pour une application de cuisine.\n" .
            "Retourne UNIQUEMENT un JSON conforme au schema.\n\n";
    }

    private function ingredientSchemaV2(): array
    {
        // même schema que stock
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

    private function assertRecipePayloadV2(array $payload): void
    {
        $recipe = $payload['recipe'] ?? null;
        if (!is_array($recipe)) throw new \RuntimeException("OpenAI: payload recette invalide (recipe manquant).");

        $name = $recipe['name'] ?? null;
        if (!is_string($name) || trim($name) === '') throw new \RuntimeException("OpenAI: recipe.name invalide.");

        $ings = $recipe['ingredients'] ?? null;
        if (!is_array($ings)) throw new \RuntimeException("OpenAI: recipe.ingredients invalide.");
    }
}
