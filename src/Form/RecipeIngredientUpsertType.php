<?php

namespace App\Form;

use App\Entity\Ingredient;
use App\Enum\Unit;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;

final class RecipeIngredientUpsertType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ingredient', EntityType::class, [
                'class' => Ingredient::class,
                'choice_label' => 'name',
                'placeholder' => 'Choisir un ingrédient',
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'Choisis un ingrédient.'),
                ],
            ])
            ->add('quantity', NumberType::class, [
                'required' => true,
                'scale' => 2,
                'html5' => true,
                'constraints' => [
                    new NotBlank(message: 'Indique une quantité.'),
                    new Positive(message: 'La quantité doit être > 0.'),
                ],
            ])
            ->add('unit', ChoiceType::class, [
                'required' => true,
                'placeholder' => false,
                'choices' => [
                    'g' => Unit::G,
                    'kg' => Unit::KG,
                    'ml' => Unit::ML,
                    'L' => Unit::L,
                    'pièce' => Unit::PIECE,
                    'pot' => Unit::POT,
                    'boîte' => Unit::BOITE,
                    'sachet' => Unit::SACHET,
                    'tranche' => Unit::TRANCHE,
                    'paquet' => Unit::PAQUET,
                ],
                'data' => Unit::G,
                'constraints' => [
                    new NotBlank(message: 'Choisis une unité.'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Pas de data_class : c'est un "petit" form de saisie (comme StockUpsertType)
        $resolver->setDefaults([
            'csrf_protection' => true,
        ]);
    }
}
