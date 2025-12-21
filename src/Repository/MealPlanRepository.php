<?php

namespace App\Repository;

use App\Entity\MealPlan;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

final class MealPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MealPlan::class);
    }

    public function existsForUserRecipeDateExcludingMealPlan(
        User $user,
        int $recipeId,
        \DateTimeImmutable $date,
        int $excludeMealPlanId
    ): bool {
        $count = (int) $this->createQueryBuilder('mp')
            ->select('COUNT(mp.id)')
            ->andWhere('mp.user = :user')
            ->andWhere('IDENTITY(mp.recipe) = :recipeId')
            ->andWhere('mp.date = :date')
            ->andWhere('mp.id != :excludeId')
            ->setParameter('user', $user)
            ->setParameter('recipeId', $recipeId)
            ->setParameter('date', $date, Types::DATE_IMMUTABLE)
            ->setParameter('excludeId', $excludeMealPlanId)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * @return MealPlan[]
     */
    public function findForUserOnDate(User $user, \DateTimeImmutable $date): array
    {
        return $this->createQueryBuilder('mp')
            ->andWhere('mp.user = :user')
            ->andWhere('mp.date = :date')
            ->setParameter('user', $user)
            ->setParameter('date', $date, Types::DATE_IMMUTABLE)
            ->orderBy('mp.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Planning futur (non validé) à partir d'une date (par défaut aujourd'hui).
     *
     * @return MealPlan[]
     */
    public function findFuturePlanned(User $user, ?\DateTimeImmutable $fromDate = null): array
    {
        $fromDate ??= new \DateTimeImmutable('today');

        return $this->createQueryBuilder('mp')
            ->andWhere('mp.user = :user')
            ->andWhere('mp.validated = false')
            ->andWhere('mp.date >= :fromDate')
            ->setParameter('user', $user)
            ->setParameter('fromDate', $fromDate, Types::DATE_IMMUTABLE)
            ->orderBy('mp.date', 'ASC')
            ->addOrderBy('mp.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Historique (validé) sur une période inclusive.
     *
     * @return MealPlan[]
     */
    public function findValidatedBetween(
        User $user,
        \DateTimeImmutable $fromDate,
        \DateTimeImmutable $toDate
    ): array {
        return $this->createQueryBuilder('mp')
            ->andWhere('mp.user = :user')
            ->andWhere('mp.validated = true')
            ->andWhere('mp.date >= :fromDate')
            ->andWhere('mp.date <= :toDate')
            ->setParameter('user', $user)
            ->setParameter('fromDate', $fromDate, Types::DATE_IMMUTABLE)
            ->setParameter('toDate', $toDate, Types::DATE_IMMUTABLE)
            ->orderBy('mp.date', 'DESC')
            ->addOrderBy('mp.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return MealPlan[]
     */
    public function findBetween(User $user, \DateTimeImmutable $fromDate, \DateTimeImmutable $toDate): array
    {
        return $this->createQueryBuilder('mp')
            ->andWhere('mp.user = :user')
            ->andWhere('mp.date >= :fromDate')
            ->andWhere('mp.date <= :toDate')
            ->setParameter('user', $user)
            ->setParameter('fromDate', $fromDate, Types::DATE_IMMUTABLE)
            ->setParameter('toDate', $toDate, Types::DATE_IMMUTABLE)
            ->orderBy('mp.date', 'ASC')
            ->addOrderBy('mp.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function existsForUserRecipeDate(User $user, int $recipeId, \DateTimeImmutable $date): bool
    {
        $count = (int) $this->createQueryBuilder('mp')
            ->select('COUNT(mp.id)')
            ->andWhere('mp.user = :user')
            ->andWhere('IDENTITY(mp.recipe) = :recipeId')
            ->andWhere('mp.date = :date')
            ->setParameter('user', $user)
            ->setParameter('recipeId', $recipeId)
            ->setParameter('date', $date, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
