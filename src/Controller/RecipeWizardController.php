<?php

namespace App\Controller;

use App\Entity\Ingredient;
use App\Entity\Recipe;
use App\Entity\RecipeIngredient;
use App\Entity\User;
use App\Form\RecipeIngredientUpsertType;
use App\Repository\RecipeIngredientRepository;
use App\Service\NameKeyNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/recipe/wizard', name: 'recipe_wizard_')]
#[IsGranted('ROLE_USER')]
final class RecipeWizardController extends AbstractController
{
    #[Route('/new', name: 'new', methods: ['GET'])]
    public function new(): Response
    {
        // Wizard création : pas encore de recipeId
        return $this->render('recipe_wizard/new.html.twig');
    }

    #[Route('/{id}', name: 'edit', methods: ['GET'])]
    public function edit(Recipe $recipe): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if ($recipe->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        // Form quick-add Step 2 (même logique que Stock)
        $form = $this->createForm(RecipeIngredientUpsertType::class);

        return $this->render('recipe_wizard/edit.html.twig', [
            'recipe' => $recipe,
            'form' => $form->createView(),
        ]);
    }

    /**
     * STEP 1 - AJAX: crée un brouillon (draft=true) ou met à jour le nom d'un brouillon existant.
     * Payload attendu (x-www-form-urlencoded ou FormData):
     * - name: string
     * - recipeId?: int (optionnel)
     */
    #[Route('/draft', name: 'save_draft', methods: ['POST'])]
    public function saveDraft(
        Request $request,
        EntityManagerInterface $em,
        NameKeyNormalizer $nameKeyNormalizer,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$request->isXmlHttpRequest()) {
            return new JsonResponse(['status' => 'error', 'message' => 'Requête invalide.'], 400);
        }

        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Le nom est obligatoire.',
            ], 422);
        }

        $recipeIdRaw = $request->request->get('recipeId');
        $recipe = null;

        if ($recipeIdRaw) {
            $recipe = $em->getRepository(Recipe::class)->find((int) $recipeIdRaw);
            if (!$recipe) {
                return new JsonResponse(['status' => 'error', 'message' => 'Recette introuvable.'], 404);
            }
            if ($recipe->getUser() !== $user) {
                return new JsonResponse(['status' => 'error', 'message' => 'Accès refusé.'], 403);
            }
        } else {
            $recipe = new Recipe();
            $recipe->setUser($user);
            $recipe->setDraft(true);
            $recipe->setFavorite(false);

            $em->persist($recipe);
        }

        $recipe->setName($name);
        $recipe->setNameKey($nameKeyNormalizer->toKey($name));

        // Si l’utilisateur reprend une recette publiée via wizard, on ne force pas draft=true
        // MAIS pour la création wizard, on veut un brouillon tant que pas publié.
        if ($recipeIdRaw === null || $recipeIdRaw === '') {
            $recipe->setDraft(true);
        }

        $em->flush();

        return new JsonResponse([
            'status' => 'ok',
            'recipeId' => $recipe->getId(),
            // On redirige vers la page wizard "edit" qui contient step2 + listing
            'editUrl' => $this->generateUrl('recipe_wizard_edit', ['id' => $recipe->getId()]),
            'previewUrl' => $this->generateUrl('recipe_wizard_preview', ['id' => $recipe->getId()]),
        ]);
    }

    /**
     * STEP 2 - AJAX: ajoute / additionne un ingrédient à une recette (brouillon ou non).
     * Copie du comportement StockController::upsert, adapté à RecipeIngredient.
     */
    #[Route('/{id}/ingredient/upsert', name: 'ingredient_upsert', methods: ['POST'])]
    public function ingredientUpsert(
        Recipe $recipe,
        Request $request,
        RecipeIngredientRepository $recipeIngredientRepository,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if ($recipe->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(RecipeIngredientUpsertType::class);
        $form->handleRequest($request);

        $isAjax = $request->isXmlHttpRequest();
        if (!$isAjax) {
            return new JsonResponse(['status' => 'error', 'message' => 'Requête invalide.'], 400);
        }

        if (!$form->isSubmitted() || !$form->isValid()) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Formulaire invalide.',
                'errors' => (string) $this->renderView('recipe_wizard/_form_errors.html.twig', [
                    'form' => $form->createView(),
                ]),
            ], 422);
        }

        /** @var Ingredient|null $ingredient */
        $ingredient = $form->get('ingredient')->getData();
        $quantityToAdd = (float) $form->get('quantity')->getData();

        if (!$ingredient) {
            return new JsonResponse(['status' => 'error', 'message' => 'Ingrédient invalide.'], 422);
        }

        if ($quantityToAdd <= 0) {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Quantité invalide.',
            ], 422);
        }

        // Recherche si déjà présent dans la recette
        $existing = $recipeIngredientRepository->findOneBy([
            'recipe' => $recipe,
            'ingredient' => $ingredient,
        ]);

        $isNew = false;

        if (!$existing) {
            $existing = new RecipeIngredient();
            $existing->setRecipe($recipe);
            $existing->setIngredient($ingredient);
            $existing->setQuantity('0.00');
            $em->persist($existing);
            $isNew = true;
        }

        // Addition (comme stock)
        $current = (float) $existing->getQuantity();
        $newQty = $current + $quantityToAdd;
        $existing->setQuantity(number_format($newQty, 2, '.', ''));

        $em->flush();

        // Rendu HTML partiel
        $htmlDesktop = $this->renderView('recipe_wizard/_ingredient_item.html.twig', [
            'ri' => $existing,
            'variant' => 'desktop',
        ]);

        $htmlMobile = $this->renderView('recipe_wizard/_ingredient_item.html.twig', [
            'ri' => $existing,
            'variant' => 'mobile',
            'first' => true,
        ]);

        $count = (int) $recipeIngredientRepository->count(['recipe' => $recipe]);

        return new JsonResponse([
            'status' => 'ok',
            'isNew' => $isNew,
            'id' => $existing->getId(),
            'quantity' => $existing->getQuantity(),
            'count' => $count,
            'htmlDesktop' => $htmlDesktop,
            'htmlMobile' => $htmlMobile,
        ]);
    }

    /**
     * STEP 2 - AJAX: modifie la quantité d'un ingrédient de recette
     */
    #[Route('/{id}/ingredient/{riId}/quantity', name: 'ingredient_update_quantity', methods: ['POST'])]
    public function ingredientUpdateQuantity(
        Recipe $recipe,
        int $riId,
        Request $request,
        RecipeIngredientRepository $recipeIngredientRepository,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if ($recipe->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $isAjax = $request->isXmlHttpRequest();
        if (!$isAjax) {
            return new JsonResponse(['status' => 'error', 'message' => 'Requête invalide.'], 400);
        }

        $ri = $recipeIngredientRepository->find($riId);
        if (!$ri) {
            return new JsonResponse(['status' => 'error', 'message' => 'Ligne introuvable.'], 404);
        }
        if ($ri->getRecipe()?->getId() !== $recipe->getId()) {
            return new JsonResponse(['status' => 'error', 'message' => 'Accès refusé.'], 403);
        }

        if (!$this->isCsrfTokenValid('update_recipe_ingredient_'.$ri->getId(), (string) $request->request->get('_token'))) {
            return new JsonResponse(['status' => 'error', 'message' => 'Token CSRF invalide.'], 403);
        }

        $raw = (string) $request->request->get('quantity', '');
        $raw = str_replace(',', '.', trim($raw));
        $qty = (float) $raw;

        if ($qty < 0) {
            return new JsonResponse(['status' => 'error', 'message' => 'Quantité invalide.'], 422);
        }

        $ri->setQuantity(number_format($qty, 2, '.', ''));
        $em->flush();

        return new JsonResponse([
            'status' => 'ok',
            'id' => $ri->getId(),
            'quantity' => $ri->getQuantity(),
        ]);
    }

    /**
     * STEP 2 - AJAX: supprime un ingrédient de recette
     */
    #[Route('/{id}/ingredient/{riId}/delete', name: 'ingredient_delete', methods: ['POST'])]
    public function ingredientDelete(
        Recipe $recipe,
        int $riId,
        Request $request,
        RecipeIngredientRepository $recipeIngredientRepository,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if ($recipe->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $isAjax = $request->isXmlHttpRequest();
        if (!$isAjax) {
            return new JsonResponse(['status' => 'error', 'message' => 'Requête invalide.'], 400);
        }

        $ri = $recipeIngredientRepository->find($riId);
        if (!$ri) {
            return new JsonResponse(['status' => 'error', 'message' => 'Ligne introuvable.'], 404);
        }
        if ($ri->getRecipe()?->getId() !== $recipe->getId()) {
            return new JsonResponse(['status' => 'error', 'message' => 'Accès refusé.'], 403);
        }

        if (!$this->isCsrfTokenValid('delete_recipe_ingredient_'.$ri->getId(), (string) $request->request->get('_token'))) {
            return new JsonResponse(['status' => 'error', 'message' => 'Token CSRF invalide.'], 403);
        }

        $deletedId = $ri->getId();
        $em->remove($ri);
        $em->flush();

        $count = (int) $recipeIngredientRepository->count(['recipe' => $recipe]);

        return new JsonResponse([
            'status' => 'ok',
            'id' => $deletedId,
            'count' => $count,
        ]);
    }

    /**
     * STEP 3 - Preview (route dédiée)
     */
    #[Route('/{id}/preview', name: 'preview', methods: ['GET'])]
    public function preview(Recipe $recipe): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if ($recipe->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('recipe_wizard/preview.html.twig', [
            'recipe' => $recipe,
        ]);
    }

    /**
     * Publish: draft=false puis redirect listing recettes
     */
    #[Route('/{id}/publish', name: 'publish', methods: ['POST'])]
    public function publish(
        Recipe $recipe,
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if ($recipe->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('recipe_publish_'.$recipe->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF invalide.');
            return $this->redirectToRoute('recipe_wizard_preview', ['id' => $recipe->getId()]);
        }

        $recipe->setDraft(false);
        $em->flush();

        $this->addFlash('success', 'Recette enregistrée ✅');

        return $this->redirectToRoute('app_recipe_index');
    }
}
