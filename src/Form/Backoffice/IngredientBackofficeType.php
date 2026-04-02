<?php

namespace App\Form\Backoffice;

use App\Entity\Ingredient;
use App\Entity\User;
use App\Enum\CategoryEnum;
use App\Enum\Unit;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class IngredientBackofficeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom',
                'required' => true,
                'attr' => [
                    'placeholder' => 'Ex : tomate',
                ],
                'label_attr' => [
                    'class' => 'mb-2',
                ],
            ])
            ->add('nameKey', TextType::class, [
                'label' => 'Name key',
                'required' => true,
                'attr' => [
                    'placeholder' => 'Ex : tomate',
                ],
                'label_attr' => [
                    'class' => 'mb-2',
                ],
            ])
            ->add('category', EnumType::class, [
                'class' => CategoryEnum::class,
                'label' => 'Catégorie',
                'required' => false,
                'placeholder' => 'Aucune catégorie',
                'choice_label' => static fn (CategoryEnum $choice) => $choice->label(),
                'label_attr' => [
                    'class' => 'mb-2',
                ],
            ])
            ->add('unit', EnumType::class, [
                'class' => Unit::class,
                'label' => 'Unité par défaut',
                'required' => true,
                'choice_label' => static fn (Unit $choice) => match ($choice) {
                    Unit::G => 'g',
                    Unit::KG => 'kg',
                    Unit::ML => 'ml',
                    Unit::L => 'L',
                    Unit::PIECE => 'pièce',
                    Unit::POT => 'pot',
                    Unit::BOITE => 'boîte',
                    Unit::SACHET => 'sachet',
                    Unit::TRANCHE => 'tranche',
                    Unit::PAQUET => 'paquet',
                },
                'label_attr' => [
                    'class' => 'mb-2',
                ],
            ])
            ->add('user', EntityType::class, [
                'class' => User::class,
                'label' => 'Portée',
                'required' => false,
                'placeholder' => 'Global',
                'choice_label' => static function (User $user): string {
                    $email = $user->getEmail() ?? 'sans email';

                    return sprintf('#%d — %s', $user->getId(), $email);
                },
                'query_builder' => static fn (UserRepository $repo) => $repo->createQueryBuilder('u')
                    ->orderBy('u.id', 'DESC'),
                'label_attr' => [
                    'class' => 'mb-2',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Ingredient::class,
        ]);
    }
}