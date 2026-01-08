<?php

namespace App\Repository;

use App\Entity\DailyMealSuggestion;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

final class DailyMealSuggestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DailyMealSuggestion::class);
    }

    public function findOneForUserDate(User $user, \DateTimeImmutable $date): ?DailyMealSuggestion
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :u')
            ->andWhere('s.date = :d')
            ->setParameter('u', $user)
            ->setParameter('d', $date, Types::DATE_IMMUTABLE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
