<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use Nowo\ApiStudioBundle\Form\CsrfOnlyType;
use Nowo\ApiStudioBundle\Ui\ApiStudioPageFactory;
use Nowo\ApiStudioBundle\Ui\ApiStudioPages;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\SubscribedService;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * UiKit page contract helpers of the Api Studio controllers (REQ-UI-003): the page factory, rendering with the shared
 * page variables, translated flashes (the UiKit layouts print flash messages as given) and the CSRF-only delete forms
 * of the confirm contract.
 */
trait ApiStudioPageTrait
{
    /**
     * @return array<string|SubscribedService>
     */
    public static function getSubscribedServices(): array
    {
        return parent::getSubscribedServices() + [
            ApiStudioPageFactory::class => ApiStudioPageFactory::class,
            'translator'                => '?' . TranslatorInterface::class,
        ];
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
