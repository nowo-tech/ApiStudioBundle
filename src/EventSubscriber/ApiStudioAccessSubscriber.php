<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\EventSubscriber;

use Nowo\ApiStudioBundle\Security\ApiStudioAccessCheckerInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioAction;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\ApiStudioFeatureGateInterface;
use Nowo\ApiStudioBundle\Security\ApiStudioRouteActions;
use Nowo\ApiStudioBundle\Security\ApiStudioRoutes;
use Nowo\ApiStudioBundle\Tenancy\ApiStudioTenant;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function in_array;
use function is_object;
use function is_string;
use function sprintf;

/**
 * Guards all Api Studio UI routes ({@code nowo_api_studio_*} and the host's {@code security.host_routes}, see
 * {@see ApiStudioRoutes}).
 *
 * - Bundle disabled ({@code nowo_api_studio.enabled: false}): every route answers 404.
 * - Requires at least one of {@code $requiredRoles}. An empty list denies (fail closed) unless
 *   {@code $allowUnauthenticated} is true or a custom {@code $accessChecker} decides; roles without an
 *   authorization checker deny as well.
 * - Calls the configured {@see ApiStudioAccessCheckerInterface} ({@code security.access_checker}) with the current
 *   user; anonymous requests are denied unless {@code $allowUnauthenticated} is true.
 * - Then `studio.open` (404), the host's {@see ApiStudioFeatureGateInterface} ({@code security.feature_gate}, 1.16:
 *   false → 403, or 404 with {@code access_denied_response: 404}) and the route's tenant-level action.
 */
