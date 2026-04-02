<?php

namespace App\Service\Ai;

use App\Entity\Recipe;
use App\Entity\User;
use App\Service\NameKeyNormalizer;
use Doctrine\ORM\EntityManagerInterface;

final class AiAddRecipeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AiRecipeIngredientApplier $recipeIngredientApplier,
        private readonly NameKeyNormalizer $nameKeyNormalizer,
    ) {}

    /**
     * @param array{
     *   recipe: array{
     *     name: string,
     *     ingredients: array<int, array{
     *       name_raw:string,
     *       name:string,
     *       quantity:float|null,
     *       quantity_raw:string|null,
     *       unit:('g'|'kg'|'ml'|'l'|'piece'|'pot'|'boite'|'sachet'|'tranche'|'paquet'|null),
     *       unit_raw:string|null,
     *       notes:string|null,
     *       confidence:float
     *     }>
     *   }
     * } $payload
     *
     * @return array{
     *   recipe: array{id:int|null, name:string|null},
     *   needs_confirmation: bool,
     *   warnings: array<int, array{index:int, warnings:string[]}>
     * }
     */
    public function handle(User $user, array $payload): array
    {
        $recipeData = $payload['recipe'] ?? null;
        if (!is_array($recipeData)) {
            throw new \InvalidArgumentException('payload.recipe manquant.');
        }

        $name = trim((string) ($recipeData['name'] ?? ''));
        if ($name === '') {
            $name = 'Recette sans titre';
        }

        $ingredients = $recipeData['ingredients'] ?? null;
        if (!is_array($ingredients)) {
            throw new \InvalidArgumentException('payload.recipe.ingredients manquant.');
        }

        $recipe = new Recipe();
        $recipe->setName($name);

        if (method_exists($recipe, 'setNameKey')) {
            $recipe->setNameKey($this->nameKeyNormalizer->toKey($name));
        }

        if (method_exists($recipe, 'setUser')) {
            $recipe->setUser($user);
        }

        $this->em->persist($recipe);

        $result = $this->recipeIngredientApplier->applyAssistantItems(
            $user,
            $recipe,
            $ingredients
        );

        $this->em->flush();

        return [
            'recipe' => [
                'id' => $recipe->getId(),
                'name' => $recipe->getName(),
            ],
            'needs_confirmation' => $result['needs_confirmation'],
            'warnings' => $result['warnings'],
        ];
    }
}