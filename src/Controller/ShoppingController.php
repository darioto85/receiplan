<?php

namespace App\Controller;

use App\Entity\Ingredient;
use App\Entity\Shopping;
use App\Entity\User;
use App\Form\StockUpsertType;
use App\Repository\ShoppingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/shopping')]
#[IsGranted('ROLE_USER')]
final class ShoppingController extends AbstractController
{
    #[Route('', name: 'shopping_index', methods: ['GET'])]
    public function index(
        ShoppingRepository $shoppingRepository
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $items = $shoppingRepository->findForUser($user);

        // ✅ même form que Stock
        $form = $this->createForm(StockUpsertType::class);

        return $this->render('shopping/index.html.twig', [
            'items' => $items,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/upsert', name: 'shopping_upsert', methods: ['POST'])]
    public function upsert(
        Request $request,
        ShoppingRepository $shoppingRepository,
        EntityManagerInterface $em
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(StockUpsertType::class);
        $form->handleRequest($request);

        $isAjax = $request->isXmlHttpRequest();

        if (!$form->isSubmitted() || !$form->isValid()) {
            if ($isAjax) {
                return new JsonResponse([
                    'status' => 'error',
                    'message' => 'Formulaire invalide.',
                    'errors' => (string) $this->renderView('shopping/_form_errors.html.twig', [
                        'form' => $form->createView(),
                    ]),
                ], 422);
            }

            $this->addFlash('danger', 'Formulaire invalide.');
            return $this->redirectToRoute('shopping_index');
        }

        /** @var Ingredient $ingredient */
        $ingredient = $form->get('ingredient')->getData();
        $quantityToAdd = (float) $form->get('quantity')->getData();

        if ($quantityToAdd <= 0) {
            if ($isAjax) {
                return new JsonResponse([
                    'status' => 'error',
                    'message' => 'Quantité invalide.',
                ], 422);
            }

            $this->addFlash('danger', 'Quantité invalide.');
            return $this->redirectToRoute('shopping_index');
        }

        // ✅ upsert sur Shopping (même ingredient => addition)
        $existing = $shoppingRepository->findOneBy([
            'user' => $user,
            'ingredient' => $ingredient,
        ]);

        $isNew = false;

        if (!$existing) {
            $existing = new Shopping();
            $existing->setUser($user);
            $existing->setIngredient($ingredient);
            $existing->setChecked(false);
            $existing->setQuantity(0);
            $em->persist($existing);
            $isNew = true;
        }

        $current = (float) $existing->getQuantity();
        $newQty = $current + $quantityToAdd;
        $existing->setQuantity($newQty);

        $em->flush();

        if (!$isAjax) {
            $this->addFlash('success', 'Liste de courses mise à jour.');
            return $this->redirectToRoute('shopping_index');
        }

        // ✅ HTML partiels (desktop + mobile)
        $htmlDesktop = $this->renderView('shopping/_shopping_item.html.twig', [
            'item' => $existing,
            'variant' => 'desktop',
        ]);

        $htmlMobile = $this->renderView('shopping/_shopping_item.html.twig', [
            'item' => $existing,
            'variant' => 'mobile',
            'first' => true,
        ]);

        // ✅ compteur
        $count = (int) $shoppingRepository->count(['user' => $user]);

        return new JsonResponse([
            'status' => 'ok',
            'isNew' => $isNew,
            'id' => $existing->getId(),
            'quantity' => $existing->getQuantity(),
            'checked' => $existing->isChecked(),
            'count' => $count,
            'htmlDesktop' => $htmlDesktop,
            'htmlMobile' => $htmlMobile,
        ]);
    }

    /**
     * ✅ Génération de liste avec modes :
     * - all       : toutes les recettes
     * - favorites : uniquement favorites
     * - week      : semaine à venir (planning)
     */
    #[Route('/generate', name: 'shopping_generate', methods: ['POST'])]
    public function generate(
        Request $request,
        \App\Service\ShoppingListService $shoppingService
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $isAjax = $request->isXmlHttpRequest();

        // ✅ CSRF
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('shopping_generate', $token)) {
            if ($isAjax) {
                return $this->json(['ok' => false, 'message' => 'CSRF invalide.'], 403);
            }
            $this->addFlash('danger', 'Action refusée (CSRF invalide).');
            return $this->redirectToRoute('shopping_index');
        }

        $mode = (string) $request->request->get('mode', 'all');
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['all', 'favorites', 'week'], true)) {
            $mode = 'all';
        }

        $flash = 'Liste générée.';

        // ✅ Appels cibles (à implémenter ensuite dans ShoppingListService)
        if ($mode === 'favorites' && method_exists($shoppingService, 'syncAutoMissingFromFavoriteRecipes')) {
            $shoppingService->syncAutoMissingFromFavoriteRecipes($user);
            $flash = 'Liste générée depuis tes recettes favorites.';
        } elseif ($mode === 'week' && method_exists($shoppingService, 'syncAutoMissingFromPlannedWeek')) {
            $shoppingService->syncAutoMissingFromPlannedWeek($user);
            $flash = 'Liste générée pour la semaine à venir.';
        } elseif ($mode === 'all' && method_exists($shoppingService, 'syncAutoMissingFromAllRecipes')) {
            $shoppingService->syncAutoMissingFromAllRecipes($user);
            $flash = 'Liste générée depuis toutes tes recettes.';
        } else {
            // ✅ Fallback: comportement actuel
            $shoppingService->syncAutoMissingFromInsufficientRecipes($user);
            $flash = 'Liste générée.';
        }

        if ($isAjax) {
            return $this->json(['ok' => true, 'message' => $flash]);
        }

        $this->addFlash('success', $flash);
        return $this->redirectToRoute('shopping_index');
    }

    #[Route('/{id}/toggle', name: 'shopping_toggle', methods: ['POST'])]
    public function toggle(
        Shopping $shopping,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if ($shopping->getUser() !== $user) {
            return $this->json(['message' => 'Accès refusé.'], 403);
        }

        $checked = (bool) ($request->request->get('checked') === '1');
        $shopping->setChecked($checked);

        $em->flush();

        return $this->json([
            'ok' => true,
            'checked' => $shopping->isChecked(),
        ]);
    }

    #[Route('/validate-cart', name: 'shopping_validate_cart', methods: ['POST'])]
    public function validateCart(
        Request $request,
        \App\Service\CartValidatorService $cartValidator
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $count = $cartValidator->validateCheckedCart($user);

        return $this->json([
            'ok' => true,
            'validated_count' => $count,
        ]);
    }

    #[Route('/{id}/quantity', name: 'shopping_update_quantity', methods: ['POST'])]
    public function updateQuantity(
        Shopping $shopping,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$shopping) {
            return $this->json(['ok' => true, 'removed' => true]);
        }

        if ($shopping->getUser() !== $user) {
            return $this->json(['message' => 'Accès refusé.'], 403);
        }

        $raw = (string) $request->request->get('quantity', '');
        $raw = str_replace(',', '.', trim($raw));
        $qty = (float) $raw;

        if ($qty <= 0) {
            $id = $shopping->getId();
            $em->remove($shopping);
            $em->flush();

            return $this->json([
                'ok' => true,
                'removed' => true,
                'id' => $id,
            ]);
        }

        $shopping->setQuantity($qty);
        $em->flush();

        return $this->json([
            'ok' => true,
            'removed' => false,
            'id' => $shopping->getId(),
            'quantity' => $shopping->getQuantity(),
        ]);
    }
}
