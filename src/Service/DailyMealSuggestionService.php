<?php

namespace App\Service;

use App\Dto\DailySuggestionResult;
use App\Entity\DailyMealSuggestion;
use App\Entity\User;
use App\Repository\DailyMealSuggestionRepository;
use App\Repository\MealPlanRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final class DailyMealSuggestionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DailyMealSuggestionRepository $suggestionRepo,
        private readonly MealPlanRepository $mealPlanRepo,
        private readonly MealPlanProposer $proposer,
    ) {}

    /**
     * Assure qu'il existe une suggestion pour aujourd'hui.
     * Retourne un DTO avec created=true si on vient de la créer pendant ce call.
     */
    public function ensureTodaySuggestion(
        User $user,
        string $context = DailyMealSuggestion::CONTEXT_TODAY_AUTO
    ): DailySuggestionResult {
        $today = new \DateTimeImmutable('today');

        // 1) Déjà existante => created=false
        $existing = $this->suggestionRepo->findOneForUserDate($user, $today);
        if ($existing) {
            return new DailySuggestionResult($existing, false);
        }

        // 2) Si déjà planifié aujourd'hui => on crée une suggestion "accepted"
        $alreadyPlanned = $this->mealPlanRepo->findForUserOnDate($user, $today);
        if ($alreadyPlanned !== []) {
            $s = (new DailyMealSuggestion())
                ->setUser($user)
                ->setDate($today)
                ->setStatus(DailyMealSuggestion::STATUS_ACCEPTED)
                ->setContext($context)
                ->setMealPlan($alreadyPlanned[0]);

            try {
                $this->em->persist($s);
                $this->em->flush();

                return new DailySuggestionResult($s, true);
            } catch (UniqueConstraintViolationException) {
                // Concurrence : quelqu'un l'a créée juste avant
                $fresh = $this->suggestionRepo->findOneForUserDate($user, $today);
                if ($fresh) {
                    return new DailySuggestionResult($fresh, false);
                }

                // Fallback (très rare)
                return new DailySuggestionResult($s, false);
            }
        }

        // 3) Sinon on crée une proposition via MealPlanProposer
        $s = (new DailyMealSuggestion())
            ->setUser($user)
            ->setDate($today)
            ->setContext($context);

        try {
            $mealPlan = $this->proposer->proposeForDate($user, $today);

            $s->setStatus(DailyMealSuggestion::STATUS_PROPOSED);
            $s->setMealPlan($mealPlan);

            $this->em->persist($s);
            $this->em->flush();

            return new DailySuggestionResult($s, true);
        } catch (\DomainException) {
            // Aucune recette faisable / toutes déjà planifiées / etc.
            $s->setStatus(DailyMealSuggestion::STATUS_NONE_POSSIBLE);
            $s->setMealPlan(null);

            try {
                $this->em->persist($s);
                $this->em->flush();

                return new DailySuggestionResult($s, true);
            } catch (UniqueConstraintViolationException) {
                // Concurrence : quelqu'un l'a créée juste avant
                $fresh = $this->suggestionRepo->findOneForUserDate($user, $today);
                if ($fresh) {
                    return new DailySuggestionResult($fresh, false);
                }

                return new DailySuggestionResult($s, false);
            }
        }
    }
}
