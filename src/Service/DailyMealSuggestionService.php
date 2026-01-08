<?php

namespace App\Service;

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

    public function ensureTodaySuggestion(User $user, string $context = DailyMealSuggestion::CONTEXT_TODAY_AUTO): DailyMealSuggestion
    {
        $today = new \DateTimeImmutable('today');

        $existing = $this->suggestionRepo->findOneForUserDate($user, $today);
        if ($existing) {
            return $existing;
        }

        // ✅ Si l'utilisateur a déjà planifié quelque chose aujourd'hui, on ne crée PAS un nouveau mealplan
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
                return $s;
            } catch (UniqueConstraintViolationException) {
                return $this->suggestionRepo->findOneForUserDate($user, $today) ?? $s;
            }
        }

        // Sinon, on crée une proposition via ton service existant
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

            return $s;
        } catch (\DomainException) {
            // aucune recette faisable / toutes déjà planifiées etc.
            $s->setStatus(DailyMealSuggestion::STATUS_NONE_POSSIBLE);
            $s->setMealPlan(null);

            try {
                $this->em->persist($s);
                $this->em->flush();
                return $s;
            } catch (UniqueConstraintViolationException) {
                return $this->suggestionRepo->findOneForUserDate($user, $today) ?? $s;
            }
        }
    }
}
