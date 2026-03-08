<?php

namespace App\Controller;

use App\Entity\Ingredient;
use App\Entity\Recipe;
use App\Entity\AssistantConversation;
use App\Repository\AssistantMessageRepository;
use App\Repository\AssistantConversationRepository;
use App\Repository\IngredientRepository;
use App\Repository\PreinscriptionRepository;
use App\Repository\RecipeRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\RedirectResponse;
use App\Service\Image\Storage\ImageStorageInterface;
use App\Service\IngredientImageResolver;
use App\Service\RecipeImageResolver;

#[Route('/godmode', name: 'backoffice_')]
final class BackofficeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ImageStorageInterface $imageStorage,
        private readonly IngredientImageResolver $ingredientImageResolver,
        private readonly RecipeImageResolver $recipeImageResolver,
    ) {
    }

    private function denyUnlessAdmin(): void
    {
        $this->denyAccessUnlessGranted('ROLE_USER_ADMIN');
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function dashboard(
        UserRepository $userRepository,
        PreinscriptionRepository $preinscriptionRepository,
        RecipeRepository $recipeRepository,
        IngredientRepository $ingredientRepository,
        AssistantConversationRepository $assistantConversationRepository,
    ): Response {
        $this->denyUnlessAdmin();

        return $this->render('backoffice/dashboard.html.twig', [
            'page_title' => 'Dashboard',
            'current_menu' => 'dashboard',
            'stats' => [
                'users' => $userRepository->count([]),
                'preinscriptions' => $preinscriptionRepository->count([]),
                'recipes' => $recipeRepository->count([]),
                'ingredients' => $ingredientRepository->count([]),
                'conversations' => $assistantConversationRepository->count([]),
            ],
        ]);
    }

    #[Route('/users', name: 'users', methods: ['GET'])]
    public function users(
        Request $request,
        UserRepository $userRepository,
    ): Response {
        $this->denyUnlessAdmin();

        $q = trim((string) $request->query->get('q', ''));

        if ($q !== '') {
            $users = $userRepository->createQueryBuilder('u')
                ->andWhere('u.email LIKE :q OR u.firstname LIKE :q OR u.lastname LIKE :q')
                ->setParameter('q', '%' . $q . '%')
                ->orderBy('u.id', 'DESC')
                ->setMaxResults(100)
                ->getQuery()
                ->getResult();
        } else {
            $users = $userRepository->findBy([], ['id' => 'DESC'], 100);
        }

        return $this->render('backoffice/users/index.html.twig', [
            'page_title' => 'Utilisateurs',
            'current_menu' => 'users',
            'q' => $q,
            'users' => $users,
        ]);
    }

    #[Route('/preinscriptions', name: 'preinscriptions', methods: ['GET'])]
    public function preinscriptions(
        Request $request,
        PreinscriptionRepository $preinscriptionRepository,
    ): Response {
        $this->denyUnlessAdmin();

        $q = trim((string) $request->query->get('q', ''));

        if ($q !== '') {
            $preinscriptions = $preinscriptionRepository->createQueryBuilder('p')
                ->andWhere('p.email LIKE :q')
                ->setParameter('q', '%' . $q . '%')
                ->orderBy('p.id', 'DESC')
                ->setMaxResults(100)
                ->getQuery()
                ->getResult();
        } else {
            $preinscriptions = $preinscriptionRepository->findBy([], ['id' => 'DESC'], 100);
        }

        return $this->render('backoffice/preinscriptions/index.html.twig', [
            'page_title' => 'Préinscriptions',
            'current_menu' => 'preinscriptions',
            'q' => $q,
            'preinscriptions' => $preinscriptions,
        ]);
    }

    #[Route('/recipes', name: 'recipes', methods: ['GET'])]
    public function recipes(
        Request $request,
        RecipeRepository $recipeRepository,
    ): Response {
        $this->denyUnlessAdmin();

        $q = trim((string) $request->query->get('q', ''));
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = 100;

        $qb = $recipeRepository->createQueryBuilder('r')
            ->leftJoin('r.user', 'u')
            ->addSelect('u')
            ->orderBy('r.id', 'DESC');

        if ($q !== '') {
            $qb->andWhere('r.name LIKE :q')
                ->setParameter('q', '%' . $q . '%');
        }

        $countQb = clone $qb;
        $total = (int) $countQb
            ->select('COUNT(r.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $recipes = $qb
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return $this->render('backoffice/recipes/index.html.twig', [
            'page_title' => 'Recettes',
            'current_menu' => 'recipes',
            'q' => $q,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'recipes' => $recipes,
        ]);
    }

    #[Route('/recipes/{id}/regenerate-image', name: 'recipes_regenerate_image', methods: ['POST'])]
    public function regenerateRecipeImage(
        Request $request,
        Recipe $recipe,
    ): RedirectResponse {
        $this->denyUnlessAdmin();

        if (!$this->isCsrfTokenValid(
            'regenerate_recipe_image_' . $recipe->getId(),
            (string) $request->request->get('_token')
        )) {
            $this->addFlash('danger', 'Token CSRF invalide.');

            return $this->redirectToRoute('backoffice_recipes', [
                'q' => $request->query->get('q', ''),
                'page' => $request->query->getInt('page', 1),
            ]);
        }

        try {
            $imageKey = $this->recipeImageResolver->getStorageKey($recipe);
            $this->imageStorage->delete($imageKey);

            $recipe->setImgGenerated(false);
            $recipe->setImgGeneratedAt(null);

            $this->entityManager->flush();

            $this->addFlash('success', sprintf(
                'L’image de la recette "%s" a été supprimée et sera régénérée.',
                $recipe->getName() ?? 'cette recette'
            ));
        } catch (\Throwable $e) {
            $this->addFlash('danger', sprintf(
                'Impossible de réinitialiser l’image de la recette "%s".',
                $recipe->getName() ?? 'cette recette'
            ));
        }

        return $this->redirectToRoute('backoffice_recipes', [
            'q' => $request->query->get('q', ''),
            'page' => $request->query->getInt('page', 1),
        ]);
    }

    #[Route('/ingredients', name: 'ingredients', methods: ['GET'])]
    public function ingredients(
        Request $request,
        IngredientRepository $ingredientRepository,
    ): Response {
        $this->denyUnlessAdmin();

        $q = trim((string) $request->query->get('q', ''));
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = 100;

        $qb = $ingredientRepository->createQueryBuilder('i')
            ->leftJoin('i.user', 'u')
            ->addSelect('u')
            ->orderBy('i.id', 'DESC');

        if ($q !== '') {
            $qb
                ->andWhere('i.name LIKE :q OR i.nameKey LIKE :q')
                ->setParameter('q', '%' . $q . '%');
        }

        $countQb = clone $qb;
        $total = (int) $countQb
            ->select('COUNT(i.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $ingredients = $qb
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return $this->render('backoffice/ingredients/index.html.twig', [
            'page_title' => 'Ingrédients',
            'current_menu' => 'ingredients',
            'q' => $q,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'ingredients' => $ingredients,
        ]);
    }

    #[Route('/ingredients/{id}/regenerate-image', name: 'ingredients_regenerate_image', methods: ['POST'])]
    public function regenerateIngredientImage(
        Request $request,
        Ingredient $ingredient,
    ): RedirectResponse {
        $this->denyUnlessAdmin();

        if (!$this->isCsrfTokenValid(
            'regenerate_ingredient_image_' . $ingredient->getId(),
            (string) $request->request->get('_token')
        )) {
            $this->addFlash('danger', 'Token CSRF invalide.');

            return $this->redirectToRoute('backoffice_ingredients', [
                'q' => $request->query->get('q', ''),
                'page' => $request->query->getInt('page', 1),
            ]);
        }

        try {
            $imageKey = $this->ingredientImageResolver->getStorageKey($ingredient);
            $this->imageStorage->delete($imageKey);

            $ingredient->setImgGenerated(false);
            $ingredient->setImgGeneratedAt(null);

            $this->entityManager->flush();

            $this->addFlash('success', sprintf(
                'L’image de "%s" a été supprimée et sera régénérée.',
                $ingredient->getName() ?? 'cet ingrédient'
            ));
        } catch (\Throwable $e) {
            $this->addFlash('danger', sprintf(
                'Impossible de réinitialiser l’image de "%s".',
                $ingredient->getName() ?? 'cet ingrédient'
            ));
        }

        return $this->redirectToRoute('backoffice_ingredients', [
            'q' => $request->query->get('q', ''),
            'page' => $request->query->getInt('page', 1),
        ]);
    }

    #[Route('/assistant-conversations', name: 'assistant_conversations', methods: ['GET'])]
    public function assistantConversations(
        Request $request,
        AssistantConversationRepository $assistantConversationRepository,
    ): Response {
        $this->denyUnlessAdmin();

        $q = trim((string) $request->query->get('q', ''));
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = 100;

        $qb = $assistantConversationRepository->createQueryBuilder('c')
            ->leftJoin('c.user', 'u')
            ->addSelect('u')
            ->orderBy('c.id', 'DESC');

        if ($q !== '') {
            $qb->andWhere('u.email LIKE :q')
                ->setParameter('q', '%' . $q . '%');
        }

        $countQb = clone $qb;
        $total = (int) $countQb
            ->select('COUNT(c.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $conversations = $qb
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return $this->render('backoffice/assistant_conversations/index.html.twig', [
            'page_title' => 'Conversations assistant',
            'current_menu' => 'assistant_conversations',
            'q' => $q,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'conversations' => $conversations,
        ]);
    }

    #[Route('/assistant-conversations/{id}', name: 'assistant_conversations_show', methods: ['GET'])]
    public function assistantConversationShow(
        AssistantConversation $conversation,
        AssistantMessageRepository $assistantMessageRepository,
    ): Response {
        $this->denyUnlessAdmin();

        $messages = $assistantMessageRepository->createQueryBuilder('m')
            ->andWhere('m.conversation = :conversation')
            ->setParameter('conversation', $conversation)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('backoffice/assistant_conversations/show.html.twig', [
            'page_title' => 'Conversation assistant #' . $conversation->getId(),
            'current_menu' => 'assistant_conversations',
            'conversation' => $conversation,
            'messages' => $messages,
        ]);
    }

    private function entityHasField(string $className, string $field): bool
    {
        $metadata = $this->entityManager->getClassMetadata($className);

        return $metadata->hasField($field) || $metadata->hasAssociation($field);
    }
}