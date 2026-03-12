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
        $date = $data['date'] ?? null;
        $meal = $data['meal'] ?? null;

        if ($recipeName === '') {
            throw new \RuntimeException('meal_plan.plan: recipe_name manquant.');
        }

        if (!is_string($date) || trim($date) === '') {
            throw new \RuntimeException('meal_plan.plan: date manquante.');
        }

        if (!is_string($meal) || trim($meal) === '') {
            throw new \RuntimeException('meal_plan.plan: meal manquant.');
        }

        return $this->handler->handle($user, [
            'recipe_name' => $recipeName,
            'date' => $date,
            'meal' => $meal,
        ]);
    }
}