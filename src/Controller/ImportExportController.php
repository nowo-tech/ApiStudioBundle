<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Form\ImportFileFormType;
use Nowo\ApiStudioBundle\Model\ImportResult;
use Nowo\ApiStudioBundle\Repository\ApiEnvironmentRepository;
use Nowo\ApiStudioBundle\Repository\ApiServiceRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Service\ImportExport\EnvironmentVariableExporter;
use Nowo\ApiStudioBundle\Service\ImportExport\EnvironmentVariableImporter;
use Nowo\ApiStudioBundle\Service\ImportExport\OpenApiExporter;
use Nowo\ApiStudioBundle\Service\ImportExport\OpenApiImporter;
use Nowo\ApiStudioBundle\Service\ImportExport\PostmanCollectionImporter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

#[Route(path: '/workspaces/{workspaceId}', name: 'nowo_api_studio_io_')]
final class ImportExportController extends AbstractController
{
    public function __construct(
        private readonly ApiWorkspaceRepository $workspaceRepository,
        private readonly ApiServiceRepository $serviceRepository,
        private readonly ApiEnvironmentRepository $environmentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly OpenApiImporter $openApiImporter,
        private readonly OpenApiExporter $openApiExporter,
        private readonly PostmanCollectionImporter $postmanImporter,
        private readonly EnvironmentVariableImporter $variableImporter,
        private readonly EnvironmentVariableExporter $variableExporter,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/import-export', name: 'hub', requirements: ['workspaceId' => '\d+'], methods: ['GET'])]
    public function hub(int $workspaceId): Response
    {
        return $this->render('@NowoApiStudioBundle/import_export/hub.html.twig', [
            'workspace' => $this->requireWorkspace($workspaceId),
        ]);
    }

    #[Route('/import/openapi', name: 'import_openapi', requirements: ['workspaceId' => '\d+'], methods: ['GET', 'POST'])]
    public function importOpenApi(Request $request, int $workspaceId): Response
    {
        $workspace = $this->requireWorkspace($workspaceId);
        $form      = $this->createImportForm('openapi');

        return $this->handleImport($request, $form, function (FormInterface $form) use ($workspace): ImportResult {
            return $this->openApiImporter->import(
                $workspace,
                $this->readUploadedFile($form),
                $this->uploadedFilename($form),
            );
        }, $workspace, 'import.openapi.title');
    }

    #[Route('/import/postman', name: 'import_postman', requirements: ['workspaceId' => '\d+'], methods: ['GET', 'POST'])]
    public function importPostman(Request $request, int $workspaceId): Response
    {
        $workspace = $this->requireWorkspace($workspaceId);
        $form      = $this->createImportForm('postman', showPostmanVariables: true);

        return $this->handleImport($request, $form, function (FormInterface $form) use ($workspace): ImportResult {
            $importVariables = $form->has('importVariables') ? (bool) $form->get('importVariables')->getData() : true;

            return $this->postmanImporter->import(
                $workspace,
                $this->readUploadedFile($form),
                $this->uploadedFilename($form),
                $importVariables,
            );
        }, $workspace, 'import.postman.title');
    }

    #[Route('/import/variables', name: 'import_variables_workspace', requirements: ['workspaceId' => '\d+'], methods: ['GET', 'POST'])]
    public function importVariablesWorkspace(Request $request, int $workspaceId): Response
    {
        $workspace = $this->requireWorkspace($workspaceId);
        $form      = $this->createImportForm('variables', showMode: true, extensions: ['json', 'yaml', 'yml', 'env']);

        return $this->handleImport($request, $form, function (FormInterface $form) use ($workspace): ImportResult {
            $mode = $form->has('mode') ? (string) $form->get('mode')->getData() : 'merge';

            return $this->variableImporter->importIntoWorkspace(
                $workspace,
                $this->readUploadedFile($form),
                $mode,
                $this->uploadedFilename($form),
            );
        }, $workspace, 'import.variables.workspace_title');
    }

    #[Route('/export/openapi', name: 'export_openapi_workspace', requirements: ['workspaceId' => '\d+'], methods: ['GET'])]
    public function exportOpenApiWorkspace(int $workspaceId): Response
    {
        $workspace = $this->requireWorkspace($workspaceId);
        $json      = $this->openApiExporter->exportWorkspace($workspace);

        return $this->downloadResponse($json, $workspace->getSlug() . '-openapi.json', 'application/json');
    }

    #[Route('/export/variables.{format}', name: 'export_variables_workspace', requirements: ['workspaceId' => '\d+', 'format' => 'json|yaml|env'], methods: ['GET'])]
    public function exportVariablesWorkspace(int $workspaceId, string $format): Response
    {
        $workspace = $this->requireWorkspace($workspaceId);
        $content   = $this->variableExporter->renderWorkspace($workspace, $format);
        $extension = $format === 'env' ? 'env' : ($format === 'yaml' ? 'yaml' : 'json');
        $mime      = match ($format) {
            'yaml'  => 'application/x-yaml',
            'env'   => 'text/plain',
            default => 'application/json',
        };

        return $this->downloadResponse($content, $workspace->getSlug() . '-variables.' . $extension, $mime);
    }

    #[Route('/services/{serviceId}/import/openapi', name: 'import_openapi_service', requirements: ['workspaceId' => '\d+', 'serviceId' => '\d+'], methods: ['GET', 'POST'])]
    public function importOpenApiService(Request $request, int $workspaceId, int $serviceId): Response
    {
        $workspace = $this->requireWorkspace($workspaceId);
        $service   = $this->requireService($serviceId, $workspace);
        $form      = $this->createImportForm('openapi');

        return $this->handleImport($request, $form, function (FormInterface $form) use ($workspace, $service): ImportResult {
            return $this->openApiImporter->import(
                $workspace,
                $this->readUploadedFile($form),
                $this->uploadedFilename($form),
                $service,
            );
        }, $workspace, 'import.openapi.service_title', $service);
    }

    #[Route('/services/{serviceId}/import/postman', name: 'import_postman_service', requirements: ['workspaceId' => '\d+', 'serviceId' => '\d+'], methods: ['GET', 'POST'])]
    public function importPostmanService(Request $request, int $workspaceId, int $serviceId): Response
    {
        $workspace = $this->requireWorkspace($workspaceId);
        $service   = $this->requireService($serviceId, $workspace);
        $form      = $this->createImportForm('postman', showPostmanVariables: true);

        return $this->handleImport($request, $form, function (FormInterface $form) use ($workspace, $service): ImportResult {
            $importVariables = $form->has('importVariables') ? (bool) $form->get('importVariables')->getData() : true;

            return $this->postmanImporter->import(
                $workspace,
                $this->readUploadedFile($form),
                $this->uploadedFilename($form),
                $importVariables,
                $service,
            );
        }, $workspace, 'import.postman.service_title', $service);
    }

    #[Route('/services/{serviceId}/export/openapi', name: 'export_openapi_service', requirements: ['workspaceId' => '\d+', 'serviceId' => '\d+'], methods: ['GET'])]
    public function exportOpenApiService(int $workspaceId, int $serviceId): Response
    {
        $workspace = $this->requireWorkspace($workspaceId);
        $service   = $this->requireService($serviceId, $workspace);
        $json      = $this->openApiExporter->exportService($service);

        return $this->downloadResponse($json, $service->getSlug() . '-openapi.json', 'application/json');
    }

    #[Route('/environments/{environmentId}/import/variables', name: 'import_variables_environment', requirements: ['workspaceId' => '\d+', 'environmentId' => '\d+'], methods: ['GET', 'POST'])]
    public function importVariablesEnvironment(Request $request, int $workspaceId, int $environmentId): Response
    {
        $workspace   = $this->requireWorkspace($workspaceId);
        $environment = $this->requireEnvironment($environmentId, $workspace);
        $form        = $this->createImportForm('variables', showMode: true, extensions: ['json', 'yaml', 'yml', 'env']);

        return $this->handleImport($request, $form, function (FormInterface $form) use ($environment): ImportResult {
            $mode = $form->has('mode') ? (string) $form->get('mode')->getData() : 'merge';

            return $this->variableImporter->importIntoEnvironment(
                $environment,
                $this->readUploadedFile($form),
                $mode,
                $this->uploadedFilename($form),
            );
        }, $workspace, 'import.variables.environment_title', environment: $environment);
    }

    #[Route('/environments/{environmentId}/export/variables.{format}', name: 'export_variables_environment', requirements: ['workspaceId' => '\d+', 'environmentId' => '\d+', 'format' => 'json|yaml|env'], methods: ['GET'])]
    public function exportVariablesEnvironment(int $workspaceId, int $environmentId, string $format): Response
    {
        $workspace   = $this->requireWorkspace($workspaceId);
        $environment = $this->requireEnvironment($environmentId, $workspace);
        $content     = $this->variableExporter->render($environment, $format);
        $extension   = $format === 'env' ? 'env' : ($format === 'yaml' ? 'yaml' : 'json');
        $mime        = match ($format) {
            'yaml'  => 'application/x-yaml',
            'env'   => 'text/plain',
            default => 'application/json',
        };

        return $this->downloadResponse($content, $environment->getSlug() . '-variables.' . $extension, $mime);
    }

    /**
     * @param FormInterface<mixed> $form
     * @param callable(FormInterface<mixed>): ImportResult $importer
     */
    private function handleImport(
        Request $request,
        FormInterface $form,
        callable $importer,
        ApiWorkspace $workspace,
        string $titleKey,
        ?ApiService $service = null,
        ?ApiEnvironment $environment = null,
    ): Response {
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $result = $importer($form);
                $this->entityManager->flush();
                $this->addFlash('success', $this->translator->trans('flash.imported', [
                    '%services%'          => $result->servicesCreated,
                    '%endpoints%'         => $result->endpointsCreated,
                    '%variables_new%'     => $result->variablesCreated,
                    '%variables_updated%' => $result->variablesUpdated,
                    '%environments%'      => $result->environmentsCreated,
                ], 'NowoApiStudioBundle'));

                if ($service instanceof ApiService) {
                    return $this->redirectToRoute('nowo_api_studio_service_show', [
                        'workspaceId' => $workspace->getId(),
                        'id'          => $service->getId(),
                    ]);
                }
                if ($environment instanceof ApiEnvironment) {
                    return $this->redirectToRoute('nowo_api_studio_environment_show', [
                        'workspaceId' => $workspace->getId(),
                        'id'          => $environment->getId(),
                    ]);
                }

                return $this->redirectToRoute('nowo_api_studio_workspace_show', ['id' => $workspace->getId()]);
            } catch (Throwable $e) {
                $this->addFlash('error', 'import.error');
            }
        }

