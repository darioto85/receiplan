<?php

namespace App\Service;

use App\Entity\MealPlan;
use App\Entity\Recipe;
use App\Repository\MealPlanRepository;
use App\Service\RecipeFeasibilityService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class MealPlanProposer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MealPlanRepository $mealPlanRepository,
        private readonly RecipeFeasibilityService $feasibilityService,
    ) {
    }

    /**
     * Crée une proposition de repas (MealPlan validated=false) pour une date donnée (ou aujourd'hui).
     * Ne propose QUE des recettes faisables (stock OK).
     *
     * @throws \DomainException si aucune recette faisable n'est disponible (ou déjà planifiées ce jour)
     */
    public function proposeForDate(UserInterface $user, ?\DateTimeImmutable $date = null): MealPlan
    {
        $date ??= new \DateTimeImmutable('today');

        $feasibleResults = $this->feasibilityService->getFeasibleRecipes($user);

        // Normalise en Recipe[] (car ton service "faisable" peut renvoyer enrichi)
        $feasibleRecipes = $this->extractRecipes($feasibleResults);

        if ($feasibleRecipes === []) {
            throw new \DomainException("Aucune recette faisable avec ton stock actuel.");
        }

        $recipe = $this->pickRecipeAvoidingDuplicates($user, $date, $feasibleRecipes);

        if (!$recipe) {
            throw new \DomainException("Toutes les recettes faisables sont déjà planifiées pour ce jour.");
        }

        $mealPlan = (new MealPlan())
            ->setUser($user)
            ->setRecipe($recipe)
            ->setDate($date)
            ->setValidated(false);

        $this->em->persist($mealPlan);
        $this->em->flush();

        return $mealPlan;
    }

    /**
     * @param array $feasibleResults
     * @return Recipe[]
     */
    private function extractRecipes(array $feasibleResults): array
    {
        $recipes = [];

        foreach ($feasibleResults as $item) {
            // Cas 1: le service renvoie directement des Recipe
            if ($item instanceof Recipe) {
                if ($item->getId()) {
                    $recipes[] = $item;
                }
                continue;
            }

            // Cas 2: le service renvoie un tableau enrichi ['recipe' => Recipe, ...]
            if (is_array($item) && isset($item['recipe']) && $item['recipe'] instanceof Recipe) {
                if ($item['recipe']->getId()) {
                    $recipes[] = $item['recipe'];
                }
                continue;
            }

            // Cas 3: le service renvoie un objet DTO enrichi avec getRecipe()
            if (is_object($item) && method_exists($item, 'getRecipe')) {
                $r = $item->getRecipe();
                if ($r instanceof Recipe && $r->getId()) {
                    $recipes[] = $r;
                }
                continue;
            }
        }

        // dédup au cas où (même recipe répétée)
        $byId = [];
        foreach ($recipes as $r) {
            $byId[(int) $r->getId()] = $r;
        }

        return array_values($byId);
    }

    /**
     * @param Recipe[] $recipes
     */
    private function pickRecipeAvoidingDuplicates(
        UserInterface $user,
        \DateTimeImmutable $date,
        array $recipes
    ): ?Recipe {
        if ($recipes === []) {
            return null;
        }

        $candidates = [];

        foreach ($recipes as $r) {
            $id = $r->getId();
            if (!$id) {
                continue;
            }

            if (!$this->mealPlanRepository->existsForUserRecipeDate($user, (int) $id, $date)) {
                $candidates[] = $r;
            }
        }

        if ($candidates === []) {
            return null;
        }

        return $candidates[array_rand($candidates)];
    }

    /**
     * 🔄 Remplace la recette d'une ligne MealPlan existante (validated=false) par une autre recette faisable.
     *
     * - NE touche pas à validated (reste false)
     * - Choisit une recette faisable différente de l'actuelle
     * - Évite les doublons le même jour (compatible avec ton unique constraint user+recipe+date)
     */
    public function refreshProposal(MealPlan $mealPlan): MealPlan
    {
        if ($mealPlan->isValidated()) {
            throw new \DomainException("Impossible : repas déjà validé.");
        }

        $user = $mealPlan->getUser();
        $date = $mealPlan->getDate();

        // ⚠️ Important : normaliser en Recipe[] comme dans proposeForDate()
        $feasibleResults = $this->feasibilityService->getFeasibleRecipes($user);
        $feasibleRecipes = $this->extractRecipes($feasibleResults);

        if ($feasibleRecipes === []) {
            throw new \DomainException("Aucune recette faisable avec ton stock actuel.");
        }

        $currentRecipeId = $mealPlan->getRecipe()?->getId();
        $excludeMealPlanId = (int) ($mealPlan->getId() ?? 0);

        $candidates = [];
        foreach ($feasibleRecipes as $r) {
            $rid = $r->getId();
            if (!$rid) {
                continue;
            }

            // Forcer une recette différente
            if ($currentRecipeId && (int) $rid === (int) $currentRecipeId) {
                continue;
            }

            /**
             * ✅ Idéal : utiliser une méthode repo "exists... excluding current mealPlan"
             * Si tu ne l'as pas encore, voir fallback en dessous.
             */
            if (method_exists($this->mealPlanRepository, 'existsForUserRecipeDateExcludingMealPlan') && $excludeMealPlanId > 0) {
                if ($this->mealPlanRepository->existsForUserRecipeDateExcludingMealPlan($user, (int) $rid, $date, $excludeMealPlanId)) {
                    continue;
                }
            } else {
                // Fallback : garde ton comportement actuel
                if ($this->mealPlanRepository->existsForUserRecipeDate($user, (int) $rid, $date)) {
                    continue;
                }
            }

            $candidates[] = $r;
        }

        if ($candidates === []) {
            throw new \DomainException("Aucune autre recette faisable disponible pour relancer la proposition.");
        }

        $newRecipe = $candidates[array_rand($candidates)];

        $mealPlan->setRecipe($newRecipe);
        $mealPlan->setValidated(false);

        $this->em->flush();

        return $mealPlan;
    }
}
