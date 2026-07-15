<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiEndpointTranslation;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Enum\HttpMethod;
use Nowo\ApiStudioBundle\Form\ApiEndpointFormType;
use Nowo\ApiStudioBundle\Repository\ApiEndpointRepository;
use Nowo\ApiStudioBundle\Repository\ApiRequestHistoryRepository;
use Nowo\ApiStudioBundle\Repository\ApiServiceRepository;
use Nowo\ApiStudioBundle\Repository\ApiWorkspaceRepository;
use Nowo\ApiStudioBundle\Service\EnvironmentContextBuilder;
use Nowo\ApiStudioBundle\Service\LocaleManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function is_array;
use function is_string;

#[Route(path: '/workspaces/{workspaceId}/services/{serviceId}/endpoints', name: 'nowo_api_studio_endpoint_')]
final class ApiEndpointController extends AbstractController
{
    public function __construct(
        private readonly ApiWorkspaceRepository $workspaceRepository,
        private readonly ApiServiceRepository $serviceRepository,
        private readonly ApiEndpointRepository $repository,
        private readonly ApiRequestHistoryRepository $historyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LocaleManager $localeManager,
        private readonly EnvironmentContextBuilder $environmentContextBuilder,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('', name: 'index', requirements: ['workspaceId' => '\d+', 'serviceId' => '\d+'], methods: ['GET'])]
    public function index(int $workspaceId, int $serviceId): Response
    {
        $context = $this->requireContext($workspaceId, $serviceId);

        return $this->render('@NowoApiStudioBundle/endpoint/index.html.twig', $context + [
            'endpoints' => $context['service']->getEndpoints(),
        ]);
    }

    #[Route('/new', name: 'new', requirements: ['workspaceId' => '\d+', 'serviceId' => '\d+'], methods: ['GET', 'POST'])]
    public function new(Request $request, int $workspaceId, int $serviceId): Response
    {
        $context  = $this->requireContext($workspaceId, $serviceId);
        $endpoint = new ApiEndpoint('New endpoint', 'new_endpoint');
        $context['service']->addEndpoint($endpoint);
        $this->ensureTranslationLocales($endpoint);

        $form = $this->createForm(ApiEndpointFormType::class, $endpoint);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($endpoint);
            $this->entityManager->flush();
            $this->addFlash('success', 'flash.created');

            return $this->redirectToRoute('nowo_api_studio_endpoint_show', [
                'workspaceId' => $workspaceId,
                'serviceId'   => $serviceId,
                'id'          => $endpoint->getId(),
            ]);
        }

        $locale = $this->localeManager->resolveLocale();

