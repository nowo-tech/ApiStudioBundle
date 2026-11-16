<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional;

/**
 * Spec 003 (1.15): the pm.sendRequest endpoint exists only in sandbox mode (404 in the test kernel's inline mode) and
 * needs the console token.
 */
final class ScriptSendRequestTest extends StudioWebTestCase
{
    public function testNotServedOutsideSandboxMode(): void
    {
        $this->client->request('POST', '/api-studio/scripts/send-request/' . $this->ids['endpoint'], server: ['CONTENT_TYPE' => 'application/json'], content: '{"url":"https://93.184.215.14/"}');
        self::assertResponseStatusCodeSame(404);

        $this->client->catchExceptions(true);
        $this->client->request('GET', '/api-studio/scripts/sandbox');
        self::assertResponseStatusCodeSame(404);
    }
}
