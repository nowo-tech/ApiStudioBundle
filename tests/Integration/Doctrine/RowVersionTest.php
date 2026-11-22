<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use LogicException;
use Nowo\ApiStudioBundle\Concurrency\StaleEditException;
use Nowo\ApiStudioBundle\Doctrine\DefaultEnvironmentSwitch;
use Nowo\ApiStudioBundle\Doctrine\RowVersionGuard;
use Nowo\ApiStudioBundle\Doctrine\RowVersionListener;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEndpointTranslation;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiRequestExample;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\EventListener\ConcurrencyConflictSubscriber;
use Nowo\ApiStudioBundle\Form\RowVersionField;
use Nowo\ApiStudioBundle\Tests\Support\ScopeOrmTrait;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * 1.20 row versions on SQLite: the claim, the atomic bump of writers without a version, the listener's choices
 * (claimed, new, deleted and foreign rows), and the conflict answer of a stale edit.
 */
final class RowVersionTest extends TestCase
{
    use ScopeOrmTrait;

    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        $this->manager = $this->scopeEntityManager($this->sqliteScopeRegistry());
        $this->manager->getEventManager()->addEventListener([Events::postUpdate, Events::postPersist, Events::postRemove], new RowVersionListener());
    }

    public function testNewRowsStartAtOneAndAClaimMovesTheVersionOnce(): void
    {
        [$workspace] = $this->graph();
        self::assertSame(1, $workspace->getRowVersion());
        self::assertSame(1, $this->stored($workspace));

        $workspace->setName('Edited');
        RowVersionGuard::claim($this->manager, $workspace, 1);
        $this->manager->flush();

        self::assertSame(2, $workspace->getRowVersion());
        self::assertSame(2, $this->stored($workspace));
    }

    public function testAClaimOnAnOlderVersionIsStaleAndWritesNothing(): void
    {
        [$workspace] = $this->graph();
        $workspace->setName('Edited');
        $this->manager->flush();
        self::assertSame(2, $this->stored($workspace));

        try {
            RowVersionGuard::claim($this->manager, $workspace, 1);
            self::fail('Expected a stale edit.');
        } catch (StaleEditException $e) {
            // A lock conflict for transaction-doctrine: answered 409 anywhere it escapes.
            self::assertTrue((new ConcurrencyConflictSubscriber())->isConflict($e));
        }
        self::assertSame(2, $this->stored($workspace));
    }

    public function testAClaimNeedsAStoredRow(): void
    {
        $this->expectException(LogicException::class);
        RowVersionGuard::claim($this->manager, new ApiWorkspace('New', 'new'), 1);
    }

    public function testABumpOfADeletedRowRecordsNothing(): void
    {
        [$workspace] = $this->graph();
        $this->manager->getConnection()->executeStatement('DELETE FROM api_studio_workspace WHERE id = ?', [$workspace->getId()]);

        RowVersionGuard::bump($this->manager, $workspace);

        self::assertNull(RowVersionGuard::storedVersion($this->manager, $workspace));
        self::assertSame(1, $workspace->getRowVersion());
    }

    public function testChildWritesMoveTheParentAndDeletesDoNot(): void
    {
        [$workspace, , $endpoint, $environment] = $this->graph();
        $translation                            = $endpoint->getTranslation('en');
        self::assertInstanceOf(ApiEndpointTranslation::class, $translation);
        $translation->setTitle('Changed');
        $this->manager->flush();
        self::assertSame(2, $this->stored($endpoint));
        self::assertSame(2, $endpoint->getRowVersion());

        $variable = new ApiEnvironmentVariable('token', 'x');
        $variable->setEnvironment($environment);
        $this->manager->persist($variable);
        $this->manager->flush();
        self::assertSame(2, $this->stored($environment));

        $this->manager->remove($variable);
        $this->manager->flush();
        self::assertSame(3, $this->stored($environment));

        // A workspace deleted with its children: nothing to move (no error on the cascaded rows).
        $this->manager->remove($workspace);
        $this->manager->flush();
        self::assertSame(0, (int) $this->manager->getConnection()->fetchOne('SELECT COUNT(*) FROM api_studio_workspace'));
    }

    public function testANewDefaultClearsTheOtherDefaultsAndMovesTheirVersions(): void
    {
        [$workspace, , , $current] = $this->graph();
        $current->setIsDefault(true);
        $next = (new ApiEnvironment('Next', 'next'))->setWorkspace($workspace);
        $this->manager->persist($next);
        $this->manager->flush();
        $before = (int) $this->stored($current);

        $this->manager->getConnection()->beginTransaction();
        DefaultEnvironmentSwitch::makeDefault($this->manager, $next);
        $this->manager->getConnection()->commit();

        self::assertFalse((bool) $this->manager->getConnection()->fetchOne('SELECT is_default FROM api_studio_environment WHERE id = ?', [$current->getId()]));
        self::assertSame($before + 1, $this->stored($current));
        self::assertSame(1, $this->stored($next));
    }

    public function testANewDefaultNeedsStoredRows(): void
    {
        $this->expectException(LogicException::class);
        DefaultEnvironmentSwitch::makeDefault($this->manager, new ApiEnvironment('New', 'new'));
    }

    public function testOtherEntitiesAreIgnored(): void
    {
        [, , $endpoint] = $this->graph();
        $example        = (new ApiRequestExample('Example'))->setEndpoint($endpoint);
        $this->manager->persist($example);
        $this->manager->flush();
        $example->setName('Renamed');
        $this->manager->flush();

        // Examples are not part of the endpoint form: they do not move its version.
        self::assertSame(1, $this->stored($endpoint));
    }

    public function testTheFormFieldIsOnlyOnEditForms(): void
    {
        $resolver = new OptionsResolver();
        RowVersionField::configure($resolver);
        self::assertSame([RowVersionField::OPTION => null], $resolver->resolve([]));
        self::assertSame([RowVersionField::OPTION => 4], $resolver->resolve([RowVersionField::OPTION => 4]));
    }

    public function testTheConflictAnswerOfAStaleEdit(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'nowo_api_studio_workspace_edit');
        [$status, $headers] = (new ConcurrencyConflictSubscriber())->answerFor($request, new StaleEditException());
        self::assertSame(409, $status);
        self::assertSame('CONFLICT_001', $headers['X-Conflict-Code']);
        self::assertArrayHasKey('Retry-After', $headers);

        // Work committed earlier in the request: read again, do not repeat (no Retry-After).
        $committed          = new ConcurrencyConflict(1, TransientFailureKind::Deadlock, 1, new RuntimeException('x'), true);
        [$status, $headers] = (new ConcurrencyConflictSubscriber())->answerFor(new Request(), new StaleEditException($committed));
        self::assertSame(409, $status);
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_002'], $headers);
    }

    /**
     * @return array{ApiWorkspace, ApiService, ApiEndpoint, ApiEnvironment}
     */
    private function graph(): array
    {
        $workspace   = new ApiWorkspace('Insurers', 'insurers');
        $service     = (new ApiService('Mapfre', 'mapfre'))->setWorkspace($workspace);
        $endpoint    = (new ApiEndpoint('Quote', 'quote'))->setService($service);
        $environment = (new ApiEnvironment('Production', 'prod'))->setWorkspace($workspace);
        $endpoint->addTranslation((new ApiEndpointTranslation('en'))->setTitle('Quote'));
        foreach ([$workspace, $service, $endpoint, $environment] as $entity) {
            $this->manager->persist($entity);
        }
        $this->manager->flush();

        return [$workspace, $service, $endpoint, $environment];
    }

    private function stored(ApiWorkspace|ApiService|ApiEndpoint|ApiEnvironment $row): ?int
    {
        return RowVersionGuard::storedVersion($this->manager, $row);
    }
}
