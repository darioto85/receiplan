<?php

namespace App\Repository;

use App\Entity\AssistantConversation;
use App\Entity\AssistantRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AssistantRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssistantRun::class);
    }

    public function findActiveForConversation(AssistantConversation $conversation): ?AssistantRun
    {
        return $this->findOneBy([
            'conversation' => $conversation,
            'isActive' => true,
        ]);
    }

    public function findLastForConversation(AssistantConversation $conversation): ?AssistantRun
    {
        return $this->findOneBy(
            ['conversation' => $conversation],
            ['createdAt' => 'DESC']
        );
    }
}