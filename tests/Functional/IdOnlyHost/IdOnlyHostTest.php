<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\IdOnlyHost;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Query\ApiWorkspaceCriteria;
use Nowo\ApiStudioBundle\Query\ApiWorkspaceQueryInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiWorkspaceAccessInterface;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Nowo\ApiStudioBundle\Tests\Functional\App\Entity\HostNote;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;

use function preg_match;

/**
 * 1.19 (DDD-host checklist B10): a host with a private Api Studio entity manager and checkers that use ids only works
 * end to end — the studio's pages, the id access port and the workspace query port — in one kept-alive kernel (no
 * reboot, no services resetter: FrankenPHP worker mode with kernel reset false). The host's manager is never flushed
 * and never holds a bundle entity; ownership is recorded from the committed id event.
 */
final class IdOnlyHostTest extends TestCase
{
    private ?IdOnlyHostKernel $kernel = null;

    protected function setUp(): void
    {
        (new Filesystem())->remove(IdOnlyHostKernel::baseDir());
        @mkdir(IdOnlyHostKernel::baseDir(), 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        (new Filesystem())->remove(IdOnlyHostKernel::baseDir());
    }

    public function testAnIdOnlyHostWithAPrivateManagerWorksEndToEnd(): void
    {
        $this->kernel = new IdOnlyHostKernel();
        $this->kernel->boot();
        $registry = $this->container()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $host   = $registry->getManager('default');
        $studio = $registry->getManager('api_studio');
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        self::assertInstanceOf(EntityManagerInterface::class, $studio);
        (new SchemaTool($host))->createSchema($host->getMetadataFactory()->getAllMetadata());
        (new SchemaTool($studio))->createSchema($studio->getMetadataFactory()->getAllMetadata());
        $grants = $this->container()->get(HostGrantStore::class);
        self::assertInstanceOf(HostGrantStore::class, $grants);
        $grants->createTable();
        self::assertTrue($host->getMetadataFactory()->isTransient(ApiWorkspace::class), 'the bundle is not mapped on the host manager');

        $client = new KernelBrowser($this->kernel);
        $client->disableReboot();
        $client->setServerParameter('HTTP_X_TEST_USER', 'alice');
        $crawler = $client->request('GET', '/orgs/acme/api-studio/workspaces/new');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->submit($crawler->filter('form[name="api_workspace_form"]')->form([
            'api_workspace_form[name]' => 'Payments',
            'api_workspace_form[slug]' => 'payments',
        ]));
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame(1, preg_match('#/workspaces/(\d+)$#', (string) $client->getResponse()->headers->get('Location'), $match));
        $id = (int) $match[1];

        // Ownership came from the committed id event into the host's own table.
        self::assertSame([$id], $grants->workspaceIds('org:acme', 'u:alice'));

        // From here on the kernel is driven as a FrankenPHP worker drives it: http_kernel directly, no services
        // resetter between requests (the test client would reset the services). A host change pending meanwhile must
        // never be written or cleared by Api Studio.
        $host->persist(new HostNote('pending host change'));
        $http = $this->container()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $http);
        foreach ([['alice', 'acme', 200], ['bob', 'acme', 404], ['alice', 'globex', 404], ['alice', 'ACME', 404], ['alice', 'acme', 200]] as [$user, $org, $status]) {
            $request = Request::create('/orgs/' . $org . '/api-studio/workspaces/' . $id);
            $request->headers->set('X-Test-User', $user);
            $response = $http->handle($request);
            self::assertSame($status, $response->getStatusCode(), $user . ' in ' . $org);
            $http->terminate($request, $response);
        }

        // The id access port: same answers without a request, a user object or an entity.
        $access = $this->container()->get(ApiWorkspaceAccessInterface::class);
        self::assertInstanceOf(ApiWorkspaceAccessInterface::class, $access);
        $acme = new ApiStudioTenant('org', 'acme');
        self::assertTrue($access->isGranted('u:alice', ApiStudioAction::WorkspaceEdit, $acme, $id));
        self::assertFalse($access->isGranted('u:bob', ApiStudioAction::WorkspaceView, $acme, $id));
        self::assertFalse($access->isGranted('u:alice', ApiStudioAction::WorkspaceView, new ApiStudioTenant('org', 'ACME'), $id));
        self::assertFalse($access->isGranted('u:alice', ApiStudioAction::WorkspaceView, $acme, $id + 1));
        self::assertSame('payments', $access->viewableWorkspace('u:alice', $acme, $id)?->slug);

        // The query port lists what the host's grants allow, as scalars.
        $query = $this->container()->get(ApiWorkspaceQueryInterface::class);
        self::assertInstanceOf(ApiWorkspaceQueryInterface::class, $query);
        $page = $query->find(ApiWorkspaceCriteria::forTenant($acme)->withIds($grants->workspaceIds('org:acme', 'u:alice')));
        self::assertSame(1, $page->total);
        self::assertSame('Payments', $page->items[0]->name);
        self::assertSame(0, $query->count(ApiWorkspaceCriteria::forTenant($acme)->withIds($grants->workspaceIds('org:acme', 'u:bob'))));
        self::assertSame(0, $query->count(ApiWorkspaceCriteria::forTenant(new ApiStudioTenant('org', 'globex'))));

        // Neither port left a bundle entity in a manager; the host manager still has only its own pending note.
        $studio->clear();
        $access->isGranted('u:alice', ApiStudioAction::WorkspaceView, $acme, $id);
        $query->find(ApiWorkspaceCriteria::forTenant($acme));
        self::assertSame(0, $studio->getUnitOfWork()->size());
        self::assertCount(1, $host->getUnitOfWork()->getScheduledEntityInsertions(), "the host's pending change is untouched (never flushed or cleared)");
        self::assertSame(0, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM host_note'), 'the host manager is never flushed');
        self::assertFalse($host->getConnection()->createSchemaManager()->tablesExist(['api_studio_workspace']));
    }

    private function container(): ContainerInterface
    {
        self::assertInstanceOf(IdOnlyHostKernel::class, $this->kernel);
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }
}
