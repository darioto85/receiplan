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
                'data' => Unit::G, // défaut (si tu veux, on pourra le remplacer par l'unité de base de l'ingrédient côté JS plus tard)
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
