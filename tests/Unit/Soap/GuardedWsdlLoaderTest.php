<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Soap;

use Nowo\ApiStudioBundle\Security\ExecutionUrlValidator;
use Nowo\ApiStudioBundle\Soap\GuardedWsdlLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function file_get_contents;
use function sprintf;

/**
 * Edge cases of the WSDL/XSD download graph (cycles, document cap, redirects to blocked hosts).
 */
final class GuardedWsdlLoaderTest extends TestCase
{
    private const ROOT = 'https://93.184.215.14/service.wsdl';

    public function testImportCyclesResolveToTheAlreadyDownloadedFile(): void
    {
        $calls  = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$calls): MockResponse {
            $calls[] = $url;

            return new MockResponse($url === self::ROOT
                ? self::document('types.xsd')
                : self::document('service.wsdl'));
        });

        $loader = $this->loader($client);
        $path   = $loader->load(self::ROOT);

        self::assertSame([self::ROOT, 'https://93.184.215.14/types.xsd'], $calls);
        self::assertStringContainsString('api_studio_wsdl_', (string) file_get_contents($path));
        $loader->cleanup();
    }

    public function testTooManyImportedDocumentsAreRejected(): void
    {
        $imports = '';
        for ($i = 0; $i < 25; ++$i) {
            $imports .= sprintf('<xsd:import schemaLocation="part%d.xsd"/>', $i);
        }
        $client = new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse($url === self::ROOT
            ? '<definitions xmlns:xsd="http://www.w3.org/2001/XMLSchema">' . $imports . '</definitions>'
            : '<schema/>'));

        $loader = $this->loader($client);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('too many documents');
            $loader->load(self::ROOT);
        } finally {
            $loader->cleanup();
        }
    }

    public function testRedirectToABlockedHostIsRejected(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getInfo')->willReturn('https://10.0.0.5/internal.wsdl');
        $client = $this->createStub(HttpClientInterface::class);
        $client->method('request')->willReturn($response);

        $loader = $this->loader($client);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('not allowed');
            $loader->load(self::ROOT);
        } finally {
            $loader->cleanup();
        }
    }

    private function loader(HttpClientInterface $client): GuardedWsdlLoader
    {
        return new GuardedWsdlLoader($client, new ExecutionUrlValidator([]), 5, 0);
    }

    private static function document(string $import): string
    {
        return '<definitions xmlns:xsd="http://www.w3.org/2001/XMLSchema"><xsd:import schemaLocation="' . $import . '"/></definitions>';
    }
}
