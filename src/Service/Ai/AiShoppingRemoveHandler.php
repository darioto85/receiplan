<?php

namespace App\Service\Ai;

use App\Entity\Ingredient;
use App\Entity\Shopping;
use App\Entity\User;
use App\Service\IngredientResolver;
use Doctrine\ORM\EntityManagerInterface;

final class AiShoppingRemoveHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IngredientResolver $ingredientResolver,
    ) {}

    /**
     * V1 règle simple:
     * - si quantity est null => on supprime la ligne
     * - si quantity est fourni => on décrémente, et si <= 0 => supprime
     *
     * @param array{
     *   items: array<int, array{
     *     ingredient_id?: int|null,
     *     name_raw?: string,
     *     name?: string,
     *     name_key?: string,
     *     quantity: float|null,
     *     quantity_raw?: string|null,
     *     unit?: string|null,
     *     unit_raw?: string|null,
     *     notes?: string|null,
     *     confidence?: float
     *   }>
     * } $payload
     *
     * @return array{
     *   removed:int,
     *   decremented:int,
     *   not_found:int,
     *   warnings: array<int, array{index:int, warnings:string[]}>
     * }
     */
    public function handle(User $user, array $payload): array
    {
        $items = $payload['items'] ?? null;
        if (!is_array($items)) {
            throw new \InvalidArgumentException('payload.items manquant.');
        }

        $removed = 0;
        $decremented = 0;
        $notFound = 0;
        $warnings = [];

        $shoppingRepo = $this->em->getRepository(Shopping::class);
        $ingredientRepo = $this->em->getRepository(Ingredient::class);

        foreach ($items as $idx => $it) {
            if (!is_array($it)) continue;

            // Résolution ingrédient: ingredient_id > name
            $ingredient = null;

            $iid = $it['ingredient_id'] ?? null;
            if (is_numeric($iid)) {
                $found = $ingredientRepo->find((int)$iid);
                if ($found instanceof Ingredient) {
                    $owner = $found->getUser();
                    if ($owner === null || $owner->getId() === $user->getId()) {
                        $ingredient = $found;
                    }
                }
            }

            if (!$ingredient instanceof Ingredient) {
                $name = trim((string)($it['name'] ?? $it['name_raw'] ?? ''));
                if ($name === '') {
                    $warnings[] = ['index' => (int)$idx, 'warnings' => ['empty_name']];
                    continue;
                }

                // V1: on accepte resolveOrCreate (rare création). Pas de fuzzy typo.
                $ingredient = $this->ingredientResolver->resolveOrCreate($user, $name, null);
            }

            /** @var Shopping|null $row */
            $row = $shoppingRepo->findOneBy(['user' => $user, 'ingredient' => $ingredient]);

            if (!$row) {
                $notFound++;
                continue;
            }

            $qty = $it['quantity'] ?? null;

            // qty null => delete
            if ($qty === null || $qty === '' || !is_numeric($qty)) {
                $this->em->remove($row);
                $removed++;
                continue;
            }

            $qty = (float) $qty;
            if ($qty <= 0) {
                // rien à faire, mais on ne casse pas
                $warnings[] = ['index' => (int)$idx, 'warnings' => ['non_positive_quantity']];
                continue;
            }

            $newQty = $row->getQuantity() - $qty;
            if ($newQty <= 0) {
                $this->em->remove($row);
                $removed++;
            } else {
                $row->setQuantity($newQty);
                $decremented++;
            }
        }

        $this->em->flush();

        return [
            'removed' => $removed,
            'decremented' => $decremented,
            'not_found' => $notFound,
            'warnings' => $warnings,
        ];
    }
}
