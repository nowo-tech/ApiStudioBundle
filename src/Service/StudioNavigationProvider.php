<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service;

use BackedEnum;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_values;
use function is_scalar;

/**
 * Builds sidebar navigation tree (workspaces → services → endpoints → request examples).
 *
 * Rendered on every studio page: the tree comes from one scalar query ({@see ApiWorkspaceRepository::navigationRows()})
 * instead of walking lazy collections (1 + W + S + E queries).
 */
final class StudioNavigationProvider
{
    public function __construct(
        private readonly ApiWorkspaceRepository $workspaceRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function buildTree(): array
    {
        /** @var array<int, array<string, mixed>> $tree workspace id => node (services keyed by id while building) */
        $tree = [];
        foreach ($this->workspaceRepository->navigationRows() as $row) {
            $wid = $row['wid'];
            $tree[$wid] ??= [
                'id'       => $wid,
                'name'     => $row['wname'],
                'slug'     => $row['wslug'],
                'url'      => $this->urlGenerator->generate('nowo_api_studio_workspace_show', ['id' => $wid]),
                'services' => [],
            ];

            $sid = $row['sid'];
            if ($sid === null) {
                continue;
            }
            $tree[$wid]['services'][$sid] ??= [
                'id'        => $sid,
                'name'      => $row['sname'],
                'protocol'  => self::enumValue($row['sprotocol']),
                'url'       => $this->urlGenerator->generate('nowo_api_studio_service_show', ['workspaceId' => $wid, 'id' => $sid]),
                'endpoints' => [],
            ];

            $eid = $row['eid'];
            if ($eid === null) {
                continue;
            }
            $endpointParams = ['workspaceId' => $wid, 'serviceId' => $sid, 'id' => $eid];
            $tree[$wid]['services'][$sid]['endpoints'][$eid] ??= [
                'id'               => $eid,
                'name'             => $row['ename'],
                'method'           => self::enumValue($row['emethod']),
                'url'              => $this->urlGenerator->generate('nowo_api_studio_endpoint_show', $endpointParams),
                'request_examples' => [],
            ];

            $xid = $row['xid'];
            if ($xid === null) {
                continue;
            }
            $tree[$wid]['services'][$sid]['endpoints'][$eid]['request_examples'][] = [
                'id'   => $xid,
                'name' => $row['xname'],
                'url'  => $this->urlGenerator->generate('nowo_api_studio_endpoint_show', $endpointParams + ['request_example' => $xid]),
            ];
        }

        $out = [];
        foreach ($tree as $workspace) {
            $services = [];
            foreach ($workspace['services'] as $service) {
                $service['endpoints'] = array_values($service['endpoints']);
                $services[]           = $service;
            }
            $workspace['services'] = $services;
            $out[]                 = $workspace;
        }

        return $out;
    }

    private static function enumValue(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
