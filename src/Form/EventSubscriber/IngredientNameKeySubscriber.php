<?php

namespace App\Form\EventSubscriber;

use App\Entity\Ingredient;
use App\Service\NameKeyNormalizer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

final class IngredientNameKeySubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly NameKeyNormalizer $normalizer) {}

    public static function getSubscribedEvents(): array
    {
        return [
            FormEvents::SUBMIT => 'onSubmit',
        ];
    }

    public function onSubmit(FormEvent $event): void
    {
        $ingredient = $event->getData();
        if (!$ingredient instanceof Ingredient) {
            return;
        }

        $name = $ingredient->getName();
        if ($name === null || trim($name) === '') {
            return;
        }

        // ✅ Source de vérité
        $ingredient->setNameKey($this->normalizer->toKey($name));
    }
}