final readonly class ApiStudioAccessSubscriber implements EventSubscriberInterface
{
    public const ROUTE_PREFIX = 'nowo_api_studio_';

    /** Routes the console calls with fetch(): a refused action answers JSON there (ER-I1). */
    public const JSON_ROUTES = [
        'nowo_api_studio_execute',
        'nowo_api_studio_environment_sync_variables',
        'nowo_api_studio_endpoint_save_documentation',
        'nowo_api_studio_endpoint_examples_save_request',
        'nowo_api_studio_endpoint_examples_save_response',
        'nowo_api_studio_endpoint_examples_delete_request',
        'nowo_api_studio_endpoint_examples_delete_response',
    ];

    /**
     * @param list<string> $requiredRoles
     */
    public function __construct(
        private array $requiredRoles,
        private ?AuthorizationCheckerInterface $authorizationChecker = null,
        private bool $enabled = true,
        private ?ApiStudioAccessCheckerInterface $accessChecker = null,
        private ?TokenStorageInterface $tokenStorage = null,
        private bool $allowUnauthenticated = false,
        private ?ApiStudioAuthorization $authorization = null,
        private ?ApiStudioFeatureGateInterface $featureGate = null,
        private ApiStudioRoutes $routes = new ApiStudioRoutes(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Right after the router (priority 32): a disabled UI is unreachable before other listeners run.
            KernelEvents::REQUEST    => ['onKernelRequest', 30],
            KernelEvents::CONTROLLER => ['onKernelController', 0],
            KernelEvents::RESPONSE   => ['onKernelResponse', 0],
            // Before the framework's error renderer (-128): JSON routes get JSON.
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    public static function isApiStudioRoute(Request $request): bool
    {
        $route = $request->attributes->get('_route');

        return is_string($route) && str_starts_with($route, self::ROUTE_PREFIX);
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->enabled && $this->routes->isStudioRoute($event->getRequest()->attributes->get('_route'))) {
            throw new NotFoundHttpException('Api Studio is disabled.');
        }
    }

    /**
     * Api Studio pages, JSON answers and exports carry workspace data and variable values: never stored by the browser
     * or a shared cache.
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$this->routes->isStudioRoute($event->getRequest()->attributes->get('_route'))) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('Cache-Control', 'no-store, private');
        $headers->set('Pragma', 'no-cache');
    }

    /**
     * A refused action on a JSON route answers `{"error":"forbidden"}` with a 403 (not the HTML error page).
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $route     = $event->getRequest()->attributes->get('_route');
        if (!is_string($route) || !in_array(ApiStudioRoutes::canonical($route), self::JSON_ROUTES, true) || (!$exception instanceof AccessDeniedHttpException && !$exception instanceof AccessDeniedException)) {
            return;
        }

        $event->setResponse(new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN));
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        $route   = $request->attributes->get('_route');
        if (!is_string($route) || !$this->routes->isStudioRoute($route)) {
            return;
        }

        if (!$this->enabled) {
            throw new NotFoundHttpException('Api Studio is disabled.');
        }

        $this->checkRoles();
        $this->checkAccessChecker();
        $action = $this->checkStudioOpen($route);
        $this->checkFeatureGate($request);
        if ($action === ApiStudioAction::WorkspaceCreate) {
            $this->authorization?->denyUnlessGranted(ApiStudioAction::WorkspaceCreate);
        }
    }

    /**
     * 1.18 global search (not an Api Studio route): the same entry chain as {@see self::onKernelController()} — bundle
     * enabled, roles, access checker, `studio.open` (with the scope checker's in a scope), the feature gate — as a
     * boolean. Any refusal is false (the search shows nothing: no 403, no hint). Without the authorization service it
     * is false (deny by default).
     */
    public function admitsSearch(Request $request): bool
    {
        if (!$this->enabled || !$this->authorization instanceof ApiStudioAuthorization) {
            return false;
        }

        try {
            $this->checkRoles();
            $this->checkAccessChecker();
            if (!$this->authorization->can(ApiStudioAction::StudioOpen)) {
                return false;
            }
            $this->checkFeatureGate($request);
        } catch (AccessDeniedException|AccessDeniedHttpException) {
            return false;
        }

        return true;
    }

    /**
     * Deny by default: a route without an entry in {@see ApiStudioRouteActions::MAP} or `security.host_routes` answers
     * 404. Every route needs `studio.open` (else 404); workspace actions are checked by the controllers once the
     * workspace is loaded.
     */
    private function checkStudioOpen(string $route): ?ApiStudioAction
    {
        if (!$this->authorization instanceof ApiStudioAuthorization) {
            return null;
        }

        $action = $this->routes->actionFor($route);
        if (!$action instanceof ApiStudioAction) {
            throw new NotFoundHttpException(sprintf('Api Studio route "%s" has no action mapping.', $route));
        }

        $this->authorization->denyUnlessGranted(ApiStudioAction::StudioOpen);

        return $action;
    }

    /**
     * 1.16: the host's plan / feature switch, after `studio.open` (someone who may not see the studio learns nothing
     * about its plan) and before any controller. Deny by default: only `true` lets the request through.
     */
    private function checkFeatureGate(Request $request): void
    {
        if (!$this->featureGate instanceof ApiStudioFeatureGateInterface) {
            return;
        }

        $user   = $this->authorization?->user() ?? $this->tokenUser();
        $tenant = $this->authorization?->tenant() ?? ApiStudioTenant::instance();
        if (!$this->featureGate->isEnabled($user, $tenant, $request)) {
            throw new AccessDeniedHttpException('Api Studio is not available.');
        }
    }

    private function tokenUser(): ?object
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return is_object($user) ? $user : null;
    }

    private function checkRoles(): void
    {
        if ($this->requiredRoles === []) {
            if ($this->allowUnauthenticated || $this->accessChecker instanceof ApiStudioAccessCheckerInterface) {
                return;
            }

            throw new AccessDeniedException('Api Studio has no access role configured (an empty role list denies).');
        }

        if (!$this->authorizationChecker instanceof AuthorizationCheckerInterface) {
            throw new AccessDeniedException('Api Studio role check requires symfony/security-bundle.');
        }

        foreach ($this->requiredRoles as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return;
            }
        }

        throw new AccessDeniedException(sprintf('Api Studio requires one of the following roles: %s.', implode(', ', $this->requiredRoles)));
    }

    private function checkAccessChecker(): void
    {
        if (!$this->accessChecker instanceof ApiStudioAccessCheckerInterface) {
            return;
        }

        $user = $this->tokenStorage?->getToken()?->getUser();
        if ($user === null) {
            if ($this->allowUnauthenticated) {
                return;
            }

            throw new AccessDeniedException('Api Studio requires an authenticated user.');
        }

        if (!$this->accessChecker->canAccess($user)) {
            throw new AccessDeniedException('Access to Api Studio is denied.');
        }
    }
}
