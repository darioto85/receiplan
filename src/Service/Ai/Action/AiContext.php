<?php

namespace App\Service\Ai\Action;

use App\Entity\User;

final class AiContext
{
    public function __construct(
        public readonly string $locale = 'fr-FR',
        public readonly bool $debug = false,
        public ?User $user = null, // ✅ permet aux actions d'accéder au user si besoin
    ) {}
}
