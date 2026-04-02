<?php

namespace App\Controller;

use App\Entity\AssistantConversation;
use App\Entity\Ingredient;
use App\Entity\Recipe;
use App\Entity\User;
use App\Form\Backoffice\IngredientBackofficeType;
use App\Repository\AssistantConversationRepository;
use App\Repository\AssistantMessageRepository;
use App\Repository\IngredientRepository;
use App\Repository\PreinscriptionRepository;
use App\Repository\RecipeRepository;
use App\Repository\UserRepository;
use App\Service\Image\Storage\ImageStorageInterface;
use App\Service\IngredientImageResolver;
use App\Service\RecipeImageResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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

    private function getBackofficeAdminId(): ?int
    {
        $admin = $this->getUser();

        return $admin instanceof User ? $admin->getId() : null;
    }

    /**
     * Retourne [startUtc, endUtc] en gardant la même heure locale visible.
     */
    private function makeUtcPeriodFromNow(int $days): array
    {
        $days = max(1, $days);

        $parisTz = new \DateTimeZone('Europe/Paris');
        $utcTz = new \DateTimeZone('UTC');

        $startLocal = new \DateTimeImmutable('now', $parisTz);
        $endLocal = $startLocal->modify(sprintf('+%d days', $days));

        return [
            $startLocal->setTimezone($utcTz),
            $endLocal->setTimezone($utcTz),
        ];
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

    #[Route('/users/{id}', name: 'users_show', methods: ['GET'])]
    public function userShow(
        Request $request,
        User $user,
    ): Response {
        $this->denyUnlessAdmin();

        return $this->render('backoffice/users/show.html.twig', [
            'page_title' => 'Utilisateur #' . $user->getId(),
            'current_menu' => 'users',
            'user_entity' => $user,
            'q' => trim((string) $request->query->get('q', '')),
        ]);
    }

    #[Route('/users/{id}/start-trial', name: 'users_start_trial', methods: ['POST'])]
    public function startUserTrial(
        Request $request,
        User $user,
    ): RedirectResponse {
        $this->denyUnlessAdmin();

        if (!$this->isCsrfTokenValid(
            'backoffice_user_start_trial_' . $user->getId(),
            (string) $request->request->get('_token')
        )) {
            $this->addFlash('danger', 'Token CSRF invalide.');

            return $this->redirectToRoute('backoffice_users_show', [
                'id' => $user->getId(),
                'q' => $request->query->get('q', ''),
            ]);
        }

        $days = max(1, $request->request->getInt('days', 14));

        [$trialStartedAtUtc, $trialEndsAtUtc] = $this->makeUtcPeriodFromNow($days);

        $user->setTrialStartedAt($trialStartedAtUtc);
        $user->setTrialEndsAt($trialEndsAtUtc);

        $this->entityManager->flush();

        $this->addFlash('success', sprintf(
            'Trial lancé pour %s pendant %d jour%s.',
            $user->getEmail() ?? 'cet utilisateur',
            $days,
            $days > 1 ? 's' : ''
        ));

        return $this->redirectToRoute('backoffice_users_show', [
            'id' => $user->getId(),
            'q' => $request->query->get('q', ''),
        ]);
    }

    #[Route('/users/{id}/grant-manual-premium', name: 'users_grant_manual_premium', methods: ['POST'])]
    public function grantManualPremium(
        Request $request,
        User $user,
    ): RedirectResponse {
        $this->denyUnlessAdmin();

        if (!$this->isCsrfTokenValid(
            'backoffice_user_grant_manual_premium_' . $user->getId(),
            (string) $request->request->get('_token')
        )) {
            $this->addFlash('danger', 'Token CSRF invalide.');

            return $this->redirectToRoute('backoffice_users_show', [
                'id' => $user->getId(),
                'q' => $request->query->get('q', ''),
            ]);
        }

        $lifetime = $request->request->getBoolean('lifetime');
        $reason = trim((string) $request->request->get('reason', ''));
        $reason = $reason !== '' ? $reason : null;

        if ($lifetime) {
            [$manualPremiumStartsAtUtc] = $this->makeUtcPeriodFromNow(1);

            $user->setManualPremiumStartsAt($manualPremiumStartsAtUtc);
            $user->setManualPremiumEndsAt(null);
            $user->setManualPremiumIsLifetime(true);
            $user->setManualPremiumReason($reason);
            $user->setManualPremiumGrantedBy($this->getBackofficeAdminId());

            $this->entityManager->flush();

            $this->addFlash('success', sprintf(
                'Premium à vie attribué à %s.',
                $user->getEmail() ?? 'cet utilisateur'
            ));
        } else {
            $days = max(1, $request->request->getInt('days', 30));
            [$manualPremiumStartsAtUtc, $manualPremiumEndsAtUtc] = $this->makeUtcPeriodFromNow($days);

            $user->setManualPremiumStartsAt($manualPremiumStartsAtUtc);
            $user->setManualPremiumEndsAt($manualPremiumEndsAtUtc);
            $user->setManualPremiumIsLifetime(false);
            $user->setManualPremiumReason($reason);
            $user->setManualPremiumGrantedBy($this->getBackofficeAdminId());

            $this->entityManager->flush();

            $this->addFlash('success', sprintf(
                'Premium manuel attribué à %s pour %d jour%s.',
                $user->getEmail() ?? 'cet utilisateur',
                $days,
                $days > 1 ? 's' : ''
            ));
        }

        return $this->redirectToRoute('backoffice_users_show', [
            'id' => $user->getId(),
            'q' => $request->query->get('q', ''),
        ]);
    }

    #[Route('/users/{id}/remove-manual-premium', name: 'users_remove_manual_premium', methods: ['POST'])]
    public function removeManualPremium(
        Request $request,
        User $user,
    ): RedirectResponse {
        $this->denyUnlessAdmin();

        if (!$this->isCsrfTokenValid(
            'backoffice_user_remove_manual_premium_' . $user->getId(),
            (string) $request->request->get('_token')
        )) {
            $this->addFlash('danger', 'Token CSRF invalide.');

            return $this->redirectToRoute('backoffice_users_show', [
                'id' => $user->getId(),
                'q' => $request->query->get('q', ''),
            ]);
        }

        $user->setManualPremiumStartsAt(null);
        $user->setManualPremiumEndsAt(null);
        $user->setManualPremiumIsLifetime(false);
        $user->setManualPremiumReason(null);
        $user->setManualPremiumGrantedBy(null);

        $this->entityManager->flush();

        $this->addFlash('success', sprintf(
            'Premium manuel supprimé pour %s.',
            $user->getEmail() ?? 'cet utilisateur'
        ));

        return $this->redirectToRoute('backoffice_users_show', [
            'id' => $user->getId(),
            'q' => $request->query->get('q', ''),
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

    #[Route('/ingredients/new', name: 'ingredients_new', methods: ['GET', 'POST'])]
    public function ingredientNew(
        Request $request,
        IngredientRepository $ingredientRepository,
    ): Response {
        $this->denyUnlessAdmin();

        $ingredient = new Ingredient();
        $form = $this->createForm(IngredientBackofficeType::class, $ingredient);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $nameKey = trim((string) $ingredient->getNameKey());
            $nameKey = $nameKey !== ''
                ? Ingredient::normalizeName($nameKey)
                : Ingredient::normalizeName((string) $ingredient->getName());

            $ingredient->setNameKey($nameKey);

            $existingIngredient = $ingredientRepository->findOneBy([
                'user' => $ingredient->getUser(),
                'nameKey' => $nameKey,
            ]);

            if ($existingIngredient !== null) {
                $form->get('name')->addError(new FormError('Un ingrédient avec ce nom existe déjà pour cette portée.'));
            }

            if ($form->isValid()) {
                $this->entityManager->persist($ingredient);
                $this->entityManager->flush();

                $this->addFlash('success', sprintf(
                    'L’ingrédient "%s" a été créé.',
                    $ingredient->getName() ?? 'Nouvel ingrédient'
                ));

                return $this->redirectToRoute('backoffice_ingredients');
            }
        }

        return $this->render('backoffice/ingredients/form.html.twig', [
            'page_title' => 'Ajouter un ingrédient',
            'current_menu' => 'ingredients',
            'form' => $form->createView(),
            'ingredient' => $ingredient,
            'is_edit' => false,
        ]);
    }

    #[Route('/ingredients/{id}/edit', name: 'ingredients_edit', methods: ['GET', 'POST'])]
    public function ingredientEdit(
        Request $request,
        Ingredient $ingredient,
        IngredientRepository $ingredientRepository,
    ): Response {
        $this->denyUnlessAdmin();

        $form = $this->createForm(IngredientBackofficeType::class, $ingredient);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $nameKey = trim((string) $ingredient->getNameKey());
            $nameKey = $nameKey !== ''
                ? Ingredient::normalizeName($nameKey)
                : Ingredient::normalizeName((string) $ingredient->getName());

            $ingredient->setNameKey($nameKey);

            $existingIngredient = $ingredientRepository->findOneBy([
                'user' => $ingredient->getUser(),
                'nameKey' => $nameKey,
            ]);

            if ($existingIngredient !== null && $existingIngredient->getId() !== $ingredient->getId()) {
                $form->get('name')->addError(new FormError('Un ingrédient avec ce nom existe déjà pour cette portée.'));
            }

            if ($form->isValid()) {
                $this->entityManager->flush();

                $this->addFlash('success', sprintf(
                    'L’ingrédient "%s" a été modifié.',
                    $ingredient->getName() ?? 'cet ingrédient'
                ));

                return $this->redirectToRoute('backoffice_ingredients');
            }
        }

        return $this->render('backoffice/ingredients/form.html.twig', [
            'page_title' => 'Modifier un ingrédient',
            'current_menu' => 'ingredients',
            'form' => $form->createView(),
            'ingredient' => $ingredient,
            'is_edit' => true,
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