<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Validator;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\ApiStudioBundle;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

use function is_object;
use function is_string;
use function method_exists;
use function preg_match;
use function strlen;

/**
 * Applies {@see SlugFormat} to a new slug or a changed one (compared with the value Doctrine loaded). Stateless:
 * safe in worker mode.
 */
final class SlugFormatValidator extends ConstraintValidator
{
    public function __construct(
        private readonly ?ManagerRegistry $registry = null,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof SlugFormat) {
            throw new UnexpectedTypeException($constraint, SlugFormat::class);
        }

        if (!is_object($value) || !method_exists($value, 'getSlug')) {
            return;
        }

        $slug = $value->getSlug();
        if (!is_string($slug) || !$this->isNewOrChanged($value, $slug)) {
            return;
        }

        if (strlen($slug) <= SlugFormat::MAX_LENGTH && preg_match(SlugFormat::PATTERN, $slug) === 1) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->atPath('slug')
            ->setParameter('%slug%', $slug)
            ->setTranslationDomain(ApiStudioBundle::TRANSLATION_DOMAIN)
            ->setInvalidValue($slug)
            ->setCode(SlugFormat::INVALID_ERROR)
            ->addViolation();
    }

    private function isNewOrChanged(object $entity, string $slug): bool
    {
        if (!method_exists($entity, 'getId') || $entity->getId() === null) {
            return true;
        }

        $manager = $this->registry?->getManagerForClass($entity::class);
        if (!$manager instanceof EntityManagerInterface) {
            return true;
        }

        $original = $manager->getUnitOfWork()->getOriginalEntityData($entity);

        return !isset($original['slug']) || $original['slug'] !== $slug;
    }
}
