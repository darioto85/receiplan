<?php

namespace App\Service\Image;

use App\Repository\IngredientRepository;
use App\Repository\RecipeRepository;
use App\Service\IngredientImageResolver;
use App\Service\RecipeImageResolver;

final class AutoImageGenerationService
{
    public function __construct(
        private readonly RecipeRepository $recipeRepository,
        private readonly IngredientRepository $ingredientRepository,

        private readonly ImageGenerator $imageGenerator,
        private readonly RecipeImageTarget $recipeTarget,
        private readonly IngredientImageTarget $ingredientTarget,

        private readonly RecipeImageResolver $recipeResolver,
        private readonly IngredientImageResolver $ingredientResolver,
    ) {}

    /**
     * @return array{
     *   type: 'recipe'|'ingredient'|null,
     *   id: int|null,
     *   generated: bool,
     *   reason?: string,
     *   nameKey?: string|null
     * }
     */
    public function generateOne(): array
    {
        // 1) Recette d'abord
        $recipe = $this->recipeRepository->findOneNeedingImage();
        if ($recipe) {
            $nameKey = method_exists($recipe, 'getNameKey') ? $recipe->getNameKey() : null;

            // ✅ Dédup par nameKey: si l'image existe déjà sur le storage, on ne regénère pas
            if ($this->recipeResolver->hasImage($recipe)) {
                if (method_exists($recipe, 'setImgGenerated')) {
                    $recipe->setImgGenerated(true);
                }
                if (method_exists($recipe, 'setImgGeneratedAt')) {
                    $recipe->setImgGeneratedAt(new \DateTimeImmutable());
                }
                $this->recipeRepository->getEntityManager()->flush();

                return [
                    'type' => 'recipe',
                    'id' => $recipe->getId(),
                    'generated' => false,
                    'reason' => 'already_has_image_for_namekey',
                    'nameKey' => $nameKey,
                ];
            }

            // ✅ sinon: génération
            $this->imageGenerator->generateAndStore($this->recipeTarget, $recipe, overwrite: true);

            if (method_exists($recipe, 'setImgGenerated')) {
                $recipe->setImgGenerated(true);
            }
            if (method_exists($recipe, 'setImgGeneratedAt')) {
                $recipe->setImgGeneratedAt(new \DateTimeImmutable());
            }
            $this->recipeRepository->getEntityManager()->flush();

            return [
                'type' => 'recipe',
                'id' => $recipe->getId(),
                'generated' => true,
                'nameKey' => $nameKey,
            ];
        }

        // 2) Sinon ingrédient
        $ingredient = $this->ingredientRepository->findOneNeedingImage();
        if ($ingredient) {
            $nameKey = method_exists($ingredient, 'getNameKey') ? $ingredient->getNameKey() : null;

            // Si l'image existe déjà, on marque comme générée.
            if ($this->ingredientResolver->hasImage($ingredient)) {
                if (method_exists($ingredient, 'setImgGenerated')) {
                    $ingredient->setImgGenerated(true);
                }
                if (method_exists($ingredient, 'setImgGeneratedAt')) {
                    $ingredient->setImgGeneratedAt(new \DateTimeImmutable());
                }
                $this->ingredientRepository->getEntityManager()->flush();

                return [
                    'type' => 'ingredient',
                    'id' => $ingredient->getId(),
                    'generated' => false,
                    'reason' => 'already_has_image',
                    'nameKey' => $nameKey,
                ];
            }

            $this->imageGenerator->generateAndStore($this->ingredientTarget, $ingredient, overwrite: true);

            if (method_exists($ingredient, 'setImgGenerated')) {
                $ingredient->setImgGenerated(true);
            }
            if (method_exists($ingredient, 'setImgGeneratedAt')) {
                $ingredient->setImgGeneratedAt(new \DateTimeImmutable());
            }
            $this->ingredientRepository->getEntityManager()->flush();

            return [
                'type' => 'ingredient',
                'id' => $ingredient->getId(),
                'generated' => true,
                'nameKey' => $nameKey,
            ];
        }

        return ['type' => null, 'id' => null, 'generated' => false, 'reason' => 'nothing_to_do'];
    }
}
