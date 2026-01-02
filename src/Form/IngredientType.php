<?php

namespace App\Form;

use App\Entity\Ingredient;
use App\Enum\CategoryEnum;
use App\Form\EventSubscriber\IngredientNameKeySubscriber;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class IngredientType extends AbstractType
{
    public function __construct(
        private readonly IngredientNameKeySubscriber $nameKeySubscriber,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name')
            ->add('unit')
            ->add('category', EnumType::class, [
                'class' => CategoryEnum::class,
                'required' => false,
                'placeholder' => '—',
                'choice_label' => static fn (CategoryEnum $c) => method_exists($c, 'label') ? $c->label() : $c->value,
            ])
        ;

        // ✅ Uniformisation: calcule toujours nameKey via IngredientNameKeyNormalizer::toKey()
        $builder->addEventSubscriber($this->nameKeySubscriber);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Ingredient::class,
        ]);
    }
}
