<?php

namespace App\Form;

use App\Entity\Preinscription;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PreinscriptionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => false,
            'required' => true,
            'attr' => [
                'placeholder' => 'Ton email',
                'autocomplete' => 'email',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Preinscription::class,
            // CSRF activé par défaut, mais on peut être explicite :
            'csrf_protection' => true,
        ]);
    }
}