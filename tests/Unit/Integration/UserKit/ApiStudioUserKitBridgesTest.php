<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Integration\UserKit;

use Nowo\ApiStudioBundle\Integration\UserKit\ApiStudioUserKitEraser;
use Nowo\ApiStudioBundle\Integration\UserKit\ApiStudioUserKitExportProvider;
use Nowo\ApiStudioBundle\Security\ApiStudioActorResolverInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioHistoryScope;
use Nowo\ApiStudioBundle\Tests\Support\RecordingPersonalDataPorts;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\User\InMemoryUser;

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
