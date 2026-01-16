<?php

namespace App\Service;

use App\Entity\MealCookedPrompt;
use App\Repository\MealCookedPromptRepository;
use App\Repository\MealPlanRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MealCookedPromptBackfillService
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly MealPlanRepository $mealPlanRepo,
        private readonly MealCookedPromptRepository $promptRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @return array{
     *   date:string,
     *   limit:int,
     *   offset:int,
     *   users:int,
     *   users_with_unvalidated:int,
     *   prompts_created:int,
     *   prompts_existing:int,
     *   prompts_skipped_no_mealplan:int
     * }
     */
    public function backfillYesterday(int $limit = 200, int $offset = 0): array
    {
        $limit = max(1, min($limit, 1000));
        $offset = max(0, $offset);

        $yesterday = (new \DateTimeImmutable('today'))->modify('-1 day');

        // Batch users (limit = nb users traités)
        $users = $this->userRepo->findBy([], ['id' => 'ASC'], $limit, $offset);

        $usersWithUnvalidated = 0;
        $created = 0;
        $existing = 0;
        $skippedNoMealPlan = 0;

        foreach ($users as $user) {
            $mealPlan = $this->mealPlanRepo->findOneUnvalidatedForUserOnDate($user, $yesterday);

            if (!$mealPlan) {
                $skippedNoMealPlan++;
                continue;
            }

            $usersWithUnvalidated++;

            // Anti-spam/idempotence : max 1 prompt par user/date
            if ($this->promptRepo->existsForUserOnDate($user, $yesterday)) {
                $existing++;
                continue;
            }

            $prompt = new MealCookedPrompt(
                user: $user,
                mealPlan: $mealPlan,
                date: $yesterday,
                context: MealCookedPrompt::CONTEXT_CRON_YESTERDAY_CHECK
            );

            // ✅ Important: un prompt nouvellement créé est "PENDING" (à notifier), pas "SENT"
            $prompt->setStatus(MealCookedPrompt::STATUS_PENDING);

            $this->em->persist($prompt);
            $created++;
        }

        $this->em->flush();

        return [
            'date' => $yesterday->format('Y-m-d'),
            'limit' => $limit,
            'offset' => $offset,
            'users' => \count($users),
            'users_with_unvalidated' => $usersWithUnvalidated,
            'prompts_created' => $created,
            'prompts_existing' => $existing,
            'prompts_skipped_no_mealplan' => $skippedNoMealPlan,
        ];
    }
}
