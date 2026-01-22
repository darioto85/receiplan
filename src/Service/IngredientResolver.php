<?php

namespace App\Service;

use App\Entity\Ingredient;
use App\Entity\User;
use App\Enum\Unit;
use Doctrine\ORM\EntityManagerInterface;

final class IngredientResolver
{
    /** @var array<string, Ingredient> */
    private array $cache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NameKeyNormalizer $keyNormalizer,
    ) {}

    public function resolveOrCreate(User $user, string $normalizedName, ?string $unitGuess = null): Ingredient
    {
        $candidateKeys = $this->keyNormalizer->toCandidateKeys($normalizedName);
        if (count($candidateKeys) === 0) {
            // fallback ultra safe
            $candidateKeys = [$this->keyNormalizer->toKey($normalizedName)];
        }

        $repo = $this->em->getRepository(Ingredient::class);

        // 0) Cache (global puis privé) pour toutes les clés candidates
        foreach ($candidateKeys as $nameKey) {
            $cacheKeyGlobal = 'global|' . $nameKey;
            if (isset($this->cache[$cacheKeyGlobal])) {
                $ingredient = $this->cache[$cacheKeyGlobal];
                $this->maybeCompleteUnit($ingredient, $unitGuess);
                return $ingredient;
            }

            $cacheKeyPrivate = ((string) $user->getId()) . '|' . $nameKey;
            if (isset($this->cache[$cacheKeyPrivate])) {
                $ingredient = $this->cache[$cacheKeyPrivate];
                $this->maybeCompleteUnit($ingredient, $unitGuess);
                return $ingredient;
            }
        }

        // 1) Cherche GLOBAL pour chaque candidate key
        foreach ($candidateKeys as $nameKey) {
            /** @var Ingredient|null $ingredient */
            $ingredient = $repo->findOneBy(['user' => null, 'nameKey' => $nameKey]);
            if ($ingredient) {
                $this->maybeCompleteUnit($ingredient, $unitGuess);
                $this->cache['global|' . $nameKey] = $ingredient;
                return $ingredient;
            }
        }

        // 2) Cherche PRIVÉ du user pour chaque candidate key
        foreach ($candidateKeys as $nameKey) {
            /** @var Ingredient|null $ingredient */
            $ingredient = $repo->findOneBy(['user' => $user, 'nameKey' => $nameKey]);
            if ($ingredient) {
                $this->maybeCompleteUnit($ingredient, $unitGuess);
                $this->cache[((string)$user->getId()) . '|' . $nameKey] = $ingredient;
                return $ingredient;
            }
        }

        // 3) Sinon crée en PRIVÉ
        // On choisit la key "base" (première candidate) comme canonicale
        $nameKey = $candidateKeys[0] ?? $this->keyNormalizer->toKey($normalizedName);

        $ingredient = new Ingredient();
        $ingredient->setUser($user);
        $ingredient->setName($normalizedName);
        $ingredient->setNameKey($nameKey);

        $unitEnum = $this->toUnitEnum($unitGuess);
        if ($unitEnum !== null) {
            $ingredient->setUnit($unitEnum);
        }

        $this->em->persist($ingredient);

        $this->cache[((string)$user->getId()) . '|' . $nameKey] = $ingredient;

        return $ingredient;
    }

    private function maybeCompleteUnit(Ingredient $ingredient, ?string $unitGuess): void
    {
        if ($unitGuess === null) return;

        $unitEnum = $this->toUnitEnum($unitGuess);
        if ($unitEnum === null) return;

        try {
            if ($ingredient->getUnit() === Unit::G) {
                $ingredient->setUnit($unitEnum);
            }
        } catch (\Throwable) {}
    }

    private function toUnitEnum(?string $unit): ?Unit
    {
        if ($unit === null) return null;

        $u = strtolower(trim($unit));
        if ($u === '' || $u === 'unknown') return null;

        return match ($u) {
            'g' => Unit::G,
            'kg' => Unit::KG,
            'ml' => Unit::ML,
            'l' => Unit::L,
            'piece' => Unit::PIECE,
            'pot' => Unit::POT,
            'boite' => Unit::BOITE,
            'sachet' => Unit::SACHET,
            'tranche' => Unit::TRANCHE,
            // si ton enum a PACK, garde-le, sinon enlève
            'pack' => Unit::PACK,
            default => null,
        };
    }
}
