<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Form;

use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Form\EventListener\SlugFromName;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ApiWorkspace> */
#[FormKitConfig('api_studio')]
final class ApiWorkspaceFormType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addText($builder, 'name', []);
        // Empty: derived from the name (SlugFromName).
        $this->addText($builder, 'slug', ['required' => false, 'empty_data' => '', 'help' => 'field.slug_help']);
        SlugFromName::attach($builder);
        $this->addTextarea($builder, 'description', ['required' => false]);
        // 1.15 (sandbox mode): scripts of this level, only with workspace.edit_scripts (option `scripts`).
        if ($options['scripts']) {
            $this->addTextarea($builder, 'preRequestScript', ['required' => false, 'attr' => ['rows' => 6, 'class' => 'as-code-input']]);
            $this->addTextarea($builder, 'postRequestScript', ['required' => false, 'attr' => ['rows' => 6, 'class' => 'as-code-input']]);
        }
        $this->addCheckbox($builder, 'enabled', ['required' => false]);
        // 1.20: the row version this edit form is rendered from (hidden, unmapped; none on create forms).
        RowVersionField::add($builder, $options);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ApiWorkspace::class, 'scripts' => false]);
        $resolver->setAllowedTypes('scripts', 'bool');
        RowVersionField::configure($resolver);
    }
}
