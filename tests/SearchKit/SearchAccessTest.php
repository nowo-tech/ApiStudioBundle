<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\SearchKit;

use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Search\SearchAccess;
use Nowo\ApiStudioBundle\Search\SearchKind;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\FolderAccess;
use Nowo\ApiStudioBundle\Tests\Support\SearchFixtures;
use Nowo\ApiStudioBundle\Tests\Support\SearchFolderDouble;
use PHPUnit\Framework\TestCase;

/**
 * Edge rows of the batched checks: a folder the tree does not know is hidden (fail closed), no folder repository
 * means no folder rows, nothing to check means no query.
 */
final class SearchAccessTest extends TestCase
{
    use SkipsWithoutSearchKit;
    use SearchFixtures;

    protected function setUp(): void
    {
        $this->skipWithoutSearchKit();
    }

    public function testFailsClosedOnUnknownFolders(): void
    {
        $registry = $this->sqliteSearchRegistry();
        $this->seedSearchData($registry);
        $id     = $this->searchIds['Payments'];
        $access = new SearchAccess(new ApiStudioAuthorization(), new ApiWorkspaceRepository($registry), null, new FolderAccess(new SearchFolderDouble()));
        $rows   = [
            ['wid' => $id, 'sid' => 1, 'efolder' => 999_999],
            ['wid' => (string) $id, 'sid' => 1, 'efolder' => null],
            ['wid' => null, 'sid' => 1, 'efolder' => null],
        ];

        self::assertSame([$rows[1]], $access->visible(SearchKind::Endpoint, $rows));
        self::assertSame([], $access->visible(SearchKind::Endpoint, []));
        self::assertSame([$rows[0], $rows[1]], $access->visible(SearchKind::Workspace, $rows), 'folders only narrow endpoints');
        self::assertSame([], (new ApiWorkspaceRepository($registry))->findByIds([]));
    }
}
