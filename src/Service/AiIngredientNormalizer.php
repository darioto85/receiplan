<?php

namespace App\Service;

final class AiIngredientNormalizer
{
    private const CONFIDENCE_THRESHOLD = 0.75;

    /**
     * @param array{
     *   name:string,
     *   quantity:float|null,
     *   quantity_raw:string|null,
     *   unit:string|null,
     *   unit_raw:string|null,
     *   notes:string|null,
     *   confidence:float
     * } $item
     *
     * @return array{
     *   ingredient: array{
     *     name:string,
     *     quantity:float|null,
     *     unit:string|null,
     *     quantity_raw:string|null,
     *     unit_raw:string|null,
     *     notes:string|null
     *   },
     *   warnings: string[],
     *   needs_confirmation: bool
     * }
     */
    public function normalize(array $item): array
    {
        $warnings = [];

        $quantity = $item['quantity'];
        $quantityRaw = $item['quantity_raw'];

        // 1️⃣ Conversion virgule → float
        if ($quantity === null && is_string($quantityRaw)) {
            $parsed = $this->parseQuantityFromRaw($quantityRaw);
            if ($parsed !== null) {
                $quantity = $parsed;
                $warnings[] = 'quantity_parsed_from_raw';
            }
        }

        // 2️⃣ Unité non supportée
        if ($item['unit'] === null && $item['unit_raw'] !== null) {
            $warnings[] = 'unsupported_unit';
        }

        // 3️⃣ Quantité suspecte pour l'unité
        if ($quantity !== null && $item['unit'] !== null) {
            if ($this->isSuspiciousQuantity($quantity, $item['unit'])) {
                $warnings[] = 'suspicious_quantity_for_unit';
            }
        }

        // 4️⃣ Confidence basse
        if ($item['confidence'] < self::CONFIDENCE_THRESHOLD) {
            $warnings[] = 'low_confidence';
        }

        // 5️⃣ needs_confirmation
        $needsConfirmation =
            !empty($warnings) ||
            $quantity === null ||
            $item['unit'] === null;

        if ($item['unit'] === null) {
            $item['unit'] = 'piece';
            $warnings[] = 'unit_defaulted_to_piece';
        }

        return [
            'ingredient' => [
                'name' => $item['name'],
                'quantity' => $quantity,
                'unit' => $item['unit'],
                'quantity_raw' => $quantityRaw,
                'unit_raw' => $item['unit_raw'],
                'notes' => $item['notes'],
            ],
            'warnings' => array_values(array_unique($warnings)),
            'needs_confirmation' => $needsConfirmation,
        ];
    }

    /**
     * Ex: "1,5" -> 1.5 | "2" -> 2.0 | "x4" -> 4.0
     */
    private function parseQuantityFromRaw(string $raw): ?float
    {
        $raw = trim(strtolower($raw));

        // x4, x 4
        if (preg_match('/^x\s*(\d+(?:[.,]\d+)?)$/', $raw, $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }

        // 1,5 ou 1.5 ou 2
        if (preg_match('/^\d+(?:[.,]\d+)?$/', $raw)) {
            return (float) str_replace(',', '.', $raw);
        }

        // "une", "un"
        if (in_array($raw, ['une', 'un'], true)) {
            return 1.0;
        }

        return null;
    }

    private function isSuspiciousQuantity(float $quantity, string $unit): bool
    {
        return match ($unit) {
            'kg' => $quantity > 20,
            'l'  => $quantity > 20,
            'g'  => $quantity < 1,
            'ml' => $quantity < 1,
            'piece' => $quantity > 50,
            default => false,
        };
    }
}
