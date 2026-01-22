<?php

namespace App\Service;

use App\Entity\Ingredient;
use App\Entity\Shopping;
use App\Entity\User;
use App\Repository\ShoppingRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ShoppingListService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShoppingRepository $shoppingRepository,
        private readonly RecipeFeasibilityService $feasibility,
    ) {}

    /**
     * Synchronise la liste "AUTO missing" à partir des recettes insuffisantes.
     *
     * Règles:
     * - Unique (user, ingredient) => une seule ligne possible.
     * - Si ligne MANUAL existe: on la garde, et on la monte seulement si le "missing" est supérieur.
     * - Si ligne AUTO existe: on l'overwrite avec le missing.
     * - Si missing <= 0:
     *    - on supprime la ligne AUTO
     *    - on ne touche pas aux MANUAL (l'utilisateur a peut-être ses raisons)
     *
     * Objectif: prendre en compte ce que l'utilisateur a déjà mis, et ne jamais "gonfler" à chaque sync.
     */
    public function syncAutoMissingFromInsufficientRecipes(User $user): void
    {
        $views = $this->feasibility->getInsufficientRecipes($user);

        // 1) Agréger missing par ingredientId
        /** @var array<int, array{ingredient: Ingredient, qty: float}> $missingByIngredientId */
        $missingByIngredientId = [];

        foreach ($views as $view) {
            foreach (($view['items'] ?? []) as $item) {
                if (($item['is_missing'] ?? false) !== true) {
                    continue;
                }

                $ingredient = $item['ingredient'] ?? null;
                if (!$ingredient instanceof Ingredient) {
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

        // 2) Charger toutes les lignes existantes
        /** @var Shopping[] $existingAll */
        $existingAll = $this->shoppingRepository->findBy(['user' => $user]);

        /** @var array<int, Shopping> $existingByIngredientId */
        $existingByIngredientId = [];

        foreach ($existingAll as $line) {
            $iid = $line->getIngredient()?->getId();
            if ($iid) {
                $existingByIngredientId[$iid] = $line;
            }
        }

        // 3) Appliquer le missing sur les lignes existantes / créer AUTO si besoin
        foreach ($missingByIngredientId as $iid => $row) {
            $missingQty = round((float) $row['qty'], 2);
            $ingredient = $row['ingredient'];

            $line = $existingByIngredientId[$iid] ?? null;

            if ($line === null) {
                // Rien n'existe -> créer une ligne AUTO avec missing
                $line = (new Shopping())
                    ->setUser($user)
                    ->setIngredient($ingredient)
                    ->setSource('auto')
                    ->setQuantity($missingQty);

                $this->em->persist($line);
                continue;
            }

            // Une ligne existe déjà (manual ou auto)
            $currentQty = round((float) $line->getQuantity(), 2);

            if ($line->isAuto() || $line->getSource() === 'auto') {
                // AUTO: overwrite idempotent
                $line->setQuantity($missingQty);
            } else {
                // MANUAL: prendre en compte ce que l'utilisateur a déjà mis
                // On veut "ajouter le complément" => au niveau DB on ne peut pas stocker 2 quantités,
                // donc on fixe la quantité totale à acheter = max(manual, missing).
                //
                // Différence à ajouter (pour comprendre le calcul) :
                // $deltaToAdd = max(0, $missingQty - $currentQty);
                // mais on n'applique PAS addQuantity (sinon ça gonfle à chaque sync).
                $line->setQuantity(max($currentQty, $missingQty));
            }
        }

        // 4) Supprimer les AUTO qui ne sont plus manquants
        //    MAIS uniquement les lignes source=auto, pour éviter de supprimer du manuel.
        foreach ($existingAll as $line) {
            if (!$line->isAuto()) {
                continue;
            }

            $iid = $line->getIngredient()?->getId();
            if (!$iid) {
                continue;
            }

            // Si l'ingrédient n'est plus dans les missing -> on supprime
            if (!isset($missingByIngredientId[$iid])) {
                $this->em->remove($line);
            }
        }

        $this->em->flush();
    }
}
