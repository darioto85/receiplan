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
        // On attend au minimum { "recipe": { "name": "...", "ingredients": [...] } }
        $recipe = $data['recipe'] ?? null;

        if (!is_array($recipe)) {
            throw new \RuntimeException('recipe.add: recipe manquant.');
        }

        $name = trim((string) ($recipe['name'] ?? ''));
        $ingredients = $recipe['ingredients'] ?? null;

        if ($name === '') {
            throw new \RuntimeException('recipe.add: recipe.name manquant.');
        }

        if (!is_array($ingredients)) {
            throw new \RuntimeException('recipe.add: recipe.ingredients manquant.');
        }

        // On passe le payload attendu par ton handler existant
        return $this->handler->handle($user, [
            'recipe' => [
                'name' => $name,
                'ingredients' => $ingredients,
            ],
        ]);
    }
}