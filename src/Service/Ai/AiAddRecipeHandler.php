<?php

namespace App\Service\Ai;

use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Entity\User;
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
     *       unit:('g'|'kg'|'ml'|'l'|'piece'|null),
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

        // ✅ nameKey (important pour tes images recettes basées sur nameKey)
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
                $warningsByIndex[] = ['index' => (int) $idx, 'warnings' => $norm['warnings']];
            }

            $ingName = trim((string) ($norm['ingredient']['name'] ?? ''));
            if ($ingName === '') {
                $globalNeedsConfirmation = true;
                $warningsByIndex[] = ['index' => (int) $idx, 'warnings' => ['empty_name']];
                continue;
            }

            // Unit "guess" pour Ingredient.unit (optionnel)
            $unitGuess = $norm['ingredient']['unit'] ?? null;

            // ✅ FIX: passe bien User en 1er (sinon TypeError)
            // Le resolver gère global/private + cache
            $ingredient = $this->ingredientResolver->resolveOrCreate($user, $ingName, $unitGuess);

            // ✅ fusion par nameKey (ou par nom si pas de nameKey)
            $mergeKey = method_exists($ingredient, 'getNameKey')
                ? (string) $ingredient->getNameKey()
                : $ingName;

            $quantity = $norm['ingredient']['quantity'] ?? null;
            $unit = $norm['ingredient']['unit'] ?? null; // utile pour needs_confirmation même si tu ne le stockes pas

            // Si quantity est null => on met 0 et on force confirmation
            if ($quantity === null) {
                $globalNeedsConfirmation = true;
                $warningsByIndex[] = ['index' => (int) $idx, 'warnings' => ['missing_quantity']];
                $quantity = 0.0;
            }

            // Créer ou fusionner ligne
            if (!isset($lineByMergeKey[$mergeKey])) {
                $ri = new RecipeIngredient();
                $ri->setRecipe($recipe);
                $ri->setIngredient($ingredient);
                $ri->setQuantity((float) $quantity);

                // Si tu as un champ unit dans RecipeIngredient, adapte ici
                // if (method_exists($ri, 'setUnit')) { $ri->setUnit($unit); }

                $this->em->persist($ri);
                $lineByMergeKey[$mergeKey] = $ri;
            } else {
                $existing = $lineByMergeKey[$mergeKey];
                $existing->setQuantity((float) $existing->getQuantity() + (float) $quantity);
                $globalNeedsConfirmation = true;
                $warningsByIndex[] = ['index' => (int) $idx, 'warnings' => ['merged_duplicate_ingredient']];
            }

            // Unit null => confirmation
            if ($unit === null) {
                $globalNeedsConfirmation = true;
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
