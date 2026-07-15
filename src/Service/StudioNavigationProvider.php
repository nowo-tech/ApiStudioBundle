<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service;

use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds sidebar navigation tree (workspaces → services → endpoints).
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
        $tree = [];
        foreach ($this->workspaceRepository->findBy([], ['name' => 'ASC']) as $workspace) {
            $services = [];
            foreach ($workspace->getServices() as $service) {
                $endpoints = [];
                foreach ($service->getEndpoints() as $endpoint) {
                    $requestExamples = [];
                    foreach ($endpoint->getRequestExamples() as $example) {
                        $exampleId = $example->getId();
                        if ($exampleId === null) {
                            continue;
                        }

                        $requestExamples[] = [
                            'id'   => $exampleId,
                            'name' => $example->getName(),
                            'url'  => $this->urlGenerator->generate('nowo_api_studio_endpoint_show', [
                                'workspaceId'     => $workspace->getId(),
                                'serviceId'       => $service->getId(),
                                'id'              => $endpoint->getId(),
                                'request_example' => $exampleId,
                            ]),
                        ];
                    }

                    $endpoints[] = [
                        'id'     => $endpoint->getId(),
                        'name'   => $endpoint->getName(),
                        'method' => $endpoint->getMethod()->value,
                        'url'    => $this->urlGenerator->generate('nowo_api_studio_endpoint_show', [
                            'workspaceId' => $workspace->getId(),
                            'serviceId'   => $service->getId(),
                            'id'          => $endpoint->getId(),
                        ]),
                        'request_examples' => $requestExamples,
                    ];
                }

                $services[] = [
                    'id'       => $service->getId(),
                    'name'     => $service->getName(),
                    'protocol' => $service->getProtocol()->value,
                    'url'      => $this->urlGenerator->generate('nowo_api_studio_service_show', [
                        'workspaceId' => $workspace->getId(),
                        'id'          => $service->getId(),
                    ]),
                    'endpoints' => $endpoints,
                ];
            }

            $tree[] = [
                'id'       => $workspace->getId(),
                'name'     => $workspace->getName(),
                'slug'     => $workspace->getSlug(),
                'url'      => $this->urlGenerator->generate('nowo_api_studio_workspace_show', ['id' => $workspace->getId()]),
                'services' => $services,
            ];
        }

        return $tree;
    }
}
