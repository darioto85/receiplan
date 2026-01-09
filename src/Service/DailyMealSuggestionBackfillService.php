<?php

namespace App\Service;

use App\Entity\DailyMealSuggestion;
use App\Repository\UserRepository;

final class DailyMealSuggestionBackfillService
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly DailyMealSuggestionService $service,
        private readonly PushNotifier $pushNotifier,
    ) {}

    public function backfillToday(): array
    {
        $users = $this->userRepo->findAll();

        $created = 0;
        $existing = 0;
        $nonePossible = 0;

        $pushSentUsers = 0;
        $pushSentTotal = 0;
        $pushFailedTotal = 0;
        $pushDeletedTotal = 0;

        foreach ($users as $user) {
            $result = $this->service->ensureTodaySuggestion($user, DailyMealSuggestion::CONTEXT_CRON_BACKFILL);

            if ($result->created) {
                $created++;
            } else {
                $existing++;
            }

            $s = $result->suggestion;

            if ($s->getStatus() === DailyMealSuggestion::STATUS_NONE_POSSIBLE) {
                $nonePossible++;
                continue;
            }

            // ✅ Push uniquement si on vient de créer une suggestion "PROPOSED"
            if (!$result->created || $s->getStatus() !== DailyMealSuggestion::STATUS_PROPOSED) {
                continue;
            }

            $mealPlan = $s->getMealPlan();
            $recipe = $mealPlan?->getRecipe();

            if (!$mealPlan || !$recipe) {
                continue;
            }

            $dateStr = $mealPlan->getDate()?->format('Y-m-d') ?? (new \DateTimeImmutable('today'))->format('Y-m-d');
            $recipeName = $recipe->getName();

            $pushResult = $this->pushNotifier->notifyUser($user, [
                'title' => 'Receiplan',
                'body'  => "Ta proposition du jour est prête : {$recipeName}",
                'url'   => "/meal-plan?date={$dateStr}",
            ]);

            $sent = (int) ($pushResult['sent'] ?? 0);
            $failed = (int) ($pushResult['failed'] ?? 0);
            $deleted = (int) ($pushResult['deleted'] ?? 0);

            if ($sent > 0) {
                $pushSentUsers++;
            }

            $pushSentTotal += $sent;
            $pushFailedTotal += $failed;
            $pushDeletedTotal += $deleted;
        }

        return [
            'users' => count($users),
            'created' => $created,
            'existing' => $existing,
            'none_possible' => $nonePossible,
            'push' => [
                'users_notified' => $pushSentUsers,
                'sent' => $pushSentTotal,
                'failed' => $pushFailedTotal,
                'deleted' => $pushDeletedTotal,
            ],
        ];
    }
}
