<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * CSRF-only POST (delete workspace / service / endpoint / environment) — REQ-TWIG-005.
 *
 * Created with an empty name so the token keeps its flat name `_token`. Extra fields are allowed because
 * the service delete modal also posts `redirect` and `confirmation`.
 *
 * FormKit type (profile `api_studio`, REQ-TWIG-005 rule 11).
 *
 * @extends AbstractType<null>
 */
#[FormKitConfig('api_studio')]
final class CsrfOnlyType extends AbstractType
{
    use FormOptionsTrait;

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'POST',
            'csrf_protection'    => true,
            'csrf_field_name'    => '_token',
            'allow_extra_fields' => true,
        ]);
        $resolver->setRequired('csrf_token_id');
        $resolver->setAllowedTypes('csrf_token_id', 'string');
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
