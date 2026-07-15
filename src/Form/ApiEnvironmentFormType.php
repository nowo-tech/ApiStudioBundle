<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Form;

use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Service\VariableSyntax;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ApiEnvironment> */
final class ApiEnvironmentFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class)
            ->add('slug', TextType::class)
            ->add('isDefault', CheckboxType::class, ['required' => false])
            ->add('variables', CollectionType::class, [
                'entry_type'    => ApiEnvironmentVariableFormType::class,
                'entry_options' => ['label' => false],
                'allow_add'     => true,
                'allow_delete'  => true,
                'by_reference'  => false,
                'label'         => 'variables.section_title',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => ApiEnvironment::class,
            'translation_domain' => 'nowo_api_studio',
        ]);
    }
}

/** @extends AbstractType<ApiEnvironmentVariable> */
final class ApiEnvironmentVariableFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('variableKey', TextType::class, [
                'label' => 'field.variable_key',
                'attr'  => [
                    'placeholder' => '{{variable_name}}',
                    'class'       => 'as-var-key-input',
                    'spellcheck'  => 'false',
                ],
                'help' => 'variables.key_syntax_hint',
            ])
            ->add('value', TextType::class)
            ->add('secret', CheckboxType::class, ['required' => false])
            ->add('description', TextType::class, ['required' => false]);

        $builder->get('variableKey')->addModelTransformer(new CallbackTransformer(
            static fn (?string $value): string => $value !== null && $value !== '' ? VariableSyntax::formatKey($value) : '',
            static function (?string $value): string {
                if ($value === null || trim($value) === '') {
                    return '';
                }

                return VariableSyntax::normalizeKey($value);
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => ApiEnvironmentVariable::class,
            'translation_domain' => 'nowo_api_studio',
        ]);
    }
}
