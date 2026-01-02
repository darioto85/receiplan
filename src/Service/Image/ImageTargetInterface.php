<?php

namespace App\Service\Image;

interface ImageTargetInterface
{
    public function hasImage(object $entity): bool;
    public function getAbsolutePathForWrite(object $entity): string;
    public function buildPrompt(object $entity): string;
}
