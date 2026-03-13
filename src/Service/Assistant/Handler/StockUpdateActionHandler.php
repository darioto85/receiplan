<?php

namespace App\Service\Assistant\Handler;

use App\Entity\User;
use App\Entity\UserIngredient;
use App\Enum\AssistantActionType;
use App\Enum\Unit;
use App\Service\IngredientResolver;
use Doctrine\ORM\EntityManagerInterface;

class StockUpdateActionHandler implements AssistantActionHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IngredientResolver $ingredientResolver,
    ) {}

    public function type(): AssistantActionType
    {
        return AssistantActionType::STOCK_UPDATE;
    }

    /**
     * stock.update = définir la quantité totale actuelle en stock.
     * Si l'ingrédient n'existe pas encore dans le stock utilisateur, on le crée.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function execute(User $user, array $data): array
    {
        if (!isset($data['items']) || !is_array($data['items'])) {
            throw new \RuntimeException('stock.update: items manquant.');
        }

        $updated = 0;
        $created = 0;
        $deleted = 0;
        $warnings = [];

        /** @var \Doctrine\ORM\EntityRepository<UserIngredient> $userIngredientRepo */
        $userIngredientRepo = $this->em->getRepository(UserIngredient::class);

        foreach ($data['items'] as $idx => $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                $warnings[] = [
                    'index' => (int) $idx,
                    'warnings' => ['empty_name'],
                ];
                continue;
            }

            $quantity = $item['quantity'] ?? null;
            if (!is_numeric($quantity)) {
                $warnings[] = [
                    'index' => (int) $idx,
                    'warnings' => ['missing_quantity'],
                ];
                continue;
            }

            $quantity = (float) $quantity;
            $unit = $item['unit'] ?? null;
            $unitEnum = $this->mapUnit(is_string($unit) ? $unit : null);

            if ($unit !== null && $unitEnum === null) {
                $warnings[] = [
                    'index' => (int) $idx,
                    'warnings' => ['unsupported_unit'],
                ];
            }

            $ingredient = $this->ingredientResolver->resolveOrCreate(
                $user,
                $name,
                is_string($unit) ? $unit : null
            );

            $userIngredient = $userIngredientRepo->findOneBy([
                'user' => $user,
                'ingredient' => $ingredient,
            ]);

            // quantité <= 0 => on supprime du stock
            if ($quantity <= 0) {
                if ($userIngredient instanceof UserIngredient) {
                    $this->em->remove($userIngredient);
                    $deleted++;
                } else {
                    $warnings[] = [
                        'index' => (int) $idx,
                        'warnings' => ['not_found_for_delete'],
                    ];
                }

                continue;
            }

            if (!$userIngredient instanceof UserIngredient) {
                $userIngredient = new UserIngredient();
                $userIngredient->setUser($user);
                $userIngredient->setIngredient($ingredient);

                if ($unitEnum instanceof Unit) {
                    $userIngredient->setUnit($unitEnum);
                }

                $this->em->persist($userIngredient);
                $created++;
            } else {
                if ($unitEnum instanceof Unit) {
                    $userIngredient->setUnit($unitEnum);
                }
                $updated++;
            }

            $userIngredient->setQuantityFloat($quantity);
        }

        $this->em->flush();

        return [
            'updated' => $updated,
            'created' => $created,
            'deleted' => $deleted,
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