<?php

namespace App\Service\Ai;

use App\Entity\MealPlan;
use App\Entity\Recipe;
use App\Entity\User;
use App\Service\NameKeyNormalizer;
use Doctrine\ORM\EntityManagerInterface;

final class PlanRecipeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NameKeyNormalizer $nameKeyNormalizer,
    ) {}

    /**
     * Draft attendu (V2 compatible):
     * [
     *   'date' => 'YYYY-MM-DD',
     *   // ✅ option prioritaire (recommandée)
     *   'recipe_id' => 123,
     *   // ✅ fallback legacy
     *   'recipe' => ['name_raw' => string, 'name' => string],
     * ]
     *
     * @return array<string,mixed>
     */
    public function handle(User $user, array $draft): array
    {
        $dateStr = trim((string)($draft['date'] ?? ''));
        $date = $this->parseIsoDate($dateStr);
        if (!$date) {
            throw new \InvalidArgumentException('draft.date invalide');
        }

        // ✅ 1) Priorité à recipe_id
        $recipe = null;

        $recipeId = $draft['recipe_id'] ?? null;
        if (is_string($recipeId) && ctype_digit($recipeId)) {
            $recipeId = (int) $recipeId;
        }

        if (is_int($recipeId) && $recipeId > 0) {
            /** @var Recipe|null $found */
            $found = $this->em->getRepository(Recipe::class)->find($recipeId);

            // sécurité: recette doit appartenir au user (owner)
            if (!$found instanceof Recipe || $found->getUser()?->getId() !== $user->getId()) {
                throw new \RuntimeException('recipe_not_found');
            }

            $recipe = $found;
        }

        // ✅ 2) Fallback legacy: recipe.name
        if (!$recipe instanceof Recipe) {
            $recipeArr = $draft['recipe'] ?? null;
            if (!is_array($recipeArr)) {
                throw new \InvalidArgumentException('draft.recipe manquant');
            }

            $recipeName = trim((string)($recipeArr['name'] ?? $recipeArr['name_raw'] ?? ''));
            if ($recipeName === '') {
                throw new \InvalidArgumentException('draft.recipe.name manquant');
            }

            $recipe = $this->resolveRecipeForUserOrShared($user, $recipeName);
        }

        // unicité (user, recipe, date)
        /** @var MealPlan|null $existing */
        $existing = $this->em->getRepository(MealPlan::class)->findOneBy([
            'user' => $user,
            'recipe' => $recipe,
            'date' => $date,
        ]);

        if ($existing) {
            return [
                'status' => 'already_planned',
                'mealPlan' => [
                    'id' => $existing->getId(),
                    'date' => $dateStr,
                    'validated' => $existing->isValidated(),
                    'recipe' => [
                        'id' => $recipe->getId(),
                        'name' => $recipe->getName(),
                        'nameKey' => $recipe->getNameKey(),
                    ],
                ],
            ];
        }

        $mp = new MealPlan($user, $recipe, $date);
        $mp->setValidated(false);

        $this->em->persist($mp);
        $this->em->flush();

        return [
            'status' => 'planned',
            'mealPlan' => [
                'id' => $mp->getId(),
                'date' => $dateStr,
                'validated' => $mp->isValidated(),
                'recipe' => [
                    'id' => $recipe->getId(),
                    'name' => $recipe->getName(),
                    'nameKey' => $recipe->getNameKey(),
                ],
            ],
        ];
    }

    private function parseIsoDate(string $dateStr): ?\DateTimeImmutable
    {
        $dateStr = trim($dateStr);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            return null;
        }

        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $dateStr);
        if (!$d instanceof \DateTimeImmutable) {
            return null;
        }

        // évite les dates “auto-corrigées” par PHP (ex 2026-02-31)
        if ($d->format('Y-m-d') !== $dateStr) {
            return null;
        }

        return $d;
    }

    private function resolveRecipeForUserOrShared(User $user, string $recipeName): Recipe
    {
        $nameKey = $this->nameKeyNormalizer->toKey($recipeName);
        $repo = $this->em->getRepository(Recipe::class);

        // 1) recette du user (owner)
        /** @var Recipe|null $r */
        $r = $repo->findOneBy(['user' => $user, 'nameKey' => $nameKey]);
        if ($r instanceof Recipe) return $r;

        // 2) recette partagée (même nameKey)
        $r = $repo->findOneBy(['nameKey' => $nameKey]);
        if ($r instanceof Recipe) return $r;

        throw new \RuntimeException('recipe_not_found');
    }
}
