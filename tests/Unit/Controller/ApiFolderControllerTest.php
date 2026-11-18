<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Controller;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Controller\ApiFolderController;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Repository\ApiEndpointRepository;
use Nowo\ApiStudioBundle\Repository\ApiFolderRepository;
use Nowo\ApiStudioBundle\Repository\ApiServiceRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Security\ApiStudioAccessPolicy;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\FolderAccess;
use Nowo\ApiStudioBundle\Service\FolderTree;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function json_decode;
use function json_encode;

/**
 * 1.14 tree moves under contention (audit RJ-L1 / RJ-D-L3): a lock timeout or deadlock answers 409 with Retry-After,
 * a folder deleted meanwhile answers 404.
 */
final class ApiFolderControllerTest extends TestCase
{
    public function testContentionAnswers409WithRetryAfter(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('lock')->willThrowException(new DeadlockException($this->createStub(DriverException::class), null));

        $response = $this->controller($em, [['id' => 7, 'name' => 'F', 'parent' => null, 'service' => 2, 'workspace' => 1, 'sortOrder' => 0, 'createdBy' => null]])
            ->move($this->request(['type' => 'endpoint', 'id' => 3, 'parent' => null]), 1, 2);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertSame('folder.error.busy', json_decode((string) $response->getContent(), true)['code'] ?? null);
    }

    public function testAFolderDeletedMeanwhileAnswers404(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);

        // The folder row is gone when the move re-reads the tree under the lock.
        $response = $this->controller($em, [])->move($this->request(['type' => 'folder', 'id' => 7, 'parent' => null]), 1, 2);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(FolderTree::ERROR_GONE, json_decode((string) $response->getContent(), true)['code'] ?? null);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function controller(EntityManagerInterface $em, array $rows): ApiFolderController
    {
        $workspace = new ApiWorkspace('W', 'w');
        $service   = new ApiService('S', 's');
        $workspace->addService($service);
        (new ReflectionProperty(ApiWorkspace::class, 'id'))->setValue($workspace, 1);
        (new ReflectionProperty(ApiService::class, 'id'))->setValue($service, 2);
        $folder = (new ApiFolder('F'))->setService($service);
        (new ReflectionProperty(ApiFolder::class, 'id'))->setValue($folder, 7);
        $endpoint = (new ApiEndpoint('E', 'e'))->setService($service);
        (new ReflectionProperty(ApiEndpoint::class, 'id'))->setValue($endpoint, 3);

        $workspaces = $this->createStub(ApiWorkspaceRepository::class);
        $workspaces->method('find')->willReturn($workspace);
        $services = $this->createStub(ApiServiceRepository::class);
        $services->method('find')->willReturn($service);
        $endpoints = $this->createStub(ApiEndpointRepository::class);
        $endpoints->method('find')->willReturn($endpoint);
        $folders = $this->createStub(ApiFolderRepository::class);
        $folders->method('find')->willReturn($folder);
        $folders->method('treeRows')->willReturn($rows);
        $folders->method('children')->willReturn([]);
        $folders->method('endpointsIn')->willReturn([]);

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);
        $container = new Container();
        // 1.16 (SY-L2): the trait fails closed without them; the defaults grant everything and answer 403.
        $container->set(ApiStudioAuthorization::class, new ApiStudioAuthorization());
        $container->set(ApiStudioAccessPolicy::class, new ApiStudioAccessPolicy());
        $container->set('security.csrf.token_manager', $csrf);

        $controller = new ApiFolderController($workspaces, $services, $folders, $endpoints, new FolderTree($em, $folders), new FolderAccess(), true);
        $controller->setContainer($container);

        return $controller;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function request(array $payload): Request
    {
        return Request::create('/move', 'POST', server: ['HTTP_X_CSRF_TOKEN' => 'ok'], content: (string) json_encode($payload));
    }
}
