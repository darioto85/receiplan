<?php

namespace App\Service\Assistant\Handler;

use App\Entity\User;
use App\Enum\AssistantActionType;
use App\Service\Ai\AiAddRecipeHandler;

class RecipeAddActionHandler implements AssistantActionHandlerInterface
{
    public function __construct(
        private readonly AiAddRecipeHandler $handler,
    ) {}

    public function type(): AssistantActionType
    {
        return AssistantActionType::RECIPE_ADD;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function execute(User $user, array $data): array
    {
        $recipe = $data['recipe'] ?? null;

        if (!is_array($recipe)) {
            throw new \RuntimeException('recipe.add: recipe manquant.');
        }

        $name = trim((string) ($recipe['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('recipe.add: recipe.name manquant.');
        }

        $ingredientsRaw = $recipe['ingredients'] ?? null;
        if (!is_array($ingredientsRaw)) {
            throw new \RuntimeException('recipe.add: recipe.ingredients manquant.');
        }

        $ingredients = [];

        foreach ($ingredientsRaw as $ingredient) {
            if (!is_array($ingredient)) {
                continue;
            }

            $ingredientName = trim((string) ($ingredient['name'] ?? ''));
            if ($ingredientName === '') {
                continue;
            }

            $quantity = $ingredient['quantity'] ?? null;
            $unit = $ingredient['unit'] ?? null;

            $ingredients[] = [
                'name_raw' => (string) ($ingredient['name_raw'] ?? $ingredientName),
                'name' => $ingredientName,
                'quantity' => is_numeric($quantity) ? (float) $quantity : null,
                'quantity_raw' => array_key_exists('quantity_raw', $ingredient)
                    ? ($ingredient['quantity_raw'] !== null ? (string) $ingredient['quantity_raw'] : null)
                    : (is_numeric($quantity) ? (string) $quantity : null),
                'unit' => $unit !== null && $unit !== '' ? (string) $unit : null,
                'unit_raw' => array_key_exists('unit_raw', $ingredient)
                    ? ($ingredient['unit_raw'] !== null ? (string) $ingredient['unit_raw'] : null)
                    : ($unit !== null && $unit !== '' ? (string) $unit : null),
                'notes' => array_key_exists('notes', $ingredient)
                    ? ($ingredient['notes'] !== null ? (string) $ingredient['notes'] : null)
                    : null,
                'confidence' => array_key_exists('confidence', $ingredient) && is_numeric($ingredient['confidence'])
                    ? (float) $ingredient['confidence']
                    : 1.0,
            ];
        }

        if ($ingredients === []) {
            throw new \RuntimeException('recipe.add: aucun ingrédient exploitable.');
        }

        return $this->handler->handle($user, [
            'recipe' => [
                'name' => $name,
                'ingredients' => $ingredients,
            ],
        ]);
    }
}