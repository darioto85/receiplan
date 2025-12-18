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

    /**
     * Upsert: si (user, ingredient) existe -> update quantity, sinon create.
     * Attend POST: ingredient_id, quantity
     */
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

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('danger', 'Formulaire invalide.');
            return $this->redirectToRoute('stock_index');
        }

        /** @var Ingredient $ingredient */
        $ingredient = $form->get('ingredient')->getData();
        $quantity = (float) $form->get('quantity')->getData();

        if ($quantity < 0) {
            $this->addFlash('danger', 'Quantité invalide.');
            return $this->redirectToRoute('stock_index');
        }

        $existing = $userIngredientRepository->findOneBy([
            'user' => $user,
            'ingredient' => $ingredient,
        ]);

        if (!$existing) {
            $existing = new UserIngredient();
            $existing->setUser($user);
            $existing->setIngredient($ingredient);
            $em->persist($existing);
        }

        $existing->setQuantity(number_format($quantity, 2, '.', ''));

        $em->flush();

        $this->addFlash('success', 'Stock mis à jour.');
        return $this->redirectToRoute('stock_index');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(
        UserIngredient $userIngredient,
        EntityManagerInterface $em,
        Request $request
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // sécurité: on ne supprime que SON stock
        if ($userIngredient->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('delete_stock_'.$userIngredient->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', "Token CSRF invalide.");
            return $this->redirectToRoute('stock_index');
        }

        $em->remove($userIngredient);
        $em->flush();

        $this->addFlash('success', "Ligne supprimée.");
        return $this->redirectToRoute('stock_index');
    }
}
