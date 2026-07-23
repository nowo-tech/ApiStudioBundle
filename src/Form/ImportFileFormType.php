<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class ImportFileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $extensions = $options['allowed_extensions'];
        $mimeTypes  = match ($options['import_kind']) {
            'variables' => ['application/json', 'text/plain', 'text/yaml', 'application/x-yaml'],
            default     => ['application/json', 'text/plain', 'text/yaml', 'application/x-yaml'],
        };

        $builder
            ->add('file', FileType::class, [
                'label'              => 'import.file',
                'translation_domain' => 'nowo_api_studio',
                'constraints'        => [
                    new NotBlank(),
                    new File(maxSize: '8M', mimeTypes: $mimeTypes, extensions: $extensions),
                ],
            ]);

        if ($options['show_mode']) {
            $builder->add('mode', ChoiceType::class, [
                'label'              => 'import.mode',
                'translation_domain' => 'nowo_api_studio',
                'choices'            => [
                    'import.mode.merge'   => 'merge',
                    'import.mode.replace' => 'replace',
                ],
                'data' => 'merge',
            ]);
        }

        if ($options['show_postman_variables']) {
            $builder->add('importVariables', CheckboxType::class, [
                'label'              => 'import.postman_variables',
                'translation_domain' => 'nowo_api_studio',
                'required'           => false,
                'data'               => true,
            ]);
        }

        $builder->add('submit', SubmitType::class, [
            'label'              => 'import.submit',
            'translation_domain' => 'nowo_api_studio',
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
