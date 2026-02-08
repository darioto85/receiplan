<?php

namespace App\Service\Image;

/**
 * Redimensionne une image (JPG/PNG/WEBP/HEIC si déjà converti) via GD.
 * Objectif: réduire le coût tokens (vision) en gardant un texte lisible.
 *
 * - largeur max par défaut: 1024px
 * - sortie: JPEG qualité 82 (bon compromis poids/lisibilité)
 */
final class RecipeScanImageResizer
{
    public function __construct(
        private readonly int $maxWidth = 1024,
        private readonly int $jpegQuality = 82,
    ) {}

    /**
     * @return string chemin absolu du fichier de sortie (JPEG)
     */
    public function resizeToJpeg(string $inputAbsPath, string $outputAbsPath): string
    {
        if (!is_file($inputAbsPath)) {
            throw new \RuntimeException('Image introuvable pour redimensionnement.');
        }

        $data = file_get_contents($inputAbsPath);
        if ($data === false) {
            throw new \RuntimeException('Impossible de lire l’image.');
        }

        // Très robuste : GD sait décoder JPG/PNG/WEBP depuis une string.
        // (HEIC/HEIF: GD ne gère généralement pas; mais sur beaucoup de serveurs,
        // l’upload iPhone arrive en JPG; sinon on traitera HEIC plus tard.)
        $src = @imagecreatefromstring($data);
        if (!$src) {
            throw new \RuntimeException('Format image non supporté par GD (imagecreatefromstring a échoué).');
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        if ($srcW <= 0 || $srcH <= 0) {
            imagedestroy($src);
            throw new \RuntimeException('Dimensions image invalides.');
        }

        // Si l'image est déjà petite, on la convertit juste en JPEG (utile pour uniformiser)
        if ($srcW <= $this->maxWidth) {
            $this->saveAsJpeg($src, $outputAbsPath);
            imagedestroy($src);
            return $outputAbsPath;
        }

        $ratio = $srcH / $srcW;
        $dstW = $this->maxWidth;
        $dstH = (int) round($dstW * $ratio);

        $dst = imagecreatetruecolor($dstW, $dstH);
        if (!$dst) {
            imagedestroy($src);
            throw new \RuntimeException('Impossible de créer l’image de destination.');
        }

        // Fond blanc (utile si source avec alpha)
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $white);

        // Meilleure qualité
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        $this->saveAsJpeg($dst, $outputAbsPath);

        imagedestroy($src);
        imagedestroy($dst);

        return $outputAbsPath;
    }

    private function saveAsJpeg(\GdImage $img, string $outputAbsPath): void
    {
        $dir = dirname($outputAbsPath);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Impossible de créer le dossier de sortie pour l’image.');
            }
        }

        if (!imagejpeg($img, $outputAbsPath, $this->jpegQuality)) {
            throw new \RuntimeException('Impossible d’écrire le JPEG redimensionné.');
        }
    }
}
