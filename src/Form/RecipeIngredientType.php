<?php

namespace App\Form;

use App\Entity\Ingredient;
use App\Entity\RecipeIngredient;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RecipeIngredientType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ingredient', EntityType::class, [
                'class' => Ingredient::class,
                'choice_label' => 'name',
                'placeholder' => 'Rechercher un ingrédient…',
                'autocomplete' => true,
                'tom_select_options' => [
                    'create' => true,
                    'persist' => false,
                ],
                'attr' => [
                    'class' => 'form-select',
                    'data-controller' => 'ingredient-create-hook',
                ],
            ])
            ->add('quantity', NumberType::class, [
                'scale' => 2,
                'html5' => true,
                'attr' => [
                    'class' => 'form-control', // ✅ ici
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RecipeIngredient::class,
        ]);
    }
}
