<?php

namespace App\Service\Ai;

use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Entity\User;
use App\Enum\Unit;
use App\Service\AiIngredientNormalizer;
use App\Service\IngredientResolver;
use App\Service\NameKeyNormalizer;
use Doctrine\ORM\EntityManagerInterface;

final class AiAddRecipeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IngredientResolver $ingredientResolver,
        private readonly AiIngredientNormalizer $normalizer,
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

        /** @var array<string, RecipeIngredient> $lineByMergeKey */
        $lineByMergeKey = [];

        $globalNeedsConfirmation = false;
        $warningsByIndex = [];

        foreach ($ingredients as $idx => $item) {
            if (!is_array($item)) {
                continue;
            }

            $norm = $this->normalizer->normalize($item);

            if (!empty($norm['needs_confirmation'])) {
                $globalNeedsConfirmation = true;
            }

            if (!empty($norm['warnings'])) {
                $warningsByIndex[] = [
                    'index' => (int) $idx,
                    'warnings' => $norm['warnings'],
                ];
            }

            $ingName = trim((string) ($norm['ingredient']['name'] ?? ''));
            if ($ingName === '') {
                $globalNeedsConfirmation = true;
                $warningsByIndex[] = [
                    'index' => (int) $idx,
                    'warnings' => ['empty_name'],
                ];
                continue;
            }

            $quantity = $norm['ingredient']['quantity'] ?? null;
            $unitValue = $norm['ingredient']['unit'] ?? null;

            if ($quantity === null) {
                $globalNeedsConfirmation = true;
                $warningsByIndex[] = [
                    'index' => (int) $idx,
                    'warnings' => ['missing_quantity'],
                ];
                $quantity = 0.0;
            }

            $unit = null;
            if (is_string($unitValue) && $unitValue !== '') {
                $unit = Unit::from($unitValue);
            }

            if (!$unit instanceof Unit) {
                $globalNeedsConfirmation = true;
                $warningsByIndex[] = [
                    'index' => (int) $idx,
                    'warnings' => ['missing_unit'],
                ];
                continue;
            }

            // Sert seulement à résoudre / créer l'ingrédient métier
            $ingredient = $this->ingredientResolver->resolveOrCreate($user, $ingName, $unitValue);

            $mergeKey = method_exists($ingredient, 'getNameKey')
                ? (string) $ingredient->getNameKey()
                : $ingName;

            if (!isset($lineByMergeKey[$mergeKey])) {
                $ri = new RecipeIngredient();
                $ri->setRecipe($recipe);
                $ri->setIngredient($ingredient);
                $ri->setQuantityFloat((float) $quantity);
                $ri->setUnit($unit);

                $this->em->persist($ri);
                $lineByMergeKey[$mergeKey] = $ri;
            } else {
                $existing = $lineByMergeKey[$mergeKey];

                if ($existing->getUnit() !== $unit) {
                    $globalNeedsConfirmation = true;
                    $warningsByIndex[] = [
                        'index' => (int) $idx,
                        'warnings' => ['merged_duplicate_with_different_unit'],
                    ];
                    continue;
                }

                $existing->setQuantityFloat(
                    $existing->getQuantityFloat() + (float) $quantity
                );

                $globalNeedsConfirmation = true;
                $warningsByIndex[] = [
                    'index' => (int) $idx,
                    'warnings' => ['merged_duplicate_ingredient'],
                ];
            }
        }

        $this->em->flush();

        return [
            'recipe' => [
                'id' => $recipe->getId(),
                'name' => $recipe->getName(),
            ],
            'needs_confirmation' => $globalNeedsConfirmation,
            'warnings' => $warningsByIndex,
        ];
    }
}