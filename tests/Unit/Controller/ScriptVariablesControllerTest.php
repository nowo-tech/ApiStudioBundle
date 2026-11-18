<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\ApiStudioBundle\Controller\ScriptVariablesController;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Repository\ApiEndpointRepository;
use Nowo\ApiStudioBundle\Script\ScriptVariableStore;
use Nowo\ApiStudioBundle\Security\ApiStudioAccessPolicy;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Throwable;

use function json_decode;

/**
 * Audit RW-D-I1: a save that deadlocks (or times out waiting for a lock) against another save or a cascade delete
 * answers 409 with Retry-After, not 500, and nothing is committed.
 */
final class ScriptVariablesControllerTest extends TestCase
{
    public function testDeadlockAndLockWaitTimeoutAnswer409WithRetryAfter(): void
    {
        $driver = new class('busy') extends AbstractException {
        };
        foreach ([new DeadlockException($driver, null), new LockWaitTimeoutException($driver, null)] as $failure) {
            $rolledBack = false;
            $response   = $this->controller($failure, $rolledBack)->save(
                Request::create('/scripts/variables/3', 'POST', server: ['HTTP_X_CSRF_TOKEN' => 't'], content: '{"globals":{"a":"1"}}'),
                3,
            );

            self::assertSame(409, $response->getStatusCode());
            self::assertSame('1', $response->headers->get('Retry-After'));
            self::assertSame('script_variables.busy', json_decode((string) $response->getContent(), true)['code']);
            self::assertTrue($rolledBack, 'the save was rolled back');
        }
    }

    private function controller(Throwable $failure, bool &$rolledBack): ScriptVariablesController
    {
        $workspace = new ApiWorkspace('W', 'w');
        $service   = new ApiService('S', 's');
        $workspace->addService($service);
        $endpoint = (new ApiEndpoint('E', 'e'))->setService($service);

        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willThrowException($failure);
        $connection->method('rollBack')->willReturnCallback(static function () use (&$rolledBack): void {
            $rolledBack = true;
        });
        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->method('getTableName')->willReturn('t');
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getClassMetadata')->willReturn($metadata);

        $endpoints = $this->createStub(ApiEndpointRepository::class);
        $endpoints->method('find')->willReturn($endpoint);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);
        $container = new Container();
        // 1.16 (SY-L2): the trait fails closed without them; the defaults grant everything and answer 403.
        $container->set(ApiStudioAuthorization::class, new ApiStudioAuthorization());
        $container->set(ApiStudioAccessPolicy::class, new ApiStudioAccessPolicy());
        $container->set('security.csrf.token_manager', $csrf);
        $controller = new ScriptVariablesController($endpoints, new ScriptVariableStore($entityManager), null, 'sandbox');
        $controller->setContainer($container);

        return $controller;
    }
}
