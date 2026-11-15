<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\Secret;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Event\ApiRequestExecutedEvent;
use Nowo\ApiStudioBundle\Model\ApiExecutionResult;
use Nowo\ApiStudioBundle\Tests\Functional\App\EntityManagerTestKernel;
use Nowo\ApiStudioBundle\Tests\Functional\App\EventRecorder;
use Nowo\ApiStudioBundle\Tests\Functional\App\TestHostSecretSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stringable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Log\DebugLoggerInterface;

use function array_walk_recursive;
use function get_debug_type;
use function getmypid;
use function hash;
use function is_file;
use function is_scalar;
use function json_decode;
use function json_encode;
use function sprintf;
use function sys_get_temp_dir;
use function unlink;

/**
 * 1.13 host secret source end to end, on a standard host (one default manager, attribute mapping) and on a DDD host
 * (Api Studio entities on a dedicated manager, ids only towards the host port): the reference stored in a secret
 * variable is resolved on the server per execution, sent to the target only and masked everywhere else; a reference
 * the host does not resolve stops the request.
 */
final class HostSecretSourceTest extends TestCase
{
    private const REFERENCE = '{{secret:vault/payments-gateway}}';

    private ?EntityManagerTestKernel $kernel = null;

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function hosts(): iterable
    {
        yield 'standard host' => [false];
        yield 'DDD host (dedicated manager)' => [true];
    }

    #[DataProvider('hosts')]
    public function testReferenceIsResolvedOnTheServerAndMaskedEverywhereElse(bool $ddd): void
    {
        $value                   = self::hostValue('alpha');
        [$client, $studio, $ids] = $this->setUpHost($ddd, ['secrets' => ['source' => TestHostSecretSource::class]]);
        $source                  = $this->source();
        $source->secrets         = ['vault/payments-gateway' => $value];

        $data = $this->execute($client, $ids);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(201, $data['response_status']);
        $body = json_decode((string) $data['response_body'], true);
        self::assertIsArray($body);
        self::assertSame(hash('sha256', $value), $body['token_sha256'], 'the target received the resolved value');
        self::assertStringNotContainsString($value, (string) $client->getResponse()->getContent(), 'never back to the browser');
        self::assertStringContainsString('[REDACTED]', (string) $data['response_body'], 'the echoed value is masked');

        self::assertCount(1, $source->calls);
        $ref = $source->calls[0];
        self::assertSame('vault', $ref->source);
        self::assertSame('payments-gateway', $ref->id);
        self::assertSame('hostToken', $ref->variableKey);
        self::assertSame($ids['workspace'], $ref->workspaceId);
        self::assertSame($ids['environment'], $ref->environmentId);
        self::assertSame('', $ref->tenantType);
        self::assertNull($ref->actorId, 'anonymous runner');

        // Stored workspace: the variable keeps the reference; history, event and logs never see the value.
        $studio->clear();
        $variable = $studio->getRepository(ApiEnvironmentVariable::class)->findOneBy(['variableKey' => 'hostToken']);
        self::assertInstanceOf(ApiEnvironmentVariable::class, $variable);
        self::assertSame(self::REFERENCE, $variable->getValue());
        $rows = $studio->getConnection()->fetchAllAssociative('SELECT * FROM api_studio_request_history');
        self::assertCount(1, $rows);
        self::assertStringNotContainsString($value, (string) json_encode($rows));
        self::assertStringNotContainsString(hash('sha256', $value), (string) json_encode($rows['0']['request_headers'] ?? ''));

        $recorder = $this->container()->get(EventRecorder::class);
        self::assertInstanceOf(EventRecorder::class, $recorder);
        [$executed] = $recorder->of(ApiRequestExecutedEvent::class);
        self::assertStringNotContainsString($value, (string) json_encode((array) $executed['event']));
        $logs = $this->logs();
        self::assertStringContainsString('93.184.215.14', $logs, 'the execution was logged');
        self::assertStringNotContainsString($value, $logs);

        // No memo: the next execution asks the host again.
        $this->execute($client, $ids);
        self::assertCount(2, $this->source()->calls);
    }

    #[DataProvider('hosts')]
    public function testUnresolvedReferenceStopsTheRequest(bool $ddd): void
    {
        [$client, , $ids] = $this->setUpHost($ddd, ['secrets' => ['source' => TestHostSecretSource::class]]);
        $source           = $this->source();
        $source->secrets  = ['vault/payments-gateway' => self::hostValue('beta')];
        // The host refuses this workspace (per-workspace authorisation is the host's).
        $source->allowedWorkspaces = [$ids['workspace'] + 1000];

        $data = $this->execute($client, $ids);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame(ApiExecutionResult::ERROR_SECRET_UNRESOLVED, $data['error_code']);
        self::assertSame(['hostToken'], $data['unresolved_variables']);
        self::assertNull($data['response_status'], 'nothing was sent');
        self::assertSame('', $data['request_url']);
        self::assertStringContainsString('{{hostToken}}', (string) $data['error_message']);
        self::assertStringNotContainsString('payments-gateway', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('execute_secret_unresolved', $this->logs());
    }

    #[DataProvider('hosts')]
    public function testWithoutASourceTheReferenceIsAPlainSecretValue(bool $ddd): void
    {
        [$client, , $ids] = $this->setUpHost($ddd);

        $data = $this->execute($client, $ids);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $data['response_body'], true);
        self::assertIsArray($body);
        self::assertSame(hash('sha256', self::REFERENCE), $body['token_sha256'], '1.12 behaviour: the stored value is sent as is');
        self::assertArrayNotHasKey('error_code', $data);
        self::assertSame([], $this->source()->calls);
    }

