<?php

namespace App\Service;

use App\Entity\Ingredient;
use Doctrine\ORM\EntityManagerInterface;

final class IngredientResolver
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IngredientNameKeyNormalizer $keyNormalizer,
    ) {}

    public function resolveOrCreate(string $normalizedName, ?string $unitGuess = null): Ingredient
    {
        $nameKey = $this->keyNormalizer->toKey($normalizedName);

        /** @var Ingredient|null $ingredient */
        $ingredient = $this->em->getRepository(Ingredient::class)->findOneBy(['nameKey' => $nameKey]);

        if ($ingredient) {
            return $ingredient;
        }

        $ingredient = new Ingredient();
        $ingredient->setName($normalizedName);
        $ingredient->setNameKey($nameKey);

        // Si tu as un champ unit sur Ingredient
        if ($unitGuess !== null && method_exists($ingredient, 'setUnit')) {
            $ingredient->setUnit($unitGuess);
        }

        $this->em->persist($ingredient);

        return $ingredient;
    }
}
