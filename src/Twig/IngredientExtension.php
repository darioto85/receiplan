<?php

namespace App\Twig;

use App\Entity\Ingredient;
use App\Service\IngredientImageResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class IngredientExtension extends AbstractExtension
{
    public function __construct(
        private readonly IngredientImageResolver $imageResolver
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('ingredient_image', [$this, 'ingredientImage']),
        ];
    }

    public function ingredientImage(Ingredient $ingredient): string
    {
        // ✅ Appelle la méthode existante du resolver
        return $this->imageResolver->getPublicUrl($ingredient);
    }
}
