<?php

namespace App\Service\Ai;

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

        $repo = $this->em->getRepository(Shopping::class);

        foreach ($items as $idx => $it) {
            if (!is_array($it)) continue;

            $name = trim((string)($it['name'] ?? $it['name_raw'] ?? ''));
            if ($name === '') {
                $warnings[] = ['index' => (int)$idx, 'warnings' => ['empty_name']];
                continue;
            }

            // On résout l’ingrédient (si absent, on évite de le créer pour un remove)
            // => On tente d’abord global/private en DB via IngredientResolver, mais lui va créer.
            // Pour rester simple V1: on accepte le create (rare), OU tu peux faire un resolver "findOnly" plus tard.
            $ingredient = $this->ingredientResolver->resolveOrCreate($user, $name, null);

            /** @var Shopping|null $row */
            $row = $repo->findOneBy(['user' => $user, 'ingredient' => $ingredient]);

            if (!$row) {
                $notFound++;
                continue;
            }

            $qty = $it['quantity'] ?? null;

            if ($qty === null || $qty === '' || !is_numeric($qty)) {
                // delete
                $this->em->remove($row);
                $removed++;
                continue;
            }

            $newQty = $row->getQuantity() - (float)$qty;
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
