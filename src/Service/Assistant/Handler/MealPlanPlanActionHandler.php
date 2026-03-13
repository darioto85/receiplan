<?php

namespace App\Service\Assistant\Handler;

use App\Entity\User;
use App\Enum\AssistantActionType;
use App\Service\Ai\PlanRecipeHandler;

class MealPlanPlanActionHandler implements AssistantActionHandlerInterface
{
    public function __construct(
        private readonly PlanRecipeHandler $handler,
    ) {}

    public function type(): AssistantActionType
    {
        return AssistantActionType::MEAL_PLAN_PLAN;
    }

    public function execute(User $user, array $data): array
    {
        $recipeName = trim((string) ($data['recipe_name'] ?? ''));
        $date = trim((string) ($data['date'] ?? ''));

        if ($recipeName === '') {
            throw new \RuntimeException('meal_plan.plan: recipe_name manquant.');
        }

        if ($date === '') {
            throw new \RuntimeException('meal_plan.plan: date manquante.');
        }

        return $this->handler->handle($user, [
            'date' => $date,
            'recipe' => [
                'name_raw' => $recipeName,
                'name' => $recipeName,
            ],
        ]);
    }
}