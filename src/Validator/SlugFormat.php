<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Validator;

use Attribute;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * Slug format (1.11): lowercase letters and digits, single `-` or `_` between them, at most 128 characters. Checked
 * only when the slug is new or changed, so rows saved before 1.11 with another shape stay editable until their slug
 * is edited.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class SlugFormat extends Constraint
{
    public const INVALID_ERROR = '9b4e2c61-7d3a-4f0b-8e15-3a6c9d2f7e48';
    public const PATTERN       = '/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/';
    public const MAX_LENGTH    = 128;

    protected const ERROR_NAMES = [
        self::INVALID_ERROR => 'INVALID_ERROR',
    ];

    /**
     * @param list<string>|null $groups
     */
    #[HasNamedArguments]
    public function __construct(
        public readonly string $message = 'slug.invalid',
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
