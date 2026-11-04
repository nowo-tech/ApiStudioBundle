<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Validator;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\ApiStudioBundle;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

use function is_object;
use function is_string;
use function method_exists;
use function ucfirst;

/**
 * Looks the slug up in the entity's scope ({@see UniqueSlug::$scope}) and reports a duplicate on the `slug` field.
 * Stateless (only the registry): safe in worker mode.
 */
final class UniqueSlugValidator extends ConstraintValidator
{
    public function __construct(
        private readonly ManagerRegistry $registry,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueSlug) {
            throw new UnexpectedTypeException($constraint, UniqueSlug::class);
        }

        if (!is_object($value) || !method_exists($value, 'getSlug')) {
            return;
        }

        $slug = $value->getSlug();
        if (!is_string($slug) || $slug === '') {
            return;
        }

        $manager = $this->registry->getManagerForClass($value::class);
        if (!$manager instanceof EntityManagerInterface) {
            return;
        }

        $query = $manager->createQueryBuilder()
            ->select('e')
            ->from($value::class, 'e')
            ->where('e.slug = :slug')
            ->setParameter('slug', $slug)
            ->setMaxResults(2);
        if ($constraint->scope !== null) {
            $getter = 'get' . ucfirst($constraint->scope);
            $parent = method_exists($value, $getter) ? $value->{$getter}() : null;
            // Not attached yet: nothing in the database can conflict with it.
            if (!is_object($parent) || !method_exists($parent, 'getId') || $parent->getId() === null) {
                return;
            }
            $query->andWhere('e.' . $constraint->scope . ' = :parent')->setParameter('parent', $parent->getId());
        }

        // Tenancy on: the workspace slug is unique per tenant (the lookup never crosses tenants, so the message reveals
        // nothing about other tenants).
        if ($value instanceof ApiWorkspace && $manager->getClassMetadata(ApiWorkspace::class)->hasField('tenantType')) {
            $tenant = $value->getTenant();
            $query->andWhere('e.tenantType = :tenant_type AND e.tenantId = :tenant_id')
                ->setParameter('tenant_type', $tenant->type)
                ->setParameter('tenant_id', $tenant->id);
        }

        /** @var list<object> $existingRows */
        $existingRows = $query->getQuery()->getResult();
        foreach ($existingRows as $existing) {
            if ($existing === $value || (method_exists($value, 'getId') && method_exists($existing, 'getId') && $value->getId() !== null && $existing->getId() === $value->getId())) {
                continue;
            }

            $this->context->buildViolation($constraint->message)
                ->atPath('slug')
                ->setParameter('%slug%', $slug)
                ->setTranslationDomain(ApiStudioBundle::TRANSLATION_DOMAIN)
                ->setInvalidValue($slug)
                ->setCode(UniqueSlug::NOT_UNIQUE_ERROR)
                ->addViolation();

            return;
        }
    }
}
