<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Integration\UserKit;

use InvalidArgumentException;
use Nowo\ApiStudioBundle\Integration\UserKit\ApiStudioUserKitEraser;
use Nowo\ApiStudioBundle\Integration\UserKit\ApiStudioUserKitExportProvider;
use Nowo\ApiStudioBundle\Security\ApiStudioActorResolverInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioHistoryScope;
use Nowo\ApiStudioBundle\Tests\Support\InMemoryLogger;
use Nowo\ApiStudioBundle\Tests\Support\RecordingPersonalDataPorts;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\User\InMemoryUser;
use UnexpectedValueException;

/**
 * 1.24 UserKit bridges: the eraser erases every actor id of the person (the original identifier too, so a host
 * identity eraser that ran first does not hide the rows), the export section merges them; keys and priorities.
 */
final class ApiStudioUserKitBridgesTest extends TestCase
{
    public function testTheEraserErasesTheOriginalAndTheCurrentActorIds(): void
    {
        $eraser = new RecordingPersonalDataPorts();
        $bridge = new ApiStudioUserKitEraser($eraser, new ApiStudioHistoryScope('user'));

        // UserKit ran the host identity eraser first: the identifier is anonymised, the original kept in the request.
        $bridge->erase(new ErasureRequest(new InMemoryUser('deleted-7', null), null, [], 'ana@example.test'));

        self::assertSame(['u:ana@example.test', 'u:deleted-7'], $eraser->erased);
        self::assertSame('api_studio', $bridge->getKey());
        self::assertTrue($bridge->supports(new stdClass()));
    }

    public function testWithAHostResolverOnlyItsIdIsUsed(): void
    {
        $eraser   = new RecordingPersonalDataPorts();
        $resolver = new class implements ApiStudioActorResolverInterface {
            public function actorId(object $user): string
            {
                return 'u:0b9e2f3c-uuid';
            }
        };
        (new ApiStudioUserKitEraser($eraser, new ApiStudioHistoryScope('user', $resolver)))
            ->erase(new ErasureRequest(new InMemoryUser('deleted-7', null), null, [], 'ana@example.test'));

        self::assertSame(['u:0b9e2f3c-uuid'], $eraser->erased);
    }

    /**
     * Audit ZCL-H1: default config (history.scope endpoint), identifiers with a space or non-ASCII characters.
     */
    public function testIdentifiersWithSpacesAndNonAsciiReachThePorts(): void
    {
        $ports = new RecordingPersonalDataPorts();
        (new ApiStudioUserKitEraser($ports, new ApiStudioHistoryScope()))
            ->erase(new ErasureRequest(new InMemoryUser('erased-1', null), null, [], 'John Smith'));
        (new ApiStudioUserKitExportProvider($ports, new ApiStudioHistoryScope()))->export(new InMemoryUser('josé@example.test', null));

        self::assertSame(['u:John Smith', 'u:erased-1'], $ports->erased);
        self::assertSame(['u:josé@example.test'], $ports->exported);
    }

    public function testAMalformedHostResolverIdIsLoggedAndSkipped(): void
    {
        $ports    = new RecordingPersonalDataPorts();
        $logger   = new InMemoryLogger();
        $resolver = new class implements ApiStudioActorResolverInterface {
            public function actorId(object $user): string
            {
                return 'has space';
            }
        };
        $scope = new ApiStudioHistoryScope('user', $resolver);

        (new ApiStudioUserKitEraser($ports, $scope, $logger))->erase(new ErasureRequest(new InMemoryUser('ana', null)));
        $section = (new ApiStudioUserKitExportProvider($ports, $scope, $logger))->export(new InMemoryUser('ana', null));

        self::assertSame([], $ports->erased);
        self::assertSame(['history' => [], 'folders_created' => []], $section);
        self::assertCount(2, $logger->records);
        self::assertSame(['exception' => UnexpectedValueException::class], $logger->records[0]['context']);
        self::assertStringNotContainsString('has space', $logger->text());
    }

    /**
     * Audit ZCL-v-L1: an id the port refuses is logged by class and skipped, never thrown into UserKit.
     */
    public function testAnIdThePortRefusesIsLoggedAndSkipped(): void
    {
        $ports         = new RecordingPersonalDataPorts();
        $ports->refuse = 'u:refused';
        $logger        = new InMemoryLogger();

        (new ApiStudioUserKitEraser($ports, new ApiStudioHistoryScope(), $logger))
            ->erase(new ErasureRequest(new InMemoryUser('ana', null), null, [], 'refused'));
        $section = (new ApiStudioUserKitExportProvider($ports, new ApiStudioHistoryScope(), $logger))->export(new InMemoryUser('refused', null));

        self::assertSame(['u:refused', 'u:ana'], $ports->erased);
        self::assertSame(['history' => [], 'folders_created' => []], $section);
        self::assertCount(2, $logger->records);
        self::assertSame(['exception' => InvalidArgumentException::class], $logger->records[1]['context']);
        self::assertStringNotContainsString('refused', $logger->text());
    }

    public function testTheSameIdentifierIsErasedOnce(): void
    {
        $eraser = new RecordingPersonalDataPorts();
        (new ApiStudioUserKitEraser($eraser, new ApiStudioHistoryScope()))
            ->erase(new ErasureRequest(new InMemoryUser('ana@example.test', null)));

        self::assertSame(['u:ana@example.test'], $eraser->erased);
    }

    public function testTheExportSectionMergesTheActorIds(): void
    {
        $ports  = new RecordingPersonalDataPorts();
        $bridge = new ApiStudioUserKitExportProvider($ports, new ApiStudioHistoryScope('user'));

        $section = $bridge->export(new InMemoryUser('ana@example.test', null));

        self::assertSame(['u:ana@example.test'], $ports->exported);
        self::assertSame(['history' => [RecordingPersonalDataPorts::row(1)], 'folders_created' => []], $section);
        self::assertSame('api_studio', $bridge->getSectionKey());
        self::assertTrue($bridge->supports(new stdClass()));
        self::assertSame(['history' => [], 'folders_created' => []], $bridge->export(new stdClass()), 'no identifier, nothing to export');
    }
}
