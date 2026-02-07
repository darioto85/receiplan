<?php

namespace App\Controller;

use App\Entity\Recipe;
use App\Entity\User;
use App\Form\RecipeType;
use App\Repository\MealPlanRepository;
use App\Service\NameKeyNormalizer;
use App\Service\RecipeFeasibilityService;
use App\Service\RecipeUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

#[Route('/recipe')]
final class RecipeController extends AbstractController
{
    #[Route(name: 'app_recipe_index', methods: ['GET'])]
    public function index(RecipeFeasibilityService $feasibility): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('recipe/index.html.twig', [
            'feasible' => $feasibility->getFeasibleRecipes($user),
            'insufficient' => $feasibility->getInsufficientRecipes($user),
        ]);
    }

    #[Route('/new', name: 'app_recipe_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        NameKeyNormalizer $nameKeyNormalizer,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $recipe = new Recipe();
        $recipe->setUser($user);
        $recipe->addRecipeIngredient(new \App\Entity\RecipeIngredient());

        $form = $this->createForm(RecipeType::class, $recipe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // ✅ Source de vérité unique pour nameKey (comme IngredientController)
            $name = trim((string) ($recipe->getName() ?? ''));
            $recipe->setNameKey($name !== '' ? $nameKeyNormalizer->toKey($name) : null);

            $entityManager->persist($recipe);
            $entityManager->flush();

            return $this->redirectToRoute('app_recipe_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('recipe/new.html.twig', [
            'recipe' => $recipe,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_recipe_show', methods: ['GET'])]
    public function show(Recipe $recipe): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if ($recipe->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('recipe/show.html.twig', [
            'recipe' => $recipe,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_recipe_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Recipe $recipe,
        EntityManagerInterface $entityManager,
        NameKeyNormalizer $nameKeyNormalizer,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if ($recipe->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(RecipeType::class, $recipe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // ✅ Recalcule toujours le nameKey à la sauvegarde
            $name = trim((string) ($recipe->getName() ?? ''));
            $recipe->setNameKey($name !== '' ? $nameKeyNormalizer->toKey($name) : null);

            $entityManager->flush();

            return $this->redirectToRoute('app_recipe_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('recipe/edit.html.twig', [
            'recipe' => $recipe,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_recipe_delete', methods: ['POST'])]
    public function delete(Request $request, Recipe $recipe, EntityManagerInterface $entityManager): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if ($recipe->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        if ($this->isCsrfTokenValid('delete' . $recipe->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($recipe);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_recipe_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * AJAX - Valider un MealPlan (décrémente le stock via RecipeUpdater)
     * URL: POST /recipe/{id}/validate
     */
    #[Route('/{id}/validate', name: 'recipe_validate', methods: ['POST'])]
    public function validateMealPlan(
        int $id,
        Request $request,
        MealPlanRepository $mealPlanRepository,
        RecipeUpdater $recipeUpdater,
        RecipeFeasibilityService $feasibility,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $mealPlan = $mealPlanRepository->find($id);
        if (!$mealPlan) {
            return $this->json(['message' => 'MealPlan introuvable.'], 404);
        }

        if ($mealPlan->getUser() !== $user) {
            return $this->json(['message' => 'Accès refusé.'], 403);
        }

        $csrf = $request->headers->get('X-CSRF-TOKEN', '');
        if (!$this->isCsrfTokenValid('mealplan_update_' . $mealPlan->getId(), $csrf)) {
            return $this->json(['message' => 'CSRF invalide.'], 419);
        }

        try {
            $recipeUpdater->validateMealPlan($mealPlan);

            $from = new \DateTimeImmutable('today');
            $updates = $this->buildPendingUpdates($user, $from, $mealPlanRepository, $feasibility);

            $html = $this->renderView('mealplan/_meal_item.html.twig', [
                'meal' => $mealPlan,
                'is_feasible' => true, // badge non affiché si validated=true
            ]);

            return $this->json([
                'ok' => true,
                'validated' => true,
                'mealId' => $mealPlan->getId(),
                'html' => $html,
                'updates' => $updates,
            ]);
        } catch (\DomainException $e) {
            return $this->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * AJAX - Annuler la validation d'un MealPlan
     * URL: POST /recipe/{id}/cancel
     */
    #[Route('/{id}/cancel', name: 'recipe_cancel', methods: ['POST'])]
    public function cancelMealPlan(
        int $id,
        Request $request,
        MealPlanRepository $mealPlanRepository,
        RecipeUpdater $recipeUpdater,
        RecipeFeasibilityService $feasibility,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $mealPlan = $mealPlanRepository->find($id);
        if (!$mealPlan) {
            return $this->json(['message' => 'MealPlan introuvable.'], 404);
        }

        if ($mealPlan->getUser() !== $user) {
            return $this->json(['message' => 'Accès refusé.'], 403);
        }

        $csrf = $request->headers->get('X-CSRF-TOKEN', '');
        if (!$this->isCsrfTokenValid('mealplan_update_' . $mealPlan->getId(), $csrf)) {
            return $this->json(['message' => 'CSRF invalide.'], 419);
        }

        try {
            $recipeUpdater->cancelMealPlanValidation($mealPlan);

            $from = new \DateTimeImmutable('today');
            $updates = $this->buildPendingUpdates($user, $from, $mealPlanRepository, $feasibility);

            $recipe = $mealPlan->getRecipe();
            $ok = true;
            if ($recipe) {
                $map = $feasibility->getFeasibilityMapForRecipes($user, [$recipe]);
                $ok = $map[$recipe->getId()] ?? true;
            }

            $html = $this->renderView('mealplan/_meal_item.html.twig', [
                'meal' => $mealPlan,
                'is_feasible' => $ok,
            ]);

            return $this->json([
                'ok' => true,
                'validated' => false,
                'mealId' => $mealPlan->getId(),
                'html' => $html,
                'updates' => $updates,
            ]);
        } catch (\DomainException $e) {
            return $this->json(['message' => $e->getMessage()], 400);
        }
    }

    #[Route('/{id}/favorite', name: 'app_recipe_toggle_favorite', methods: ['POST'])]
    public function toggleFavorite(
        Recipe $recipe,
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if ($recipe->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        if (!$request->isXmlHttpRequest()) {
            return new JsonResponse(['status' => 'error', 'message' => 'Requête invalide.'], 400);
        }

        $csrf = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('recipe_favorite_' . $recipe->getId(), $csrf)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Token CSRF invalide.'], 403);
        }

        $recipe->setFavorite(!$recipe->isFavorite());
        $em->flush();

        return new JsonResponse([
            'status' => 'ok',
            'favorite' => $recipe->isFavorite(),
        ]);
    }

    /**
     * Construit updates[] pour tous les MealPlan non validés à partir d'une date.
     *
     * @return array<int, array{mealId:int, html:string}>
     */
    private function buildPendingUpdates(
        User $user,
        \DateTimeImmutable $from,
        MealPlanRepository $mealPlanRepository,
        RecipeFeasibilityService $feasibility,
    ): array {
        $pendingMeals = $mealPlanRepository->findPendingFrom($user, $from);

        $recipesById = [];
        foreach ($pendingMeals as $mp) {
            $r = $mp->getRecipe();
            if ($r) {
                $recipesById[$r->getId()] = $r;
            }
        }

        $feasibleByRecipeId = $feasibility->getFeasibilityMapForRecipes($user, array_values($recipesById));

        $updates = [];
        foreach ($pendingMeals as $mp) {
            $rid = $mp->getRecipe()?->getId();
            $ok = $rid ? ($feasibleByRecipeId[$rid] ?? true) : true;

            $updates[] = [
                'mealId' => $mp->getId(),
                'html' => $this->renderView('mealplan/_meal_item.html.twig', [
                    'meal' => $mp,
                    'is_feasible' => $ok,
                ]),
            ];
        }

        return $updates;
    }
}
