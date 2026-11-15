<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service;

use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Model\HostSecretResolution;
use Nowo\ApiStudioBundle\Secret\ApiStudioSecretRef;
use Nowo\ApiStudioBundle\Secret\ApiStudioSecretSourceInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function in_array;
use function strlen;

/**
 * Resolves the host secret references (`{{secret:<source>/<id>}}`) stored in secret environment variables through
 * the host's {@see ApiStudioSecretSourceInterface} (`nowo_api_studio.secrets.source`, 1.13), per execution.
 *
 * Stateless (FrankenPHP worker mode, kernel kept alive): nothing is memoised between calls. Without a configured
 * source it returns the variables untouched, so the request is the 1.12 one.
 *
 * @internal
 */
final class HostSecretResolver
{
    /**
     * Shorter values are refused (QE-B-L5): masking short values mangles unrelated text and turns an echoing target
     * into a guessing oracle.
     */
    public const MIN_LENGTH = 8;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ?ApiStudioSecretSourceInterface $source = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function isEnabled(): bool
    {
        return $this->source instanceof ApiStudioSecretSourceInterface;
    }

    /**
     * Keys of the secret variables whose stored value is a host secret reference (the ones {@see self::resolve()}
     * resolves), whether or not it is well formed. Empty without a source.
     *
     * @param list<string> $overriddenKeys keys the console sent a value for
     *
     * @return list<string>
     */
    public function referenceKeys(ApiEnvironment $environment, array $overriddenKeys): array
    {
        if (!$this->source instanceof ApiStudioSecretSourceInterface) {
            return [];
        }

        $keys = [];
        foreach ($environment->getVariables() as $variable) {
            $key = $variable->getVariableKey();
            if ($variable->isSecret() && !$variable->isUnreadable() && !in_array($key, $overriddenKeys, true) && ApiStudioSecretRef::parse($variable->getValue()) !== null) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Only the stored values of secret variables are resolved: a key the console overrides keeps the override (a
     * reference typed in the console is never resolved).
     *
     * @param array<string, string> $variables the variable map of the execution
     * @param list<string> $overriddenKeys keys the console sent a value for
     * @param string|null $targetOrigin origin of the request before host secrets are substituted (QE-B-M1)
     */
    public function resolve(ApiEnvironment $environment, ApiWorkspace $workspace, array $variables, array $overriddenKeys, ?string $actorId, ?string $targetOrigin = null): HostSecretResolution
    {
        if (!$this->source instanceof ApiStudioSecretSourceInterface) {
            return new HostSecretResolution($variables);
        }

        $values     = [];
        $unresolved = [];
        $tenant     = $workspace->getTenant();
        foreach ($environment->getVariables() as $variable) {
            $key = $variable->getVariableKey();
            if (!$variable->isSecret() || $variable->isUnreadable() || in_array($key, $overriddenKeys, true)) {
                continue;
            }

            $reference = ApiStudioSecretRef::parse($variable->getValue());
            if ($reference === null) {
                continue;
            }

            $value = null;
            $error = null;
            try {
                $ref   = new ApiStudioSecretRef($reference[0], $reference[1], $key, (int) $workspace->getId(), $environment->getId(), $tenant->type, $tenant->id, $actorId, $targetOrigin);
                $value = $this->source->resolve($ref)?->reveal();
            } catch (Throwable $e) {
                // The message may carry the secret or the store's internals: only the class is kept.
                $error = $e::class;
            }

            if ($value === null || strlen($value) < self::MIN_LENGTH) {
                unset($variables[$key]);
                $unresolved[] = $key;
                $this->logger->warning('Api Studio host secret not resolved', [
                    'bundle'       => 'api-studio',
                    'action'       => 'execute_secret_unresolved',
                    'variable'     => $key,
                    'source'       => $reference[0],
                    'workspace_id' => $workspace->getId(),
                    'reason'       => $error ?? ($value === null ? 'not_found_or_denied' : 'too_short'),
                ]);

                continue;
            }

            $variables[$key] = $value;
            $values[]        = $value;
        }

        return new HostSecretResolution($variables, $values, $unresolved);
    }
}
