<?php

namespace App\Service\Ai;

use App\Entity\Ingredient;
use App\Entity\User;
use App\Entity\UserIngredient;
use App\Service\IngredientResolver;
use Doctrine\ORM\EntityManagerInterface;

final class AiConsumeStockHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IngredientResolver $ingredientResolver,
    ) {}

    /**
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
     *   updated:int,
     *   not_found:int,
     *   warnings:array<int,array{index:int,warnings:string[]}>
     * }
     */
    public function handle(User $user, array $payload): array
    {
        $items = $payload['items'] ?? null;
        if (!is_array($items)) {
            throw new \InvalidArgumentException('payload.items manquant.');
        }

        $updated = 0;
        $notFound = 0;
        $warnings = [];

        $ingredientRepo = $this->em->getRepository(Ingredient::class);
        $uiRepo = $this->em->getRepository(UserIngredient::class);

        foreach ($items as $idx => $it) {
            if (!is_array($it)) continue;

            // quantity obligatoire pour consume
            $qty = $it['quantity'] ?? null;
            if ($qty === null || $qty === '' || !is_numeric($qty)) {
                $warnings[] = ['index' => (int)$idx, 'warnings' => ['missing_quantity']];
                continue;
            }

            $qty = (float) $qty;
            if ($qty <= 0) {
                $warnings[] = ['index' => (int)$idx, 'warnings' => ['non_positive_quantity']];
                continue;
            }

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

                $unitGuess = $it['unit'] ?? null;

                $ingredient = $this->ingredientResolver->resolveOrCreate(
                    $user,
                    $name,
                    is_string($unitGuess) ? $unitGuess : null
                );
            }

            if (!$ingredient instanceof Ingredient) {
                $warnings[] = ['index' => (int)$idx, 'warnings' => ['ingredient_not_resolved']];
                continue;
            }

            /** @var UserIngredient|null $ui */
            $ui = $uiRepo->findOneBy([
                'user' => $user,
                'ingredient' => $ingredient,
            ]);

            if (!$ui) {
                $notFound++;
                continue;
            }

            $current = $ui->getQuantityFloat();
            $new = max(0.0, $current - $qty);
            $ui->setQuantityFloat($new);
            $updated++;
        }

        $this->em->flush();

        return [
            'updated' => $updated,
            'not_found' => $notFound,
            'warnings' => $warnings,
        ];
    }
}
