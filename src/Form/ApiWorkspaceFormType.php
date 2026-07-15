<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Form;

use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ApiWorkspace> */
final class ApiWorkspaceFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class)
            ->add('slug', TextType::class)
            ->add('description', TextareaType::class, ['required' => false])
            ->add('enabled', CheckboxType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ApiWorkspace::class]);
    }
}
