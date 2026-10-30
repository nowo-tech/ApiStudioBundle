<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service;

use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Service\StudioNavigationProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function http_build_query;

final class StudioNavigationProviderTest extends TestCase
{
    public function testBuildsNestedTreeFromFlatRows(): void
    {
        $row = static fn (int $wid, ?int $sid = null, mixed $protocol = null, ?int $eid = null, mixed $method = null, ?int $xid = null): array => [
            'wid' => $wid, 'wname' => 'W' . $wid, 'wslug' => 'w' . $wid,
            'sid' => $sid, 'sname' => $sid !== null ? 'S' . $sid : null, 'sprotocol' => $protocol,
            'eid' => $eid, 'ename' => $eid !== null ? 'E' . $eid : null, 'emethod' => $method,
            'xid' => $xid, 'xname' => $xid !== null ? 'X' . $xid : null,
        ];

        $repository = $this->createStub(ApiWorkspaceRepository::class);
        $repository->method('navigationRows')->willReturn([
            $row(1),
            $row(2, 10, ApiProtocol::Rest, 100, HttpMethod::Get, 1000),
            $row(2, 10, ApiProtocol::Rest, 100, HttpMethod::Get, 1001),
            $row(2, 10, ApiProtocol::Rest, 101, 'POST'),
            $row(2, 11, 'soap'),
            $row(2, 12, null, 120, null),
        ]);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $params = []): string => $route . '?' . http_build_query($params));

        $tree = (new StudioNavigationProvider($repository, $urls))->buildTree();

        self::assertCount(2, $tree);
        self::assertSame(['id' => 1, 'name' => 'W1', 'slug' => 'w1', 'url' => 'nowo_api_studio_workspace_show?id=1', 'services' => []], $tree[0]);

        $services = $tree[1]['services'];
        self::assertCount(3, $services);
        self::assertSame('rest', $services[0]['protocol']);
        self::assertSame('nowo_api_studio_service_show?workspaceId=2&id=10', $services[0]['url']);
        self::assertCount(2, $services[0]['endpoints']);
        self::assertSame('GET', $services[0]['endpoints'][0]['method']);
        self::assertSame('nowo_api_studio_endpoint_show?workspaceId=2&serviceId=10&id=100', $services[0]['endpoints'][0]['url']);
        self::assertSame([
            ['id' => 1000, 'name' => 'X1000', 'url' => 'nowo_api_studio_endpoint_show?workspaceId=2&serviceId=10&id=100&request_example=1000'],
            ['id' => 1001, 'name' => 'X1001', 'url' => 'nowo_api_studio_endpoint_show?workspaceId=2&serviceId=10&id=100&request_example=1001'],
        ], $services[0]['endpoints'][0]['request_examples']);
        self::assertSame('POST', $services[0]['endpoints'][1]['method']);
        self::assertSame([], $services[0]['endpoints'][1]['request_examples']);
        self::assertSame('soap', $services[1]['protocol']);
        self::assertSame([], $services[1]['endpoints']);
        self::assertSame('', $services[2]['protocol']);
        self::assertSame('', $services[2]['endpoints'][0]['method']);
    }
}
