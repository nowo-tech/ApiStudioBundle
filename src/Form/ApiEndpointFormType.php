<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Form;

use Nowo\ApiStudioBundle\ApiStudioBundle;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEndpointTranslation;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ApiEndpoint> */
#[FormKitConfig('api_studio')]
final class ApiEndpointFormType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addText($builder, 'name', []);
        $this->addText($builder, 'slug', []);
        $this->addWithDefaults($builder, 'method', EnumType::class, ['class' => HttpMethod::class]);
        $this->addText($builder, 'path', []);
        $this->addText($builder, 'soapAction', ['required' => false]);
        $this->addText($builder, 'contentType', []);
        $this->addWithDefaults($builder, 'headers', JsonMapType::class, ['required' => false, 'label' => 'field.headers']);
        $this->addWithDefaults($builder, 'queryParams', JsonMapType::class, ['required' => false, 'label' => 'field.query_params']);
        $this->addTextarea($builder, 'requestBodyTemplate', ['required' => false]);
        $this->addTextarea($builder, 'preRequestScript', ['required' => false, 'attr' => ['rows' => 8, 'class' => 'font-monospace']]);
        $this->addTextarea($builder, 'postRequestScript', ['required' => false, 'attr' => ['rows' => 8, 'class' => 'font-monospace']]);
        $this->addInteger($builder, 'sortOrder', []);
        $this->addCheckbox($builder, 'enabled', ['required' => false]);
        $this->addWithDefaults($builder, 'translations', CollectionType::class, [
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
            'translation_domain' => ApiStudioBundle::TRANSLATION_DOMAIN,
        ]);
    }
}

/** @extends AbstractType<ApiEndpointTranslation> */
#[FormKitConfig('api_studio')]
final class ApiEndpointTranslationFormType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addWithDefaults($builder, 'locale', HiddenType::class, []);
        $this->addText($builder, 'title', ['required' => false]);
        $this->addTextarea($builder, 'description', ['required' => false]);
        $this->addTextarea($builder, 'notes', ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ApiEndpointTranslation::class]);
    }
}
