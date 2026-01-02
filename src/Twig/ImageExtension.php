<?php

namespace App\Twig;

use App\Entity\Ingredient;
use App\Entity\Recipe;
use App\Service\IngredientImageResolver;
use App\Service\RecipeImageResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ImageExtension extends AbstractExtension
{
    public function __construct(
        private readonly IngredientImageResolver $ingredientResolver,
        private readonly RecipeImageResolver $recipeResolver,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('ingredient_image', [$this, 'ingredientImage']),
            new TwigFunction('recipe_image', [$this, 'recipeImage']),
        ];
    }

    public function ingredientImage(Ingredient $ingredient): string
    {
        return $this->ingredientResolver->getPublicUrl($ingredient);
    }

    public function recipeImage(Recipe $recipe): string
    {
        return $this->recipeResolver->getPublicUrl($recipe);
    }
}
