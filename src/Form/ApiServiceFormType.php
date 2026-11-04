<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Form;

use Nowo\ApiStudioBundle\ApiStudioBundle;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\AuthType;
use Nowo\ApiStudioBundle\Form\EventListener\SlugFromName;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function strtoupper;

/** @extends AbstractType<ApiService> */
#[FormKitConfig('api_studio')]
final class ApiServiceFormType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addText($builder, 'name', []);
        // Empty: derived from the name (SlugFromName).
        $this->addText($builder, 'slug', ['required' => false, 'empty_data' => '', 'help' => 'field.slug_help']);
        SlugFromName::attach($builder);
        $this->addTextarea($builder, 'description', ['required' => false]);
        $this->addText($builder, 'baseUrl', [
            // A translation key + parameter (not a TranslatableInterface): host form themes test attr values as strings.
            'attr'                        => ['placeholder' => 'api_service_form.base_url.placeholder'],
            'attr_translation_parameters' => ['%example%' => '{{base_url}}'],
            'help'                        => 'field.base_url_help',
        ]);
        $this->addWithDefaults($builder, 'defaultHeaders', JsonMapType::class, ['required' => false, 'label' => 'field.default_headers']);
        $this->addWithDefaults($builder, 'protocol', EnumType::class, [
            'class'                     => ApiProtocol::class,
            'choice_label'              => static fn (ApiProtocol $protocol): string => strtoupper($protocol->value),
            'choice_translation_domain' => false,
        ]);
        $this->addWithDefaults($builder, 'authType', EnumType::class, [
            'class'                     => AuthType::class,
            'choice_label'              => static fn (AuthType $type): string => $type->value,
            'choice_translation_domain' => false,
        ]);
        // Scripts run in other users' browsers: only with workspace.edit_scripts (option `scripts`, default true).
        if ($options['scripts']) {
            $this->addTextarea($builder, 'preRequestScript', ['required' => false, 'attr' => ['rows' => 6]]);
            $this->addTextarea($builder, 'postRequestScript', ['required' => false, 'attr' => ['rows' => 6]]);
        }
        $this->addCheckbox($builder, 'enabled', ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'scripts'            => true,
            'data_class'         => ApiService::class,
            'translation_domain' => ApiStudioBundle::TRANSLATION_DOMAIN,
        ]);
    }
}
