<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration\Tenancy;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\ApiStudioBundle\Doctrine\TenantSqlFilter;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEndpointTranslation;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiRequestExample;
use Nowo\ApiStudioBundle\Entity\ApiRequestHistory;
use Nowo\ApiStudioBundle\Entity\ApiResponseExample;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\ApiStudioPermissionCheckerInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioScope;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenantContext;
use Nowo\ApiStudioBundle\Tenancy\TenantSqlFilterListener;
use Nowo\ApiStudioBundle\Tests\Support\TenantOrmTrait;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Defence in depth: with the filter on, every bundle entity of another tenant (or an invisible workspace) is out of
 * every query; the listener turns it on for Api Studio requests only and off when they finish.
 */
final class TenantSqlFilterTest extends TestCase
{
    use TenantOrmTrait;

    private const CLASSES = [
        ApiWorkspace::class, ApiService::class, ApiEnvironment::class, ApiEndpoint::class, ApiEnvironmentVariable::class,
        ApiRequestExample::class, ApiResponseExample::class, ApiEndpointTranslation::class, ApiRequestHistory::class,
    ];

    public function testEveryEntityIsNarrowedToTheTenantAndTheVisibleWorkspaces(): void
    {
        $em   = $this->createTenantEntityManager();
        $acme = $this->tree($em, 'acme', 'one');
        $this->tree($em, 'acme', 'two');
        $this->tree($em, 'beta', 'one');
        $em->clear();

        foreach (self::CLASSES as $class) {
            self::assertCount(3, self::all($em, $class), $class . ' without the filter');
        }

        $filter = $em->getFilters()->enable(TenantSqlFilter::NAME);
        self::assertInstanceOf(TenantSqlFilter::class, $filter);
        TenantSqlFilter::configure($filter, new ApiStudioScope(new ApiStudioTenant('org', 'acme'), true));
        foreach (self::CLASSES as $class) {
            self::assertCount(2, self::all($em, $class), $class . ' of the tenant');
        }

        TenantSqlFilter::configure($filter, new ApiStudioScope(new ApiStudioTenant('org', 'acme'), true, [$acme]));
        foreach (self::CLASSES as $class) {
            self::assertCount(1, self::all($em, $class), $class . ' of the visible workspace');
        }

        TenantSqlFilter::configure($filter, new ApiStudioScope(new ApiStudioTenant('org', 'acme'), true, []));
        self::assertCount(0, self::all($em, ApiWorkspace::class), 'nothing visible');
    }

    public function testListenerEnablesTheFilterOnStudioRequestsOnly(): void
    {
        $em       = $this->createTenantEntityManager();
        $registry = $this->tenantRegistry($em);
        $stack    = new RequestStack();
        $request  = new Request(attributes: ['_route' => 'nowo_api_studio_dashboard', ApiStudioTenantContext::REQUEST_ATTRIBUTE => new ApiStudioTenant('org', 'acme')]);
        $stack->push($request);
        $authorization = new ApiStudioAuthorization($this->createStub(ApiStudioPermissionCheckerInterface::class), new ApiStudioTenantContext($stack, true), null, $stack);
        $kernel        = $this->createStub(HttpKernelInterface::class);
        $controller    = static fn (): null => null;

        $off = new TenantSqlFilterListener($registry, $authorization, false);
        $off->onKernelController(new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST));
        $off->onKernelFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertFalse($em->getFilters()->isEnabled(TenantSqlFilter::NAME), 'tenancy.sql_filter off');

        $listener = new TenantSqlFilterListener($registry, $authorization, true);
        $listener->onKernelController(new ControllerEvent($kernel, $controller, new Request(attributes: ['_route' => 'app_home']), HttpKernelInterface::MAIN_REQUEST));
        self::assertFalse($em->getFilters()->isEnabled(TenantSqlFilter::NAME), 'host routes are left alone');

        $listener->onKernelController(new ControllerEvent($kernel, $controller, $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertTrue($em->getFilters()->isEnabled(TenantSqlFilter::NAME));

        $listener->onKernelFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST));
        self::assertTrue($em->getFilters()->isEnabled(TenantSqlFilter::NAME), 'a sub-request does not turn it off');
        $listener->onKernelFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertFalse($em->getFilters()->isEnabled(TenantSqlFilter::NAME));
        self::assertSame(-8, TenantSqlFilterListener::getSubscribedEvents()['kernel.controller'][1]);
    }

    public function testFilterIgnoresOtherEntities(): void
    {
        $em     = $this->createTenantEntityManager();
        $filter = $em->getFilters()->enable(TenantSqlFilter::NAME);
        self::assertInstanceOf(TenantSqlFilter::class, $filter);
        TenantSqlFilter::configure($filter, ApiStudioScope::unrestricted());

        self::assertSame('', $filter->addFilterConstraint(new ClassMetadata(stdClass::class), 'x'), 'not a bundle entity');
        $renamed = new ClassMetadata(ApiWorkspace::class);
        $renamed->setPrimaryTable(['name' => 'somewhere_else']);
        self::assertSame('', $filter->addFilterConstraint($renamed, 'x'), 'table not recognised');
    }

    /**
     * @param class-string $class
     *
     * @return list<mixed>
     */
    private static function all(EntityManager $em, string $class): array
    {
        /** @var list<mixed> $rows */
        $rows = $em->createQueryBuilder()->select('x')->from($class, 'x')->getQuery()->getResult();

        return $rows;
    }

    /**
     * A workspace with one row of every child entity; returns the workspace id.
     */
    private function tree(EntityManager $em, string $tenant, string $slug): int
    {
        $workspace = $this->tenantWorkspace($em, $tenant, $slug);
        $service   = (new ApiService('S', 's'))->setBaseUrl('https://x.test');
        $workspace->addService($service);
        $environment = new ApiEnvironment('E', 'e');
        $workspace->addEnvironment($environment);
        $endpoint = (new ApiEndpoint('P', 'p'))->setPath('/p')->setService($service);
        $variable = new ApiEnvironmentVariable('k', 'v');
        $environment->addVariable($variable);
        $request     = (new ApiRequestExample('r'))->setEndpoint($endpoint);
        $response    = (new ApiResponseExample('r'))->setEndpoint($endpoint);
        $translation = (new ApiEndpointTranslation('en'))->setEndpoint($endpoint);
        $history     = (new ApiRequestHistory())->setEndpoint($endpoint)->setRequestUrl('https://x.test')->setRequestMethod('GET');
        foreach ([$service, $environment, $endpoint, $variable, $request, $response, $translation, $history] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return (int) $workspace->getId();
    }
}
