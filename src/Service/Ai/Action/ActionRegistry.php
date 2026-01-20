<?php

namespace App\Service\Ai\Action;

final class ActionRegistry
{
    /** @var array<string, AiActionInterface> */
    private array $byName = [];

    /**
     * @param iterable<AiActionInterface> $actions
     */
    public function __construct(iterable $actions)
    {
        foreach ($actions as $a) {
            $this->byName[$a->name()] = $a;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->byName[$name]);
    }

    public function get(string $name): AiActionInterface
    {
        if (!$this->has($name)) {
            throw new \RuntimeException('Unknown AI action: ' . $name);
        }
        return $this->byName[$name];
    }
}
