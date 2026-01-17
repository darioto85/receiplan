<?php

namespace App\Repository;

use App\Entity\AssistantConversation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AssistantConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssistantConversation::class);
    }

    public function findForUserAndDay(User $user, \DateTimeImmutable $day): ?AssistantConversation
    {
        return $this->findOneBy([
            'user' => $user,
            'day' => $day,
        ]);
    }

    public function getOrCreateForToday(User $user): AssistantConversation
    {
        $today = new \DateTimeImmutable('today');

        $conversation = $this->findForUserAndDay($user, $today);
        if ($conversation) {
            return $conversation;
        }

        $conversation = new AssistantConversation($user, $today);

        $em = $this->getEntityManager();
        $em->persist($conversation);
        $em->flush();

        return $conversation;
    }

    public function deleteBefore(\DateTimeImmutable $day): int
    {
        return $this->createQueryBuilder('c')
            ->delete()
            ->where('c.day < :day')
            ->setParameter('day', $day)
            ->getQuery()
            ->execute();
    }
}
