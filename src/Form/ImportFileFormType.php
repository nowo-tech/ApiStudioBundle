<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Form;

use Nowo\ApiStudioBundle\ApiStudioBundle;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array<string, mixed>>
 */
#[FormKitConfig('api_studio')]
final class ImportFileFormType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $extensions = $options['allowed_extensions'];
        $mimeTypes  = match ($options['import_kind']) {
            'variables' => ['application/json', 'text/plain', 'text/yaml', 'application/x-yaml'],
            default     => ['application/json', 'text/plain', 'text/yaml', 'application/x-yaml'],
        };

        $this->addWithDefaults($builder, 'file', FileType::class, [
            'label'              => 'import.file',
            'translation_domain' => ApiStudioBundle::TRANSLATION_DOMAIN,
            'constraints'        => [
                new NotBlank(),
                new File(maxSize: '8M', mimeTypes: $mimeTypes, extensions: $extensions),
            ],
        ]);

        if ($options['show_mode']) {
            $this->addChoice($builder, 'mode', [
                'label'              => 'import.mode',
                'translation_domain' => ApiStudioBundle::TRANSLATION_DOMAIN,
                'choices'            => [
                    'import.mode.merge'   => 'merge',
                    'import.mode.replace' => 'replace',
                ],
                'data' => 'merge',
            ]);
        }

        if ($options['show_postman_variables']) {
            $this->addCheckbox($builder, 'importVariables', [
                'label'              => 'import.postman_variables',
                'translation_domain' => ApiStudioBundle::TRANSLATION_DOMAIN,
                'required'           => false,
                'data'               => true,
            ]);
        }

        $this->addWithDefaults($builder, 'submit', SubmitType::class, [
            'label'              => 'import.submit',
            'translation_domain' => ApiStudioBundle::TRANSLATION_DOMAIN,
            'attr'               => ['class' => 'as-btn as-btn-primary'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'import_kind'            => 'openapi',
            'allowed_extensions'     => ['json', 'yaml', 'yml'],
            'show_mode'              => false,
            'show_postman_variables' => false,
        ]);
    }
}
