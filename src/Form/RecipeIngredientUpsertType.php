<?php

namespace App\Form;

use App\Entity\Ingredient;
use App\Entity\User;
use App\Enum\Unit;
use App\Repository\IngredientRepository;
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
        /** @var User|null $user */
        $user = $options['user'];

        $builder
            ->add('ingredient', EntityType::class, [
                'class' => Ingredient::class,
                'choice_label' => 'name',
                'placeholder' => 'Choisir un ingrédient',
                'required' => true,
                'query_builder' => function (IngredientRepository $ingredientRepository) use ($user) {
                    if (!$user instanceof User) {
                        return $ingredientRepository->createQueryBuilder('i')
                            ->where('1 = 0');
                    }

                    return $ingredientRepository
                        ->createVisibleToUserQueryBuilder($user, 'i')
                        ->orderBy('i.name', 'ASC');
                },
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

                'choice_label' => static function ($choice, $key, $value) {
                    return (string) $key;
                },

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

                'data' => Unit::G,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'user' => null,
        ]);

        $resolver->setAllowedTypes('user', ['null', User::class]);
    }
}