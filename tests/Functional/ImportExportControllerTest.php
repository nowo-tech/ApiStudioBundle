<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function str_starts_with;
use function strtr;
use function substr;

/**
 * Import (upload forms) and export (downloads) routes of ImportExportController.
 */
final class ImportExportControllerTest extends StudioWebTestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures/import/';

    public function testHubRenders(): void
    {
        $crawler = $this->client->request('GET', $this->workspaceUrl('/import-export'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="api-studio-io-hub"]'));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function imports(): iterable
    {
        yield 'openapi into workspace' => ['/import/openapi', 'openapi.json', 'workspace', ''];
        yield 'postman into workspace' => ['/import/postman', 'postman.json', 'workspace', ''];
        yield 'variables into workspace' => ['/import/variables', 'variables.json', 'workspace', ''];
        yield 'openapi into service' => ['/services/{service}/import/openapi', 'openapi.json', 'service', ''];
        yield 'postman into service' => ['/services/{service}/import/postman', 'postman.json', 'service', ''];
        yield 'variables into environment' => ['/environments/{environment}/import/variables', 'variables.env', 'environment', 'replace'];
    }

    #[DataProvider('imports')]
    public function testImportUploadsTheFileAndRedirects(string $path, string $file, string $target, string $mode): void
    {
        $url     = $this->workspaceUrl(strtr($path, ['{service}' => (string) $this->ids['service'], '{environment}' => (string) $this->ids['environment']]));
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="api-studio-import"]'));

        $form   = $crawler->filter('form[name="import_file_form"]')->form();
        $upload = $form['import_file_form[file]'];
        self::assertInstanceOf(FileFormField::class, $upload);
        $upload->upload(self::FIXTURES . $file);
        if ($mode !== '') {
            $choice = $form['import_file_form[mode]'];
            self::assertInstanceOf(ChoiceFormField::class, $choice);
            $choice->select($mode);
        }
        $this->client->submit($form);

        $expected = match ($target) {
            'service'     => $this->serviceUrl(),
            'environment' => $this->environmentUrl(),
            default       => $this->workspaceUrl(),
        };
        self::assertResponseRedirects($expected);
        $this->client->followRedirect();
        self::assertStringContainsString('Import complete:', (string) $this->client->getResponse()->getContent());
    }

    public function testImportFailureShowsTheErrorAndKeepsTheForm(): void
    {
        $crawler = $this->client->request('GET', $this->workspaceUrl('/import/openapi'));
        $form    = $crawler->filter('form[name="import_file_form"]')->form();
        $upload  = $form['import_file_form[file]'];
        self::assertInstanceOf(FileFormField::class, $upload);
        $upload->upload(self::FIXTURES . 'not-openapi.json');
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Import failed. Check file format and try again.', (string) $this->client->getResponse()->getContent());
    }

    public function testImportWithoutFileIsInvalid(): void
    {
        $crawler = $this->client->request('GET', $this->workspaceUrl('/import/postman'));
        $this->client->submit($crawler->filter('form[name="import_file_form"]')->form());

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string, non-empty-string}>
     */
    public static function exports(): iterable
    {
        yield 'workspace openapi' => ['/export/openapi', 'application/json', 'demo-openapi.json'];
        yield 'workspace variables json' => ['/export/variables.json', 'application/json', 'demo-variables.json'];
        yield 'workspace variables yaml' => ['/export/variables.yaml', 'application/x-yaml', 'demo-variables.yaml'];
        yield 'workspace variables env' => ['/export/variables.env', 'text/plain', 'demo-variables.env'];
        yield 'service openapi' => ['/services/{service}/export/openapi', 'application/json', '-openapi.json'];
        yield 'environment variables json' => ['/environments/{environment}/export/variables.json', 'application/json', '-variables.json'];
        yield 'environment variables yaml' => ['/environments/{environment}/export/variables.yaml', 'application/x-yaml', '-variables.yaml'];
        yield 'environment variables env' => ['/environments/{environment}/export/variables.env', 'text/plain', '-variables.env'];
    }

    /**
     * @param non-empty-string $mime
     */
    #[DataProvider('exports')]
    public function testExportDownloadsAnAttachment(string $path, string $mime, string $filenameSuffix): void
    {
        $this->client->request('GET', $this->workspaceUrl(strtr($path, ['{service}' => (string) $this->ids['service'], '{environment}' => (string) $this->ids['environment']])));

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertStringStartsWith($mime, (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString($filenameSuffix, (string) $response->headers->get('Content-Disposition'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notFound(): iterable
    {
        yield 'unknown workspace' => ['/api-studio/workspaces/999999/import-export'];
        yield 'service of another workspace' => ['{other}/services/{service}/export/openapi'];
        yield 'environment of another workspace' => ['{other}/environments/{environment}/export/variables.json'];
    }

    #[DataProvider('notFound')]
    public function testForeignOrUnknownIdsAreNotFound(string $path): void
    {
        $url = str_starts_with($path, '/') ? $path : $this->workspaceUrl(strtr(substr($path, 7), ['{service}' => (string) $this->ids['service'], '{environment}' => (string) $this->ids['environment']]), $this->ids['other']);

        $this->expectException(NotFoundHttpException::class);
        $this->client->request('GET', $url);
    }
}
