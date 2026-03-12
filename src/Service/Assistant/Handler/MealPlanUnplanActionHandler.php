<?php

namespace App\Service\Assistant\Handler;

use App\Entity\User;
use App\Enum\AssistantActionType;
use App\Service\Ai\UnplanRecipeHandler;

class MealPlanUnplanActionHandler implements AssistantActionHandlerInterface
{
    public function __construct(
        private readonly UnplanRecipeHandler $handler,
    ) {}

    public function type(): AssistantActionType
    {
        return AssistantActionType::MEAL_PLAN_UNPLAN;
    }

    public function execute(User $user, array $data): array
    {
        $date = $data['date'] ?? null;
        $meal = $data['meal'] ?? null;

        if (!is_string($date) || trim($date) === '') {
            throw new \RuntimeException('meal_plan.unplan: date manquante.');
        }

        if (!is_string($meal) || trim($meal) === '') {
            throw new \RuntimeException('meal_plan.unplan: meal manquant.');
        }

        return $this->handler->handle($user, [
            'date' => $date,
            'meal' => $meal,
        ]);
    }
}