        return $this->render('@NowoApiStudioBundle/endpoint/form.html.twig', $context + [
            'form'           => $form,
            'title'          => 'page.new_endpoint',
            'enabledLocales' => $this->localeManager->getEnabledLocales(),
            'currentLocale'  => $locale,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['workspaceId' => '\d+', 'serviceId' => '\d+', 'id' => '\d+'], methods: ['GET'])]
    public function show(Request $request, int $workspaceId, int $serviceId, int $id): Response
    {
        $context              = $this->requireContext($workspaceId, $serviceId);
        $endpoint             = $this->requireEndpoint($id, $context['service']);
        $locale               = $this->localeManager->resolveLocale();
        $loadRequestExampleId = $request->query->getInt('request_example') ?: null;

        return $this->render('@NowoApiStudioBundle/endpoint/show.html.twig', $context + [
            'endpoint'              => $endpoint,
            'translation'           => $endpoint->getTranslation($locale),
            'enabledLocales'        => $this->localeManager->getEnabledLocales(),
            'documentationByLocale' => $this->buildDocumentationByLocale($endpoint),
            'loadRequestExampleId'  => $loadRequestExampleId,
            'environments'          => $context['workspace']->getEnvironments(),
            'environmentMaps'       => $this->environmentContextBuilder->buildMaps($context['workspace']),
            'variableCatalog'       => $this->environmentContextBuilder->buildVariableCatalog($context['workspace']),
            'mergedHeaders'         => array_merge(
                $context['service']->getDefaultHeaders(),
                $endpoint->getHeaders(),
            ),
            'history' => $this->historyRepository->findBy(
                ['endpoint' => $endpoint],
                ['executedAt' => 'DESC'],
                10,
            ),
            'executeUrl'         => $this->generateUrl('nowo_api_studio_execute', ['id' => $endpoint->getId()]),
            'csrfToken'          => $this->csrfTokenManager->getToken('api_studio_execute')->getValue(),
            'envSyncCsrfToken'   => $this->csrfTokenManager->getToken('api_studio_env_sync')->getValue(),
            'envSyncUrlTemplate' => str_replace(
                '/environments/0/',
                '/environments/__ENV__/',
                $this->generateUrl('nowo_api_studio_environment_sync_variables', [
                    'workspaceId' => $workspaceId,
                    'id'          => 0,
                ]),
            ),
            'saveRequestExampleUrl' => $this->generateUrl('nowo_api_studio_endpoint_examples_save_request', [
                'workspaceId' => $workspaceId,
                'serviceId'   => $serviceId,
                'endpointId'  => $endpoint->getId(),
            ]),
            'saveResponseExampleUrl' => $this->generateUrl('nowo_api_studio_endpoint_examples_save_response', [
                'workspaceId' => $workspaceId,
                'serviceId'   => $serviceId,
                'endpointId'  => $endpoint->getId(),
            ]),
            'deleteRequestExampleUrlTemplate' => str_replace(
                '/examples/request/0/delete',
                '/examples/request/__EXAMPLE__/delete',
                $this->generateUrl('nowo_api_studio_endpoint_examples_delete_request', [
                    'workspaceId' => $workspaceId,
                    'serviceId'   => $serviceId,
                    'endpointId'  => $endpoint->getId(),
                    'exampleId'   => 0,
                ]),
            ),
            'deleteResponseExampleUrlTemplate' => str_replace(
                '/examples/response/0/delete',
                '/examples/response/__EXAMPLE__/delete',
                $this->generateUrl('nowo_api_studio_endpoint_examples_delete_response', [
                    'workspaceId' => $workspaceId,
                    'serviceId'   => $serviceId,
                    'endpointId'  => $endpoint->getId(),
                    'exampleId'   => 0,
                ]),
            ),
            'examplesCsrfToken'    => $this->csrfTokenManager->getToken('api_studio_examples')->getValue(),
            'httpMethods'          => HttpMethod::values(),
            'currentLocale'        => $locale,
            'saveDocumentationUrl' => $this->generateUrl('nowo_api_studio_endpoint_save_documentation', [
                'workspaceId' => $workspaceId,
                'serviceId'   => $serviceId,
                'id'          => $endpoint->getId(),
            ]),
            'documentationCsrfToken' => $this->csrfTokenManager->getToken('api_studio_endpoint_doc')->getValue(),
        ]);
    }

