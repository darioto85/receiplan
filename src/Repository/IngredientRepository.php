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
     * Autocomplete / recherche simple.
     * (Tu peux brancher ça sur TomSelect)
     *
     * @return Ingredient[]
     */
    public function searchVisibleToUser(User $user, string $query, int $limit = 20): array
    {
        $q = trim($query);
        if ($q === '') {
            return [];
        }

        return $this->createVisibleToUserQueryBuilder($user, 'i')
            ->andWhere('i.name LIKE :q')
            ->setParameter('q', '%' . $q . '%')
            // ✅ Globaux d'abord (optionnel, mais UX souvent meilleure)
            ->addOrderBy('CASE WHEN i.user IS NULL THEN 0 ELSE 1 END', 'ASC')
            ->addOrderBy('i.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
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
