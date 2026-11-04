<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Validator;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Entity\ApiEndpoint;
use Nowo\ApiStudioBundle\Entity\ApiService;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Tests\Support\InMemoryOrmTrait;
use Nowo\ApiStudioBundle\Validator\UniqueSlug;
use Nowo\ApiStudioBundle\Validator\UniqueSlugValidator;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Validation;

/**
 * Slug uniqueness per scope (instance, workspace, service): a duplicate is a violation on `slug`, never a DB error.
 */
final class UniqueSlugValidatorTest extends TestCase
{
    use InMemoryOrmTrait;

    public function testDuplicateWorkspaceSlugIsAViolationOnTheSlugField(): void
    {
        $em = $this->createEntityManager();
        $em->persist(new ApiWorkspace('Taken', 'payments'));
        $em->flush();

        $violations = $this->validate(new ApiWorkspace('New', 'payments'), new UniqueSlug(), $this->createRegistry($em));

        self::assertCount(1, $violations);
        $violation = $violations->get(0);
        self::assertSame('slug', $violation->getPropertyPath());
        self::assertSame('slug.taken', $violation->getMessageTemplate());
        self::assertSame(['%slug%' => 'payments'], $violation->getParameters());
        self::assertSame(UniqueSlug::NOT_UNIQUE_ERROR, $violation->getCode());
        self::assertSame('NOT_UNIQUE_ERROR', UniqueSlug::getErrorName(UniqueSlug::NOT_UNIQUE_ERROR));
    }

    public function testTheEntityItselfAndOtherScopesAreNotDuplicates(): void
    {
        $em        = $this->createEntityManager();
        $workspace = new ApiWorkspace('Mine', 'mine');
        $other     = new ApiWorkspace('Other', 'other');
        $service   = new ApiService('Orders', 'orders');
        $workspace->addService($service);
        $em->persist($workspace);
        $em->persist($other);
        $em->flush();
        $registry = $this->createRegistry($em);

        self::assertCount(0, $this->validate($workspace, new UniqueSlug(), $registry), 'Saving itself.');

        $copy = new ApiWorkspace('Copy', 'mine');
        (new ReflectionProperty(ApiWorkspace::class, 'id'))->setValue($copy, $workspace->getId());
        self::assertCount(0, $this->validate($copy, new UniqueSlug(), $registry), 'Same id (detached copy).');

        $sameSlugElsewhere = new ApiService('Orders', 'orders');
        $other->addService($sameSlugElsewhere);
        self::assertCount(0, $this->validate($sameSlugElsewhere, new UniqueSlug(scope: 'workspace'), $registry), 'Another workspace.');

        $duplicate = new ApiService('Orders 2', 'orders');
        $workspace->addService($duplicate);
        self::assertCount(1, $this->validate($duplicate, new UniqueSlug(scope: 'workspace'), $registry));
    }

    public function testNothingToCheckWithoutSlugParentOrManager(): void
    {
        $em       = $this->createEntityManager();
        $registry = $this->createRegistry($em);

        self::assertCount(0, $this->validate(new ApiWorkspace('Empty', ''), new UniqueSlug(), $registry), 'Empty slug: NotBlank is not this constraint.');
        self::assertCount(0, $this->validate(new stdClass(), new UniqueSlug(), $registry), 'No slug at all.');
        self::assertCount(0, $this->validate(new ApiEndpoint('Unattached', 'call'), new UniqueSlug(scope: 'service'), $registry), 'No parent yet.');
        self::assertCount(0, $this->validate((new ApiEndpoint('Unsaved parent', 'call'))->setService(new ApiService('S', 's')), new UniqueSlug(scope: 'service'), $registry), 'Parent not saved.');
        self::assertCount(0, $this->validate(new ApiWorkspace('Unknown scope', 'x'), new UniqueSlug(scope: 'nothing'), $registry), 'Unknown scope getter.');

        $noManager = $this->createStub(ManagerRegistry::class);
        $noManager->method('getManagerForClass')->willReturn(null);
        self::assertCount(0, $this->validate(new ApiWorkspace('No manager', 'x'), new UniqueSlug(), $noManager));
    }

    public function testOtherConstraintsAreRefused(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        (new UniqueSlugValidator($this->createStub(ManagerRegistry::class)))->validate(new ApiWorkspace('W', 'w'), new NotBlank());
    }

    public function testConstraintDefaults(): void
    {
        $constraint = new UniqueSlug(scope: 'workspace', groups: ['Default']);

        self::assertSame('workspace', $constraint->scope);
        self::assertSame('slug.taken', $constraint->message);
        self::assertSame(UniqueSlug::CLASS_CONSTRAINT, $constraint->getTargets());
    }

    private function validate(object $value, UniqueSlug $constraint, ManagerRegistry $registry): ConstraintViolationListInterface
    {
        $validator = Validation::createValidatorBuilder()
            ->setConstraintValidatorFactory(new class($registry) extends ConstraintValidatorFactory {
                public function __construct(ManagerRegistry $registry)
                {
                    parent::__construct([UniqueSlugValidator::class => new UniqueSlugValidator($registry)]);
                }
            })
            ->getValidator();

        return $validator->validate($value, $constraint);
    }
}
