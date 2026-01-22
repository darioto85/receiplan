<?php

namespace App\Service;

use App\Entity\Recipe;
use App\Entity\User;
use App\Repository\RecipeRepository;

final class RecipeResolver
{
    public function __construct(
        private readonly RecipeRepository $recipes,
        private readonly NameKeyNormalizer $keyNormalizer,
    ) {}

    /**
     * @return array{
     *   status: 'matched'|'ambiguous'|'not_found',
     *   recipe: ?Recipe,
     *   candidates: Recipe[],
     *   tried_keys: string[]
     * }
     */
    public function resolve(User $user, string $rawName, int $limit = 8): array
    {
        $rawName = trim($rawName);
        if ($rawName === '') {
            return ['status' => 'not_found', 'recipe' => null, 'candidates' => [], 'tried_keys' => []];
        }

        // Candidate keys (robustes) + recette normalizeNameKey
        $keys = $this->keyNormalizer->toCandidateKeys($rawName);
        $keys[] = Recipe::normalizeNameKey($rawName);

        // unique preserving order
        $seen = [];
        $tried = [];
        foreach ($keys as $k) {
            $k = trim((string)$k);
            if ($k === '' || isset($seen[$k])) continue;
            $seen[$k] = true;
            $tried[] = $k;
        }

        // 1) Exact match nameKey
        foreach ($tried as $k) {
            $r = $this->recipes->findOneBy(['user' => $user, 'nameKey' => $k]);
            if ($r instanceof Recipe) {
                return ['status' => 'matched', 'recipe' => $r, 'candidates' => [], 'tried_keys' => $tried];
            }
        }

        // 2) LIKE fallback
        $candidates = $this->recipes->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->setParameter('user', $user)
            ->andWhere('r.name LIKE :q OR r.nameKey LIKE :qk')
            ->setParameter('q', '%' . $rawName . '%')
            ->setParameter('qk', '%' . Recipe::normalizeNameKey($rawName) . '%')
            ->orderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        if (count($candidates) === 1) {
            return ['status' => 'matched', 'recipe' => $candidates[0], 'candidates' => [], 'tried_keys' => $tried];
        }

        if (count($candidates) > 1) {
            // Tentative de meilleur candidat: exact name case-insensitive
            $lower = mb_strtolower($rawName);
            foreach ($candidates as $c) {
                if (mb_strtolower((string)$c->getName()) === $lower) {
                    return ['status' => 'matched', 'recipe' => $c, 'candidates' => [], 'tried_keys' => $tried];
                }
            }

            return ['status' => 'ambiguous', 'recipe' => null, 'candidates' => $candidates, 'tried_keys' => $tried];
        }

        return ['status' => 'not_found', 'recipe' => null, 'candidates' => [], 'tried_keys' => $tried];
    }
}
