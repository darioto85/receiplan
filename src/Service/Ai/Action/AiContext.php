<?php

namespace App\Service\Ai\Action;

final class AiContext
{
    public function __construct(
        public readonly string $locale = 'fr-FR',
        public readonly bool $debug = false,
    ) {}
}
