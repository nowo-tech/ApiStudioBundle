<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Twig;

use Nowo\ApiStudioBundle\Form\ClientSideFieldsType;
use Nowo\ApiStudioBundle\Form\CsrfOnlyType;
use Nowo\ApiStudioBundle\Form\ImportFileFormType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\FormView;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Form views for the API Studio layout partials and CSRF-only actions (REQ-TWIG-005).
 *
 * Layout partials (sidebar filter, service export / import / delete modals) and `_delete_confirm.html.twig` are
 * included from many pages, so they build their Symfony Forms here instead of relying on every controller:
 *
 * - api_studio_csrf_form(action, csrfTokenId) — flat `_token` CSRF-only POST form
 * - api_studio_tree_filter_form() — sidebar tree filter (client-side, id `as-tree-filter`; rendered without a form element)
 * - api_studio_service_modal_forms() — `export_format`, `import_format`, `import` (ImportFileFormType named
 *   `import_file_form`) and `delete` (`_token` / `redirect` filled by api-studio-shell.js, `confirmation`)
 */
final class ApiStudioFormsExtension extends AbstractExtension
{
    public function __construct(
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('api_studio_csrf_form', $this->csrfForm(...)),
            new TwigFunction('api_studio_tree_filter_form', $this->treeFilterForm(...)),
            new TwigFunction('api_studio_service_modal_forms', $this->serviceModalForms(...)),
        ];
    }

    public function csrfForm(string $action, string $csrfTokenId): FormView
    {
        return $this->formFactory->createNamed('', CsrfOnlyType::class, null, [
            'action'        => $action,
            'csrf_token_id' => $csrfTokenId,
        ])->createView();
    }

    public function treeFilterForm(): FormView
    {
        return $this->clientSide([
            'search' => [SearchType::class, [
                'html_id' => 'as-tree-filter',
                'label'   => false,
                'attr'    => [
                    'class'        => 'as-sidebar-search',
                    'placeholder'  => 'sidebar.filter',
                    'autocomplete' => 'off',
                ],
            ]],
        ]);
    }

    /**
     * @return array{export_format: FormView, import_format: FormView, import: FormView, delete: FormView}
     */
    public function serviceModalForms(): array
    {
        return [
            'export_format' => $this->clientSide([
                'as-export-format' => [ChoiceType::class, $this->formatChoiceOptions(['export.format.openapi' => 'openapi'])],
            ]),
            'import_format' => $this->clientSide([
                'as-import-format' => [ChoiceType::class, $this->formatChoiceOptions([
                    'import.format.openapi' => 'openapi',
                    'import.format.postman' => 'postman',
                ])],
            ]),
            'import' => $this->formFactory->createNamed('import_file_form', ImportFileFormType::class, null, [
                'show_postman_variables' => true,
            ])->createView(),
            'delete' => $this->clientSide([
                '_token'       => [HiddenType::class, ['html_id' => 'as-service-delete-csrf']],
                'redirect'     => [HiddenType::class, ['html_id' => 'as-service-delete-redirect']],
                'confirmation' => [TextType::class, [
                    'html_id' => 'as-service-delete-input',
                    'label'   => false,
                    'attr'    => ['class' => 'as-input', 'autocomplete' => 'off', 'spellcheck' => 'false'],
                ]],
            ]),
        ];
    }

    /**
     * @param array<string, string> $choices
     *
     * @return array<string, mixed>
     */
    private function formatChoiceOptions(array $choices): array
    {
        return [
            'choices'     => $choices,
            'expanded'    => true,
            'required'    => true,
            'placeholder' => false,
            'label'       => false,
            'data'        => 'openapi',
        ];
    }

    /**
     * @param array<string, array{0: class-string<FormTypeInterface<mixed>>, 1?: array<string, mixed>}> $fields
     */
    private function clientSide(array $fields): FormView
    {
        return $this->formFactory->createNamed('', ClientSideFieldsType::class, null, ['fields' => $fields])->createView();
    }
}
