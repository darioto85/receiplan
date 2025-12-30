<?php

namespace App\Service;

use App\Entity\Shopping;
use App\Entity\User;
use App\Entity\UserIngredient;
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

        // cache in-memory: ingredientId => UserIngredient
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
            if ($qty <= 0) {
                // si quantité invalide, on ignore (doux) ou on peut aussi supprimer
                continue;
            }

            $iid = $ingredient->getId();
            if (!$iid) {
                continue;
            }

            // Upsert stock (user + ingredient)
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
                        ->setQuantity(0);

                    $this->em->persist($ui);
                }

                $stockCache[$iid] = $ui;
            }

            $current = (float) ($ui->getQuantity() ?? 0);
            $ui->setQuantity(number_format($current + $qty, 2, '.', ''));

            // Remove shopping line (car acheté)
            $this->em->remove($item);

            $count++;
        }

        $this->em->flush();

        return $count;
    }
}
