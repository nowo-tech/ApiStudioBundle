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
        $this->addCheckbox($builder, 'enabled', ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ApiWorkspace::class]);
    }
}
