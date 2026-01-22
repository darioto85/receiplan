<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\ShoppingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;


#[Route('/shopping')]
#[IsGranted('ROLE_USER')]
final class ShoppingController extends AbstractController
{
    #[Route('', name: 'shopping_index', methods: ['GET'])]
        public function index(
        ShoppingRepository $shoppingRepository,
        \App\Service\ShoppingListService $shoppingService
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException();
        }

        $items = $shoppingRepository->findForUser($user);

        return $this->render('shopping/index.html.twig', [
            'items' => $items,
        ]);
    }

    #[Route('/generate', name: 'shopping_generate', methods: ['GET'])]
    public function generate(
        \App\Service\ShoppingListService $shoppingService
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $shoppingService->syncAutoMissingFromInsufficientRecipes($user);

        // Redirection vers la liste
        return $this->redirectToRoute('shopping_index');
    }

    #[Route('/{id}/toggle', name: 'shopping_toggle', methods: ['POST'])]
    public function toggle(
        \App\Entity\Shopping $shopping,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
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
        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException();
        }

        // (Optionnel) CSRF header ou token POST, comme tu fais ailleurs

        $count = $cartValidator->validateCheckedCart($user);

        return $this->json([
            'ok' => true,
            'validated_count' => $count,
        ]);
    }

    #[Route('/{id}/quantity', name: 'shopping_update_quantity', methods: ['POST'])]
    public function updateQuantity(
        \App\Entity\Shopping $shopping,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
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
            // Option UX : 0 => on retire de la liste
            $em->remove($shopping);
            $em->flush();

            return $this->json([
                'ok' => true,
                'removed' => true,
                'id' => $shopping->getId(),
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
