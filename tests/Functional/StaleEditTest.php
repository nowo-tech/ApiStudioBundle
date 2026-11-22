<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Entity\RowVersionedInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\InputFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function sprintf;

/**
 * 1.20 (REQ-RUNTIME-003 rule 4): the workspace, service, endpoint and environment edit forms save only on the row
 * version they were rendered from. A form saved after another save answers the domain stale-edit answer (1.21: 409 +
 * `X-Error-Code: API_STUDIO_STALE_EDIT`, never `X-Conflict-Code` and never `Retry-After` — the same save can only be
 * refused again), shows the house conflict message and the stored data, and writes nothing; saving that new form works.
 */
final class StaleEditTest extends StudioWebTestCase
{
    /**
     * @return iterable<string, array{class-string<RowVersionedInterface>, string, string}>
     */
    public static function pages(): iterable
    {
        yield 'workspace' => [ApiWorkspace::class, 'workspace', 'api_workspace_form'];
        yield 'service' => [ApiService::class, 'service', 'api_service_form'];
        yield 'endpoint' => [ApiEndpoint::class, 'endpoint', 'api_endpoint_form'];
        yield 'environment' => [ApiEnvironment::class, 'environment', 'api_environment_form'];
    }

    /**
     * @param class-string<RowVersionedInterface> $class
     */
    #[DataProvider('pages')]
    public function testAFormSavedAfterAnotherSaveIsRefused(string $class, string $page, string $name): void
    {
        $form    = $this->editForm($page, $name);
        $version = (int) $this->rowVersionField($form, $name)->getValue();
        $this->assertStoredVersion($class, $page, $version);

        // Another editor saves first (the same statement as a claim, plus its own change).
        $this->otherSave($class, $page, 'Saved by the other editor');

        $this->field($form, $name . '[name]')->setValue('Mine');
        $crawler = $this->client->submit($form);

        $this->assertStaleEditAnswer();
        self::assertStringContainsString('Someone else saved this item after you opened the form.', (string) $this->client->getResponse()->getContent());
        self::assertSame('Saved by the other editor', $this->storedName($class, $page));
        $this->assertStoredVersion($class, $page, $version + 1);

        // The form shows the stored data at the new version: saving it now works.
        $again = $crawler->filter(sprintf('form[name="%s"]', $name))->form();
        self::assertSame('Saved by the other editor', $this->field($again, $name . '[name]')->getValue());
        self::assertSame((string) ($version + 1), $this->rowVersionField($again, $name)->getValue());
        $this->field($again, $name . '[name]')->setValue('Mine, on top');
        $this->client->submit($again);
        self::assertResponseRedirects();
        self::assertSame('Mine, on top', $this->storedName($class, $page));
        $this->assertStoredVersion($class, $page, $version + 2);
    }

    public function testASaveMovesTheVersionSoAnOlderTabIsRefused(): void
    {
        $first  = $this->editForm('service', 'api_service_form');
        $second = $this->editForm('service', 'api_service_form');

        $this->client->submit($first);
        self::assertResponseRedirects();
        $this->client->submit($second);
        $this->assertStaleEditAnswer();
        $this->assertStoredVersion(ApiService::class, 'service', 2);
    }

    public function testAMalformedVersionIsRefusedAndAMissingOneUsesTheLoadedRow(): void
    {
        $form = $this->editForm('workspace', 'api_workspace_form');
        $this->rowVersionField($form, 'api_workspace_form')->setValue('1 OR 1=1');
        $this->client->submit($form);
        $this->assertStaleEditAnswer();
        $this->assertStoredVersion(ApiWorkspace::class, 'workspace', 1);

        // A template override that drops the hidden field: the version this request loaded (last writer wins).
        $form   = $this->editForm('workspace', 'api_workspace_form');
        $values = $form->getPhpValues();
        unset($values['api_workspace_form']['rowVersion']);
        $values['api_workspace_form']['name'] = 'No version';
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();
        self::assertSame('No version', $this->storedName(ApiWorkspace::class, 'workspace'));
    }

    public function testAnInvalidFormKeepsTheVersionItWasRenderedFrom(): void
    {
        $form = $this->editForm('service', 'api_service_form');
        $this->field($form, 'api_service_form[slug]')->setValue('Not A Slug!');
        $crawler = $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('1', $this->rowVersionField($crawler->filter('form[name="api_service_form"]')->form(), 'api_service_form')->getValue());
        $this->assertStoredVersion(ApiService::class, 'service', 1);
    }

