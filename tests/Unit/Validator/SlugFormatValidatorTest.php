<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Validator;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Tests\Support\InMemoryOrmTrait;
use Nowo\ApiStudioBundle\Validator\SlugFormat;
use Nowo\ApiStudioBundle\Validator\SlugFormatValidator;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Validation;

use function str_repeat;

/**
 * The slug shape, checked for new or changed slugs only.
 */
final class SlugFormatValidatorTest extends TestCase
{
    use InMemoryOrmTrait;

    public function testNewSlugs(): void
    {
        foreach (['payments', 'pay-ments', 'v2_api', 'a1'] as $valid) {
            self::assertCount(0, $this->validate(new ApiWorkspace('W', $valid)), $valid);
        }
        foreach (['', 'Payments', 'pay ments', '-pay', 'pay--ments', 'pay_', str_repeat('a', 129)] as $invalid) {
            $violations = $this->validate(new ApiWorkspace('W', $invalid));
            self::assertCount(1, $violations, $invalid);
            self::assertSame('slug', $violations->get(0)->getPropertyPath());
            self::assertSame(SlugFormat::INVALID_ERROR, $violations->get(0)->getCode());
        }
        self::assertCount(0, $this->validate(new stdClass()));
        self::assertSame('INVALID_ERROR', SlugFormat::getErrorName(SlugFormat::INVALID_ERROR));
        self::assertSame(SlugFormat::CLASS_CONSTRAINT, (new SlugFormat())->getTargets());
    }

    public function testStoredSlugsAreOnlyCheckedWhenChanged(): void
    {
        $em     = $this->createEntityManager();
        $legacy = new ApiWorkspace('Legacy', 'Legacy Slug');
        $em->persist($legacy);
        $em->flush();
        $registry = $this->createRegistry($em);

        self::assertCount(0, $this->validate($legacy, $registry), 'unchanged legacy slug');
        $legacy->setSlug('Other Bad');
        self::assertCount(1, $this->validate($legacy, $registry), 'changed');

        $detached = new ApiWorkspace('Detached', 'Not Managed');
        (new ReflectionProperty(ApiWorkspace::class, 'id'))->setValue($detached, 99);
        self::assertCount(1, $this->validate($detached, $registry), 'saved but unknown to the unit of work: checked');
        self::assertCount(1, $this->validate($detached), 'no registry: checked');

        $noManager = $this->createStub(ManagerRegistry::class);
        $noManager->method('getManagerForClass')->willReturn(null);
        self::assertCount(1, $this->validate($detached, $noManager));
    }

    public function testOtherConstraintsAreRefused(): void
    {
        $this->expectException(UnexpectedTypeException::class);
        (new SlugFormatValidator())->validate(new ApiWorkspace('W', 'w'), new NotBlank());
    }

    private function validate(object $value, ?ManagerRegistry $registry = null): ConstraintViolationListInterface
    {
        $validator = Validation::createValidatorBuilder()
            ->setConstraintValidatorFactory(new ConstraintValidatorFactory([SlugFormatValidator::class => new SlugFormatValidator($registry)]))
            ->getValidator();

        return $validator->validate($value, new SlugFormat());
    }
}
