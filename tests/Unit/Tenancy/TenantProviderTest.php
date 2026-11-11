<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Tenancy;

use Nowo\ApiStudioBundle\DependencyInjection\ApiStudioExtension;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantListener;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantProviderInterface;
use Nowo\ApiStudioBundle\Tenancy\TenantProviderResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;

/**
 * `tenancy.provider` (1.12, rule 3): a request-free tenant port, adapted to the resolver the listener calls.
 */
final class TenantProviderTest extends TestCase
{
    public function testAdapterAsksTheProviderAndIgnoresTheRequest(): void
    {
        $tenant   = new ApiStudioTenant('organization', 'acme');
        $provider = new class($tenant) implements ApiStudioTenantProviderInterface {
            public function __construct(private readonly ?ApiStudioTenant $tenant)
            {
            }

            public function current(): ?ApiStudioTenant
            {
                return $this->tenant;
            }
        };

        self::assertSame($tenant, (new TenantProviderResolver($provider))->resolve(new Request()));
    }

    public function testProviderConfigurationWiresTheAdapterIntoTheListener(): void
    {
        $container = new ContainerBuilder();
        (new ApiStudioExtension())->load([['tenancy' => ['enabled' => true, 'provider' => 'app.tenant_provider']]], $container);

        $adapter = $container->getDefinition('nowo_api_studio.tenancy.provider_resolver');
        self::assertSame(TenantProviderResolver::class, $adapter->getClass());
        self::assertEquals(new Reference('app.tenant_provider'), $adapter->getArgument('$provider'));
        self::assertEquals(new Reference('nowo_api_studio.tenancy.provider_resolver'), $container->getDefinition(ApiStudioTenantListener::class)->getArgument('$resolver'));
    }

    public function testResolverConfigurationIsUnchanged(): void
    {
        $container = new ContainerBuilder();
        (new ApiStudioExtension())->load([['tenancy' => ['enabled' => true, 'resolver' => 'app.tenant_resolver']]], $container);

        self::assertFalse($container->hasDefinition('nowo_api_studio.tenancy.provider_resolver'));
        self::assertEquals(new Reference('app.tenant_resolver'), $container->getDefinition(ApiStudioTenantListener::class)->getArgument('$resolver'));
    }

    public function testDisabledTenancyIgnoresTheProvider(): void
    {
        $container = new ContainerBuilder();
        (new ApiStudioExtension())->load([['tenancy' => ['provider' => 'app.tenant_provider']]], $container);

        self::assertFalse($container->hasDefinition('nowo_api_studio.tenancy.provider_resolver'));
        self::assertNull($container->getDefinition(ApiStudioTenantListener::class)->getArgument('$resolver'));
        self::assertNull($container->getParameter(ApiStudioExtension::ENTITY_MANAGER_PARAMETER));
    }
}
