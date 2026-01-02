<?php

namespace App\Service;

use App\Entity\Recipe;

final class RecipeImageResolver
{
    public const PUBLIC_DIR = 'img/recipes';
    public const PLACEHOLDER_FILE = 'placeholder.png';

    public function __construct(
        private readonly string $projectDir,
    ) {}

    public function hasImage(Recipe $recipe): bool
    {
        $file = $this->getFileName($recipe);
        if ($file === null) return false;

        $absolute = $this->projectDir . '/public/' . self::PUBLIC_DIR . '/' . $file;
        return is_file($absolute);
    }

    public function getAbsolutePathForWrite(Recipe $recipe): string
    {
        $file = $this->getFileName($recipe);
        if ($file === null) {
            throw new \RuntimeException('Recipe id manquant.');
        }

        $dir = $this->projectDir . '/public/' . self::PUBLIC_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir . '/' . $file;
    }

    public function getPublicUrl(Recipe $recipe): string
    {
        $file = $this->getFileName($recipe);
        if ($file === null) {
            return '/' . self::PUBLIC_DIR . '/' . self::PLACEHOLDER_FILE;
        }

        $relative = self::PUBLIC_DIR . '/' . $file;
        $absolute = $this->projectDir . '/public/' . $relative;

        return is_file($absolute)
            ? '/' . $relative
            : '/' . self::PUBLIC_DIR . '/' . self::PLACEHOLDER_FILE;
    }

    private function getFileName(Recipe $recipe): ?string
    {
        $id = $recipe->getId();
        if (!$id) return null;

        return $id . '.png';
    }
}
