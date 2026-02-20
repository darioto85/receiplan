<?php

namespace App\Controller;

use App\Entity\Ingredient;
use App\Entity\User;
use App\Entity\UserIngredient;
use App\Enum\Unit;
use App\Form\StockUpsertType;
use App\Repository\IngredientRepository;
use App\Repository\UserIngredientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/stock', name: 'stock_')]
#[IsGranted('ROLE_USER')]
class StockController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(UserIngredientRepository $userIngredientRepository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $items = $userIngredientRepository->createQueryBuilder('ui')
            ->leftJoin('ui.ingredient', 'i')->addSelect('i')
            ->andWhere('ui.user = :user')->setParameter('user', $user)
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();

        $form = $this->createForm(StockUpsertType::class);

        return $this->render('stock/index.html.twig', [
            'items' => $items,
            'form' => $form->createView(),
        ]);
    }

    /**
     * ✅ Endpoint JSON pour TomSelect (recherche ingrédients)
     * GET /stock/ingredient/search?q=...
     */
    #[Route('/ingredient/search', name: 'ingredient_search', methods: ['GET'])]
    public function ingredientSearch(Request $request, IngredientRepository $ingredientRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $q = (string) $request->query->get('q', '');
        $q = trim($q);

        if ($q === '') {
            return new JsonResponse([]);
        }

        // petite limite côté API (TomSelect peut aussi limiter côté client)
        $limit = (int) $request->query->get('limit', 20);
        if ($limit <= 0 || $limit > 50) {
            $limit = 20;
        }

        $items = $ingredientRepository->searchVisibleToUser($user, $q, $limit);

        $payload = array_map(static function (Ingredient $ing): array {
            return [
                'value' => $ing->getId(),
                'text' => $ing->getName(),

                // ✅ important pour auto-unité (mais on ajoutera aussi un fallback "detail")
                'unit' => $ing->getBaseUnitValue(),
            ];
        }, $items);

        return new JsonResponse($payload);
    }

    /**
     * ✅ Endpoint JSON (fallback) : récupérer l'unité d'un ingrédient par ID
     * GET /stock/ingredient/get?id=123
     */
    #[Route('/ingredient/get', name: 'ingredient_get', methods: ['GET'])]
    public function ingredientGet(Request $request, IngredientRepository $ingredientRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $id = (int) $request->query->get('id', 0);
        if ($id <= 0) {
            return new JsonResponse(['status' => 'error', 'message' => 'ID invalide.'], 422);
        }

        // ✅ sécurité : visible pour l'user (global ou privé user)
        $ingredient = $ingredientRepository->createVisibleToUserQueryBuilder($user, 'i')
            ->andWhere('i.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$ingredient instanceof Ingredient) {
            return new JsonResponse(['status' => 'error', 'message' => 'Ingrédient introuvable.'], 404);
        }

        return new JsonResponse([
            'status' => 'ok',
            'id' => $ingredient->getId(),
            'text' => $ingredient->getName(),
            'unit' => $ingredient->getBaseUnitValue(),
        ]);
    }

    /**
     * ✅ Création d'un ingrédient à la volée (modale)
     * POST /stock/ingredient/create (AJAX)
     */
    #[Route('/ingredient/create', name: 'ingredient_create', methods: ['POST'])]
    public function ingredientCreate(
        Request $request,
        IngredientRepository $ingredientRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $name = trim((string) $request->request->get('name', ''));
        $unitRaw = (string) $request->request->get('unit', Unit::G->value);

        if ($name === '') {
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Nom requis.',
            ], 422);
        }

        $nameKey = Ingredient::normalizeName($name);

        // ✅ anti-doublon (global d'abord, puis user)
        $existing = $ingredientRepository->findOneVisibleByNameKey($user, $nameKey);
        if ($existing) {
            return new JsonResponse([
                'status' => 'ok',
                'id' => $existing->getId(),
                'name' => $existing->getName(),
                'unit' => $existing->getUnitValue(),
                'alreadyExists' => true,
            ]);
        }

        $unit = Unit::tryFrom($unitRaw) ?? Unit::G;

        $ingredient = new Ingredient();
        $ingredient->setName($name);
        $ingredient->setNameKey($nameKey);
        $ingredient->setUser($user);
        $ingredient->setUnit($unit);

        $em->persist($ingredient);
        $em->flush();

        return new JsonResponse([
            'status' => 'ok',
            'id' => $ingredient->getId(),
            'name' => $ingredient->getName(),
            'unit' => $ingredient->getUnitValue(),
            'alreadyExists' => false,
        ]);
    }

    #[Route('/upsert', name: 'upsert', methods: ['POST'])]
    public function upsert(
        Request $request,
        UserIngredientRepository $userIngredientRepository,
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
                    'errors' => (string) $this->renderView('stock/_form_errors.html.twig', [
                        'form' => $form->createView(),
                    ]),
                ], 422);
            }

            $this->addFlash('danger', 'Formulaire invalide.');
            return $this->redirectToRoute('stock_index');
        }

        /** @var Ingredient $ingredient */
        $ingredient = $form->get('ingredient')->getData();
        $quantityToAdd = (float) $form->get('quantity')->getData();

        /** @var Unit $unit */
        $unit = $form->get('unit')->getData() ?? Unit::G;

        if ($quantityToAdd <= 0) {
            if ($isAjax) {
                return new JsonResponse([
                    'status' => 'error',
                    'message' => 'Quantité invalide.',
                ], 422);
            }
            $this->addFlash('danger', 'Quantité invalide.');
            return $this->redirectToRoute('stock_index');
        }

        $existing = $userIngredientRepository->findOneBy([
            'user' => $user,
            'ingredient' => $ingredient,
        ]);

        $isNew = false;

        if (!$existing) {
            $existing = new UserIngredient();
            $existing->setUser($user);
            $existing->setIngredient($ingredient);
            $existing->setQuantity('0.00');
            $existing->setUnit($unit);
            $em->persist($existing);
            $isNew = true;
        } else {
            // ✅ choix métier : on NE change PAS l’unité d’une ligne existante automatiquement
        }

        $current = (float) $existing->getQuantity();
        $newQty = $current + $quantityToAdd;
        $existing->setQuantity(number_format($newQty, 2, '.', ''));

        $em->flush();

        if (!$isAjax) {
            $this->addFlash('success', 'Stock mis à jour.');
            return $this->redirectToRoute('stock_index');
        }

        $htmlDesktop = $this->renderView('stock/_stock_item.html.twig', [
            'ui' => $existing,
            'variant' => 'desktop',
        ]);

        $htmlMobile = $this->renderView('stock/_stock_item.html.twig', [
            'ui' => $existing,
            'variant' => 'mobile',
            'first' => true,
        ]);

        $count = (int) $userIngredientRepository->count(['user' => $user]);

        return new JsonResponse([
            'status' => 'ok',
            'isNew' => $isNew,
            'id' => $existing->getId(),
            'quantity' => $existing->getQuantity(),
            'unit' => $existing->getUnitValue(),
            'unitLabel' => $existing->getUnitLabel(),
            'count' => $count,
            'htmlDesktop' => $htmlDesktop,
            'htmlMobile' => $htmlMobile,
        ]);
    }

    #[Route('/{id}/quantity', name: 'update_quantity', methods: ['POST'])]
    public function updateQuantity(
        UserIngredient $userIngredient,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if ($userIngredient->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $isAjax = $request->isXmlHttpRequest();

        if (!$this->isCsrfTokenValid('update_stock_'.$userIngredient->getId(), (string) $request->request->get('_token'))) {
            if ($isAjax) {
                return new JsonResponse(['status' => 'error', 'message' => 'Token CSRF invalide.'], 403);
            }
            $this->addFlash('danger', 'Token CSRF invalide.');
            return $this->redirectToRoute('stock_index');
        }

        $raw = (string) $request->request->get('quantity', '');
        $raw = str_replace(',', '.', trim($raw));
        $qty = (float) $raw;

        if ($qty < 0) {
            return new JsonResponse(['status' => 'error', 'message' => 'Quantité invalide.'], 422);
        }

        $userIngredient->setQuantity(number_format($qty, 2, '.', ''));
        $em->flush();

        if (!$isAjax) {
            $this->addFlash('success', 'Quantité mise à jour.');
            return $this->redirectToRoute('stock_index');
        }

        return new JsonResponse([
            'status' => 'ok',
            'id' => $userIngredient->getId(),
            'quantity' => $userIngredient->getQuantity(),
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(
        UserIngredient $userIngredient,
        EntityManagerInterface $em,
        Request $request,
        UserIngredientRepository $userIngredientRepository
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $isAjax = $request->isXmlHttpRequest();

        if ($userIngredient->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('delete_stock_'.$userIngredient->getId(), (string) $request->request->get('_token'))) {
            if ($isAjax) {
                return new JsonResponse([
                    'status' => 'error',
                    'message' => 'Token CSRF invalide.',
                ], 403);
            }

            $this->addFlash('danger', "Token CSRF invalide.");
            return $this->redirectToRoute('stock_index');
        }

        $deletedId = $userIngredient->getId();

        $em->remove($userIngredient);
        $em->flush();

        if (!$isAjax) {
            $this->addFlash('success', "Ligne supprimée.");
            return $this->redirectToRoute('stock_index');
        }

        $count = (int) $userIngredientRepository->count(['user' => $user]);

        return new JsonResponse([
            'status' => 'ok',
            'id' => $deletedId,
            'count' => $count,
        ]);
    }
}