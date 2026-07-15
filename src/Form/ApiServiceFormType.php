<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Form;

use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\AuthType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ApiService> */
final class ApiServiceFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class)
            ->add('slug', TextType::class)
            ->add('description', TextareaType::class, ['required' => false])
            ->add('baseUrl', TextType::class, [
                'attr' => ['placeholder' => '{{base_url}}'],
                'help' => 'field.base_url_help',
            ])
            ->add('defaultHeaders', JsonMapType::class, ['required' => false, 'label' => 'field.default_headers'])
            ->add('protocol', EnumType::class, ['class' => ApiProtocol::class])
            ->add('authType', EnumType::class, ['class' => AuthType::class])
            ->add('preRequestScript', TextareaType::class, ['required' => false, 'attr' => ['rows' => 6]])
            ->add('postRequestScript', TextareaType::class, ['required' => false, 'attr' => ['rows' => 6]])
            ->add('enabled', CheckboxType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => ApiService::class,
            'translation_domain' => 'nowo_api_studio',
        ]);
    }
}
