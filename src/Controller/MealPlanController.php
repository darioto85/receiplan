<?php

namespace App\Controller;

use App\Repository\MealPlanRepository;
use App\Service\DailyMealSuggestionService;
use App\Service\MealPlanProposer;
use App\Service\RecipeFeasibilityService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

#[Route('/meal-plan')]
final class MealPlanController extends AbstractController
{
    #[Route('', name: 'meal_plan_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $today = new \DateTimeImmutable('today');

        $dateStr = $request->query->get('date');
        $center = $dateStr
            ? (\DateTimeImmutable::createFromFormat('Y-m-d', (string) $dateStr) ?: $today)
            : $today;

        $weekStart = $center->modify('monday this week');
        $weekEnd   = $weekStart->modify('+6 days');

        return $this->render('mealplan/index.html.twig', [
            'today'     => $today,
            'weekStart' => $weekStart,
            'weekEnd'   => $weekEnd,
        ]);
    }

    /**
     * Endpoint AJAX: renvoie le HTML d'une semaine (partial Twig)
     * GET /meal-plan/week?start=YYYY-MM-DD (lundi)
     */
    #[Route('/week', name: 'meal_plan_week', methods: ['GET'])]
    public function week(
        Request $request,
        MealPlanRepository $mealPlanRepository,
        RecipeFeasibilityService $feasibility,
        DailyMealSuggestionService $dailySuggestion,
        UserInterface $user,
    ): Response {

        // auto-propose si aucun repas aujourd’hui
        $today = new \DateTimeImmutable('today');
        $dailySuggestion->ensureTodaySuggestion($user);

        $startStr = (string) $request->query->get('start', '');
        $weekStart = \DateTimeImmutable::createFromFormat('Y-m-d', $startStr);

        if (!$weekStart) {
            return new JsonResponse(['message' => 'Paramètre start invalide.'], 400);
        }

        $weekStart = $weekStart->modify('monday this week');
        $weekEnd   = $weekStart->modify('+6 days');

        $meals = $mealPlanRepository->findBetween($user, $weekStart, $weekEnd);

        $planningByDate = [];
        $recipesById = [];

        foreach ($meals as $meal) {
            $key = $meal->getDate()->format('Y-m-d');
            $planningByDate[$key][] = $meal;

            $recipe = $meal->getRecipe();
            if ($recipe) {
                $recipesById[$recipe->getId()] = $recipe; // unique
            }
        }
        ksort($planningByDate);

        // ✅ Map recipeId => isFeasible (stock instantané)
        $feasibleByRecipeId = $feasibility->getFeasibilityMapForRecipes($user, array_values($recipesById));

        return $this->render('mealplan/week.html.twig', [
            'planningByDate' => $planningByDate,
            'feasibleByRecipeId' => $feasibleByRecipeId,
            'weekStart'      => $weekStart,
            'weekEnd'        => $weekEnd,
            'today'          => new \DateTimeImmutable('today'),
        ]);
    }

    #[Route('/propose', name: 'meal_plan_propose', methods: ['POST'])]
    public function propose(
        Request $request,
        MealPlanProposer $proposer,
        UserInterface $user,
    ): Response {
        $dateStr = $request->query->get('date');

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

    #[Route('/propose-ajax', name: 'meal_plan_propose_ajax', methods: ['POST'])]
    public function proposeAjax(
        Request $request,
        MealPlanProposer $proposer,
        RecipeFeasibilityService $feasibility,
        UserInterface $user,
    ): Response {
        $csrf = $request->headers->get('X-CSRF-TOKEN', '');
        if (!$this->isCsrfTokenValid('mealplan_propose', $csrf)) {
            return $this->json(['message' => 'CSRF invalide.'], 419);
        }

        $payload = $request->toArray();
        $dateStr = $payload['date'] ?? null;

        if (!is_string($dateStr) || $dateStr === '') {
            return $this->json(['message' => 'Date manquante.'], 400);
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $dateStr);
        if (!$date) {
            return $this->json(['message' => 'Date invalide.'], 400);
        }

        try {
            $mealPlan = $proposer->proposeForDate($user, $date);

            // ✅ faisabilité pour le badge
            $ok = true;
            if ($mealPlan->getRecipe()) {
                $map = $feasibility->getFeasibilityMapForRecipes($user, [$mealPlan->getRecipe()]);
                $ok = $map[$mealPlan->getRecipe()->getId()] ?? true;
            }

            $html = $this->renderView('mealplan/_meal_item.html.twig', [
                'meal' => $mealPlan,
                'is_feasible' => $ok,
            ]);

            return $this->json([
                'ok' => true,
                'date' => $dateStr,
                'mealId' => $mealPlan->getId(),
                'html' => $html,
            ]);
        } catch (\DomainException $e) {
            return $this->json(['message' => $e->getMessage()], 400);
        }
    }

    #[Route('/{id}/refresh', name: 'recipe_refresh', methods: ['POST'])]
    public function refreshMealPlan(
        int $id,
        Request $request,
        MealPlanRepository $mealPlanRepository,
        MealPlanProposer $mealPlanProposer,
    ): Response {
        $mealPlan = $mealPlanRepository->find($id);
        if (!$mealPlan) {
            return $this->json(['message' => 'MealPlan introuvable.'], 404);
        }

        $this->denyAccessUnlessGranted('ROLE_USER');

        if ($mealPlan->getUser() !== $this->getUser()) {
            return $this->json(['message' => 'Accès refusé.'], 403);
        }

        if ($mealPlan->isValidated()) {
            return $this->json(['message' => "Impossible : repas déjà validé."], 400);
        }

        $csrf = $request->headers->get('X-CSRF-TOKEN', '');
        if (!$this->isCsrfTokenValid('mealplan_update_' . $mealPlan->getId(), $csrf)) {
            return $this->json(['message' => 'CSRF invalide.'], 419);
        }

        try {
            $updated = $mealPlanProposer->refreshProposal($mealPlan);

            return $this->json([
                'ok' => true,
                'validated' => false,
                'recipeId' => $updated->getRecipe()->getId(),
                'recipeName' => $updated->getRecipe()->getName(),
            ]);
        } catch (\DomainException $e) {
            return $this->json(['message' => $e->getMessage()], 400);
        }
    }

    #[Route('/refresh-ajax/{id}', name: 'meal_plan_refresh_ajax', methods: ['POST'])]
    public function refreshAjax(
        int $id,
        Request $request,
        MealPlanRepository $mealPlanRepository,
        MealPlanProposer $proposer,
        RecipeFeasibilityService $feasibility,
        UserInterface $user,
    ): Response {
        $csrf = $request->headers->get('X-CSRF-TOKEN', '');
        if (!$this->isCsrfTokenValid('mealplan_update_' . $id, $csrf)) {
            return $this->json(['message' => 'CSRF invalide.'], 419);
        }

        $mealPlan = $mealPlanRepository->find($id);
        if (!$mealPlan) {
            return $this->json(['message' => 'Repas introuvable.'], 404);
        }

        if ($mealPlan->getUser() !== $user) {
            return $this->json(['message' => 'Accès refusé.'], 403);
        }

        try {
            $updated = $proposer->refreshProposal($mealPlan);

            // ✅ faisabilité pour le badge
            $ok = true;
            if ($updated->getRecipe()) {
                $map = $feasibility->getFeasibilityMapForRecipes($user, [$updated->getRecipe()]);
                $ok = $map[$updated->getRecipe()->getId()] ?? true;
            }

            $html = $this->renderView('mealplan/_meal_item.html.twig', [
                'meal' => $updated,
                'is_feasible' => $ok,
            ]);

            return $this->json([
                'ok' => true,
                'mealId' => $updated->getId(),
                'html' => $html,
            ]);
        } catch (\DomainException $e) {
            return $this->json(['message' => $e->getMessage()], 400);
        }
    }

    #[Route('/delete-ajax/{id}', name: 'meal_plan_delete_ajax', methods: ['POST'])]
    public function deleteAjax(
        int $id,
        Request $request,
        MealPlanRepository $mealPlanRepository,
        EntityManagerInterface $em,
        UserInterface $user,
    ): Response {
        $csrf = $request->headers->get('X-CSRF-TOKEN', '');
        if (!$this->isCsrfTokenValid('mealplan_update_' . $id, $csrf)) {
            return $this->json(['message' => 'CSRF invalide.'], 419);
        }

        $mealPlan = $mealPlanRepository->find($id);
        if (!$mealPlan) {
            return $this->json(['message' => 'Repas introuvable.'], 404);
        }

        if ($mealPlan->getUser() !== $user) {
            return $this->json(['message' => 'Accès refusé.'], 403);
        }

        if ($mealPlan->isValidated()) {
            return $this->json(['message' => 'Impossible : repas déjà validé.'], 400);
        }

        $em->remove($mealPlan);
        $em->flush();

        return $this->json([
            'ok' => true,
            'mealId' => $id,
        ]);
    }

}
