<?php

namespace App\Controller;

use App\Entity\Ingredient;
use App\Entity\User;
use App\Form\IngredientType;
use App\Repository\IngredientRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;



#[Route('/ingredient')]
final class IngredientController extends AbstractController
{
    #[Route(name: 'app_ingredient_index', methods: ['GET'])]
    public function index(IngredientRepository $ingredientRepository): Response
    {
        return $this->render('ingredient/index.html.twig', [
            'ingredients' => $ingredientRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_ingredient_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $ingredient = new Ingredient();
        
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $ingredient->setUser($user);
        $form = $this->createForm(IngredientType::class, $ingredient);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($ingredient);
            $entityManager->flush();

            return $this->redirectToRoute('app_ingredient_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('ingredient/new.html.twig', [
            'ingredient' => $ingredient,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_ingredient_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Ingredient $ingredient): Response
    {
        return $this->render('ingredient/show.html.twig', [
            'ingredient' => $ingredient,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_ingredient_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Ingredient $ingredient, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if ($ingredient->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(IngredientType::class, $ingredient);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_ingredient_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('ingredient/edit.html.twig', [
            'ingredient' => $ingredient,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_ingredient_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Ingredient $ingredient, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$ingredient->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($ingredient);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_ingredient_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/quick-create', name: 'app_ingredient_quick_create', methods: ['POST'])]
    public function quickCreate(
        Request $request,
        IngredientRepository $ingredientRepository,
        EntityManagerInterface $entityManager,
        CsrfTokenManagerInterface $csrfTokenManager
    ): JsonResponse {
        $payload = json_decode((string) $request->getContent(), true) ?? [];

        $name = trim((string) ($payload['name'] ?? ''));
        $unit = trim((string) ($payload['unit'] ?? ''));
        $csrf = (string) ($payload['_token'] ?? '');

        if (!$csrfTokenManager->isTokenValid(new CsrfToken('ingredient_quick_create', $csrf))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], 403);
        }

        if ($name === '') {
            return new JsonResponse(['error' => 'Name is required'], 422);
        }

        if ($unit === '') {
            return new JsonResponse(['error' => 'Unit is required'], 422);
        }

        // ✅ Anti-doublon robuste : on cherche par nameKey normalisé
        $nameKey = Ingredient::normalizeName($name);

        $existing = $ingredientRepository->findOneBy(['nameKey' => $nameKey]);
        if ($existing) {
            return new JsonResponse([
                'id' => $existing->getId(),
                'name' => $existing->getName(),
                'unit' => $existing->getUnit(),
                'created' => false,
            ], 200);
        }

        $ingredient = (new Ingredient())
            ->setName($name)  // setName() remplit aussi nameKey automatiquement
            ->setUnit($unit);

        $entityManager->persist($ingredient);

        try {
            $entityManager->flush();
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
            // Quelqu’un l’a créé juste avant (race condition) -> on renvoie l'existant
            $existing = $ingredientRepository->findOneBy(['nameKey' => $nameKey]);
            if ($existing) {
                return new JsonResponse([
                    'id' => $existing->getId(),
                    'name' => $existing->getName(),
                    'unit' => $existing->getUnit(),
                    'created' => false,
                ], 200);
            }

            throw $e;
        }

        return new JsonResponse([
            'id' => $ingredient->getId(),
            'name' => $ingredient->getName(),
            'unit' => $ingredient->getUnit(),
            'created' => true,
        ], 201);
    }

    #[Route('/{id}/unit', name: 'app_ingredient_unit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function unit(Ingredient $ingredient): JsonResponse
    {
        return new JsonResponse([
            'unit' => $ingredient->getUnit(),
        ]);
    }
}
