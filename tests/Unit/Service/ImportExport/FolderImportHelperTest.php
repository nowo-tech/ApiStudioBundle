<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service\ImportExport;

use Nowo\ApiStudioBundle\Service\ImportExport\FolderImportHelper;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * 1.14 audit RJ-L4: names from imported documents.
 */
final class FolderImportHelperTest extends TestCase
{
    public function testCleanNameDropsControlZeroWidthAndBidiCharacters(): void
    {
        self::assertSame('Evilname', FolderImportHelper::cleanName("Evil\u{202E}\u{0000}name"));
        self::assertSame('ab', FolderImportHelper::cleanName("a\u{200B}\u{2066}\u{FEFF}\u{0085}\tb"));
        self::assertSame('Users', FolderImportHelper::cleanName("  Users\r\n"));
        self::assertSame('Folder', FolderImportHelper::cleanName("\u{202E}\u{0007}"));
        self::assertSame('Folder', FolderImportHelper::cleanName(['array']));
        self::assertSame('Request', FolderImportHelper::cleanName(null, 'Request'));
        self::assertSame(str_repeat('é', 128), FolderImportHelper::cleanName(str_repeat('é', 200)));
    }
}
