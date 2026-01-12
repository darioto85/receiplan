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
        private readonly RecipeImageResolver $recipeImageResolver,
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

        $today = new \DateTimeImmutable('today');

        foreach ($users as $user) {
            $result = $this->service->ensureTodaySuggestion(
                $user,
                DailyMealSuggestion::CONTEXT_CRON_BACKFILL
            );

            if ($result->created) {
                $created++;
            } else {
                $existing++;
            }

            $suggestion = $result->suggestion;

            if ($suggestion->getStatus() === DailyMealSuggestion::STATUS_NONE_POSSIBLE) {
                $nonePossible++;
                continue;
            }

            // ✅ Push uniquement si on vient de créer une suggestion PROPOSED
            if (!$result->created || $suggestion->getStatus() !== DailyMealSuggestion::STATUS_PROPOSED) {
                continue;
            }

            $mealPlan = $suggestion->getMealPlan();
            $recipe = $mealPlan?->getRecipe();

            if (!$mealPlan || !$recipe) {
                continue;
            }

            $date = $mealPlan->getDate() ?? $today;
            $dateStr = $date->format('Y-m-d');

            // ✅ Image publique (ou placeholder automatique)
            $recipeImageUrl = $this->recipeImageResolver->getPublicUrl($recipe);

            $payload = [
                // 🎯 Titre = nom de la recette
                'title' => 'Votre proposition "Receiplan" du jour : ' . (string) $recipe->getName(),

                // 📝 Texte demandé
                'body'  => 'Vous pouvez la changer à tout moment en cliquant ici.',

                // 👉 Clic = planning du jour
                'url'   => "/meal-plan?date={$dateStr}",

                // 🖼️ Image riche de la recette
                'image' => $recipeImageUrl,
                'icon' => $recipeImageUrl,

                // 🧯 Anti-spam : une seule notif par jour
                'tag' => "daily-meal-{$dateStr}",
                'renotify' => false,
            ];

            $pushResult = $this->pushNotifier->notifyUser($user, $payload);

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
            'users' => \count($users),
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
