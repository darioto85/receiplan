<?php

namespace App\Service\Assistant;

use App\Entity\AssistantRun;
use App\Entity\AssistantRunAction;
use App\Enum\AssistantActionStatus;
use App\Enum\AssistantActionType;
use App\Repository\AssistantRunActionRepository;
use Doctrine\ORM\EntityManagerInterface;

class AssistantRunActionManager
{
    public function __construct(
        private readonly AssistantRunActionRepository $runActionRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $actionsPayload
     *
     * @return array<int, AssistantRunAction>
     */
    public function syncActions(AssistantRun $run, array $actionsPayload): array
    {
        $synced = [];

        foreach ($actionsPayload as $index => $actionPayload) {
            if (!is_array($actionPayload)) {
                continue;
            }

            $clientActionId = trim((string) ($actionPayload['client_action_id'] ?? ''));
            $typeValue = trim((string) ($actionPayload['type'] ?? ''));
            $statusValue = trim((string) ($actionPayload['status'] ?? ''));
            $data = $actionPayload['data'] ?? [];
            $missing = $actionPayload['missing'] ?? [];

            if ($clientActionId === '' || $typeValue === '' || $statusValue === '') {
                continue;
            }

            if (!is_array($data)) {
                $data = [];
            }

            if (!is_array($missing)) {
                $missing = [];
            }

            $type = AssistantActionType::tryFrom($typeValue);
            $status = AssistantActionStatus::tryFrom($statusValue);

            if (!$type instanceof AssistantActionType || !$status instanceof AssistantActionStatus) {
                continue;
            }

            // ✅ Garde-fou métier :
            // une action avec missing ne doit jamais être READY
            if ($missing !== []) {
                $status = AssistantActionStatus::NEEDS_INPUT;
            }

            $action = $this->runActionRepository->findOneBy([
                'run' => $run,
                'clientActionId' => $clientActionId,
            ]);

            if (!$action instanceof AssistantRunAction) {
                $action = new AssistantRunAction(
                    $run,
                    $clientActionId,
                    $type,
                    $status
                );

                $run->addAction($action);
                $this->em->persist($action);
            } else {
                $action->setType($type);
                $action->setStatus($status);
            }

            $action->setData($data);
            $action->setMissing($missing);
            $action->setExecutionOrder($this->resolveExecutionOrder($type, $index));

            $synced[] = $action;
        }

        return $synced;
    }

    /**
     * @return array<int, AssistantRunAction>
     */
    public function getReadyActions(AssistantRun $run): array
    {
        return $this->runActionRepository->findBy(
            [
                'run' => $run,
                'status' => AssistantActionStatus::READY,
            ],
            [
                'executionOrder' => 'ASC',
                'id' => 'ASC',
            ]
        );
    }

    public function hasBlockingMissing(AssistantRun $run): bool
    {
        $actions = $this->runActionRepository->findByRunOrdered($run);

        foreach ($actions as $action) {
            $missing = $action->getMissing();
            if (is_array($missing) && $missing !== []) {
                return true;
            }
        }

        return false;
    }

    private function resolveExecutionOrder(AssistantActionType $type, int $fallbackIndex): int
    {
        return match ($type) {
            AssistantActionType::STOCK_REMOVE => 10,
            AssistantActionType::STOCK_UPDATE => 20,
            AssistantActionType::STOCK_ADD => 30,

            AssistantActionType::SHOPPING_REMOVE => 40,
            AssistantActionType::SHOPPING_UPDATE => 50,
            AssistantActionType::SHOPPING_ADD => 60,

            AssistantActionType::RECIPE_UPDATE => 70,
            AssistantActionType::RECIPE_ADD => 80,

            AssistantActionType::MEAL_PLAN_UNPLAN => 90,
            AssistantActionType::MEAL_PLAN_PLAN => 100,
        } + $fallbackIndex;
    }
}