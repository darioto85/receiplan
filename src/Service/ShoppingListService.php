<?php

namespace App\Service;

use App\Entity\Ingredient;
use App\Entity\Shopping;
use App\Entity\User;
use App\Enum\Unit;
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
     * ✅ Mode "Toutes mes recettes"
     */
    public function syncAutoMissingFromAllRecipes(User $user): void
    {
        $views = $this->feasibility->getInsufficientRecipes($user);
        $this->syncAutoMissingFromViews($user, $views);
    }

    /**
     * ✅ Mode "Mes recettes favorites"
     */
    public function syncAutoMissingFromFavoriteRecipes(User $user): void
    {
        $views = $this->feasibility->getInsufficientRecipes($user);

        $filtered = [];
        foreach ($views as $view) {
            $recipe = $view['recipe'] ?? null;

            if ($recipe && method_exists($recipe, 'isFavorite') && $recipe->isFavorite()) {
                $filtered[] = $view;
            }
        }

        $this->syncAutoMissingFromViews($user, $filtered);
    }

    /**
     * ✅ Mode "Semaine qui vient"
     */
    public function syncAutoMissingFromPlannedWeek(
        User $user,
        ?\DateTimeImmutable $from = null
    ): void {
        $from ??= new \DateTimeImmutable('today');
        $to = $from->modify('+7 days');

        $views = $this->feasibility->getInsufficientPlannedRecipes(
            $user,
            $from,
            $to,
            true
        );

        $this->syncAutoMissingFromViews($user, $views);
    }

    /**
     * Ancienne méthode conservée pour compatibilité
     */
    public function syncAutoMissingFromInsufficientRecipes(User $user): void
    {
        $this->syncAutoMissingFromAllRecipes($user);
    }

    /**
     * 🔁 Implémentation centrale
     *
     * Compromis UX demandé :
     * - On NE SUPPRIME PAS les lignes AUTO automatiquement.
     * - On NE DIMINUE PAS une ligne AUTO automatiquement.
     *
     * => Donc les générations successives (favorites, puis week, etc.) s'additionnent naturellement.
     *
     * @param iterable<mixed> $views
     */
    private function syncAutoMissingFromViews(User $user, iterable $views): void
    {
        /**
         * @var array<int, array{
         *     ingredient: Ingredient,
         *     qty: float,
         *     unit: Unit
         * }> $missingByIngredientId
         */
        $missingByIngredientId = [];

        // 1) Agréger missing par ingrédient
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

                $unit = $item['unit'] ?? null;
                if (!$unit instanceof Unit) {
                    $unit = $ingredient->getUnit();
                }

                if (!isset($missingByIngredientId[$iid])) {
                    $missingByIngredientId[$iid] = [
                        'ingredient' => $ingredient,
                        'qty' => 0.0,
                        'unit' => $unit,
                    ];
                }

                // ⚠️ Si jamais le même ingrédient remonte avec plusieurs unités différentes,
                // ton modèle actuel Shopping (unique user + ingredient) ne permet pas
                // de stocker plusieurs lignes séparées pour le même ingrédient.
                // Donc ici on garde l’unité de la première occurrence.
                $missingByIngredientId[$iid]['qty'] += $missing;
            }
        }

        // 2) Charger toutes les lignes existantes (user)
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

        // 3) Upsert / update (sans suppression)
        foreach ($missingByIngredientId as $iid => $row) {
            $missingQty = round((float) $row['qty'], 2);
            $ingredient = $row['ingredient'];
            $unit = $row['unit'];

            $line = $existingByIngredientId[$iid] ?? null;

            if ($line === null) {
                $line = (new Shopping())
                    ->setUser($user)
                    ->setIngredient($ingredient)
                    ->setSource('auto')
                    ->setUnit($unit)
                    ->setQuantity($missingQty);

                $this->em->persist($line);
                continue;
            }

            $currentQty = round((float) $line->getQuantity(), 2);

            // ✅ on synchronise aussi l’unité de la ligne shopping
            $line->setUnit($unit);

            if ($line->isAuto()) {
                $line->setQuantity(max($currentQty, $missingQty));
            } else {
                $line->setQuantity(max($currentQty, $missingQty));
            }
        }

        // ✅ 4) IMPORTANT: on ne supprime plus les AUTO absents du missing

        $this->em->flush();
    }
}