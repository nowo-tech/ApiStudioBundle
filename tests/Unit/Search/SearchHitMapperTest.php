<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Search;

use DateTime;
use DateTimeImmutable;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Search\SearchHit;
use Nowo\ApiStudioBundle\Search\SearchHitMapper;
use Nowo\ApiStudioBundle\Search\SearchKind;
use Nowo\ApiStudioBundle\Security\ApiStudioScope;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tests\Support\SearchLabelsDouble;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function array_map;
use function mb_strlen;
use function str_repeat;

final class SearchHitMapperTest extends TestCase
{
    public function testPathsLoseTheirQueryStringAndAreCut(): void
    {
        self::assertSame('/v1/charges', SearchHitMapper::path('/v1/charges?api_key=x#frag'));
        self::assertSame('/v1', SearchHitMapper::path('/v1#a?b'));
        self::assertSame('', SearchHitMapper::path(null));
        $long = SearchHitMapper::path('/' . str_repeat('é', 300));
        self::assertSame(SearchHitMapper::MAX_PATH_LENGTH, mb_strlen($long));
        self::assertStringEndsWith('…', $long);
    }

    public function testRowsOfEveryShape(): void
    {
        $labels = new SearchLabelsDouble();
        $mapper = new SearchHitMapper($this->router(), $labels);
        $tenant = ApiStudioTenant::instance();

        $rows = [
            ['sid' => '3', 'sname' => 'S', 'sprotocol' => 'soap', 'supdated' => new DateTime('2026-01-02'), 'wid' => 1, 'wname' => 'W', 'wscope' => 'bad value'],
            ['sid' => 4, 'sname' => null, 'sprotocol' => ApiProtocol::Rest, 'supdated' => 'not a date', 'wid' => 'x', 'wname' => 'W', 'wscope' => null],
            ['sid' => 5, 'sname' => 'T', 'sprotocol' => 'unknown', 'wid' => 1, 'wname' => '', 'wscope' => ''],
        ];
        self::assertSame([], $mapper->labels($rows, $tenant));
        $hits = array_map(static fn (array $row): SearchHit => $mapper->hit(SearchKind::Service, $row, null), $rows);
        self::assertSame(0, $labels->calls, 'no valid scope to label');
        self::assertSame('W · SOAP', $hits[0]->subtitle);
        self::assertSame('bad value', $hits[0]->scopeLabel);
        self::assertInstanceOf(DateTimeImmutable::class, $hits[0]->updatedAt);
        self::assertSame('/w/1/s/3', $hits[0]->url);
        self::assertSame('', $hits[1]->title);
        self::assertSame('W · REST', $hits[1]->subtitle);
        self::assertNull($hits[1]->updatedAt);
        self::assertSame('/w/0/s/4', $hits[1]->url);
        self::assertSame('', $hits[2]->subtitle);
        self::assertSame('tabler:server', $hits[2]->icon);

        $endpointRows = [
            ['eid' => 9, 'ename' => 'E', 'epath' => '/p?k=v', 'emethod' => HttpMethod::Delete, 'sid' => 2, 'sname' => 'S', 'wid' => 1, 'wscope' => 'project:a'],
            ['eid' => 10, 'ename' => 'F', 'epath' => '/q', 'emethod' => 'nope', 'sid' => 2, 'sname' => 'S', 'wid' => 1, 'wscope' => ''],
        ];
        $found     = $mapper->labels($endpointRows, $tenant);
        $endpoints = array_map(static fn (array $row): SearchHit => $mapper->hit(SearchKind::Endpoint, $row, null, [], $found), $endpointRows);
        self::assertSame(1, $labels->calls);
        self::assertSame('DELETE /p · S', $endpoints[0]->subtitle);
        self::assertSame('Project project:a', $endpoints[0]->scopeLabel);
        self::assertSame('/q · S', $endpoints[1]->subtitle);
        self::assertNull($mapper->moreUrl(SearchKind::Endpoint, null));
        self::assertSame([], (new SearchHitMapper($this->router()))->labels($endpointRows, $tenant), 'no label provider');
    }

    public function testExactRowsSkipWhatIsNotARow(): void
    {
        $scope = new ApiStudioScope(new ApiStudioTenant('org', 'a'), true);
        self::assertSame(
            [['wid' => 1, 'wscope' => '']],
            ApiWorkspaceRepository::exactSearchRows(['x', ['wid' => 1, 'wtenanttype' => 'org', 'wtenantid' => 'a'], ['wid' => 2, 'wtenanttype' => 'org', 'wtenantid' => 'A']], $scope),
        );
    }

    private function router(): UrlGenerator
    {
        $routes = new RouteCollection();
        $routes->add('nowo_api_studio_service_show', new Route('/w/{workspaceId}/s/{id}'));
        $routes->add('nowo_api_studio_endpoint_show', new Route('/w/{workspaceId}/s/{serviceId}/e/{id}'));

        return new UrlGenerator($routes, new RequestContext());
    }
}
