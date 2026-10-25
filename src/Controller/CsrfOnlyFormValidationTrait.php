<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use Nowo\ApiStudioBundle\Form\CsrfOnlyType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Validates CSRF-only POSTs (delete buttons) through {@see CsrfOnlyType} — REQ-TWIG-005.
 *
 * Same contract as before: flat `_token` field, token id chosen by the caller.
 */
trait CsrfOnlyFormValidationTrait
{
    private function isCsrfOnlyFormValid(Request $request, string $csrfTokenId): bool
    {
        /** @var FormFactoryInterface $formFactory */
        $formFactory = $this->container->get('form.factory');
        $form        = $formFactory->createNamed('', CsrfOnlyType::class, null, ['csrf_token_id' => $csrfTokenId]);
        // Root-less CSRF-only form: submit the POST body explicitly (handleRequest() skips a root-less form whose
        // payload has no child field).
        $form->submit($request->request->all());

        return $form->isValid();
    }
}
