<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Form;

use Nowo\ApiStudioBundle\ApiStudioBundle;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Service\LocaleManager;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<ApiEndpoint> */
#[FormKitConfig('api_studio')]
final class ApiEndpointFormType extends AbstractType
{
    use FormOptionsTrait;

    public function __construct(private readonly ?LocaleManager $localeManager = null)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addText($builder, 'name', []);
        $this->addText($builder, 'slug', []);
        $this->addWithDefaults($builder, 'method', EnumType::class, [
            'class'                     => HttpMethod::class,
            'choice_label'              => static fn (HttpMethod $method): string => $method->value,
            'choice_translation_domain' => false,
        ]);
        $this->addText($builder, 'path', []);
        $this->addText($builder, 'soapAction', ['required' => false]);
        $this->addText($builder, 'contentType', []);
        $this->addWithDefaults($builder, 'headers', JsonMapType::class, ['required' => false, 'label' => 'field.headers']);
        $this->addWithDefaults($builder, 'queryParams', JsonMapType::class, ['required' => false, 'label' => 'field.query_params']);
        $this->addTextarea($builder, 'requestBodyTemplate', ['required' => false]);
        $this->addTextarea($builder, 'preRequestScript', ['required' => false, 'attr' => ['rows' => 8, 'class' => 'as-code-input']]);
        $this->addTextarea($builder, 'postRequestScript', ['required' => false, 'attr' => ['rows' => 8, 'class' => 'as-code-input']]);
        $this->addInteger($builder, 'sortOrder', []);
        $this->addCheckbox($builder, 'enabled', ['required' => false]);
        $this->addWithDefaults($builder, 'translations', CollectionType::class, [
            'entry_type'    => ApiEndpointTranslationFormType::class,
            'entry_options' => ['label' => false, 'error_bubbling' => false],
            'allow_add'     => false,
            'allow_delete'  => false,
            'by_reference'  => false,
            'locale_tabs'   => $this->localeTabs(),
        ]);
    }

    /**
     * FormKit locale tabs (mode entries): one tab per enabled UI locale, the default one first.
     *
     * @return array<string, mixed>
     */
    private function localeTabs(): array
    {
        if (!$this->localeManager instanceof LocaleManager) {
            return ['mode' => 'entries'];
        }

        return [
            'mode'           => 'entries',
            'locales'        => $this->localeManager->getEnabledLocales(),
            'default_locale' => $this->localeManager->getDefaultLocale(),
        ];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => ApiEndpoint::class,
            'translation_domain' => ApiStudioBundle::TRANSLATION_DOMAIN,
        ]);
    }
}
