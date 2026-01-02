<?php

namespace App\Service;

use App\Entity\Ingredient;

final class IngredientImageResolver
{
    public const PUBLIC_DIR = 'img/ingredients';
    public const PLACEHOLDER_FILE = 'placeholder.png';

    public function __construct(
        private readonly string $projectDir, // injecte %kernel.project_dir%
    ) {}

    public function getPublicUrl(Ingredient $ingredient): string
    {
        $file = $this->getFileName($ingredient);
        if ($file === null) {
            return '/' . self::PUBLIC_DIR . '/' . self::PLACEHOLDER_FILE;
        }

        $relative = self::PUBLIC_DIR . '/' . $file;
        $absolute = $this->projectDir . '/public/' . $relative;

        if (is_file($absolute)) {
            return '/' . $relative;
        }

        return '/' . self::PUBLIC_DIR . '/' . self::PLACEHOLDER_FILE;
    }

    public function hasImage(Ingredient $ingredient): bool
    {
        $file = $this->getFileName($ingredient);
        if ($file === null) {
            return false;
        }

        $absolute = $this->projectDir . '/public/' . self::PUBLIC_DIR . '/' . $file;
        return is_file($absolute);
    }

    public function getAbsolutePathForWrite(Ingredient $ingredient): string
    {
        $file = $this->getFileName($ingredient);
        if ($file === null) {
            throw new \RuntimeException('Ingredient nameKey manquant.');
        }

        $dir = $this->projectDir . '/public/' . self::PUBLIC_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir . '/' . $file;
    }

    private function getFileName(Ingredient $ingredient): ?string
    {
        $nameKey = $ingredient->getNameKey();
        if (!$nameKey) {
            return null;
        }

        return $nameKey . '.png';
    }
}
