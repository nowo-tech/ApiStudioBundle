<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Throwable;

use function in_array;
use function is_object;
use function sprintf;

/**
 * `security.access_denied_response` (1.16): how a refused Api Studio request answers.
 *
 * - `403` (default): as before — a refused action is a 403, a hidden or missing resource a 404.
 * - `404`: every refusal on an Api Studio route (permission checker, tenancy, feature gate, role and access checks of
 *   a signed-in user, the host's own denials on its `security.host_routes`) becomes the same 404 as a genuine
 *   not-found, so the answer is no oracle: on {@see KernelEvents::EXCEPTION}, before the firewall (priority 1) and the
 *   framework error renderer, any 403 / 404 of such a route is replaced by one {@see NotFoundHttpException} with the
 *   message {@see self::NOT_FOUND_MESSAGE}, no headers and no previous exception (the firewall walks the chain; the
 *   original is logged at info level instead). After every other response listener (the session listener adds
 *   `max-age=0, must-revalidate` only when the session was read), a 404 of such a route gets the same
 *   `Cache-Control: no-store, private` and no `Expires`, whichever layer answered. The JSON answers built by the
 *   console controllers use {@see self::deniedJson()}: the same body as their own not-found answer.
 *
 * An anonymous request refused by the role or access check keeps the firewall's answer (its entry point, e.g. the
 * login page): it is the same for every Api Studio URL and reveals nothing about a resource.
 *
 * Stateless (worker-safe): the status and the route list are configuration.
 */
final readonly class ApiStudioAccessPolicy implements EventSubscriberInterface
{
    public const FORBIDDEN = Response::HTTP_FORBIDDEN;
    public const NOT_FOUND = Response::HTTP_NOT_FOUND;
    public const RESPONSES = [self::FORBIDDEN, self::NOT_FOUND];

    /** The message of every 404 of an Api Studio route under the `404` policy. */
    public const NOT_FOUND_MESSAGE = 'Not Found';

    public function __construct(
        private int $deniedStatus = self::FORBIDDEN,
        private ApiStudioRoutes $routes = new ApiStudioRoutes(),
        private ?TokenStorageInterface $tokenStorage = null,
        private ?LoggerInterface $logger = null,
    ) {
        if (!in_array($deniedStatus, self::RESPONSES, true)) {
            throw new InvalidArgumentException(sprintf('Api Studio access_denied_response must be 403 or 404, %d given.', $deniedStatus));
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Before the firewall's exception listener (1) and the bundle's JSON 403 answer (0).
            KernelEvents::EXCEPTION => ['onKernelException', 16],
            // After the session listener (-1000): the cache headers of a 404 do not tell which layer answered.
            KernelEvents::RESPONSE => ['onKernelResponse', -2048],
        ];
    }

    /**
     * True when refusals answer 404.
     */
    public function hidesDenials(): bool
    {
        return $this->deniedStatus === self::NOT_FOUND;
    }

    /**
     * The JSON answer of a refused console action: `{"error":"forbidden"}` with a 403, or — under the `404` policy —
     * exactly the route's own not-found answer (`{"error": $notFoundError}` with a 404).
     */
    public function deniedJson(string $notFoundError): JsonResponse
    {
        return $this->hidesDenials()
            ? new JsonResponse(['error' => $notFoundError], self::NOT_FOUND)
            : new JsonResponse(['error' => 'forbidden'], self::FORBIDDEN);
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$this->hidesDenials() || !$this->routes->isStudioRoute($event->getRequest()->attributes->get('_route'))) {
            return;
        }

        $throwable = $event->getThrowable();
        if (!$this->isRefusal($throwable)) {
            return;
        }

        $this->logger?->info('Api Studio: refusal answered as a 404 (access_denied_response: 404).', ['exception' => $throwable]);
        $event->setThrowable(new NotFoundHttpException(self::NOT_FOUND_MESSAGE));
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        if (!$this->hidesDenials() || $response->getStatusCode() !== self::NOT_FOUND || !$this->routes->isStudioRoute($event->getRequest()->attributes->get('_route'))) {
            return;
        }

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->remove('Expires');
    }

    private function isRefusal(Throwable $throwable): bool
    {
        if ($throwable instanceof HttpExceptionInterface) {
            return in_array($throwable->getStatusCode(), self::RESPONSES, true);
        }

        return $throwable instanceof AccessDeniedException && is_object($this->tokenStorage?->getToken()?->getUser());
    }
}
