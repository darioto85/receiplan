<?php

namespace App\Controller;

use App\Entity\Ingredient;
use App\Entity\User;
use App\Entity\UserIngredient;
use App\Form\StockUpsertType;
use App\Repository\IngredientRepository;
use App\Repository\UserIngredientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\JsonResponse;


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

        $form = $this->createForm(\App\Form\StockUpsertType::class);

        return $this->render('stock/index.html.twig', [
            'items' => $items,
            'form' => $form->createView(), // ✅ IMPORTANT
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
                // On renvoie des erreurs exploitables
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
            $em->persist($existing);
            $isNew = true;
        }

        // ✅ addition (et non overwrite)
        $current = (float) $existing->getQuantity();
        $newQty = $current + $quantityToAdd;
        $existing->setQuantity(number_format($newQty, 2, '.', ''));

        $em->flush();

        if (!$isAjax) {
            $this->addFlash('success', 'Stock mis à jour.');
            return $this->redirectToRoute('stock_index');
        }

        // Rendu HTML partiel (desktop + mobile)
        $htmlDesktop = $this->renderView('stock/_stock_item.html.twig', [
            'ui' => $existing,
            'variant' => 'desktop',
        ]);

        $htmlMobile = $this->renderView('stock/_stock_item.html.twig', [
            'ui' => $existing,
            'variant' => 'mobile',
            'first' => true, // si on prepend en haut
        ]);

        // Compteur (optionnel mais pratique)
        $count = (int) $userIngredientRepository->count(['user' => $user]);

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

        // sécurité: on ne modifie que SON stock
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

        // sécurité: on ne supprime que SON stock
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
