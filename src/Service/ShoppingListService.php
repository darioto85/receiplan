<?php

namespace App\Service;

use App\Entity\Ingredient;
use App\Entity\Shopping;
use App\Entity\User;
use App\Repository\ShoppingRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final class ShoppingListService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShoppingRepository $shoppingRepository,
        private readonly RecipeFeasibilityService $feasibility,
    ) {}

    public function syncAutoMissingFromInsufficientRecipes(User $user): void
    {
        $views = $this->feasibility->getInsufficientRecipes($user);

        // 1) Agréger missing par ingredientId
        $missingByIngredientId = []; // int => [ingredient, qty]
        foreach ($views as $view) {
            foreach (($view['items'] ?? []) as $item) {
                if (($item['is_missing'] ?? false) !== true) {
                    continue;
                }

                $ingredient = $item['ingredient'] ?? null;
                if (!$ingredient instanceof \App\Entity\Ingredient) {
                    continue;
                }

                $missing = round((float) ($item['missing_amount'] ?? 0.0), 2);
                if ($missing <= 0) {
                    continue;
                }

                $iid = $ingredient->getId();
                if (!$iid) {
                    continue;
                }

                if (!isset($missingByIngredientId[$iid])) {
                    $missingByIngredientId[$iid] = ['ingredient' => $ingredient, 'qty' => 0.0];
                }

                $missingByIngredientId[$iid]['qty'] += $missing;
            }
        }

        // 2) Charger les lignes AUTO existantes seulement
        $existingAuto = $this->shoppingRepository->findBy([
            'user' => $user,
            'source' => 'auto',
        ]);

        $existingByIngredientId = [];
        foreach ($existingAuto as $line) {
            $iid = $line->getIngredient()?->getId();
            if ($iid) {
                $existingByIngredientId[$iid] = $line;
            }
        }

        // 3) Upsert (AUTO) : setQuantity
        foreach ($missingByIngredientId as $iid => $row) {
            $qty = round((float) $row['qty'], 2);
            $ingredient = $row['ingredient'];

            $line = $existingByIngredientId[$iid] ?? null;

            if (!$line) {
                $line = (new \App\Entity\Shopping())
                    ->setUser($user)
                    ->setIngredient($ingredient)
                    ->setSource('auto')
                    ->setQuantity($qty);

                $this->em->persist($line);
            } else {
                $line->setQuantity($qty);
            }

            unset($existingByIngredientId[$iid]); // reste = à supprimer (AUTO seulement)
        }

        // 4) Supprimer les AUTO qui ne sont plus manquants
        foreach ($existingByIngredientId as $line) {
            $this->em->remove($line);
        }

        $this->em->flush();
    }


}
