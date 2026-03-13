<?php

namespace App\Service\Assistant;

use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\AssistantRun;
use App\Enum\AssistantMessageRole;
use Doctrine\ORM\EntityManagerInterface;

class AssistantMessageManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function addUserMessage(
        AssistantConversation $conversation,
        AssistantRun $run,
        string $content
    ): AssistantMessage {
        $message = new AssistantMessage(
            $conversation,
            AssistantMessageRole::USER,
            $content,
            null,
            $run
        );

        $conversation->addMessage($message);
        $run->addMessage($message);

        $this->em->persist($message);

        return $message;
    }

    public function addAssistantMessage(
        AssistantConversation $conversation,
        AssistantRun $run,
        string $content,
        ?array $payload = null
    ): AssistantMessage {
        $message = new AssistantMessage(
            $conversation,
            AssistantMessageRole::ASSISTANT,
            $content,
            $payload,
            $run
        );

        $conversation->addMessage($message);
        $run->addMessage($message);

        $this->em->persist($message);

        return $message;
    }

    public function addSystemMessage(
        AssistantConversation $conversation,
        AssistantRun $run,
        string $content,
        ?array $payload = null
    ): AssistantMessage {
        $message = new AssistantMessage(
            $conversation,
            AssistantMessageRole::SYSTEM,
            $content,
            $payload,
            $run
        );

        $conversation->addMessage($message);
        $run->addMessage($message);

        $this->em->persist($message);

        return $message;
    }

    public function serialize(AssistantMessage $message): array
    {
        return [
            'id' => $message->getId(),
            'role' => $message->getRole(),
            'content' => $message->getContent(),
            'payload' => $message->getPayload(),
            'created_at' => $message->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param AssistantMessage[] $messages
     */
    public function serializeMany(array $messages): array
    {
        return array_map(
            fn (AssistantMessage $message) => $this->serialize($message),
            $messages
        );
    }
}