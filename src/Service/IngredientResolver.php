<?php

namespace App\Service;

use App\Entity\Ingredient;
use App\Entity\User;
use App\Enum\Unit;
use Doctrine\ORM\EntityManagerInterface;

final class IngredientResolver
{
    /**
     * Cache in-memory pour éviter de recréer/rérequêter le même ingrédient
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
     * Résout ou crée un Ingredient pour un user donné.
     *
     * Stratégie:
     * 1) GLOBAL (user = null)
     * 2) PRIVÉ (user = $user)
     * 3) sinon création PRIVÉ (user = $user)
     */
    public function resolveOrCreate(User $user, string $normalizedName, ?string $unitGuess = null): Ingredient
    {
        $nameKey = $this->keyNormalizer->toKey($normalizedName);

        // ✅ Cache: on privilégie un cache "global" si existant
        $cacheKeyGlobal = 'global|' . $nameKey;
        $cacheKeyPrivate = ((string) $user->getId()) . '|' . $nameKey;

        // 1) Cache global
        if (isset($this->cache[$cacheKeyGlobal])) {
            $ingredient = $this->cache[$cacheKeyGlobal];
            $this->maybeCompleteUnit($ingredient, $unitGuess);
            return $ingredient;
        }

        // 2) Cache privé
        if (isset($this->cache[$cacheKeyPrivate])) {
            $ingredient = $this->cache[$cacheKeyPrivate];
            $this->maybeCompleteUnit($ingredient, $unitGuess);
            return $ingredient;
        }

        $repo = $this->em->getRepository(Ingredient::class);

        // ✅ 1) Cherche d'abord GLOBAL
        /** @var Ingredient|null $ingredient */
        $ingredient = $repo->findOneBy([
            'user' => null,
            'nameKey' => $nameKey,
        ]);

        if ($ingredient) {
            $this->maybeCompleteUnit($ingredient, $unitGuess);
            $this->cache[$cacheKeyGlobal] = $ingredient;
            return $ingredient;
        }

        // ✅ 2) Sinon cherche PRIVÉ du user
        /** @var Ingredient|null $ingredient */
        $ingredient = $repo->findOneBy([
            'user' => $user,
            'nameKey' => $nameKey,
        ]);

        if ($ingredient) {
            $this->maybeCompleteUnit($ingredient, $unitGuess);
            $this->cache[$cacheKeyPrivate] = $ingredient;
            return $ingredient;
        }

        // ✅ 3) Sinon crée en PRIVÉ
        $ingredient = new Ingredient();
        $ingredient->setUser($user);
        $ingredient->setName($normalizedName); // setName() remplit aussi nameKey
        $ingredient->setNameKey($nameKey);

        // ✅ Conversion string -> Unit enum
        $unitEnum = $this->toUnitEnum($unitGuess);
        if ($unitEnum !== null) {
            $ingredient->setUnit($unitEnum);
        }

        $this->em->persist($ingredient);

        // ✅ cache immédiat (privé)
        $this->cache[$cacheKeyPrivate] = $ingredient;

        return $ingredient;
    }

    private function maybeCompleteUnit(Ingredient $ingredient, ?string $unitGuess): void
    {
        if ($unitGuess === null) {
            return;
        }

        $unitEnum = $this->toUnitEnum($unitGuess);
        if ($unitEnum === null) {
            return;
        }

        // ⚠️ Important: dans ton Entity, Unit n'est pas nullable et vaut Unit::G par défaut.
        // Donc on "complète" seulement si l'unité actuelle est le défaut Unit::G.
        try {
            if ($ingredient->getUnit() === Unit::G) {
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
