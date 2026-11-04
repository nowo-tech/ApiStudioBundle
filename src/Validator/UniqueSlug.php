<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Validator;

use Attribute;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * The entity's slug is unique within its scope: the instance for a workspace, the workspace for a service or an
 * environment, the service for an endpoint (the database unique indexes). A duplicate is a form error (422), never a
 * database error (500).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class UniqueSlug extends Constraint
{
    public const NOT_UNIQUE_ERROR = '6f3b1d7e-2a4c-4b8e-9d51-0c7a8e2f4b19';

    protected const ERROR_NAMES = [
        self::NOT_UNIQUE_ERROR => 'NOT_UNIQUE_ERROR',
    ];

    /**
     * @param string|null $scope association that scopes the slug (null: the whole table)
     * @param list<string>|null $groups
     */
    #[HasNamedArguments]
    public function __construct(
        public readonly ?string $scope = null,
        public readonly string $message = 'slug.taken',
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
