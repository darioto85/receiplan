<?php

namespace App\Service\Ai;

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
     *     name_raw:string,
     *     name:string,
     *     quantity:float|null,
     *     quantity_raw:string|null,
     *     unit:string|null,
     *     unit_raw:string|null,
     *     notes:string|null,
     *     confidence:float
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

        $repo = $this->em->getRepository(Shopping::class);

        foreach ($items as $idx => $it) {
            if (!is_array($it)) continue;

            $name = trim((string)($it['name'] ?? $it['name_raw'] ?? ''));
            if ($name === '') {
                $warnings[] = ['index' => (int)$idx, 'warnings' => ['empty_name']];
                continue;
            }

            $qty = $it['quantity'] ?? null;
            if ($qty === null || $qty === '' || !is_numeric($qty)) {
                // On considère que la clarify doit déjà avoir rempli,
                // mais on reste safe côté serveur
                $warnings[] = ['index' => (int)$idx, 'warnings' => ['missing_quantity']];
                continue;
            }

            // unit canonical = Ingredient.unit (on peut passer un guess si l’IA en donne un)
            $unitGuess = is_string($it['unit'] ?? null) ? (string)$it['unit'] : null;

            $ingredient = $this->ingredientResolver->resolveOrCreate($user, $name, $unitGuess);

            /** @var Shopping|null $row */
            $row = $repo->findOneBy(['user' => $user, 'ingredient' => $ingredient]);

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
            }

            $row->addQuantity((float)$qty);
        }

        $this->em->flush();

        return [
            'updated' => $updated,
            'created' => $created,
            'warnings' => $warnings,
        ];
    }
}
