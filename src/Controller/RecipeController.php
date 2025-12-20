<?php

namespace App\Controller;

use App\Entity\Recipe;
use App\Entity\User;
use App\Form\RecipeType;
use App\Repository\MealPlanRepository;
use App\Repository\RecipeRepository;
use App\Service\RecipeFeasibilityService;
use App\Service\RecipeUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/recipe')]
final class RecipeController extends AbstractController
{
    #[Route(name: 'app_recipe_index', methods: ['GET'])]
    public function index(RecipeFeasibilityService $feasibility): Response
    {
        /** @var \App\Entity\User|null $user */
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('recipe/index.html.twig', [
            'feasible' => $feasibility->getFeasibleRecipes($user),
            'insufficient' => $feasibility->getInsufficientRecipes($user),
        ]);
    }

    #[Route('/new', name: 'app_recipe_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $recipe = new Recipe();

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $recipe->setUser($user);
        $recipe->addRecipeIngredient(new \App\Entity\RecipeIngredient());

        $form = $this->createForm(RecipeType::class, $recipe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
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
        return $this->render('recipe/show.html.twig', [
            'recipe' => $recipe,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_recipe_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Recipe $recipe, EntityManagerInterface $entityManager): Response
    {
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
        if ($this->isCsrfTokenValid('delete'.$recipe->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($recipe);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_recipe_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * AJAX - Valider un MealPlan (déclenche potentiellement décrément stock via RecipeUpdater)
     * URL finale: POST /recipe/{id}/validate
     */
    #[Route('/{id}/validate', name: 'recipe_validate', methods: ['POST'])]
    public function validateMealPlan(
        int $id,
        Request $request,
        MealPlanRepository $mealPlanRepository,
        RecipeUpdater $recipeUpdater,
    ): Response {
        $mealPlan = $mealPlanRepository->find($id);
        if (!$mealPlan) {
            return $this->json(['message' => 'MealPlan introuvable.'], 404);
        }

        $this->denyAccessUnlessGranted('ROLE_USER');

        if ($mealPlan->getUser() !== $this->getUser()) {
            return $this->json(['message' => 'Accès refusé.'], 403);
        }

        $csrf = $request->headers->get('X-CSRF-TOKEN', '');
        if (!$this->isCsrfTokenValid('mealplan_update_' . $mealPlan->getId(), $csrf)) {
            return $this->json(['message' => 'CSRF invalide.'], 419);
        }

        try {
            $recipeUpdater->validateMealPlan($mealPlan);

            return $this->json([
                'ok' => true,
                'validated' => true,
            ]);
        } catch (\DomainException $e) {
            return $this->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * AJAX - Annuler la validation d'un MealPlan
     * URL finale: POST /recipe/{id}/cancel
     */
    #[Route('/{id}/cancel', name: 'recipe_cancel', methods: ['POST'])]
    public function cancelMealPlan(
        int $id,
        Request $request,
        MealPlanRepository $mealPlanRepository,
        RecipeUpdater $recipeUpdater,
    ): Response {
        $mealPlan = $mealPlanRepository->find($id);
        if (!$mealPlan) {
            return $this->json(['message' => 'MealPlan introuvable.'], 404);
        }

        $this->denyAccessUnlessGranted('ROLE_USER');

        if ($mealPlan->getUser() !== $this->getUser()) {
            return $this->json(['message' => 'Accès refusé.'], 403);
        }

        $csrf = $request->headers->get('X-CSRF-TOKEN', '');
        if (!$this->isCsrfTokenValid('mealplan_update_' . $mealPlan->getId(), $csrf)) {
            return $this->json(['message' => 'CSRF invalide.'], 419);
        }

        try {
            $recipeUpdater->cancelMealPlanValidation($mealPlan);

            return $this->json([
                'ok' => true,
                'validated' => false,
            ]);
        } catch (\DomainException $e) {
            return $this->json(['message' => $e->getMessage()], 400);
        }
    }
}
