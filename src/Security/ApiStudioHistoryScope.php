<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

use Symfony\Component\Security\Core\User\UserInterface;

use function hash;
use function method_exists;
use function strlen;

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
     * Actor id of $user (null: anonymous). Default: the user identifier, hashed (sha256) when longer than 64 characters.
     */
    public function actorId(?object $user): ?string
    {
        if ($user === null) {
            return null;
        }

        if ($this->actorResolver instanceof ApiStudioActorResolverInterface) {
            return $this->actorResolver->actorId($user);
        }

        $identifier = $user instanceof UserInterface ? $user->getUserIdentifier() : (method_exists($user, '__toString') ? (string) $user : '');
        if ($identifier === '') {
            return null;
        }

        return strlen($identifier) > 64 ? hash('sha256', $identifier) : $identifier;
    }
}
