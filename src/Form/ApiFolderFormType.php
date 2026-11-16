<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Form;

use Nowo\ApiStudioBundle\ApiStudioBundle;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Enum\AuthType;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Folder of a service tree (1.14): name, description, parent (option `parents`: the folders it may move under, the
 * folder itself and its descendants already left out), order, and the auth / headers its endpoints inherit.
 *
 * @extends AbstractType<ApiFolder>
 */
#[FormKitConfig('api_studio')]
final class ApiFolderFormType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addText($builder, 'name', ['attr' => ['maxlength' => 128]]);
        $this->addTextarea($builder, 'description', ['required' => false, 'attr' => ['rows' => 3]]);
        /** @var list<ApiFolder> $parents */
        $parents = $options['parents'];
        $this->addChoice($builder, 'parent', [
            'label'                     => 'field.folder_parent',
            'required'                  => false,
            'placeholder'               => 'folder.root',
            'choices'                   => $parents,
            'choice_value'              => static fn (?ApiFolder $folder): string => $folder?->getId() !== null ? (string) $folder->getId() : '',
            'choice_label'              => static fn (ApiFolder $folder): string => self::pathLabel($folder),
            'choice_translation_domain' => false,
        ]);
        $this->addInteger($builder, 'sortOrder', []);
        $this->addWithDefaults($builder, 'authType', EnumType::class, [
            'label'                     => 'field.folder_auth_type',
            'class'                     => AuthType::class,
            'required'                  => false,
            'placeholder'               => 'folder.auth_inherit',
            'choice_label'              => static fn (AuthType $type): string => $type->value,
            'choice_translation_domain' => false,
            'help'                      => 'field.folder_auth_type_help',
        ]);
        $this->addWithDefaults($builder, 'authConfig', JsonMapType::class, ['required' => false, 'label' => 'field.folder_auth_config', 'help' => 'field.folder_auth_config_help']);
        // 1.15 (sandbox mode): scripts of this level, only with workspace.edit_scripts (option `scripts`).
        if ($options['scripts']) {
            $this->addTextarea($builder, 'preRequestScript', ['required' => false, 'attr' => ['rows' => 6, 'class' => 'as-code-input']]);
            $this->addTextarea($builder, 'postRequestScript', ['required' => false, 'attr' => ['rows' => 6, 'class' => 'as-code-input']]);
        }
        $this->addWithDefaults($builder, 'headers', JsonMapType::class, ['required' => false, 'label' => 'field.headers', 'help' => 'field.folder_headers_help']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'parents'            => [],
            'scripts'            => false,
            'data_class'         => ApiFolder::class,
            'translation_domain' => ApiStudioBundle::TRANSLATION_DOMAIN,
        ]);
        $resolver->setAllowedTypes('parents', 'array');
        $resolver->setAllowedTypes('scripts', 'bool');
    }

    /**
     * `Users / Admin`: the folder's path in its service.
     */
    public static function pathLabel(ApiFolder $folder): string
    {
        return implode(' / ', array_map(static fn (ApiFolder $node): string => $node->getName(), $folder->getPath()));
    }
}
