<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\ApiStudioBundle\Ui\ApiStudioPages;
use Nowo\ApiStudioBundle\Validator\UniqueSlug;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_string;
use function str_contains;
use function strtolower;

/**
 * Race safety net of the slug validation ({@see UniqueSlug}): when another request
 * takes the same slug between validation and flush, the unique index answers and the form shows the same error with a
 * 422 instead of a 500. Needs the controller's `$entityManager` and the {@see ApiStudioPageTrait} services.
 */
trait SlugConflictTrait
{
    /**
     * Flushes; false (with a form error on `slug`) when a slug unique index refused the write.
     *
     * @param FormInterface<mixed> $form
     */
    private function flushUnlessSlugTaken(FormInterface $form): bool
    {
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains(strtolower($e->getMessage()), 'slug') || !$form->has('slug')) {
                throw $e;
            }

            $slug    = $form->get('slug')->getData();
            $message = 'slug.taken';
            $params  = ['%slug%' => is_string($slug) ? $slug : ''];
            if ($this->container->has('translator')) {
                /** @var TranslatorInterface $translator */
                $translator = $this->container->get('translator');
                $message    = $translator->trans('slug.taken', $params, ApiStudioPages::DOMAIN);
            }
            $form->get('slug')->addError(new FormError($message, 'slug.taken', $params));

            return false;
        }

        return true;
    }
}
