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
        $unit = $this->normalizeUnit($item['unit'] ?? null);
        $unitRaw = $item['unit_raw'] ?? null;

        // Si l'unité normalisée est encore nulle, on tente depuis unit_raw
        if ($unit === null && is_string($unitRaw) && trim($unitRaw) !== '') {
            $parsedUnit = $this->normalizeUnit($unitRaw);
            if ($parsedUnit !== null) {
                $unit = $parsedUnit;
                $warnings[] = 'unit_normalized_from_raw';
            }
        }

        // 1️⃣ Conversion quantity_raw → float
        if ($quantity === null && is_string($quantityRaw)) {
            $parsed = $this->parseQuantityFromRaw($quantityRaw);
            if ($parsed !== null) {
                $quantity = $parsed;
                $warnings[] = 'quantity_parsed_from_raw';
            }
        }

        // 2️⃣ Unité non supportée
        if ($unit === null && $unitRaw !== null) {
            $warnings[] = 'unsupported_unit';
        }

        // 3️⃣ Quantité suspecte pour l'unité
        if ($quantity !== null && $unit !== null) {
            if ($this->isSuspiciousQuantity($quantity, $unit)) {
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
            $unit === null;

        // 6️⃣ fallback unité
        if ($unit === null) {
            $unit = 'piece';
            $warnings[] = 'unit_defaulted_to_piece';
        }

        return [
            'ingredient' => [
                'name' => $item['name'],
                'quantity' => $quantity,
                'unit' => $unit,
                'quantity_raw' => $quantityRaw,
                'unit_raw' => $unitRaw,
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
        $raw = trim(mb_strtolower($raw));

        // x4, x 4
        if (preg_match('/^x\s*(\d+(?:[.,]\d+)?)$/u', $raw, $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }

        // 1,5 ou 1.5 ou 2
        if (preg_match('/^\d+(?:[.,]\d+)?$/u', $raw)) {
            return (float) str_replace(',', '.', $raw);
        }

        // "une", "un"
        if (in_array($raw, ['une', 'un'], true)) {
            return 1.0;
        }

        return null;
    }

    private function normalizeUnit(?string $unit): ?string
    {
        if ($unit === null) {
            return null;
        }

        $u = trim(mb_strtolower($unit));
        if ($u === '') {
            return null;
        }

        $u = str_replace('.', '', $u);
        $u = str_replace(['é', 'è', 'ê', 'ë'], 'e', $u);
        $u = str_replace(['à', 'â'], 'a', $u);
        $u = str_replace(['î', 'ï'], 'i', $u);
        $u = str_replace(['ô', 'ö'], 'o', $u);
        $u = str_replace(['ù', 'û', 'ü'], 'u', $u);
        $u = str_replace('ç', 'c', $u);

        return match ($u) {
            'g', 'gramme', 'grammes', 'gr' => 'g',

            'kg', 'kilo', 'kilos', 'kilogramme', 'kilogrammes' => 'kg',

            'ml', 'millilitre', 'millilitres' => 'ml',

            'l', 'litre', 'litres' => 'l',

            'piece', 'pieces', 'pièce', 'pièces', 'unite', 'unites', 'unité', 'unités' => 'piece',

            'pot', 'pots' => 'pot',

            'boite', 'boites', 'boîte', 'boîtes' => 'boite',

            'sachet', 'sachets' => 'sachet',

            'tranche', 'tranches' => 'tranche',

            'paquet', 'paquets' => 'paquet',

            default => null,
        };
    }

    private function isSuspiciousQuantity(float $quantity, string $unit): bool
    {
        return match ($unit) {
            'kg' => $quantity > 20,
            'l' => $quantity > 20,
            'g' => $quantity < 1,
            'ml' => $quantity < 1,
            'piece' => $quantity > 50,
            'pot' => $quantity > 50,
            'boite' => $quantity > 50,
            'sachet' => $quantity > 100,
            'tranche' => $quantity > 100,
            'paquet' => $quantity > 50,
            default => false,
        };
    }
}