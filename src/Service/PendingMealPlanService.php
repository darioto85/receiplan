<?php

namespace App\Service;

use App\Repository\MealPlanRepository;
use App\Repository\UserRepository;

final class PendingMealPlanService
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly MealPlanRepository $mealPlanRepo,
    ) {}

    /**
     * @return array{
     *   date:string,
     *   limit:int,
     *   offset:int,
     *   users:int,
     *   users_with_pending:int,
     *   users_without_pending:int,
     *   pending: array<int, array{
     *     user_id:int,
     *     meal_plan_id:int,
     *     recipe_id:int,
     *     recipe_name:string,
     *     date:string
     *   }>
     * }
     */
    public function run(int $limit = 200, int $offset = 0): array
    {
        $limit = max(1, min($limit, 1000));
        $offset = max(0, $offset);

        $yesterday = (new \DateTimeImmutable('today'))->modify('-1 day');
        $yesterdayStr = $yesterday->format('Y-m-d');

        // Batch users
        $users = $this->userRepo->findBy([], ['id' => 'ASC'], $limit, $offset);

        $pending = [];
        $with = 0;
        $without = 0;

        foreach ($users as $user) {
            $mp = $this->mealPlanRepo->findOneUnvalidatedForUserOnDate($user, $yesterday);

            if (!$mp) {
                $without++;
                continue;
            }

            $recipe = $mp->getRecipe();

            // Sécurité (normalement non-null)
            if (!$recipe) {
                $without++;
                continue;
            }

            $with++;

            $pending[] = [
                'user_id' => (int) $user->getId(),
                'meal_plan_id' => (int) $mp->getId(),
                'recipe_id' => (int) $recipe->getId(),
                'recipe_name' => (string) $recipe->getName(),
                'date' => $yesterdayStr,
            ];
        }

        return [
            'date' => $yesterdayStr,
            'limit' => $limit,
            'offset' => $offset,
            'users' => \count($users),
            'users_with_pending' => $with,
            'users_without_pending' => $without,
            'pending' => $pending,
        ];
    }
}
