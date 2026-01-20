<?php

namespace App\Service\Ai\Action;

use App\Entity\User;

interface AiActionInterface
{
    public function name(): string;

    public function extractDraft(string $text, AiContext $ctx): array;

    public function normalizeDraft(array $draft, AiContext $ctx): array;

    /** @return array<int, array<string,mixed>> */
    public function buildClarifyQuestions(array $draft, AiContext $ctx): array;

    public function buildConfirmText(array $draft, AiContext $ctx): string;

    /** @return array<string,mixed> */
    public function apply(User $user, array $draft): array;
}
