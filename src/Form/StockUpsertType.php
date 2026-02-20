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
            ->add('unit', ChoiceType::class, [
                'required' => true,
                'placeholder' => false,

                // ✅ important : labels affichés
                'choice_label' => static function ($choice, $key, $value) {
                    // $key = 'g', 'kg', etc. dans notre tableau
                    return (string) $key;
                },

                // ✅ IMPORTANT : value HTML = backed enum value ("g", "kg", "piece"...)
                'choice_value' => static function (?Unit $choice) {
                    return $choice?->value;
                },

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

                // ✅ valeur par défaut
                'data' => Unit::G,
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