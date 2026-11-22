<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Controller\RowVersionEditTrait;
use Nowo\ApiStudioBundle\Controller\SlugConflictTrait;
use Nowo\ApiStudioBundle\Entity\RowVersionedInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The trait on a minimal controller (the manager and the service locator it needs).
 */
final class RowVersionEditProbe
{
    use RowVersionEditTrait;
    use SlugConflictTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContainerInterface $container,
    ) {
    }

    /** @param FormInterface<mixed> $form */
    public function save(FormInterface $form, RowVersionedInterface $row, ?callable $beforeClaim = null): bool
    {
        return $this->saveEdit($form, $row, $beforeClaim);
    }

    /** @return array{int, array<string, string>} */
    public function answer(Request $request, RowVersionedInterface $row, object ...$written): array
    {
        return $this->answerStaleEdit($request, $row, ...$written);
    }

    public function createNotFoundException(string $message = 'Not Found', ?Throwable $previous = null): NotFoundHttpException
    {
        return new NotFoundHttpException($message, $previous);
    }
}
