<?php

namespace App\Controller;

use App\Service\DailyMealSuggestionBackfillService;
use App\Service\MealCookedPromptBackfillService;
use App\Service\MealCookedPromptNotifyService;
use App\Service\PendingMealPlanService;
use App\Service\Image\AutoImageGenerationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/cron')]
final class CronController extends AbstractController
{
    #[Route('/images/generate-one', name: 'cron_images_generate_one', methods: ['POST'])]
    public function generateOne(
        Request $request,
        AutoImageGenerationService $service,
        #[Autowire('%env(CRON_SECRET)%')] string $cronSecret,
    ): JsonResponse {
        $token = (string) $request->headers->get('X-CRON-SECRET', '');

        if ($cronSecret === '' || !hash_equals($cronSecret, $token)) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        try {
            $result = $service->generateOne();
            return new JsonResponse(['ok' => true] + $result);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'generation_failed',
                'detail' => $e->getMessage(),
                'type' => get_class($e),
            ], 500);
        }
    }

    #[Route('/daily-meal-suggestion', name: 'cron_daily_meal_suggestion', methods: ['POST'])]
    public function backfillToday(
        Request $request,
        DailyMealSuggestionBackfillService $service,
        #[Autowire('%env(CRON_SECRET)%')] string $cronSecret,
    ): JsonResponse {
        $token = (string) $request->headers->get('X-CRON-SECRET', '');

        if ($cronSecret === '' || !hash_equals($cronSecret, $token)) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        try {
            $result = $service->backfillToday();
            return new JsonResponse(['ok' => true] + $result);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'backfill_failed',
                'detail' => $e->getMessage(),
                'type' => get_class($e),
            ], 500);
        }
    }

    #[Route('/pending-mealplans', name: 'cron_pending_mealplans', methods: ['POST'])]
    public function pendingMealPlans(
        Request $request,
        PendingMealPlanService $service,
        #[Autowire('%env(CRON_SECRET)%')] string $cronSecret,
    ): JsonResponse {
        $token = (string) $request->headers->get('X-CRON-SECRET', '');

        if ($cronSecret === '' || !hash_equals($cronSecret, $token)) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        $limit = (int) $request->query->get('limit', 200);
        $offset = (int) $request->query->get('offset', 0);

        try {
            $result = $service->run($limit, $offset);
            return new JsonResponse(['ok' => true] + $result);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'yesterday_pending_failed',
                'detail' => $e->getMessage(),
                'type' => get_class($e),
            ], 500);
        }
    }

    #[Route('/meal-cooked-prompt/yesterday', name: 'cron_meal_cooked_prompt_yesterday', methods: ['POST'])]
    public function backfillMealCookedPromptYesterday(
        Request $request,
        MealCookedPromptBackfillService $service,
        #[Autowire('%env(CRON_SECRET)%')] string $cronSecret,
    ): JsonResponse {
        $token = (string) $request->headers->get('X-CRON-SECRET', '');

        if ($cronSecret === '' || !hash_equals($cronSecret, $token)) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        $limit = (int) $request->query->get('limit', 200);
        $offset = (int) $request->query->get('offset', 0);

        try {
            $result = $service->backfillYesterday($limit, $offset);
            return new JsonResponse(['ok' => true] + $result);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'meal_cooked_prompt_backfill_failed',
                'detail' => $e->getMessage(),
                'type' => get_class($e),
            ], 500);
        }
    }

    #[Route('/meal-cooked-prompt/notify-yesterday', name: 'cron_meal_cooked_prompt_notify_yesterday', methods: ['POST'])]
    public function notifyMealCookedPromptYesterday(
        Request $request,
        MealCookedPromptNotifyService $service,
        #[Autowire('%env(CRON_SECRET)%')] string $cronSecret,
    ): JsonResponse {
        $token = (string) $request->headers->get('X-CRON-SECRET', '');

        if ($cronSecret === '' || !hash_equals($cronSecret, $token)) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        $limit = (int) $request->query->get('limit', 200);

        try {
            $result = $service->notifyYesterday($limit);
            return new JsonResponse(['ok' => true] + $result);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'ok' => false,
                'error' => 'meal_cooked_prompt_notify_failed',
                'detail' => $e->getMessage(),
                'type' => get_class($e),
            ], 500);
        }
    }
}
