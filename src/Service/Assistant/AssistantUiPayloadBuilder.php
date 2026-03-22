<?php

namespace App\Service\Assistant;

use App\Enum\AssistantConversationStatus;

class AssistantUiPayloadBuilder
{
    /**
     * @param array<string, mixed> $llmResult
     * @param array<int, array<string, mixed>> $actions
     * @param array{
     *     success: bool,
     *     results: array<int, array<string, mixed>>,
     *     errors: array<int, array<string, mixed>>
     * }|null $execution
     *
     * @return array<string, mixed>
     */
    public function build(
        array $llmResult,
        array $actions,
        string $status,
        ?array $execution = null,
    ): array {
        $payload = [
            'type' => 'info',
            'conversation_status' => $status,
        ];

        if ($execution !== null) {
            $payload['execution'] = $execution;
            $payload['success'] = (bool) ($execution['success'] ?? false);
        }

        if ($status === AssistantConversationStatus::CONTINUE->value) {
            $continueCollectPayload = $this->buildContinueCollectPayload($actions);
            if ($continueCollectPayload !== null) {
                return $continueCollectPayload + [
                    'conversation_status' => $status,
                ];
            }
        }

        return $payload;
    }

    /**
     * @param array<int, array<string, mixed>> $actions
     *
     * @return array<string, mixed>|null
     */
    private function buildContinueCollectPayload(array $actions): ?array
    {
        $collectable = [];

        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $type = trim((string) ($action['type'] ?? ''));
            $missing = $action['missing'] ?? [];
            $status = trim((string) ($action['status'] ?? ''));

            if (!$this->isCollectableActionType($type)) {
                continue;
            }

            if (is_array($missing) && $missing !== []) {
                continue;
            }

            if (!in_array($status, ['ready', 'needs_input', 'blocked', 'cancelled'], true)) {
                continue;
            }

            $collectable[] = $action;
        }

        if ($collectable === []) {
            return null;
        }

        $primary = $collectable[0];
        $type = (string) ($primary['type'] ?? '');
        $clientActionId = (string) ($primary['client_action_id'] ?? '');

        $summary = $this->buildItemsSummary($primary);

        return [
            'type' => 'continue_collect',
            'collect_action' => $type,
            'client_action_id' => $clientActionId,
            'summary' => $summary,
            'buttons' => [
                [
                    'action' => 'add_more',
                    'label' => 'Ajouter autre chose',
                    'focus_input' => true,
                ],
                [
                    'action' => 'finish',
                    'label' => 'C’est fini',
                    'close_run' => true,
                ],
            ],
        ];
    }

    private function isCollectableActionType(string $type): bool
    {
        return in_array($type, ['stock.add', 'shopping.add'], true);
    }

    /**
     * @param array<string, mixed> $action
     * @return array<int, string>
     */
    private function buildItemsSummary(array $action): array
    {
        $data = $action['data'] ?? [];
        if (!is_array($data)) {
            return [];
        }

        $items = $data['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $lines = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $quantity = $item['quantity'] ?? null;
            $unit = trim((string) ($item['unit'] ?? ''));

            if (is_numeric($quantity)) {
                $line = $name . ' — ' . $this->formatNumber((float) $quantity);

                if ($unit !== '') {
                    $line .= ' ' . $this->formatUnitLabel($unit, (float) $quantity);
                }

                $lines[] = $line;
                continue;
            }

            $lines[] = $name;
        }

        return $lines;
    }

    private function formatNumber(float $number): string
    {
        if (floor($number) === $number) {
            return (string) (int) $number;
        }

        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    private function formatUnitLabel(string $unit, float $quantity): string
    {
        return match ($unit) {
            'piece' => $quantity === 1.0 ? 'pièce' : 'pièces',
            'ml' => 'mL',
            'l' => 'L',
            'boite' => $quantity === 1.0 ? 'boîte' : 'boîtes',
            'sachet' => $quantity === 1.0 ? 'sachet' : 'sachets',
            'tranche' => $quantity === 1.0 ? 'tranche' : 'tranches',
            'pot' => $quantity === 1.0 ? 'pot' : 'pots',
            'paquet' => $quantity === 1.0 ? 'paquet' : 'paquets',
            default => $unit,
        };
    }
}