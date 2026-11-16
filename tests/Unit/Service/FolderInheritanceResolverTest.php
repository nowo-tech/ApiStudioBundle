<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service;

use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Enum\AuthType;
use Nowo\ApiStudioBundle\Security\ExecutionUrlValidator;
use Nowo\ApiStudioBundle\Service\FolderInheritanceResolver;
use Nowo\ApiStudioBundle\Service\RequestExecutor;
use Nowo\ApiStudioBundle\Service\VariableResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function base64_encode;

/**
 * 1.14 folders: headers and auth inherited from the folder path, and 1.13's result without folders.
 */
final class FolderInheritanceResolverTest extends TestCase
{
    public function testWithoutFolderTheResultIsTheServiceAndEndpointMergeOf113(): void
    {
        $service  = (new ApiService('Shop', 'shop'))->setDefaultHeaders(['x-a' => 's', 'X-S' => '1'])->setAuthType(AuthType::Bearer)->setAuthConfig(['token' => 't']);
        $endpoint = (new ApiEndpoint('Call', 'call'))->setService($service)->setHeaders(['X-A' => 'e']);

        // array_merge semantics kept (case-sensitive) for endpoints at the service root.
        self::assertSame(['x-a' => 's', 'X-S' => '1', 'X-A' => 'e'], FolderInheritanceResolver::headers($service, $endpoint));
        self::assertSame(['type' => AuthType::Bearer, 'config' => ['token' => 't'], 'folder' => null], FolderInheritanceResolver::auth($service, $endpoint));
    }

    public function testHeadersMergeServiceFoldersAndEndpointCaseInsensitively(): void
    {
        [$service, $top, $child, $endpoint] = $this->tree();
        $service->setDefaultHeaders(['X-A' => '1', 'X-Keep' => 'service']);
        $top->setHeaders(['x-a' => '2', 'X-B' => '1']);
        $child->setHeaders(['X-C' => 'child']);
        $endpoint->setHeaders(['x-b' => '2']);

        self::assertSame(
            ['X-Keep' => 'service', 'x-a' => '2', 'X-C' => 'child', 'x-b' => '2'],
            FolderInheritanceResolver::headers($service, $endpoint),
        );
    }

    public function testNearestFolderWithAnExplicitAuthWins(): void
    {
        [$service, $top, $child, $endpoint] = $this->tree();
        $service->setAuthType(AuthType::Bearer)->setAuthConfig(['token' => 'service']);

        self::assertSame(AuthType::Bearer, FolderInheritanceResolver::auth($service, $endpoint)['type']);

        $top->setAuthType(AuthType::Basic)->setAuthConfig(['username' => 'u', 'password' => 'p']);
        $auth = FolderInheritanceResolver::auth($service, $endpoint);
        self::assertSame(AuthType::Basic, $auth['type']);
        self::assertSame($top, $auth['folder']);

        $child->setAuthType(AuthType::None);
        $auth = FolderInheritanceResolver::auth($service, $endpoint);
        self::assertSame(AuthType::None, $auth['type']);
        self::assertSame($child, $auth['folder']);
    }

    public function testExecutorSendsTheInheritedAuthAndHeaders(): void
    {
        [$service, $top, , $endpoint] = $this->tree();
        $service->setBaseUrl('https://93.184.215.14')->setAuthType(AuthType::Bearer)->setAuthConfig(['token' => 'svc']);
        $top->setAuthType(AuthType::Basic)->setAuthConfig(['username' => '{{user}}', 'password' => 'p']);
        $top->setHeaders(['X-Folder' => 'yes']);

        $sent     = [];
        $executor = new RequestExecutor(new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = $options['normalized_headers'] ?? [];

            return new MockResponse('{}', ['http_code' => 200]);
        }), new VariableResolver(), new ExecutionUrlValidator([]), 5);

        $result = $executor->execute($endpoint, null, null, [], [], ['user' => 'ana']);

        self::assertTrue($result->success);
        self::assertSame('Basic ' . base64_encode('ana:p'), $result->requestHeaders['Authorization']);
        self::assertSame('yes', $result->requestHeaders['X-Folder']);
        self::assertSame(['user'], RequestExecutor::referencedVariables($endpoint, $service));
        self::assertContains('user', RequestExecutor::protectedVariables($endpoint, $service, null));
        self::assertNotSame([], $sent);
    }

    public function testExplicitNoAuthFolderSendsNoAuthorization(): void
    {
        [$service, , $child, $endpoint] = $this->tree();
        $service->setBaseUrl('https://93.184.215.14')->setAuthType(AuthType::Bearer)->setAuthConfig(['token' => 'svc']);
        $child->setAuthType(AuthType::None);

        $executor = new RequestExecutor(new MockHttpClient(new MockResponse('{}')), new VariableResolver(), new ExecutionUrlValidator([]), 5);
        $result   = $executor->execute($endpoint);

        self::assertArrayNotHasKey('Authorization', $result->requestHeaders);
    }

    public function testFolderPathAndDepthStopOnACycle(): void
    {
        $a = new ApiFolder('A');
        $b = (new ApiFolder('B'))->setParent($a);
        $a->setParent($b);

        self::assertCount(2, $b->getPath());
        self::assertSame(2, $a->getDepth());
    }

    public function testCreatorIdIsCappedAndEmptyIsNull(): void
    {
        $folder = new ApiFolder('A');
        self::assertNull($folder->setCreatedBy('')->getCreatedBy());
        self::assertNull($folder->setCreatedBy(str_repeat('x', 129))->getCreatedBy());
        self::assertSame('u:ana', $folder->setCreatedBy('u:ana')->getCreatedBy());
        self::assertLessThanOrEqual($folder->getUpdatedAt(), $folder->getCreatedAt());
    }

    /**
     * @return array{ApiService, ApiFolder, ApiFolder, ApiEndpoint}
     */
    private function tree(): array
    {
        $service  = new ApiService('Shop', 'shop');
        $top      = (new ApiFolder('Users'))->setService($service);
        $child    = (new ApiFolder('Admin'))->setService($service)->setParent($top);
        $endpoint = (new ApiEndpoint('Call', 'call'))->setService($service)->setPath('/x')->setFolder($child);

        return [$service, $top, $child, $endpoint];
    }
}