        return $this->render('@NowoApiStudioBundle/import_export/import.html.twig', [
            'workspace'   => $workspace,
            'service'     => $service,
            'environment' => $environment,
            'form'        => $form,
            'title'       => $titleKey,
        ]);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function readUploadedFile(FormInterface $form): string
    {
        /** @var UploadedFile $file */
        $file = $form->get('file')->getData();

        return $file->getContent();
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function uploadedFilename(FormInterface $form): string
    {
        /** @var UploadedFile $file */
        $file = $form->get('file')->getData();

        return $file->getClientOriginalName();
    }

    /**
     * @param list<string> $extensions
     *
     * @return FormInterface<mixed>
     */
    private function createImportForm(
        string $kind,
        bool $showMode = false,
        bool $showPostmanVariables = false,
        array $extensions = ['json', 'yaml', 'yml'],
    ): FormInterface {
        return $this->createForm(ImportFileFormType::class, null, [
            'import_kind'            => $kind,
            'allowed_extensions'     => $extensions,
            'show_mode'              => $showMode,
            'show_postman_variables' => $showPostmanVariables,
        ]);
    }

    private function downloadResponse(string $content, string $filename, string $mimeType): Response
    {
        $response = new Response($content);
        $response->headers->set('Content-Type', $mimeType);
        $disposition = $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    private function requireWorkspace(int $id): ApiWorkspace
    {
        $workspace = $this->workspaceRepository->find($id);
        if (!$workspace instanceof ApiWorkspace) {
            throw $this->createNotFoundException('Workspace not found.');
        }

        return $workspace;
    }

    private function requireService(int $id, ApiWorkspace $workspace): ApiService
    {
        $service = $this->serviceRepository->find($id);
        if (!$service instanceof ApiService || $service->getWorkspace()?->getId() !== $workspace->getId()) {
            throw $this->createNotFoundException('Service not found.');
        }

        return $service;
    }

    private function requireEnvironment(int $id, ApiWorkspace $workspace): ApiEnvironment
    {
        $environment = $this->environmentRepository->find($id);
        if (!$environment instanceof ApiEnvironment || $environment->getWorkspace()?->getId() !== $workspace->getId()) {
            throw $this->createNotFoundException('Environment not found.');
        }

        return $environment;
    }
}
