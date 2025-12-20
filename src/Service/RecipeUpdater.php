<?php

namespace App\Service;

use App\Entity\MealPlan;
use App\Entity\RecipeIngredient;
use App\Repository\UserIngredientRepository;
use Doctrine\ORM\EntityManagerInterface;

final class RecipeUpdater
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserIngredientRepository $userIngredientRepository,
    ) {}

    public function validateMealPlan(MealPlan $mealPlan): void
    {
        if ($mealPlan->isValidated()) {
            // no-op (ou throw)
            return;
        }

        $user = $mealPlan->getUser();
        $recipe = $mealPlan->getRecipe();

        // 1) Décrémenter le stock selon la recette
        foreach ($recipe->getRecipeIngredients() as $ri) {
            if (!$ri instanceof RecipeIngredient) {
                continue;
            }

            $ingredient = $ri->getIngredient();
            $needed = (float) $ri->getQuantity();

            if ($needed <= 0) {
                continue;
            }

            $ui = $this->userIngredientRepository->findOneBy([
                'user' => $user,
                'ingredient' => $ingredient,
            ]);

            $current = $ui?->getQuantity() ?? 0.0;

            if ($current < $needed) {
                // On bloque: stock insuffisant au moment de valider
                throw new \DomainException(sprintf(
                    "Stock insuffisant pour %s (%.2f requis, %.2f dispo).",
                    $ingredient->getName(),
                    $needed,
                    $current
                ));
            }

            // décrément
            $ui->setQuantity($current - $needed);
        }

        // 2) Valider le meal plan
        $mealPlan->setValidated(true);

        $this->em->flush();
    }

    public function cancelMealPlanValidation(MealPlan $mealPlan): void
    {
        if (!$mealPlan->isValidated()) {
            return;
        }

        $user = $mealPlan->getUser();
        $recipe = $mealPlan->getRecipe();

        // Option: recréditer le stock (si tu veux annulation "inverse")
        foreach ($recipe->getRecipeIngredients() as $ri) {
            $ingredient = $ri->getIngredient();
            $qty = (float) $ri->getQuantity();

            if ($qty <= 0) {
                continue;
            }

            $ui = $this->userIngredientRepository->findOneBy([
                'user' => $user,
                'ingredient' => $ingredient,
            ]);

            if (!$ui) {
                // Si l'ingrédient n'existe pas en stock, on peut le créer à 0 puis ajouter
                // Mais tu as peut-être une règle différente. Ici: on crée.
                $ui = new \App\Entity\UserIngredient();
                $ui->setUser($user);
                $ui->setIngredient($ingredient);
                $ui->setQuantity(0.0);

                $this->em->persist($ui);
            }

            $ui->setQuantity(((float) $ui->getQuantity()) + $qty);
        }

        $mealPlan->setValidated(false);

        $this->em->flush();
    }
}
