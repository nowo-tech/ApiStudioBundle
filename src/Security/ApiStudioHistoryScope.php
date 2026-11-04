<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

use Symfony\Component\Security\Core\User\UserInterface;
use UnexpectedValueException;

use function hash;
use function method_exists;
use function preg_match;
use function strlen;
use function substr;

/**
 * `nowo_api_studio.history.scope`: `endpoint` (default, 1.10 behaviour) = one history per endpoint, shared; `user` =
 * every row records who executed it ({@see ApiStudioActorResolverInterface}) and the endpoint page lists only the
 * viewer's rows unless `history.view_all` is granted.
 */
final readonly class ApiStudioHistoryScope
{
    public const SCOPE_ENDPOINT = 'endpoint';
    public const SCOPE_USER     = 'user';
    public const SCOPES         = [self::SCOPE_ENDPOINT, self::SCOPE_USER];

    public function __construct(
        private string $scope = self::SCOPE_ENDPOINT,
        private ?ApiStudioActorResolverInterface $actorResolver = null,
    ) {
    }

    public function isPerUser(): bool
    {
        return $this->scope === self::SCOPE_USER;
    }

    /**
     * Actor id of $user (null: anonymous). Default: `u:` + the user identifier when it fits (≤ 62 characters), else
     * `h:` + its SHA-256 (62 hex characters): the namespaces keep a raw identifier from ever equalling a hashed one
     * (ER-I2). A host resolver's id must be 1–64 printable ASCII characters without spaces.
     *
     * @throws UnexpectedValueException when the host resolver returns another shape
     */
    public function actorId(?object $user): ?string
    {
        if ($user === null) {
            return null;
        }

        if ($this->actorResolver instanceof ApiStudioActorResolverInterface) {
            $actor = $this->actorResolver->actorId($user);
            if (preg_match('/^[\x21-\x7E]{1,64}$/', $actor) !== 1) {
                throw new UnexpectedValueException('ApiStudioActorResolverInterface::actorId() must return 1 to 64 printable ASCII characters without spaces.');
            }

            return $actor;
        }

        $identifier = $user instanceof UserInterface ? $user->getUserIdentifier() : (method_exists($user, '__toString') ? (string) $user : '');
        if ($identifier === '') {
            return null;
        }

        return strlen($identifier) <= 62 ? 'u:' . $identifier : 'h:' . substr(hash('sha256', $identifier), 0, 62);
    }
}
