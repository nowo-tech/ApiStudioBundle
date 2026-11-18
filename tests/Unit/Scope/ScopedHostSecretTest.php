<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Scope;

use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Scope\WorkspaceScope;
use Nowo\ApiStudioBundle\Secret\ApiStudioSecretRef;
use Nowo\ApiStudioBundle\Secret\ApiStudioSecretSourceInterface;
use Nowo\ApiStudioBundle\Secret\ApiStudioSecretValue;
use Nowo\ApiStudioBundle\Service\HostSecretResolver;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * 1.17 (audit UA-M2): the host secret source gets the workspace's scope with every reference, so it can authorise
 * `source/id` against the tenant and the scope (a reference copied into another project must not resolve there).
 */
final class ScopedHostSecretTest extends TestCase
{
    public function testTheReferenceCarriesTheTenantAndTheScope(): void
    {
        $source = new class implements ApiStudioSecretSourceInterface {
            /** @var list<ApiStudioSecretRef> */
            public array $refs = [];

            public function resolve(ApiStudioSecretRef $ref): ?ApiStudioSecretValue
            {
                $this->refs[] = $ref;

                // Granted only in the project the secret belongs to.
                return $ref->scope === 'project:alpha' ? new ApiStudioSecretValue('value-REDACTED-TEST') : null;
            }
        };
        $resolver = new HostSecretResolver(source: $source);

        [$alpha, $alphaEnv] = $this->workspace('project:alpha', 1);
        [$beta, $betaEnv]   = $this->workspace('project:beta', 2);
        [$org, $orgEnv]     = $this->workspace(null, 3);

        self::assertSame([], $resolver->resolve($alphaEnv, $alpha, ['api_key' => ''], [], null)->unresolvedKeys);
        self::assertSame(['api_key'], $resolver->resolve($betaEnv, $beta, ['api_key' => ''], [], null)->unresolvedKeys);
        self::assertSame(['api_key'], $resolver->resolve($orgEnv, $org, ['api_key' => ''], [], null)->unresolvedKeys);

        self::assertSame(['project:alpha', 'project:beta', null], array_map(static fn (ApiStudioSecretRef $ref): ?string => $ref->scope, $source->refs));
        self::assertSame(['acme', 'acme', 'acme'], array_map(static fn (ApiStudioSecretRef $ref): string => $ref->tenantId, $source->refs));
    }

    public function testTheScopeDefaultsToNullForSourcesBuiltBefore117(): void
    {
        self::assertNull((new ApiStudioSecretRef('vault', 'x', 'k', 1, null, '', '', null, null))->scope);
    }

    /**
     * @return array{ApiWorkspace, ApiEnvironment}
     */
    private function workspace(?string $scope, int $id): array
    {
        $workspace = (new ApiWorkspace('W', 'w'))->assignTenant(new ApiStudioTenant('org', 'acme'))->assignScope($scope !== null ? new WorkspaceScope($scope) : null);
        (new ReflectionProperty(ApiWorkspace::class, 'id'))->setValue($workspace, $id);
        $environment = new ApiEnvironment('Prod', 'prod');
        $environment->addVariable((new ApiEnvironmentVariable('api_key', '{{secret:vault/core/api-key}}'))->setSecret(true));
        $workspace->addEnvironment($environment);

        return [$workspace, $environment];
    }
}
