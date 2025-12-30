<?php

namespace App\Repository;

use App\Entity\Shopping;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Shopping>
 */
final class ShoppingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Shopping::class);
    }

    /**
     * Retourne la liste de courses d'un utilisateur,
     * avec jointure Ingredient pour affichage.
     *
     * @return Shopping[]
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.ingredient', 'i')->addSelect('i')
            ->andWhere('s.user = :user')
            ->setParameter('user', $user)
            ->orderBy('i.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Shopping[]
     */
    public function findCheckedForUser(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.ingredient', 'i')->addSelect('i')
            ->andWhere('s.user = :user')
            ->andWhere('s.checked = true')
            ->setParameter('user', $user)
            ->orderBy('s.checkedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