    public function testAnEnvironmentSaveRefusedAsStaleChangesNoDefaultFlag(): void
    {
        $before = $this->defaultFlags();
        $form   = $this->editForm('environment', 'api_environment_form');
        $this->otherSave(ApiEnvironment::class, 'environment', 'Saved by the other editor');
        $isDefault = $form['api_environment_form[isDefault]'];
        self::assertInstanceOf(ChoiceFormField::class, $isDefault);
        $isDefault->tick();
        $this->client->submit($form);
        $this->assertStaleEditAnswer();

        self::assertSame($before, $this->defaultFlags(), 'the refused save changed no default flag');
    }

    /** @return array<int, bool> */
    private function defaultFlags(): array
    {
        $flags = [];
        foreach ($this->connection()->fetchAllAssociative(sprintf('SELECT id, is_default FROM %s ORDER BY id', $this->table(ApiEnvironment::class))) as $row) {
            $flags[(int) $row['id']] = (bool) $row['is_default'];
        }

        return $flags;
    }

    public function testDocumentationSavedMeanwhileRefusesTheEndpointForm(): void
    {
        $form    = $this->editForm('endpoint', 'api_endpoint_form');
        $version = (int) $this->rowVersionField($form, 'api_endpoint_form')->getValue();
        $crawler = $this->client->request('GET', $this->endpointUrl());
        $csrf    = (string) $crawler->filter('#endpoint-doc-panel')->attr('data-csrf');
        $this->client->request('POST', $this->endpointUrl('/documentation'), [], [], ['HTTP_X_CSRF_TOKEN' => $csrf, 'CONTENT_TYPE' => 'application/json'], '{"locale":"en","title":"Docs saved meanwhile"}');
        self::assertResponseIsSuccessful();
        $this->assertStoredVersion(ApiEndpoint::class, 'endpoint', $version + 1);

        $this->client->submit($form);
        $this->assertStaleEditAnswer();
    }

    public function testARowDeletedMeanwhileAnswers404(): void
    {
        $form = $this->editForm('environment', 'api_environment_form');
        $this->connection()->executeStatement(sprintf('DELETE FROM %s WHERE id = ?', $this->table(ApiEnvironment::class)), [$this->ids['environment']]);

        $this->expectException(NotFoundHttpException::class);
        $this->client->submit($form);
    }

    /**
     * The whole contract of a domain stale edit (1.21): 409, the `X-Error-Code` value, and neither the transaction
     * contract's `X-Conflict-Code` nor a `Retry-After` inviting a retry.
     */
    private function assertStaleEditAnswer(): void
    {
        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('X-Error-Code', 'API_STUDIO_STALE_EDIT');
        self::assertResponseNotHasHeader('X-Conflict-Code');
        self::assertResponseNotHasHeader('Retry-After');
    }

    private function editForm(string $page, string $name): Form
    {
        $url = match ($page) {
            'workspace' => $this->workspaceUrl('/edit'),
            'service'   => $this->serviceUrl('/edit'),
            'endpoint'  => $this->endpointUrl('/edit'),
            default     => $this->environmentUrl('/edit'),
        };
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler->filter(sprintf('form[name="%s"]', $name))->form();
    }

    private function rowVersionField(Form $form, string $name): InputFormField
    {
        return $this->field($form, $name . '[rowVersion]');
    }

    private function field(Form $form, string $name): InputFormField
    {
        $field = $form[$name];
        self::assertInstanceOf(InputFormField::class, $field);

        return $field;
    }

    /**
     * @param class-string<RowVersionedInterface> $class
     */
    private function otherSave(string $class, string $page, string $name): void
    {
        $changed = $this->connection()->executeStatement(
            sprintf('UPDATE %s SET row_version = row_version + 1, name = ? WHERE id = ?', $this->table($class)),
            [$name, $this->ids[$page]],
        );
        self::assertSame(1, $changed);
    }

    /**
     * @param class-string<RowVersionedInterface> $class
     */
    private function storedName(string $class, string $page): string
    {
        return (string) $this->connection()->fetchOne(sprintf('SELECT name FROM %s WHERE id = ?', $this->table($class)), [$this->ids[$page]]);
    }

    /**
     * @param class-string<RowVersionedInterface> $class
     */
    private function assertStoredVersion(string $class, string $page, int $expected): void
    {
        self::assertSame($expected, (int) $this->connection()->fetchOne(sprintf('SELECT row_version FROM %s WHERE id = ?', $this->table($class)), [$this->ids[$page]]));
    }

    /**
     * @param class-string $class
     */
    private function table(string $class): string
    {
        return $this->entityManager()->getClassMetadata($class)->getTableName();
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }
}
