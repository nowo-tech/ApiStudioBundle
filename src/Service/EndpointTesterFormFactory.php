<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Service;

use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Form\ClientSideFieldsType;
use Nowo\ApiStudioBundle\Form\KeyValueRowType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;

use function is_scalar;

/**
 * Builds the client-side controls of the endpoint tester page (REQ-TWIG-005).
 *
 * Nothing here is submitted by the bundle: api-tester.js / api-endpoint-doc.js read the controls by id or class and
 * call the JSON endpoints. The form only renders them through Symfony Form views with the historical ids / classes.
 */
final readonly class EndpointTesterFormFactory
{
    public function __construct(
        private FormFactoryInterface $formFactory,
    ) {
    }

    /**
     * @param iterable<ApiEnvironment> $environments
     * @param array<string, string> $mergedHeaders
     */
    public function create(ApiEndpoint $endpoint, ApiService $service, iterable $environments, array $mergedHeaders): FormView
    {
        $environmentChoices = [];
        $defaultEnvironment = null;
        foreach ($environments as $environment) {
            $environmentChoices[$environment->getName()] = (string) $environment->getId();
            if ($environment->isDefault()) {
                $defaultEnvironment = (string) $environment->getId();
            }
        }

        $methods = [];
        foreach (HttpMethod::values() as $method) {
            $methods[$method] = $method;
        }

        $fields = [
            'environment' => [ChoiceType::class, [
                'html_id'                   => 'tester-environment',
                'label'                     => false,
                'choices'                   => $environmentChoices,
                'choice_translation_domain' => false,
                'placeholder'               => 'tester.no_environment',
                'data'                      => $defaultEnvironment,
                'attr'                      => ['class' => 'as-select', 'title' => 'tester.environment'],
            ]],
            'docTitle' => [TextType::class, [
                'html_id' => 'endpoint-doc-title-input',
                // Placeholder is the endpoint name (not a translation key); the template passes the translated label.
                'label'              => 'endpoint.doc_title',
                'translation_domain' => false,
                'label_attr'         => ['class' => 'as-modal-label'],
                'attr'               => ['class' => 'as-input', 'maxlength' => 255, 'placeholder' => $endpoint->getName()],
            ]],
            'docDescription' => [TextareaType::class, [
                'html_id'    => 'endpoint-doc-desc-input',
                'label'      => 'endpoint.doc_description',
                'label_attr' => ['class' => 'as-modal-label'],
                'attr'       => ['class' => 'as-code-editor', 'rows' => 4, 'placeholder' => 'endpoint.doc_description_placeholder'],
            ]],
            'method' => [ChoiceType::class, [
                'html_id'                   => 'tester-method',
                'label'                     => false,
                'required'                  => true,
                'choices'                   => $methods,
                'choice_translation_domain' => false,
                'data'                      => $endpoint->getMethod()->value,
                'attr'                      => ['class' => 'as-url-method-select', 'title' => 'tester.http_method'],
            ]],
            'urlDisplay' => [TextType::class, [
                'html_id'            => 'tester-url-display',
                'label'              => false,
                'data'               => $service->getBaseUrl() . $endpoint->getPath(),
                'translation_domain' => false,
                'attr'               => ['class' => 'as-url-input', 'readonly' => 'readonly'],
            ]],
            'persistVariables' => [CheckboxType::class, [
                'html_id' => 'tester-persist-vars',
                'label'   => 'tester.persist_variables',
            ]],
            'body' => [TextareaType::class, [
                'html_id' => 'tester-body',
                'label'   => false,
                'data'    => $endpoint->getRequestBodyTemplate(),
                'attr'    => ['class' => 'as-code-editor', 'rows' => 12, 'placeholder' => 'tester.request_body'],
            ]],
            'params'    => [CollectionType::class, $this->keyValueOptions($endpoint->getQueryParams())],
            'headers'   => [CollectionType::class, $this->keyValueOptions($mergedHeaders)],
            'preScript' => [TextareaType::class, [
                'html_id'            => 'tester-pre-script',
                'label'              => false,
                'data'               => $endpoint->getPreRequestScript(),
                'translation_domain' => false,
                'attr'               => ['class' => 'as-code-editor', 'rows' => 10, 'placeholder' => "// pm.environment.set('token', '...')"],
            ]],
            'postScript' => [TextareaType::class, [
                'html_id'            => 'tester-post-script',
                'label'              => false,
                'data'               => $endpoint->getPostRequestScript(),
                'translation_domain' => false,
                'attr'               => ['class' => 'as-code-editor', 'rows' => 10, 'placeholder' => "// const json = pm.response.json(); pm.environment.set('token', json.data.token);"],
            ]],
            'exampleName' => [TextType::class, [
                'html_id'    => 'tester-example-name',
                'label'      => 'examples.name_label',
                'label_attr' => ['class' => 'as-modal-label'],
                'attr'       => ['class' => 'as-input', 'maxlength' => 128, 'autocomplete' => 'off'],
            ]],
        ];

        return $this->formFactory->createNamed('', ClientSideFieldsType::class, null, ['fields' => $fields])->createView();
    }

    /**
     * @param array<mixed> $pairs
     *
     * @return array<string, mixed>
     */
    private function keyValueOptions(array $pairs): array
    {
        $rows = [];
        foreach ($pairs as $key => $value) {
            $rows[] = ['key' => (string) $key, 'value' => is_scalar($value) ? (string) $value : ''];
        }

        return [
            'label'         => false,
            'entry_type'    => KeyValueRowType::class,
            'entry_options' => ['label' => false],
            'data'          => $rows,
        ];
    }
}
