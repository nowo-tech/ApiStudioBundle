<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Form;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\ApiStudioBundle;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Service\VariableSyntax;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Translation\StaticMessage;

use function is_string;

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
                'placeholder' => new StaticMessage('{{variable_name}}'),
                'class'       => 'as-var-key-input',
                'spellcheck'  => 'false',
            ],
            'help' => 'variables.key_syntax_hint',
        ]);
        // An empty value is a valid (empty) variable, not null.
        $this->addText($builder, 'value', ['required' => false, 'empty_data' => '']);
        $this->addCheckbox($builder, 'secret', ['required' => false]);
        $this->addText($builder, 'description', ['required' => false]);

        $builder->get('variableKey')->addModelTransformer(new CallbackTransformer(
            static fn (?string $value): string => $value !== null && $value !== '' ? VariableSyntax::formatKey($value) : '',
            static function (?string $value): string {
                // Invalid or empty keys are form errors, not exceptions escaping the request.
                try {
                    return VariableSyntax::normalizeKey((string) $value);
                } catch (InvalidArgumentException $e) {
                    throw new TransformationFailedException($e->getMessage(), 0, $e, $e->getMessage());
                }
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ApiEnvironmentVariable::class,
            // Rows added to the collection: the entity needs a key; the submitted one is mapped right after.
            'empty_data'         => static fn (FormInterface $form): ApiEnvironmentVariable => new ApiEnvironmentVariable(self::submittedKey($form)),
            'translation_domain' => ApiStudioBundle::TRANSLATION_DOMAIN,
        ]);
    }

    /**
     * Key of a new row: the normalized submitted key, else a placeholder (the row is invalid and never persisted).
     *
     * @param FormInterface<mixed> $form
     */
    private static function submittedKey(FormInterface $form): string
    {
        $key = $form->get('variableKey')->getData();

        return is_string($key) && $key !== '' ? $key : 'variable';
    }
}
