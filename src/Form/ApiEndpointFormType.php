<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Form;

use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEndpointTranslation;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ApiEndpoint> */
final class ApiEndpointFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class)
            ->add('slug', TextType::class)
            ->add('method', EnumType::class, ['class' => HttpMethod::class])
            ->add('path', TextType::class)
            ->add('soapAction', TextType::class, ['required' => false])
            ->add('contentType', TextType::class)
            ->add('headers', JsonMapType::class, ['required' => false, 'label' => 'field.headers'])
            ->add('queryParams', JsonMapType::class, ['required' => false, 'label' => 'field.query_params'])
            ->add('requestBodyTemplate', TextareaType::class, ['required' => false])
            ->add('preRequestScript', TextareaType::class, ['required' => false, 'attr' => ['rows' => 8, 'class' => 'font-monospace']])
            ->add('postRequestScript', TextareaType::class, ['required' => false, 'attr' => ['rows' => 8, 'class' => 'font-monospace']])
            ->add('sortOrder', IntegerType::class)
            ->add('enabled', CheckboxType::class, ['required' => false])
            ->add('translations', CollectionType::class, [
                'entry_type'    => ApiEndpointTranslationFormType::class,
                'entry_options' => ['label' => false],
                'allow_add'     => false,
                'allow_delete'  => false,
                'by_reference'  => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => ApiEndpoint::class,
            'translation_domain' => 'nowo_api_studio',
        ]);
    }
}

/** @extends AbstractType<ApiEndpointTranslation> */
final class ApiEndpointTranslationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('locale', HiddenType::class)
            ->add('title', TextType::class, ['required' => false])
            ->add('description', TextareaType::class, ['required' => false])
            ->add('notes', TextareaType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ApiEndpointTranslation::class]);
    }
}
