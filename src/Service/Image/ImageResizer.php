<?php

namespace App\Service\Image;

final class ImageResizer
{
    /**
     * Redimensionne un PNG en carré (ex: 200x200)
     * - conserve le fond blanc
     * - optimise la taille
     */
    public function resizePng(
        string $sourcePng,
        string $targetPng,
        int $size = 200
    ): void {
        $src = imagecreatefromstring($sourcePng);
        if ($src === false) {
            throw new \RuntimeException('Impossible de lire le PNG source.');
        }

        $dst = imagecreatetruecolor($size, $size);

        // fond blanc
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        // scale uniforme
        $scale = min($size / $srcW, $size / $srcH);
        $newW = (int) ($srcW * $scale);
        $newH = (int) ($srcH * $scale);

        $dstX = (int) (($size - $newW) / 2);
        $dstY = (int) (($size - $newH) / 2);

        imagecopyresampled(
            $dst,
            $src,
            $dstX,
            $dstY,
            0,
            0,
            $newW,
            $newH,
            $srcW,
            $srcH
        );

        // compression PNG (0 = max qualité, 9 = max compression)
        imagepng($dst, $targetPng, 9);

        imagedestroy($src);
        imagedestroy($dst);
    }
}
