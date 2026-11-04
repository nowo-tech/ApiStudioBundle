<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tenancy;

use InvalidArgumentException;

use function preg_match;
use function sprintf;

/**
 * Opaque owner of a workspace: a `(type, id)` pair chosen by the host (e.g. `('organization', <public uuid>)`).
 * `('', '')` is the instance tenant: every workspace of a host without tenancy, and workspaces created outside any
 * tenant (CLI, seeds) when tenancy is on.
 */
final readonly class ApiStudioTenant
{
    private const TYPE_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';
    private const ID_PATTERN   = '/^[A-Za-z0-9._:-]{1,64}$/';

    public function __construct(
        public string $type,
        public string $id,
    ) {
        if ($type === '' && $id === '') {
            return;
        }

        if (preg_match(self::TYPE_PATTERN, $type) !== 1 || preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid Api Studio tenant ("%s", "%s"): the type must match %s and the id %s, or both must be empty (instance tenant).', $type, $id, self::TYPE_PATTERN, self::ID_PATTERN));
        }
    }

    public static function instance(): self
    {
        return new self('', '');
    }

    public function isInstance(): bool
    {
        return $this->type === '' && $this->id === '';
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }

    /**
     * `''` for the instance tenant, else `type:id` (cache keys, logs).
     */
    public function key(): string
    {
        return $this->isInstance() ? '' : $this->type . ':' . $this->id;
    }
}
