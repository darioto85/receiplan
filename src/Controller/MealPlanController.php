<?php

namespace App\Controller;

use App\Repository\MealPlanRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use App\Service\MealPlanProposer;

#[Route('/meal-plan')]
final class MealPlanController extends AbstractController
{
    #[Route('', name: 'meal_plan_index', methods: ['GET'])]
    public function index(
        MealPlanRepository $mealPlanRepository,
        UserInterface $user
    ): Response {
        $today = new \DateTimeImmutable('today');

        // Période affichée :
        // - 7 jours dans le passé (historique récent)
        // - 14 jours dans le futur (planning)
        $fromDate = $today->modify('-7 days');
        $toDate   = $today->modify('+14 days');

        $meals = $mealPlanRepository->findBetween(
            $user,
            $fromDate,
            $toDate
        );

        /**
         * Organisation par date pour la vue :
         * [
         *   '2025-01-12' => [MealPlan, MealPlan],
         *   '2025-01-13' => [...]
         * ]
         */
        $planningByDate = [];

        foreach ($meals as $meal) {
            $key = $meal->getDate()->format('Y-m-d');
            $planningByDate[$key][] = $meal;
        }

        // Optionnel : garantir l'ordre des dates
        ksort($planningByDate);

        return $this->render('mealplan/index.html.twig', [
            'planningByDate' => $planningByDate,
            'fromDate'       => $fromDate,
            'toDate'         => $toDate,
            'today'          => $today,
        ]);
    }

    #[Route('/propose', name: 'meal_plan_propose', methods: ['POST'])]
    public function propose(
        Request $request,
        MealPlanProposer $proposer,
        UserInterface $user,
    ): Response {
        $dateStr = $request->query->get('date'); // YYYY-MM-DD (optionnel)

        $date = null;
        if (is_string($dateStr) && $dateStr !== '') {
            $date = \DateTimeImmutable::createFromFormat('Y-m-d', $dateStr) ?: null;
        }

        try {
            $proposer->proposeForDate($user, $date);
            $this->addFlash('success', 'Repas proposé ✅');
        } catch (\DomainException $e) {
            $this->addFlash('warning', $e->getMessage());
        }

        return $this->redirectToRoute('meal_plan_index');
    }
}
