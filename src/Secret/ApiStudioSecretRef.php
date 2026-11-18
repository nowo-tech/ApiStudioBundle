<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Secret;

use InvalidArgumentException;

use function preg_match;
use function sprintf;
use function strlen;
use function trim;

/**
 * A host secret reference to resolve (1.13, {@see ApiStudioSecretSourceInterface}), with the context the host
 * authorises it in. Ids only: no bundle entity, no Symfony user.
 *
 * Stored as the value of a secret environment variable: `{{secret:<source>/<id>}}` (spaces inside the braces allowed),
 * `<source>` = `[a-z][a-z0-9_-]{0,31}` (e.g. `vault`), `<id>` = 1–255 characters of `[A-Za-z0-9._~:@/-]` without a
 * leading `/`, an empty segment (`//`, trailing `/`) or a `.` / `..` segment (QE-B-L6: no traversal into the store's
 * layout). Authorise on the exact id.
 */
final readonly class ApiStudioSecretRef
{
    public const SOURCE_PATTERN = '/^[a-z][a-z0-9_-]{0,31}$/D';

    public const ID_PATTERN = '/^(?!\/)(?!.*\/\/)(?!.*\/$)(?!(?:.*\/)?\.\.?(?:\/|$))[A-Za-z0-9._~:@\/-]{1,255}$/D';

    private const REFERENCE_PATTERN = '/^\{\{\s*secret:([a-z][a-z0-9_-]{0,31})\/([A-Za-z0-9._~:@\/-]{1,255})\s*\}\}$/';

    /**
     * @param string $source host secret store named in the reference (e.g. `vault`)
     * @param string $id secret id within that store
     * @param string $variableKey key of the environment variable that holds the reference
     * @param int $workspaceId workspace of the executed endpoint
     * @param int|null $environmentId environment the variable belongs to
     * @param string $tenantType tenant of the workspace (`''` = instance tenant, tenancy off)
     * @param string $tenantId tenant of the workspace (`''` = instance tenant, tenancy off)
     * @param string|null $actorId opaque id of the user running the request (the id history rows and events carry:
     *                             `history.actor_resolver`, else the user identifier); null = anonymous
     * @param string|null $targetOrigin origin (`scheme://host[:port]`, lower case, default port omitted) the request
     *                                  goes to, computed from the service base URL before any host secret is
     *                                  substituted (QE-B-M1); null when the base URL itself uses a host secret. Bind
     *                                  each secret to the origins it may be sent to: an editor can retarget a service
     *                                  and wait for someone allowed to run it. The bundle refuses to send if the final
     *                                  origin differs from this one.
     * @param string|null $scope 1.17: host scope of the workspace (`scope.enabled`, e.g. `project:<uuid>`); null at
     *                           tenant level or with scopes off. A source must authorise `source/id` against the
     *                           tenant **and** the scope: a reference copied into another project's workspace must
     *                           not resolve there (audit UA-M2).
     */
    public function __construct(
        public string $source,
        public string $id,
        public string $variableKey,
        public int $workspaceId,
        public ?int $environmentId,
        public string $tenantType,
        public string $tenantId,
        public ?string $actorId,
        public ?string $targetOrigin,
        public ?string $scope = null,
    ) {
        if (preg_match(self::SOURCE_PATTERN, $source) !== 1 || preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid Api Studio secret reference: the source must match %s and the id %s.', self::SOURCE_PATTERN, self::ID_PATTERN));
        }
    }

    /**
     * `[source, id]` when the whole value is a host secret reference, else null.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parse(string $value): ?array
    {
        $value = trim($value);
        if (strlen($value) > 300 || preg_match(self::REFERENCE_PATTERN, $value, $matches) !== 1) {
            return null;
        }

        return [$matches[1], $matches[2]];
    }
}
