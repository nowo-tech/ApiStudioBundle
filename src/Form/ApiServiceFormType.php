<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Form;

use Nowo\ApiStudioBundle\ApiStudioBundle;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\AuthType;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Translation\StaticMessage;

use function strtoupper;

/** @extends AbstractType<ApiService> */
#[FormKitConfig('api_studio')]
final class ApiServiceFormType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addText($builder, 'name', []);
        $this->addText($builder, 'slug', []);
        $this->addTextarea($builder, 'description', ['required' => false]);
        $this->addText($builder, 'baseUrl', [
            'attr' => ['placeholder' => new StaticMessage('{{base_url}}')],
            'help' => 'field.base_url_help',
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
        $this->addTextarea($builder, 'preRequestScript', ['required' => false, 'attr' => ['rows' => 6]]);
        $this->addTextarea($builder, 'postRequestScript', ['required' => false, 'attr' => ['rows' => 6]]);
        $this->addCheckbox($builder, 'enabled', ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => ApiService::class,
            'translation_domain' => ApiStudioBundle::TRANSLATION_DOMAIN,
        ]);
    }
}
