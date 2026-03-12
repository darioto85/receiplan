<?php

namespace App\Service\Assistant\Handler;

use App\Entity\User;
use App\Enum\AssistantActionType;
use App\Service\Ai\AiUpdateRecipeHandler;

class RecipeUpdateActionHandler implements AssistantActionHandlerInterface
{
    public function __construct(
        private readonly AiUpdateRecipeHandler $handler,
    ) {}

    public function type(): AssistantActionType
    {
        return AssistantActionType::RECIPE_UPDATE;
    }

    public function execute(User $user, array $data): array
    {
        $recipe = $data['recipe'] ?? null;

        if (!is_array($recipe)) {
            throw new \RuntimeException('recipe.update: recipe manquant.');
        }

        $name = trim((string) ($recipe['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('recipe.update: recipe.name manquant.');
        }

        $ingredientsRaw = $recipe['ingredients'] ?? [];
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
            $replaceFrom = $ingredient['replace_from'] ?? null;

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
                'replace_from' => is_string($replaceFrom) && trim($replaceFrom) !== ''
                    ? trim($replaceFrom)
                    : null,
            ];
        }

        return $this->handler->handle($user, [
            'recipe' => [
                'name' => $name,
                'ingredients' => $ingredients,
            ],
        ]);
    }
}