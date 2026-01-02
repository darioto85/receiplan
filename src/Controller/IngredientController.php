<?php

namespace App\Controller;

use App\Entity\Ingredient;
use App\Entity\User;
use App\Form\IngredientType;
use App\Repository\IngredientRepository;
use App\Service\IngredientNameKeyNormalizer;
use App\Service\IngredientImageGenerator;
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
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // ✅ Ne pas exposer les ingrédients privés des autres utilisateurs
        // Liste = globaux (user IS NULL) + privés du user courant
        $qb = $ingredientRepository->createQueryBuilder('i')
            ->where('i.user IS NULL OR i.user = :user')
            ->setParameter('user', $user)
            ->orderBy('i.name', 'ASC');

        return $this->render('ingredient/index.html.twig', [
            'ingredients' => $qb->getQuery()->getResult(),
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

        // ✅ Par défaut, un ajout via CRUD crée un ingrédient privé
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
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // ✅ Autorisé si global, ou privé appartenant au user
        if ($ingredient->getUser() !== null && $ingredient->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

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

        // ✅ On n’édite pas un ingrédient global ici (ni un privé d’un autre user)
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
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // ✅ On ne supprime pas un global, ni un privé d’un autre user
        if ($ingredient->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

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
        CsrfTokenManagerInterface $csrfTokenManager,
        IngredientNameKeyNormalizer $nameKeyNormalizer,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

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

        // ✅ Source de vérité unique pour nameKey
        $nameKey = $nameKeyNormalizer->toKey($name);

        // ✅ 1) Cherche d'abord un ingrédient GLOBAL
        $existingGlobal = $ingredientRepository->findOneBy([
            'user' => null,
            'nameKey' => $nameKey,
        ]);

        if ($existingGlobal) {
            return new JsonResponse([
                'id' => $existingGlobal->getId(),
                'name' => $existingGlobal->getName(),
                'unit' => $existingGlobal->getUnit(),
                'created' => false,
                'scope' => 'global',
            ], 200);
        }

        // ✅ 2) Sinon, cherche un ingrédient PRIVÉ du user
        $existingPrivate = $ingredientRepository->findOneBy([
            'user' => $user,
            'nameKey' => $nameKey,
        ]);

        if ($existingPrivate) {
            return new JsonResponse([
                'id' => $existingPrivate->getId(),
                'name' => $existingPrivate->getName(),
                'unit' => $existingPrivate->getUnit(),
                'created' => false,
                'scope' => 'private',
            ], 200);
        }

        // ✅ 3) Sinon, crée en PRIVÉ (comme avant)
        $ingredient = (new Ingredient())
            ->setUser($user)
            ->setName($name)
            ->setNameKey($nameKey)
            ->setUnitFromString($unit);

        $entityManager->persist($ingredient);

        try {
            $entityManager->flush();
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
            // Race condition / double submit : re-check global puis privé

            $existingGlobal = $ingredientRepository->findOneBy([
                'user' => null,
                'nameKey' => $nameKey,
            ]);

            if ($existingGlobal) {
                return new JsonResponse([
                    'id' => $existingGlobal->getId(),
                    'name' => $existingGlobal->getName(),
                    'unit' => $existingGlobal->getUnit(),
                    'created' => false,
                    'scope' => 'global',
                ], 200);
            }

            $existingPrivate = $ingredientRepository->findOneBy([
                'user' => $user,
                'nameKey' => $nameKey,
            ]);

            if ($existingPrivate) {
                return new JsonResponse([
                    'id' => $existingPrivate->getId(),
                    'name' => $existingPrivate->getName(),
                    'unit' => $existingPrivate->getUnit(),
                    'created' => false,
                    'scope' => 'private',
                ], 200);
            }

            throw $e;
        }

        return new JsonResponse([
            'id' => $ingredient->getId(),
            'name' => $ingredient->getName(),
            'unit' => $ingredient->getUnit(),
            'created' => true,
            'scope' => 'private',
        ], 201);
    }

    #[Route('/{id}/unit', name: 'app_ingredient_unit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function unit(Ingredient $ingredient): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        // ✅ Autorisé si global, ou privé appartenant au user
        if ($ingredient->getUser() !== null && $ingredient->getUser() !== $user) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        return new JsonResponse([
            'unit' => $ingredient->getUnit(),
        ]);
    }

    #[Route('/{id}/generate-image', name: 'app_ingredient_generate_image', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function generateImage(Ingredient $ingredient, IngredientImageGenerator $generator): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        // sécurité: un user ne doit pas générer pour un ingrédient privé d’un autre
        /** @var User $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        if ($ingredient->getUser() !== null && $ingredient->getUser() !== $user) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        // Option: empêcher de générer pour les ingrédients globaux si tu veux garder le contrôle.
        // if ($ingredient->getUser() === null) { return new JsonResponse(['error' => 'Forbidden'], 403); }

        $generator->generateAndStore($ingredient, overwrite: true);

        return new JsonResponse([
            'ok' => true,
        ]);
    }
}
