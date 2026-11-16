<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Script;

use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiFolder;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Script\Runner\ScriptSource;
use Nowo\ApiStudioBundle\Script\ScriptChainBuilder;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * Spec 003 US4 (1.15): run order and labels of the script chain outside the browser (same as the console).
 */
final class ScriptChainBuilderTest extends TestCase
{
    public function testEveryLevelInRunOrderWithItsLabel(): void
    {
        $endpoint = $this->endpoint();
        $builder  = new ScriptChainBuilder(scriptLevels: true);

        self::assertSame([
            'workspace pre: w-pre',
            'service pre: s-pre',
            'folder Users pre: f1-pre',
            'folder Admins pre: f2-pre',
            'pre-request: e-pre',
        ], $this->describe($builder->preRequest($endpoint)));
        self::assertSame([
            'post-request: e-post',
            'folder Admins post: f2-post',
            'folder Users post: f1-post',
            'service post: s-post',
            'workspace post: w-post',
        ], $this->describe($builder->postResponse($endpoint)));
    }

    public function testEndpointScriptOverrideAndEmptyScriptsLeftOut(): void
    {
        $endpoint = $this->endpoint();
        $endpoint->getService()?->setPreRequestScript('   ');
        $endpoint->getFolder()?->setPostRequestScript(null);
        $builder = new ScriptChainBuilder(scriptLevels: true);

        self::assertSame(['workspace pre: w-pre', 'folder Users pre: f1-pre', 'folder Admins pre: f2-pre', 'pre-request: edited'], $this->describe($builder->preRequest($endpoint, 'edited')));
        self::assertSame(['folder Users post: f1-post', 'service post: s-post', 'workspace post: w-post'], $this->describe($builder->postResponse($endpoint, '')));
    }

    public function testWithoutScriptLevelsOnlyServiceAndEndpointRun(): void
    {
        $endpoint = $this->endpoint();
        $builder  = new ScriptChainBuilder();

        self::assertSame(['service pre: s-pre', 'pre-request: e-pre'], $this->describe($builder->preRequest($endpoint)));
        self::assertSame(['post-request: e-post', 'service post: s-post'], $this->describe($builder->postResponse($endpoint)));
    }

    public function testEndpointWithoutServiceOrFolder(): void
    {
        $endpoint = (new ApiEndpoint('E', 'e'))->setPreRequestScript('only')->setPostRequestScript(null);
        $builder  = new ScriptChainBuilder(scriptLevels: true);

        self::assertSame(['pre-request: only'], $this->describe($builder->preRequest($endpoint)));
        self::assertSame([], $builder->postResponse($endpoint));
    }

    private function endpoint(): ApiEndpoint
    {
        $workspace = (new ApiWorkspace('W', 'w'))->setPreRequestScript('w-pre')->setPostRequestScript('w-post');
        $service   = (new ApiService('S', 's'))->setWorkspace($workspace)->setPreRequestScript('s-pre')->setPostRequestScript('s-post');
        $users     = (new ApiFolder('Users'))->setService($service)->setPreRequestScript('f1-pre')->setPostRequestScript('f1-post');
        $admins    = (new ApiFolder('Admins'))->setService($service)->setParent($users)->setPreRequestScript('f2-pre')->setPostRequestScript('f2-post');

        return (new ApiEndpoint('E', 'e'))->setService($service)->setFolder($admins)->setPreRequestScript('e-pre')->setPostRequestScript('e-post');
    }

    /**
     * @param list<ScriptSource> $chain
     *
     * @return list<string>
     */
    private function describe(array $chain): array
    {
        return array_map(static fn (ScriptSource $s): string => $s->label . ': ' . $s->code, $chain);
    }
}
