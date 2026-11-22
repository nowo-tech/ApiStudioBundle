<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Form;

use Nowo\ApiStudioBundle\Doctrine\RowVersionGuard;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The hidden `rowVersion` field of the edit forms (1.20, REQ-RUNTIME-003 rule 4): option `row_version` (int, the row
 * version the form is rendered from; null on create forms, which get no field). Not mapped: the edit pages read it and
 * claim the row at that version ({@see RowVersionGuard}); a submitted form keeps the
 * submitted value when it is shown again (422).
 */
final class RowVersionField
{
    public const NAME = 'rowVersion';

    public const OPTION = 'row_version';

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    public static function add(FormBuilderInterface $builder, array $options): void
    {
        $version = $options[self::OPTION] ?? null;
        if ($version === null) {
            return;
        }

        $builder->add(self::NAME, HiddenType::class, ['mapped' => false, 'data' => (string) $version]);
    }

    public static function configure(OptionsResolver $resolver): void
    {
        $resolver->setDefault(self::OPTION, null);
        $resolver->setAllowedTypes(self::OPTION, ['null', 'int']);
    }
}
