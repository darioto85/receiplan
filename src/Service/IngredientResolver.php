<?php

namespace App\Service;

use App\Entity\Ingredient;
use App\Entity\User;
use App\Enum\Unit;
use Doctrine\ORM\EntityManagerInterface;

final class IngredientResolver
{
    /**
     * Cache in-memory pour éviter de recréer le même ingrédient
     * dans une même requête avant flush().
     *
     * @var array<string, Ingredient>
     */
    private array $cache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IngredientNameKeyNormalizer $keyNormalizer,
    ) {}

    /**
     * Résout ou crée un Ingredient pour un user donné (clé unique: user + nameKey)
     */
    public function resolveOrCreate(User $user, string $normalizedName, ?string $unitGuess = null): Ingredient
    {
        $nameKey = $this->keyNormalizer->toKey($normalizedName);
        $cacheKey = ((string) $user->getId()) . '|' . $nameKey;

        // ✅ 1) Cache: évite les doublons dans la même requête avant flush()
        if (isset($this->cache[$cacheKey])) {
            $ingredient = $this->cache[$cacheKey];
            $this->maybeCompleteUnit($ingredient, $unitGuess);
            return $ingredient;
        }

        /** @var Ingredient|null $ingredient */
        $ingredient = $this->em->getRepository(Ingredient::class)->findOneBy([
            'user' => $user,
            'nameKey' => $nameKey,
        ]);

        if ($ingredient) {
            $this->maybeCompleteUnit($ingredient, $unitGuess);
            $this->cache[$cacheKey] = $ingredient;
            return $ingredient;
        }

        $ingredient = new Ingredient();
        $ingredient->setUser($user);
        $ingredient->setName($normalizedName);
        $ingredient->setNameKey($nameKey);

        // ✅ Conversion string -> Unit enum (Ingredient::setUnit attend un Unit)
        if ($unitGuess !== null && method_exists($ingredient, 'setUnit')) {
            $unitEnum = $this->toUnitEnum($unitGuess);
            if ($unitEnum !== null) {
                $ingredient->setUnit($unitEnum);
            }
        }

        $this->em->persist($ingredient);

        // ✅ cache immédiat
        $this->cache[$cacheKey] = $ingredient;

        return $ingredient;
    }

    private function maybeCompleteUnit(Ingredient $ingredient, ?string $unitGuess): void
    {
        if ($unitGuess === null) {
            return;
        }

        if (!method_exists($ingredient, 'getUnit') || !method_exists($ingredient, 'setUnit')) {
            return;
        }

        try {
            $unitEnum = $this->toUnitEnum($unitGuess);
            if ($unitEnum !== null && $ingredient->getUnit() === null) {
                $ingredient->setUnit($unitEnum);
            }
        } catch (\Throwable) {
            // Ne pas casser la résolution pour un souci d’unité
        }
    }

    private function toUnitEnum(?string $unit): ?Unit
    {
        if ($unit === null) {
            return null;
        }

        $u = strtolower(trim($unit));
        if ($u === '' || $u === 'unknown') {
            return null;
        }

        return match ($u) {
            'g' => Unit::G,
            'kg' => Unit::KG,
            'ml' => Unit::ML,
            'l' => Unit::L,
            'piece' => Unit::PIECE,
            'pack' => Unit::PACK,
            default => null,
        };
    }
}
