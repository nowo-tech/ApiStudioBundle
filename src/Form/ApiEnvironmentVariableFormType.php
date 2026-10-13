<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Form;

use Nowo\ApiStudioBundle\ApiStudioBundle;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Service\VariableSyntax;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ApiEnvironmentVariable> */
#[FormKitConfig('api_studio')]
final class ApiEnvironmentVariableFormType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addText($builder, 'variableKey', [
            'label' => 'field.variable_key',
            'attr'  => [
                'placeholder' => '{{variable_name}}',
                'class'       => 'as-var-key-input',
                'spellcheck'  => 'false',
            ],
            'help' => 'variables.key_syntax_hint',
        ]);
        $this->addText($builder, 'value', []);
        $this->addCheckbox($builder, 'secret', ['required' => false]);
        $this->addText($builder, 'description', ['required' => false]);

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
            'translation_domain' => ApiStudioBundle::TRANSLATION_DOMAIN,
        ]);
    }
}
