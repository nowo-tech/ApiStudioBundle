<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration\Repository;

use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEndpointTranslation;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiRequestExample;
use Nowo\ApiStudioBundle\Entity\ApiRequestHistory;
use Nowo\ApiStudioBundle\Entity\ApiResponseExample;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Repository\ApiEndpointRepository;
use Nowo\ApiStudioBundle\Repository\ApiEndpointTranslationRepository;
use Nowo\ApiStudioBundle\Repository\ApiEnvironmentRepository;
use Nowo\ApiStudioBundle\Repository\ApiEnvironmentVariableRepository;
use Nowo\ApiStudioBundle\Repository\ApiRequestExampleRepository;
use Nowo\ApiStudioBundle\Repository\ApiRequestHistoryRepository;
use Nowo\ApiStudioBundle\Repository\ApiResponseExampleRepository;
use Nowo\ApiStudioBundle\Repository\ApiServiceRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Tests\Support\InMemoryOrmTrait;
use PHPUnit\Framework\TestCase;

/**
 * The repositories are bound to their entity and the navigation query returns one flat row per leaf.
 */
final class RepositoriesTest extends TestCase
{
    use InMemoryOrmTrait;

    public function testEveryRepositoryFindsItsEntity(): void
    {
        $em       = $this->createEntityManager();
        $registry = $this->createRegistry($em);

        $workspace = new ApiWorkspace('Shop', 'shop');
        $service   = new ApiService('Orders', 'orders');
        $endpoint  = new ApiEndpoint('List', 'list');
        $env       = new ApiEnvironment('Dev', 'dev');
        $workspace->addService($service)->addEnvironment($env);
        $service->addEndpoint($endpoint);
        $endpoint->addTranslation((new ApiEndpointTranslation('en'))->setTitle('List orders'));
        $endpoint->addRequestExample(new ApiRequestExample('Default'));
        $endpoint->addResponseExample(new ApiResponseExample('OK'));
        $env->addVariable(new ApiEnvironmentVariable('base_url', 'https://example.test'));
        $history = (new ApiRequestHistory())->setEndpoint($endpoint)->setRequestUrl('https://example.test/orders')->setRequestMethod('GET');
        $em->persist($workspace);
        $em->persist($history);
        $em->flush();
        $em->clear();

        $repositories = [
            ApiWorkspaceRepository::class           => ApiWorkspace::class,
            ApiServiceRepository::class             => ApiService::class,
            ApiEndpointRepository::class            => ApiEndpoint::class,
            ApiEndpointTranslationRepository::class => ApiEndpointTranslation::class,
            ApiEnvironmentRepository::class         => ApiEnvironment::class,
            ApiEnvironmentVariableRepository::class => ApiEnvironmentVariable::class,
            ApiRequestExampleRepository::class      => ApiRequestExample::class,
            ApiResponseExampleRepository::class     => ApiResponseExample::class,
            ApiRequestHistoryRepository::class      => ApiRequestHistory::class,
        ];
        foreach ($repositories as $repositoryClass => $entityClass) {
            $repository = new $repositoryClass($registry);
            self::assertSame($entityClass, $repository->getClassName());
            self::assertSame(1, $repository->count([]), $repositoryClass);
            self::assertInstanceOf($entityClass, $repository->findOneBy([]));
        }
    }

    public function testNavigationRowsListWorkspacesServicesEndpointsAndExamplesInOrder(): void
    {
        $em = $this->createEntityManager();

        $empty = new ApiWorkspace('Alpha', 'alpha');
        $shop  = new ApiWorkspace('Shop', 'shop');
        $soap  = (new ApiService('Billing', 'billing'))->setProtocol(ApiProtocol::Soap);
        $rest  = new ApiService('Orders', 'orders');
        $shop->addService($rest)->addService($soap);
        $second = (new ApiEndpoint('Create', 'create'))->setMethod(HttpMethod::Post)->setSortOrder(2);
        $first  = (new ApiEndpoint('List', 'list'))->setSortOrder(1);
        $rest->addEndpoint($second)->addEndpoint($first);
        $first->addRequestExample((new ApiRequestExample('Second'))->setSortOrder(2));
        $first->addRequestExample((new ApiRequestExample('First'))->setSortOrder(1));
        $em->persist($empty);
        $em->persist($shop);
        $em->flush();

        $rows = (new ApiWorkspaceRepository($this->createRegistry($em)))->navigationRows();

        $summary = array_map(static fn (array $row): string => implode('|', [$row['wname'], $row['sname'] ?? '-', $row['ename'] ?? '-', $row['xname'] ?? '-']), $rows);
        self::assertSame([
            'Alpha|-|-|-',
            'Shop|Billing|-|-',
            'Shop|Orders|List|First',
            'Shop|Orders|List|Second',
            'Shop|Orders|Create|-',
        ], $summary);
        self::assertSame('shop', $rows[1]['wslug']);
    }
}
