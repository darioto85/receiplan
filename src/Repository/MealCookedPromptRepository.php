<?php

namespace App\Repository;

use App\Entity\MealCookedPrompt;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

final class MealCookedPromptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MealCookedPrompt::class);
    }

    public function findOneForUserOnDate(User $user, \DateTimeImmutable $date): ?MealCookedPrompt
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->andWhere('p.date = :date')
            ->setParameter('user', $user)
            ->setParameter('date', $date, Types::DATE_IMMUTABLE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function existsForUserOnDate(User $user, \DateTimeImmutable $date): bool
    {
        $count = (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.user = :user')
            ->andWhere('p.date = :date')
            ->setParameter('user', $user)
            ->setParameter('date', $date, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Prompts envoyés mais sans réponse (pratique plus tard pour relance/expiration).
     *
     * @return MealCookedPrompt[]
     */
    public function findPendingAnswers(\DateTimeImmutable $forDate, int $limit = 200): array
    {
        $limit = max(1, min($limit, 2000));

        return $this->createQueryBuilder('p')
            ->andWhere('p.status = :status')
            ->andWhere('p.date = :date')
            ->setParameter('status', MealCookedPrompt::STATUS_SENT)
            ->setParameter('date', $forDate, Types::DATE_IMMUTABLE)
            ->orderBy('p.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
