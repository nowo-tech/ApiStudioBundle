<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use UnexpectedValueException;

use function is_array;
use function is_scalar;
use function is_string;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * JSON object editor for string maps (headers, query params).
 *
 * @extends AbstractType<mixed>
 */
final class JsonMapType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            static function (?array $value): string {
                if ($value === null || $value === []) {
                    return '{}';
                }

                return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            },
            static function (?string $value): array {
                if ($value === null || trim($value) === '') {
                    return [];
                }

                $decoded = json_decode($value, true);
                if (!is_array($decoded)) {
                    throw new UnexpectedValueException('Invalid JSON object.');
                }

                $map = [];
                foreach ($decoded as $key => $item) {
                    if (!is_string($key) || $key === '') {
                        continue;
                    }
                    $map[$key] = is_scalar($item) ? (string) $item : json_encode($item);
                }

                return $map;
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'attr' => [
                'rows'        => 6,
                'class'       => 'font-monospace',
                'placeholder' => '{"Authorization": "Bearer {{token}}"}',
            ],
        ]);
    }

    public function getParent(): string
    {
        return TextareaType::class;
    }
}
