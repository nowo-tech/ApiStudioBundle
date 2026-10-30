<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function is_string;

/**
 * Generic holder for client-side-only controls (REQ-TWIG-005).
 *
 * `fields` maps a child name to `[FormTypeClass, options]`; every child is unmapped and never submitted by the bundle
 * (the API Studio scripts read them by id / name / class). An `html_id` entry in the child options forces the HTML id
 * the scripts rely on. Empty block prefix keeps the child names flat (e.g. `as-export-format`).
 *
 * FormKit type (profile `api_studio`, REQ-TWIG-005 rule 11).
 *
 * @extends AbstractType<null>
 */
#[FormKitConfig('api_studio')]
final class ClientSideFieldsType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, array{0: class-string<FormTypeInterface<mixed>>, 1?: array<string, mixed>}> $fields */
        $fields = $options['fields'];
        foreach ($fields as $name => $definition) {
            $childOptions = $definition[1] ?? [];
            unset($childOptions['html_id']);
            $builder->add($name, $definition[0], ['mapped' => false] + $childOptions + ['required' => false]);
        }
    }

    /**
     * @param FormView<mixed> $view
     * @param FormInterface<mixed> $form
     * @param array<string, mixed> $options
     */
    #[Override]
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        /** @var array<string, array{0: class-string<FormTypeInterface<mixed>>, 1?: array<string, mixed>}> $fields */
        $fields = $options['fields'];
        foreach ($fields as $name => $definition) {
            $htmlId = $definition[1]['html_id'] ?? null;
            if (is_string($htmlId) && isset($view->children[$name])) {
                $view->children[$name]->vars['id'] = $htmlId;
            }
        }
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection'    => false,
            'translation_domain' => 'NowoApiStudioBundle',
            'fields'             => [],
        ]);
        $resolver->setAllowedTypes('fields', 'array');
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