    #[Route('/{id}/documentation', name: 'save_documentation', requirements: ['workspaceId' => '\d+', 'serviceId' => '\d+', 'id' => '\d+'], methods: ['POST'])]
    public function saveDocumentation(Request $request, int $workspaceId, int $serviceId, int $id): Response
    {
        if (!$this->isCsrfTokenValid('api_studio_endpoint_doc', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $endpoint = $this->requireEndpoint($id, $this->requireContext($workspaceId, $serviceId)['service']);
        $payload  = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $locale = isset($payload['locale']) && is_string($payload['locale'])
            ? $payload['locale']
            : $this->localeManager->resolveLocale();
        if (!$this->localeManager->isEnabled($locale)) {
            return new JsonResponse(['error' => 'Invalid locale.'], Response::HTTP_BAD_REQUEST);
        }

        $title       = isset($payload['title']) ? trim((string) $payload['title']) : '';
        $description = isset($payload['description']) ? trim((string) $payload['description']) : '';

        $translation = $endpoint->getTranslation($locale);
        if (!$translation instanceof ApiEndpointTranslation) {
            $translation = new ApiEndpointTranslation($locale);
            $endpoint->addTranslation($translation);
        }

        $translation->setTitle($title !== '' ? $title : null);
        $translation->setDescription($description !== '' ? $description : null);

        $this->entityManager->flush();

        return new JsonResponse([
            'locale'        => $locale,
            'title'         => $translation->getTitle() ?? '',
            'display_title' => $translation->getTitle() ?? $endpoint->getName(),
            'description'   => $translation->getDescription() ?? '',
        ]);
    }

    private function ensureTranslationLocales(ApiEndpoint $endpoint): void
    {
        foreach ($this->localeManager->getEnabledLocales() as $locale) {
            if ($endpoint->getTranslation($locale) === null) {
                $endpoint->addTranslation(new ApiEndpointTranslation($locale));
            }
        }
    }

    /** @return array<string, array{title: string, description: string}> */
    private function buildDocumentationByLocale(ApiEndpoint $endpoint): array
    {
        $documentation = [];
        foreach ($this->localeManager->getEnabledLocales() as $locale) {
            $translation            = $endpoint->getTranslation($locale);
            $documentation[$locale] = [
                'title'       => $translation?->getTitle() ?? '',
                'description' => $translation?->getDescription() ?? '',
            ];
        }

        return $documentation;
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['workspaceId' => '\d+', 'serviceId' => '\d+', 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $workspaceId, int $serviceId, int $id): Response
    {
        $context  = $this->requireContext($workspaceId, $serviceId);
        $endpoint = $this->requireEndpoint($id, $context['service']);
        $this->ensureTranslationLocales($endpoint);
        $form = $this->createForm(ApiEndpointFormType::class, $endpoint);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'flash.updated');

            return $this->redirectToRoute('nowo_api_studio_endpoint_show', [
                'workspaceId' => $workspaceId,
                'serviceId'   => $serviceId,
                'id'          => $id,
            ]);
        }

        $locale = $this->localeManager->resolveLocale();

        return $this->render('@NowoApiStudioBundle/endpoint/form.html.twig', $context + [
            'form'           => $form,
            'title'          => 'page.edit_endpoint',
            'endpoint'       => $endpoint,
            'enabledLocales' => $this->localeManager->getEnabledLocales(),
            'currentLocale'  => $locale,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['workspaceId' => '\d+', 'serviceId' => '\d+', 'id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $workspaceId, int $serviceId, int $id): Response
    {
        $context  = $this->requireContext($workspaceId, $serviceId);
        $endpoint = $this->requireEndpoint($id, $context['service']);

        if (!$this->isCsrfTokenValid('delete' . $endpoint->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $this->entityManager->remove($endpoint);
        $this->entityManager->flush();
        $this->addFlash('success', 'flash.deleted');

        return $this->redirectToRoute('nowo_api_studio_endpoint_index', [
            'workspaceId' => $workspaceId,
            'serviceId'   => $serviceId,
        ]);
    }

    /** @return array{workspace: ApiWorkspace, service: ApiService} */
    private function requireContext(int $workspaceId, int $serviceId): array
    {
        $workspace = $this->workspaceRepository->find($workspaceId);
        if (!$workspace instanceof ApiWorkspace) {
            throw $this->createNotFoundException('Workspace not found.');
        }

        $service = $this->serviceRepository->find($serviceId);
        if (!$service instanceof ApiService || $service->getWorkspace()?->getId() !== $workspace->getId()) {
            throw $this->createNotFoundException('Service not found.');
        }

        return ['workspace' => $workspace, 'service' => $service];
    }

    private function requireEndpoint(int $id, ApiService $service): ApiEndpoint
    {
        $endpoint = $this->repository->find($id);
        if (!$endpoint instanceof ApiEndpoint || $endpoint->getService()?->getId() !== $service->getId()) {
            throw $this->createNotFoundException('Endpoint not found.');
        }

        return $endpoint;
    }
}
