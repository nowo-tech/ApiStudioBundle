<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Form;

use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One editable key/value row of the endpoint tester (query params, headers) — REQ-TWIG-005.
 *
 * Client-side only: api-tester reads the row inputs by class (`as-kv-enabled`, `as-kv-key`, `as-kv-value`).
 *
 * @extends AbstractType<array{key?: string|null, value?: string|null}>
 */
final class KeyValueRowType extends AbstractType
{
    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enabled', CheckboxType::class, [
                'mapped'   => false,
                'required' => false,
                'label'    => false,
                'data'     => true,
                'attr'     => ['class' => 'as-kv-enabled'],
            ])
            ->add('key', TextType::class, [
                'required' => false,
                'label'    => false,
                'attr'     => ['class' => 'as-kv-key'],
            ])
            ->add('value', TextType::class, [
                'required' => false,
                'label'    => false,
                'attr'     => ['class' => 'as-kv-value'],
            ]);
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_protection' => false]);
    }
}
