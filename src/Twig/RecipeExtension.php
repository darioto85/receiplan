<?php

namespace App\Twig;

use App\Entity\Recipe;
use App\Service\RecipeImageResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class RecipeExtension extends AbstractExtension
{
    public function __construct(
        private readonly RecipeImageResolver $resolver,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('recipe_image', [$this, 'recipeImage']),
        ];
    }

    public function recipeImage(Recipe $recipe): string
    {
        return $this->resolver->getPublicUrl($recipe);
    }
}