    private static function hostValue(string $suffix): string
    {
        return 'hs-REDACTED-TEST-' . $suffix;
    }

    /**
     * @param array<string, mixed> $bundle
     *
     * @return array{KernelBrowser, EntityManagerInterface, array{workspace: int, service: int, endpoint: int, environment: int}}
     */
    private function setUpHost(bool $ddd, array $bundle = []): array
    {
        $doctrine = $ddd
            ? [
                'dbal' => [
                    'default_connection' => 'default',
                    'connections'        => [
                        'default'    => ['url' => 'sqlite:///' . $this->database('host')],
                        'api_studio' => ['url' => 'sqlite:///' . $this->database('studio')],
                    ],
                ],
                'orm' => [
                    'default_entity_manager' => 'default',
                    'entity_managers'        => [
                        'default'    => ['connection' => 'default', 'auto_mapping' => false],
                        'api_studio' => ['connection' => 'api_studio'],
                    ],
                ],
            ]
            : ['dbal' => ['url' => 'sqlite:///' . $this->database('standard')], 'orm' => ['auto_mapping' => true]];
        if ($ddd) {
            $bundle['entity_manager'] = 'api_studio';
        }

        $this->kernel = new EntityManagerTestKernel($doctrine, $bundle);
        $this->kernel->boot();
        $registry = $this->container()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $studio = $registry->getManager($ddd ? 'api_studio' : 'default');
        self::assertInstanceOf(EntityManagerInterface::class, $studio);
        (new SchemaTool($studio))->createSchema($studio->getMetadataFactory()->getAllMetadata());

        $workspace = new ApiWorkspace('Payments', 'payments');
        $service   = (new ApiService('Gateway', 'gateway'))->setBaseUrl('https://93.184.215.14');
        $workspace->addService($service);
        $endpoint = (new ApiEndpoint('Echo', 'echo'))->setMethod(HttpMethod::Get)->setPath('/echo')->setHeaders(['X-Host-Token' => '{{hostToken}}'])->setService($service);
        $env      = new ApiEnvironment('Prod', 'prod');
        $workspace->addEnvironment($env);
        $env->addVariable((new ApiEnvironmentVariable('hostToken', self::REFERENCE))->setSecret(true));
        foreach ([$workspace, $service, $endpoint, $env] as $entity) {
            $studio->persist($entity);
        }
        $studio->flush();
        $ids = ['workspace' => (int) $workspace->getId(), 'service' => (int) $service->getId(), 'endpoint' => (int) $endpoint->getId(), 'environment' => (int) $env->getId()];
        $studio->clear();

        $client = new KernelBrowser($this->kernel);
        $client->disableReboot();

        return [$client, $studio, $ids];
    }

    /**
     * Runs the endpoint the way the console does: the secret variable comes back as its own placeholder.
     *
     * @param array{workspace: int, service: int, endpoint: int, environment: int} $ids
     *
     * @return array<string, mixed>
     */
    private function execute(KernelBrowser $client, array $ids): array
    {
        $crawler = $client->request('GET', sprintf('/api-studio/workspaces/%d/services/%d/endpoints/%d', $ids['workspace'], $ids['service'], $ids['endpoint']));
        self::assertStringNotContainsString('payments-gateway', (string) $client->getResponse()->getContent(), 'the reference never reaches the page');
        $token = (string) $crawler->filter('#api-studio-tester')->attr('data-csrf-token');
        $client->request('POST', sprintf('/api-studio/execute/%d', $ids['endpoint']), [], [], ['HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'environment_id' => $ids['environment'],
            'variables'      => ['hostToken' => '{{hostToken}}'],
        ]));
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        /* @var array<string, mixed> $data */
        return $data;
    }

    private function source(): TestHostSecretSource
    {
        $source = $this->container()->get(TestHostSecretSource::class);
        self::assertInstanceOf(TestHostSecretSource::class, $source);

        return $source;
    }

    private function logs(): string
    {
        $logger = $this->container()->get('logger');

        self::assertInstanceOf(DebugLoggerInterface::class, $logger, get_debug_type($logger));

        $text = '';
        $logs = $logger->getLogs();
        array_walk_recursive($logs, static function (mixed $value) use (&$text): void {
            if (is_scalar($value) || $value instanceof Stringable) {
                $text .= (string) $value . "\n";
            }
        });

        return $text;
    }

    private function container(): ContainerInterface
    {
        self::assertInstanceOf(EntityManagerTestKernel::class, $this->kernel);
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    private function database(string $name): string
    {
        $file = sprintf('%s/nowo_api_studio_secret_%s_%d.sqlite', sys_get_temp_dir(), $name, (int) getmypid());
        if (is_file($file)) {
            unlink($file);
        }
        $this->files[] = $file;

        return $file;
    }
}
