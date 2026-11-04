<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Form;

use Nowo\ApiStudioBundle\ApiStudioBundle;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Form\EventListener\SlugFromName;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ApiEnvironment> */
#[FormKitConfig('api_studio')]
final class ApiEnvironmentFormType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addText($builder, 'name', []);
        // Empty: derived from the name (SlugFromName).
        $this->addText($builder, 'slug', ['required' => false, 'empty_data' => '', 'help' => 'field.slug_help']);
        SlugFromName::attach($builder);
        $this->addCheckbox($builder, 'isDefault', ['required' => false]);
        $this->addWithDefaults($builder, 'variables', CollectionType::class, [
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
            'translation_domain' => ApiStudioBundle::TRANSLATION_DOMAIN,
        ]);
    }
}
