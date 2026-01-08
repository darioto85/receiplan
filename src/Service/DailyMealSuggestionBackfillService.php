<?php

namespace App\Service;

use App\Entity\DailyMealSuggestion;
use App\Repository\UserRepository;

final class DailyMealSuggestionBackfillService
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly DailyMealSuggestionService $service,
    ) {}

    public function backfillToday(): array
    {
        $users = $this->userRepo->findAll();

        $created = 0;
        $nonePossible = 0;

        foreach ($users as $user) {
            $s = $this->service->ensureTodaySuggestion($user, DailyMealSuggestion::CONTEXT_CRON_BACKFILL);

            if ($s->getStatus() === DailyMealSuggestion::STATUS_PROPOSED || $s->getStatus() === DailyMealSuggestion::STATUS_ACCEPTED) {
                $created++;
            } elseif ($s->getStatus() === DailyMealSuggestion::STATUS_NONE_POSSIBLE) {
                $nonePossible++;
            }
        }

        return [
            'users' => count($users),
            'created_or_existing' => $created,
            'none_possible' => $nonePossible,
        ];
    }
}
