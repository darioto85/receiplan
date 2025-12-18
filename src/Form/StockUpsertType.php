<?php

namespace App\Form;

use App\Entity\Ingredient;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class StockUpsertType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ingredient', EntityType::class, [
                'class' => Ingredient::class,
                'choice_label' => 'name',
                'placeholder' => 'Rechercher un ingrédient…',
                // clé: active Symfony UX Autocomplete
                'autocomplete' => true,
                'required' => true,
            ])
            ->add('quantity', NumberType::class, [
                'required' => true,
                'html5' => true,
                'scale' => 2,
                'attr' => [
                    'min' => 0,
                    'step' => '0.01',
                    'placeholder' => '0.00',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Form “non mappé” (on fait un upsert logique dans le controller)
        $resolver->setDefaults([
            'csrf_protection' => true,
        ]);
    }
}
