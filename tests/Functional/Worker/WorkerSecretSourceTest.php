<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\Worker;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tests\Functional\App\TestHostSecretSource;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

use function gc_collect_cycles;
use function hash;
use function json_decode;
use function json_encode;
use function memory_get_usage;
use function mkdir;
use function sprintf;

/**
 * 1.13 (REQ-RUNTIME-002): host secrets in a kept-alive kernel without the services resetter (FrankenPHP worker mode,
 * `FRANKENPHP_RESET_KERNEL=false`) on a DDD host (dedicated manager, tenants, ids only towards the port). Two users
 * alternate on the same endpoint: each execution asks the host again (no memo), each user gets the value the host gives
 * them, a refusal is never answered from an earlier success, and memory stays flat.
 */
final class WorkerSecretSourceTest extends TestCase
{
    private const ITERATIONS = 150;

    private const WARM_UP = 20;

    private const MEMORY_MARGIN = 4 * 1024 * 1024;

    private ?WorkerTestKernel $kernel = null;

    protected function setUp(): void
    {
        (new Filesystem())->remove([WorkerTestKernel::baseDir() . '/host.sqlite', WorkerTestKernel::baseDir() . '/studio.sqlite']);
        @mkdir(WorkerTestKernel::baseDir(), 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        (new Filesystem())->remove(WorkerTestKernel::baseDir());
    }

    public function testEachExecutionResolvesForItsOwnActorWithoutMemo(): void
    {
        $this->kernel = new WorkerTestKernel();
        $this->kernel->boot();
        $registry = $this->container()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        foreach (['default', 'api_studio'] as $name) {
            $manager = $registry->getManager($name);
            self::assertInstanceOf(EntityManagerInterface::class, $manager);
            (new SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());
        }
        $studio = $registry->getManager('api_studio');
        self::assertInstanceOf(EntityManagerInterface::class, $studio);

        $workspace = (new ApiWorkspace('Payments', 'payments'))->assignTenant(new ApiStudioTenant('org', 'acme'));
        $service   = (new ApiService('Gateway', 'gateway'))->setBaseUrl('https://93.184.215.14');
        $workspace->addService($service);
        $endpoint = (new ApiEndpoint('Echo', 'echo'))->setMethod(HttpMethod::Get)->setPath('/echo')->setHeaders(['X-Host-Token' => '{{hostToken}}'])->setService($service);
        $env      = new ApiEnvironment('Prod', 'prod');
        $workspace->addEnvironment($env);
        $env->addVariable((new ApiEnvironmentVariable('hostToken', '{{secret:vault/payments}}'))->setSecret(true));
        foreach ([$workspace, $service, $endpoint, $env] as $entity) {
            $studio->persist($entity);
        }
        $studio->flush();
        $ids = [(int) $workspace->getId(), (int) $service->getId(), (int) $endpoint->getId(), (int) $env->getId()];
        $studio->clear();

        $source = $this->container()->get(TestHostSecretSource::class);
        self::assertInstanceOf(TestHostSecretSource::class, $source);
        $values          = ['alice' => self::value('alice'), 'bob' => self::value('bob')];
        $source->secrets = ['u:alice|vault/payments' => $values['alice'], 'u:bob|vault/payments' => $values['bob']];

        // One browser (cookie jar: session + CSRF token) per user, on the same kept-alive kernel.
        $clients = [];
        foreach ($values as $user => $value) {
            $client = new KernelBrowser($this->kernel);
            $client->disableReboot();
            $crawler = $client->request('GET', sprintf('/orgs/acme/api-studio/workspaces/%d/services/%d/endpoints/%d', $ids[0], $ids[1], $ids[2]), [], [], ['HTTP_X_TEST_USER' => $user]);
            self::assertSame(200, $client->getResponse()->getStatusCode(), $user);
            $clients[$user] = [$client, (string) $crawler->filter('#api-studio-tester')->attr('data-csrf-token')];
        }

        $baseline = 0;
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            // Every fifth round the host withdraws bob's access: no earlier success may answer for it.
            $source->allowedActors = $i % 5 === 4 ? ['u:alice'] : null;
            foreach ($clients as $user => [$client, $token]) {
                $client->request('POST', sprintf('/orgs/acme/api-studio/execute/%d', $ids[2]), [], [], [
                    'HTTP_X_TEST_USER'  => $user,
                    'HTTP_X_CSRF_TOKEN' => $token,
                    'CONTENT_TYPE'      => 'application/json',
                ], (string) json_encode(['environment_id' => $ids[3], 'variables' => ['hostToken' => '{{hostToken}}']]));
                $content = (string) $client->getResponse()->getContent();
                $data    = json_decode($content, true);
                self::assertIsArray($data, 'iteration ' . $i . ' ' . $user);
                self::assertStringNotContainsString($values['alice'], $content);
                self::assertStringNotContainsString($values['bob'], $content);

                self::assertCount(1, $source->calls, 'iteration ' . $i . ' ' . $user . ': the host is asked on every execution');
                self::assertSame('u:' . $user, $source->calls[0]->actorId);
                self::assertSame('org', $source->calls[0]->tenantType);
                self::assertSame('acme', $source->calls[0]->tenantId);
                self::assertSame('https://93.184.215.14', $source->calls[0]->targetOrigin);
                $source->calls = [];

                if ($user === 'bob' && $i % 5 === 4) {
                    self::assertSame(422, $client->getResponse()->getStatusCode(), 'iteration ' . $i . ': bob is refused');
                    self::assertSame('secret_unresolved', $data['error_code']);

                    continue;
                }

                self::assertSame(200, $client->getResponse()->getStatusCode(), 'iteration ' . $i . ' ' . $user);
                $body = json_decode((string) $data['response_body'], true);
                self::assertIsArray($body);
                self::assertSame(hash('sha256', $values[$user]), $body['token_sha256'], 'iteration ' . $i . ': ' . $user . ' sent their own value');
            }

            if ($i === self::WARM_UP) {
                $baseline = $this->collectedMemory();
            }
        }

        self::assertLessThan(self::MEMORY_MARGIN, $this->collectedMemory() - $baseline, 'memory stays flat');
        self::assertSame(0, $studio->getUnitOfWork()->size(), 'no Api Studio entity survives the requests');
    }

    private static function value(string $user): string
    {
        return 'hv-REDACTED-TEST-' . $user;
    }

    private function container(): ContainerInterface
    {
        self::assertInstanceOf(WorkerTestKernel::class, $this->kernel);
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    private function collectedMemory(): int
    {
        gc_collect_cycles();

        return memory_get_usage();
    }
}
