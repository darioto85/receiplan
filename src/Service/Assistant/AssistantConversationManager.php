<?php

namespace App\Service\Assistant;

use App\Entity\AssistantConversation;
use App\Entity\AssistantRun;
use App\Entity\User;
use App\Enum\AssistantConversationStatus;
use App\Repository\AssistantConversationRepository;
use App\Repository\AssistantRunRepository;
use Doctrine\ORM\EntityManagerInterface;

class AssistantConversationManager
{
    public function __construct(
        private readonly AssistantConversationRepository $conversationRepository,
        private readonly AssistantRunRepository $runRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function getOrCreateConversation(User $user): AssistantConversation
    {
        $conversation = $this->conversationRepository->findOneBy([
            'user' => $user,
        ]);

        if ($conversation instanceof AssistantConversation) {
            return $conversation;
        }

        $conversation = new AssistantConversation($user);

        $this->em->persist($conversation);
        $this->em->flush();

        return $conversation;
    }

    public function getActiveRun(AssistantConversation $conversation): ?AssistantRun
    {
        return $this->runRepository->findOneBy([
            'conversation' => $conversation,
            'isActive' => true,
        ]);
    }

    public function getOrCreateActiveRun(AssistantConversation $conversation): AssistantRun
    {
        $run = $this->getActiveRun($conversation);

        if ($run instanceof AssistantRun) {
            return $run;
        }

        return $this->createRun($conversation);
    }

    public function createRun(AssistantConversation $conversation): AssistantRun
    {
        $run = new AssistantRun($conversation);

        $conversation->addRun($run);

        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    public function closeRun(
        AssistantRun $run,
        AssistantConversationStatus $status = AssistantConversationStatus::READY
    ): void {
        if (!$run->isActive()) {
            return;
        }

        $run->close($status);

        $this->em->persist($run);
        $this->em->flush();
    }
}