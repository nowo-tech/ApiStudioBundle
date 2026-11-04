<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\ApiStudioBundle\Ui\ApiStudioPages;
use Nowo\ApiStudioBundle\Validator\UniqueSlug;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function implode;
use function in_array;
use function is_object;
use function is_string;
use function preg_match;
use function preg_quote;

/**
 * Race safety net of the slug validation ({@see UniqueSlug}): when another request
 * takes the same slug between validation and flush, the unique index answers and the form shows the same error with a
 * 422 instead of a 500. Needs the controller's `$entityManager` and the {@see ApiStudioPageTrait} services. The violation is recognised by
 * the entity's slug unique index (name or columns), never by the word "slug" in the message.
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
            if (!$form->has('slug') || !$this->isSlugIndexViolation($e, $form->getData())) {
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

    /**
     * Whether the database refused the write on a unique index of the entity that covers `slug`: matched by the index
     * name (MySQL, PostgreSQL, SQL Server…) or, for SQLite, by its `table.column` list; never by the word "slug" alone.
     */
    private function isSlugIndexViolation(UniqueConstraintViolationException $e, mixed $entity): bool
    {
        if (!is_object($entity)) {
            return false;
        }

        $metadata = $this->entityManager->getClassMetadata($entity::class);
        $table    = $metadata->getTableName();
        $message  = $e->getMessage();
        foreach ($metadata->table['uniqueConstraints'] ?? [] as $name => $definition) {
            $columns = $definition['columns'] ?? [];
            if (!in_array('slug', $columns, true)) {
                continue;
            }

            if (preg_match('/(?<![A-Za-z0-9_])' . preg_quote((string) $name, '/') . '(?![A-Za-z0-9_])/', $message) === 1) {
                return true;
            }

            $sqlite = implode(', ', array_map(static fn (string $column): string => $table . '.' . $column, $columns));
            if (preg_match('/UNIQUE constraint failed: ' . preg_quote($sqlite, '/') . '(?![A-Za-z0-9_.,])/', $message) === 1) {
                return true;
            }
        }

        return false;
    }
}
