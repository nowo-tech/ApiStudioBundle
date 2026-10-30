<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Integration\Form;

use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEndpointTranslation;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Enum\ApiProtocol;
use Nowo\ApiStudioBundle\Enum\AuthType;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Form\ApiEndpointFormType;
use Nowo\ApiStudioBundle\Form\ApiEndpointTranslationFormType;
use Nowo\ApiStudioBundle\Form\ApiEnvironmentFormType;
use Nowo\ApiStudioBundle\Form\ApiServiceFormType;
use Nowo\ApiStudioBundle\Form\ApiWorkspaceFormType;
use Nowo\ApiStudioBundle\Form\ClientSideFieldsType;
use Nowo\ApiStudioBundle\Form\CsrfOnlyType;
use Nowo\ApiStudioBundle\Form\ImportFileFormType;
use Nowo\ApiStudioBundle\Form\JsonMapType;
use Nowo\ApiStudioBundle\Form\KeyValueRowType;
use Nowo\ApiStudioBundle\Tests\Integration\App\IntegrationKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The bundle form types build, map and transform their data inside a real kernel (FormKit defaults, CSRF, validation).
 */
final class FormTypesTest extends KernelTestCase
{
    private FormFactoryInterface $factory;

    protected static function getKernelClass(): string
    {
        return IntegrationKernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $factory);
        $this->factory = $factory;
    }

    public function testWorkspaceForm(): void
    {
        $workspace = new ApiWorkspace('Old', 'old');
        $form      = $this->factory->create(ApiWorkspaceFormType::class, $workspace, ['csrf_protection' => false]);
        $form->submit(['name' => 'Shop', 'slug' => 'shop', 'description' => 'Store APIs', 'enabled' => false]);

        self::assertTrue($form->isSynchronized());
        self::assertSame('Shop', $workspace->getName());
        self::assertSame('Store APIs', $workspace->getDescription());
        self::assertFalse($workspace->isEnabled());
    }

    public function testServiceFormMapsEnumsAndJsonHeaders(): void
    {
        $service = new ApiService('Old', 'old');
        $form    = $this->factory->create(ApiServiceFormType::class, $service, ['csrf_protection' => false]);
        $form->submit([
            'name'           => 'Orders',
            'slug'           => 'orders',
            'baseUrl'        => '{{base_url}}',
            'defaultHeaders' => '{"Accept": "application/json", "X-Retry": 3, "X-Obj": {"a": 1}, "": "skip"}',
            'protocol'       => 'graphql',
            'authType'       => 'bearer',
            'enabled'        => true,
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertSame(ApiProtocol::Graphql, $service->getProtocol());
        self::assertSame(AuthType::Bearer, $service->getAuthType());
        self::assertSame(['Accept' => 'application/json', 'X-Retry' => '3', 'X-Obj' => '{"a":1}'], $service->getDefaultHeaders());
    }

    public function testJsonMapTypeTransformsBothWays(): void
    {
        $form = $this->factory->create(JsonMapType::class, ['Authorization' => 'Bearer {{token}}']);
        self::assertStringContainsString('"Authorization": "Bearer {{token}}"', (string) $form->createView()->vars['value']);
        self::assertSame('{}', (string) $this->factory->create(JsonMapType::class, [])->createView()->vars['value']);
        self::assertSame('{}', (string) $this->factory->create(JsonMapType::class, null)->createView()->vars['value']);

        $empty = $this->factory->create(JsonMapType::class);
        $empty->submit('   ');
        self::assertSame([], $empty->getData());

        $invalid = $this->factory->create(JsonMapType::class);
        $invalid->submit('[1, 2');
        self::assertFalse($invalid->isSynchronized());
        self::assertSame('Invalid JSON object.', $invalid->getTransformationFailure()?->getInvalidMessage());
    }

    public function testEndpointFormWithLocaleTabsAndTranslations(): void
    {
        $endpoint = new ApiEndpoint('Old', 'old');
        $endpoint->addTranslation(new ApiEndpointTranslation('en'));
        $form = $this->factory->create(ApiEndpointFormType::class, $endpoint, ['csrf_protection' => false]);

        $form->submit([
            'name'         => 'Create order',
            'slug'         => 'create_order',
            'method'       => 'POST',
            'path'         => '/orders',
            'contentType'  => 'application/json',
            'headers'      => '{"Accept": "application/json"}',
            'queryParams'  => '',
            'sortOrder'    => '3',
            'enabled'      => true,
            'translations' => [['locale' => 'en', 'title' => 'Create an order']],
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertSame(HttpMethod::Post, $endpoint->getMethod());
        self::assertSame(3, $endpoint->getSortOrder());
        self::assertSame(['Accept' => 'application/json'], $endpoint->getHeaders());
        self::assertSame([], $endpoint->getQueryParams());
        self::assertSame('Create an order', $endpoint->getTranslation('en')?->getTitle());
        self::assertTrue($form->has('translations'));
    }

    public function testTranslationFormAlone(): void
    {
        $translation = new ApiEndpointTranslation('es');
        $form        = $this->factory->create(ApiEndpointTranslationFormType::class, $translation, ['csrf_protection' => false]);
        $form->submit(['locale' => 'es', 'title' => 'Listar', 'description' => 'Lista', 'notes' => 'Notas']);

        self::assertSame('Notas', $translation->getNotes());
    }

    public function testEnvironmentFormNormalizesVariableKeys(): void
    {
        $environment = new ApiEnvironment('Dev', 'dev');
        $environment->addVariable(new ApiEnvironmentVariable('base_url', 'https://old.example.test'));
        $form = $this->factory->create(ApiEnvironmentFormType::class, $environment, ['csrf_protection' => false]);
        self::assertSame('{{base_url}}', $form->createView()->children['variables']->children[0]->children['variableKey']->vars['value']);

        $form->submit([
            'name'      => 'Dev',
            'slug'      => 'dev',
            'isDefault' => true,
            'variables' => [
                ['variableKey' => '{{ base_url }}', 'value' => 'https://dev.example.test'],
                ['variableKey' => 'token', 'value' => 'REDACTED-TEST', 'secret' => true, 'description' => 'API token'],
            ],
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($environment->isDefault());
        self::assertSame(['base_url' => 'https://dev.example.test', 'token' => 'REDACTED-TEST'], $environment->getVariableMap());
    }

    public function testEnvironmentVariableKeyTransformerHandlesEmptyValues(): void
    {
        $environment = new ApiEnvironment('Dev', 'dev');
        $environment->addVariable(new ApiEnvironmentVariable('kept'));
        $form = $this->factory->create(ApiEnvironmentFormType::class, $environment, ['csrf_protection' => false]);
        $form->submit(['name' => 'Dev', 'slug' => 'dev', 'variables' => [
            ['variableKey' => '  ', 'value' => ''],
            ['variableKey' => 'not a key!', 'value' => 'x'],
        ]]);

        self::assertFalse($form->isValid());
        $variables = $form->get('variables');
        self::assertFalse($variables->get('0')->get('variableKey')->isSynchronized());
        self::assertFalse($variables->get('1')->get('variableKey')->isSynchronized());
        self::assertSame('kept', $environment->getVariables()->first() ? $environment->getVariables()->first()->getVariableKey() : null);
    }

    public function testImportFileFormOptions(): void
    {
        $plain = $this->factory->create(ImportFileFormType::class);
        self::assertTrue($plain->has('file'));
        self::assertFalse($plain->has('mode'));
        self::assertFalse($plain->has('importVariables'));

        $full = $this->factory->create(ImportFileFormType::class, null, [
            'import_kind'            => 'variables',
            'allowed_extensions'     => ['env'],
            'show_mode'              => true,
            'show_postman_variables' => true,
        ]);
        self::assertSame('merge', $full->get('mode')->getData());
        self::assertTrue($full->get('importVariables')->getData());
        self::assertTrue($full->has('submit'));
    }

    public function testCsrfOnlyAndClientSideFieldTypes(): void
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack = self::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $requestStack);
        $requestStack->push($request);

        $csrf = $this->factory->create(CsrfOnlyType::class, null, ['csrf_token_id' => 'delete42']);
        self::assertSame('POST', $csrf->getConfig()->getMethod());
        self::assertArrayHasKey('_token', $csrf->createView()->children);
        self::assertSame('', $csrf->getConfig()->getType()->getBlockPrefix());

        $fields = $this->factory->create(ClientSideFieldsType::class, null, ['fields' => [
            'url'  => [TextType::class, ['html_id' => 'as-url', 'attr' => ['placeholder' => 'https://']]],
            'note' => [TextType::class],
        ]]);
        $view = $fields->createView();
        self::assertSame('as-url', $view->children['url']->vars['id']);
        self::assertSame('note', $view->children['note']->vars['id']);
        self::assertFalse($fields->get('url')->getConfig()->getMapped());
        self::assertFalse($fields->getConfig()->getOption('csrf_protection'));

        $row = $this->factory->create(KeyValueRowType::class);
        self::assertTrue($row->get('enabled')->getData());
        self::assertTrue($row->has('key'));
        self::assertTrue($row->has('value'));
    }
}
