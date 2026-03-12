<?php

namespace App\Service\Ai;

use App\Entity\Ingredient;
use App\Entity\Shopping;
use App\Entity\User;
use App\Enum\Unit;
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
            if (!is_array($it)) {
                continue;
            }

            $qty = $it['quantity'] ?? null;
            if ($qty === null || $qty === '' || !is_numeric($qty)) {
                $warnings[] = ['index' => (int) $idx, 'warnings' => ['missing_quantity']];
                continue;
            }

            $qty = (float) $qty;
            if ($qty <= 0) {
                $warnings[] = ['index' => (int) $idx, 'warnings' => ['non_positive_quantity']];
                continue;
            }

            $unit = is_string($it['unit'] ?? null) ? (string) $it['unit'] : null;
            $unitEnum = $this->mapUnit($unit);

            if ($unit !== null && $unitEnum === null) {
                $warnings[] = ['index' => (int) $idx, 'warnings' => ['unsupported_unit']];
            }

            $ingredient = null;

            $iid = $it['ingredient_id'] ?? null;
            if (is_numeric($iid)) {
                $found = $ingredientRepo->find((int) $iid);
                if ($found instanceof Ingredient) {
                    $owner = $found->getUser();
                    if ($owner === null || $owner->getId() === $user->getId()) {
                        $ingredient = $found;
                    }
                }
            }

            if (!$ingredient instanceof Ingredient) {
                $name = trim((string) ($it['name'] ?? $it['name_raw'] ?? ''));
                if ($name === '') {
                    $warnings[] = ['index' => (int) $idx, 'warnings' => ['empty_name']];
                    continue;
                }

                $ingredient = $this->ingredientResolver->resolveOrCreate($user, $name, $unit);
            }

            /** @var Shopping|null $row */
            $row = $shoppingRepo->findOneBy([
                'user' => $user,
                'ingredient' => $ingredient,
            ]);

            if (!$row) {
                $row = new Shopping();
                $row->setUser($user);
                $row->setIngredient($ingredient);
                $row->setSource('manual');
                $row->setChecked(false);
                $row->setQuantity(0.0);

                if ($unitEnum instanceof Unit) {
                    $row->setUnit($unitEnum);
                }

                $this->em->persist($row);
                $created++;
            } else {
                $updated++;

                if ($row->getSource() === 'auto') {
                    $row->setSource('manual');
                }

                if ($unitEnum instanceof Unit) {
                    $row->setUnit($unitEnum);
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

    private function mapUnit(?string $unit): ?Unit
    {
        if ($unit === null || trim($unit) === '') {
            return null;
        }

        return match ($unit) {
            'g' => Unit::G,
            'kg' => Unit::KG,
            'ml' => Unit::ML,
            'l' => Unit::L,
            'piece' => Unit::PIECE,
            'pot' => Unit::POT,
            'boite' => Unit::BOITE,
            'sachet' => Unit::SACHET,
            'tranche' => Unit::TRANCHE,
            'paquet' => Unit::PAQUET,
            default => null,
        };
    }
}