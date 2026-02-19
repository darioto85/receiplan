<?php

namespace App\Repository;

use App\Entity\Ingredient;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;

/**
 * @extends ServiceEntityRepository<Ingredient>
 */
class IngredientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ingredient::class);
    }

    /**
     * Base QueryBuilder : ingrédients visibles par un user
     * => globaux (user IS NULL) + privés du user (user = :user)
     */
    public function createVisibleToUserQueryBuilder(User $user, string $alias = 'i'): QueryBuilder
    {
        return $this->createQueryBuilder($alias)
            ->where(sprintf('%s.user IS NULL OR %s.user = :user', $alias, $alias))
            ->setParameter('user', $user);
    }

    /**
     * Liste tous les ingrédients visibles par un user (globaux + privés)
     *
     * @return Ingredient[]
     */
    public function findVisibleToUser(User $user): array
    {
        return $this->createVisibleToUserQueryBuilder($user, 'i')
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Résolution anti-doublon "propre" :
     * 1) cherche un global
     * 2) sinon cherche un privé du user
     */
    public function findOneVisibleByNameKey(User $user, string $nameKey): ?Ingredient
    {
        // 1) Global
        $global = $this->findOneBy([
            'user' => null,
            'nameKey' => $nameKey,
        ]);

        if ($global) {
            return $global;
        }

        // 2) Privé du user
        return $this->findOneBy([
            'user' => $user,
            'nameKey' => $nameKey,
        ]);
    }

    /**
     * Autocomplete / recherche (TomSelect).
     *
     * Pertinence:
     * - d'abord "commence par"
     * - puis "contient"
     * - globaux d'abord (optionnel)
     * - tri alpha
     *
     * Remarque:
     * - si tu passes aussi le nameKey normalisé (sans accents), on match dessus
     *   pour être tolérant (ex: "creme" match "crème").
     *
     * @return Ingredient[]
     */
    public function searchVisibleToUser(User $user, string $query, int $limit = 20, ?string $queryNameKey = null): array
    {
        $q = trim($query);
        if ($q === '') {
            return [];
        }

        $qb = $this->createVisibleToUserQueryBuilder($user, 'i');

        // LIKE sensibles au collation / DB, mais on améliore déjà beaucoup via le ranking.
        $qb->andWhere('(i.name LIKE :q OR i.nameKey LIKE :qKey)')
            ->setParameter('q', '%' . $q . '%')
            ->setParameter('qKey', '%' . ($queryNameKey ?? Ingredient::normalizeName($q)) . '%');

        // Ranking: begins-with avant contains
        $qb->addSelect(
            "CASE
                WHEN i.name LIKE :qPrefix THEN 0
                WHEN i.nameKey LIKE :qKeyPrefix THEN 1
                ELSE 2
            END AS HIDDEN rank_match"
        )
            ->setParameter('qPrefix', $q . '%')
            ->setParameter('qKeyPrefix', ($queryNameKey ?? Ingredient::normalizeName($q)) . '%');

        // ✅ Globaux d'abord (UX)
        $qb->addSelect('CASE WHEN i.user IS NULL THEN 0 ELSE 1 END AS HIDDEN rank_scope')
            ->addOrderBy('rank_match', 'ASC')
            ->addOrderBy('rank_scope', 'ASC')
            ->addOrderBy('i.name', 'ASC')
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    public function findOneNeedingImage(): ?Ingredient
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.imgGenerated = false')
            ->andWhere('i.nameKey IS NOT NULL')
            ->andWhere('i.nameKey <> :empty')
            ->setParameter('empty', '')
            ->orderBy('i.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
