<?php

namespace App\Service\Ai;

use App\Entity\MealPlan;
use App\Entity\Recipe;
use App\Entity\User;
use App\Service\NameKeyNormalizer;
use Doctrine\ORM\EntityManagerInterface;

final class UnplanRecipeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NameKeyNormalizer $nameKeyNormalizer,
    ) {}

    /**
     * Draft attendu (V2 compatible):
     * [
     *   'date' => 'YYYY-MM-DD',
     *   'recipe_id' => 123,
     *   'recipe' => null | ['name_raw' => string, 'name' => string]
     * ]
     */
    public function handle(User $user, array $draft): array
    {
        $dateStr = trim((string)($draft['date'] ?? ''));
        $date = $this->parseIsoDate($dateStr);
        if (!$date) {
            throw new \InvalidArgumentException('draft.date invalide');
        }

        $repoMeal = $this->em->getRepository(MealPlan::class);
        $repoRecipe = $this->em->getRepository(Recipe::class);

        // ✅ 1) Priorité à recipe_id
        $recipe = null;

        $recipeId = $draft['recipe_id'] ?? null;
        if (is_string($recipeId) && ctype_digit($recipeId)) {
            $recipeId = (int) $recipeId;
        }

        if (is_int($recipeId) && $recipeId > 0) {
            /** @var Recipe|null $found */
            $found = $repoRecipe->find($recipeId);

            if (!$found instanceof Recipe || $found->getUser()?->getId() !== $user->getId()) {
                throw new \RuntimeException('recipe_not_found');
            }

            $recipe = $found;
        }

        // ✅ 2) Fallback legacy: recipe.name
        if (!$recipe instanceof Recipe) {
            if (array_key_exists('recipe', $draft) && is_array($draft['recipe'])) {
                $recipeName = trim((string)($draft['recipe']['name'] ?? $draft['recipe']['name_raw'] ?? ''));
                if ($recipeName !== '') {
                    $recipe = $this->resolveRecipeForUserOrShared($user, $recipeName);
                }
            }
        }

        // Cas recette fournie => supprimer 1 ligne (user+recipe+date)
        if ($recipe instanceof Recipe) {
            /** @var MealPlan|null $mp */
            $mp = $repoMeal->findOneBy([
                'user' => $user,
                'recipe' => $recipe,
                'date' => $date,
            ]);

            if (!$mp) {
                return [
                    'status' => 'not_found',
                    'removed' => 0,
                    'date' => $dateStr,
                    'recipe' => [
                        'id' => $recipe->getId(),
                        'name' => $recipe->getName(),
                        'nameKey' => $recipe->getNameKey(),
                    ],
                ];
            }

            $this->em->remove($mp);
            $this->em->flush();

            return [
                'status' => 'unplanned',
                'removed' => 1,
                'date' => $dateStr,
                'recipe' => [
                    'id' => $recipe->getId(),
                    'name' => $recipe->getName(),
                    'nameKey' => $recipe->getNameKey(),
                ],
            ];
        }

        // Cas pas de recette => supprimer tout du jour
        /** @var MealPlan[] $plans */
        $plans = $repoMeal->findBy([
            'user' => $user,
            'date' => $date,
        ]);

        if (count($plans) === 0) {
            return [
                'status' => 'not_found',
                'removed' => 0,
                'date' => $dateStr,
            ];
        }

        foreach ($plans as $mp) {
            $this->em->remove($mp);
        }
        $this->em->flush();

        return [
            'status' => 'unplanned_day',
            'removed' => count($plans),
            'date' => $dateStr,
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

        if ($d->format('Y-m-d') !== $dateStr) {
            return null;
        }

        return $d;
    }

    private function resolveRecipeForUserOrShared(User $user, string $recipeName): Recipe
    {
        $nameKey = $this->nameKeyNormalizer->toKey($recipeName);
        $repo = $this->em->getRepository(Recipe::class);

        // 1) owner exact
        /** @var Recipe|null $r */
        $r = $repo->findOneBy(['user' => $user, 'nameKey' => $nameKey]);
        if ($r instanceof Recipe) return $r;

        // 1.b) owner LIKE
        $qb = $repo->createQueryBuilder('r')
            ->andWhere('r.user = :u')
            ->andWhere('(r.nameKey LIKE :k OR LOWER(r.name) LIKE :q)')
            ->setParameter('u', $user)
            ->setParameter('k', '%' . $nameKey . '%')
            ->setParameter('q', '%' . mb_strtolower($recipeName) . '%')
            ->setMaxResults(1)
            ->orderBy('r.id', 'ASC');

        $cand = $qb->getQuery()->getOneOrNullResult();
        if ($cand instanceof Recipe) return $cand;

        // 2) shared exact (oldest)
        $qb2 = $repo->createQueryBuilder('r')
            ->andWhere('r.nameKey = :k')
            ->setParameter('k', $nameKey)
            ->setMaxResults(1)
            ->orderBy('r.id', 'ASC');

        $shared = $qb2->getQuery()->getOneOrNullResult();
        if ($shared instanceof Recipe) return $shared;

        throw new \RuntimeException('recipe_not_found');
    }
}
