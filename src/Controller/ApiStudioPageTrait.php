<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use Nowo\ApiStudioBundle\EventListener\ConcurrencyConflictSubscriber;
use Nowo\ApiStudioBundle\Form\CsrfOnlyType;
use Nowo\ApiStudioBundle\Routing\ApiStudioUrlGenerator;
use Nowo\ApiStudioBundle\Security\ApiStudioAuthorization;
use Nowo\ApiStudioBundle\Security\ApiStudioHistoryScope;
use Nowo\ApiStudioBundle\Ui\ApiStudioPageFactory;
use Nowo\ApiStudioBundle\Ui\ApiStudioPages;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\Attribute\SubscribedService;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * UiKit page contract helpers of the Api Studio controllers (REQ-UI-003): the page factory, rendering with the shared
 * page variables, translated flashes (the UiKit layouts print flash messages as given) and the CSRF-only delete forms
 * of the confirm contract.
 */
trait ApiStudioPageTrait
{
    use StudioEventsTrait;

    /**
     * @return array<string|SubscribedService>
     */
    public static function getSubscribedServices(): array
    {
        return parent::getSubscribedServices() + [
            ApiStudioPageFactory::class   => ApiStudioPageFactory::class,
            ApiStudioAuthorization::class => '?' . ApiStudioAuthorization::class,
            'event_dispatcher'            => '?' . EventDispatcherInterface::class,
            ApiStudioHistoryScope::class  => '?' . ApiStudioHistoryScope::class,
            // 1.20: the conflict answer of a stale edit form (RowVersionEditTrait).
            ConcurrencyConflictSubscriber::class => ConcurrencyConflictSubscriber::class,
            'translator'                         => '?' . TranslatorInterface::class,
            ApiStudioUrlGenerator::class         => '?' . ApiStudioUrlGenerator::class,
        ];
    }

    /**
     * 1.17: bundle links of a request mounted under `scope.path` target the twin routes ({@see ApiStudioUrlGenerator});
     * `redirectToRoute()` goes through here too.
     *
     * @param array<string, mixed> $parameters
     */
    protected function generateUrl(string $route, array $parameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        return $this->studioUrls()?->generate($route, $parameters, $referenceType) ?? parent::generateUrl($route, $parameters, $referenceType);
    }

    /**
     * 1.17: the route to link to for `$route` on this request (a twin on a scoped mount), e.g. for UiKit pagination.
     */
    private function studioRoute(string $route): string
    {
        return $this->studioUrls()?->routeName($route) ?? $route;
    }

    private function studioUrls(): ?ApiStudioUrlGenerator
    {
        if (!$this->container->has(ApiStudioUrlGenerator::class)) {
            return null;
        }

        /** @var ApiStudioUrlGenerator $urls */
        $urls = $this->container->get(ApiStudioUrlGenerator::class);

        return $urls;
    }

    private function pages(): ApiStudioPageFactory
    {
        /** @var ApiStudioPageFactory $factory */
        $factory = $this->container->get(ApiStudioPageFactory::class);

        return $factory;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function renderPage(string $template, array $vars, ?Response $response = null): Response
    {
        return $this->render($template, $this->pages()->context($vars), $response);
    }

    /**
     * Adds a flash message translated in the bundle domain.
     *
     * @param array<string, mixed> $params
     */
    private function flash(string $type, string $key, array $params = []): void
    {
        $message = $key;
        if ($this->container->has('translator')) {
            /** @var TranslatorInterface $translator */
            $translator = $this->container->get('translator');
            $message    = $translator->trans($key, $params, ApiStudioPages::DOMAIN);
        }

        $this->addFlash($type, $message);
    }

    /**
     * CSRF-only POST form (flat `_token`) of a destructive action; `$hidden` adds hidden fields (name => value).
     *
     * @param array<string, string> $hidden
     */
    private function csrfOnlyForm(string $action, string $csrfTokenId, array $hidden = []): FormView
    {
        /** @var FormFactoryInterface $formFactory */
        $formFactory = $this->container->get('form.factory');
        $builder     = $formFactory->createNamedBuilder('', CsrfOnlyType::class, null, [
            'action'        => $action,
            'csrf_token_id' => $csrfTokenId,
        ]);
        foreach ($hidden as $name => $value) {
            $builder->add($name, HiddenType::class, ['data' => $value, 'mapped' => false]);
        }

        return $builder->getForm()->createView();
    }
}
