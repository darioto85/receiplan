<?php

namespace App\Service;

use App\Entity\Shopping;
use App\Entity\User;
use App\Entity\UserIngredient;
use App\Enum\Unit;
use App\Repository\ShoppingRepository;
use App\Repository\UserIngredientRepository;
use Doctrine\ORM\EntityManagerInterface;

final class CartValidatorService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShoppingRepository $shoppingRepository,
        private readonly UserIngredientRepository $userIngredientRepository,
    ) {}

    /**
     * Parcourt les items cochés, ajoute au stock, puis supprime de la shopping list.
     *
     * @return int nombre d'items validés
     */
    public function validateCheckedCart(User $user): int
    {
        $checkedItems = $this->shoppingRepository->findCheckedForUser($user);

        /** @var array<int, UserIngredient> $stockCache */
        $stockCache = [];

        $count = 0;

        foreach ($checkedItems as $item) {
            if (!$item instanceof Shopping) {
                continue;
            }

            $ingredient = $item->getIngredient();
            if (!$ingredient) {
                continue;
            }

            $qty = (float) $item->getQuantity();
            $shoppingUnit = $item->getUnit();

            if ($qty <= 0) {
                continue;
            }

            $iid = $ingredient->getId();
            if (!$iid) {
                continue;
            }

            $ui = $stockCache[$iid] ?? null;
            if (!$ui) {
                $ui = $this->userIngredientRepository->findOneBy([
                    'user' => $user,
                    'ingredient' => $ingredient,
                ]);

                if (!$ui) {
                    $ui = (new UserIngredient())
                        ->setUser($user)
                        ->setIngredient($ingredient)
                        ->setQuantity('0.00')
                        ->setUnit($shoppingUnit);

                    $this->em->persist($ui);
                }

                $stockCache[$iid] = $ui;
            }

            $currentQty = $ui->getQuantityFloat();
            $stockUnit = $ui->getUnit();

            if ($stockUnit === $shoppingUnit) {
                $ui->setQuantityFloat($currentQty + $qty);

                $this->em->remove($item);
                $count++;
                continue;
            }

            $convertedQty = $this->convertQuantity($qty, $shoppingUnit, $stockUnit);

            if ($convertedQty !== null) {
                $ui->setQuantityFloat($currentQty + $convertedQty);

                $this->em->remove($item);
                $count++;
                continue;
            }

            /**
             * Cas non convertible (ex: pot -> g, pièce -> ml, etc.)
             *
             * Comme ton modèle impose une seule ligne de stock par ingredient,
             * on ne peut pas stocker plusieurs unités pour le même ingrédient.
             *
             * Donc ici :
             * - si le stock existant est vide, on adopte l'unité du panier
             * - sinon on ignore la validation de cet item pour éviter une corruption d'unité
             */
            if ($currentQty <= 0.0) {
                $ui->setUnit($shoppingUnit);
                $ui->setQuantityFloat($qty);

                $this->em->remove($item);
                $count++;
            }
        }

        $this->em->flush();

        return $count;
    }

    private function convertQuantity(float $quantity, Unit $from, Unit $to): ?float
    {
        if ($from === $to) {
            return $quantity;
        }

        $fromMeta = $this->getUnitMeta($from);
        $toMeta = $this->getUnitMeta($to);

        if ($fromMeta === null || $toMeta === null) {
            return null;
        }

        if ($fromMeta['family'] !== $toMeta['family']) {
            return null;
        }

        $baseQuantity = $quantity * $fromMeta['factor'];

        return $baseQuantity / $toMeta['factor'];
    }

    /**
     * factor = multiplicateur vers l'unité canonique de la famille
     * - poids  : base = g
     * - volume : base = ml
     * - autres : pas de conversion inter-unités
     *
     * @return array{family: string, factor: float}|null
     */
    private function getUnitMeta(Unit $unit): ?array
    {
        return match ($unit) {
            Unit::G => ['family' => 'weight', 'factor' => 1.0],
            Unit::KG => ['family' => 'weight', 'factor' => 1000.0],

            Unit::ML => ['family' => 'volume', 'factor' => 1.0],
            Unit::L => ['family' => 'volume', 'factor' => 1000.0],

            Unit::PIECE => ['family' => 'piece', 'factor' => 1.0],
            Unit::POT => ['family' => 'pot', 'factor' => 1.0],
            Unit::BOITE => ['family' => 'boite', 'factor' => 1.0],
            Unit::SACHET => ['family' => 'sachet', 'factor' => 1.0],
            Unit::TRANCHE => ['family' => 'tranche', 'factor' => 1.0],
            Unit::PAQUET => ['family' => 'paquet', 'factor' => 1.0],
        };
    }
}