<?php

namespace App\Service\Ai;

use App\Entity\Ingredient;
use App\Entity\Shopping;
use App\Entity\User;
use App\Service\IngredientResolver;
use Doctrine\ORM\EntityManagerInterface;

final class AiShoppingAddHandler
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
     *   created:int,
     *   warnings: array<int, array{index:int, warnings:string[]}>
     * }
     */
    public function handle(User $user, array $payload): array
    {
        $items = $payload['items'] ?? null;
        if (!is_array($items)) {
            throw new \InvalidArgumentException('payload.items manquant.');
        }

        $updated = 0;
        $created = 0;
        $warnings = [];

        $shoppingRepo = $this->em->getRepository(Shopping::class);
        $ingredientRepo = $this->em->getRepository(Ingredient::class);

        foreach ($items as $idx => $it) {
            if (!is_array($it)) continue;

            // quantity obligatoire pour add
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

                $unitGuess = is_string($it['unit'] ?? null) ? (string)$it['unit'] : null;
                $ingredient = $this->ingredientResolver->resolveOrCreate($user, $name, $unitGuess);
            }

            /** @var Shopping|null $row */
            $row = $shoppingRepo->findOneBy(['user' => $user, 'ingredient' => $ingredient]);

            if (!$row) {
                $row = new Shopping();
                $row->setUser($user);
                $row->setIngredient($ingredient);
                $row->setSource('manual');
                $row->setChecked(false);
                $row->setQuantity(0.0);

                $this->em->persist($row);
                $created++;
            } else {
                $updated++;
                // si la ligne existante est auto, on la “prend en main” (optionnel mais UX souvent attendue)
                if ($row->getSource() === 'auto') {
                    $row->setSource('manual');
                }
            }

            $row->addQuantity($qty);
        }

        $this->em->flush();

        return [
            'updated' => $updated,
            'created' => $created,
            'warnings' => $warnings,
        ];
    }
}
