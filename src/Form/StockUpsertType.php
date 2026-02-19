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

class StockUpsertType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ingredient', EntityType::class, [
                'class' => Ingredient::class,
                'choice_label' => 'name',
                'placeholder' => 'Rechercher un ingrédient…',
                'required' => true,

                /**
                 * TomSelect va transformer ce <select>.
                 * On met un target Stimulus pour l’attraper facilement côté JS.
                 *
                 * IMPORTANT: on ne met pas "autocomplete => true" ici (Symfony UX),
                 * car on veut TomSelect (et pas l’autocomplete Symfony).
                 */
                'attr' => [
                    'data-stock-target' => 'ingredientSelect',
                    'data-placeholder' => 'Rechercher un ingrédient…',
                    'autocomplete' => 'off',
                ],
            ])
            ->add('quantity', NumberType::class, [
                'required' => true,
                'html5' => true,
                'scale' => 2,
                'attr' => [
                    'min' => 0,
                    'step' => '0.01',
                    'placeholder' => '0.00',
                    'autocomplete' => 'off',
                    'inputmode' => 'decimal',
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
                'attr' => [
                    'autocomplete' => 'off',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
        ]);
    }
}
