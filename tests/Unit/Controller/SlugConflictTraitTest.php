<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Controller;

use Closure;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\ApiStudioBundle\Controller\SlugConflictTrait;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The race safety net: a violation of the entity's slug unique index (by name, or by columns on SQLite) becomes a
 * form error; any other violation still throws (EQ-I6: never matched on the word "slug").
 */
final class SlugConflictTraitTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function slugViolations(): iterable
    {
        yield 'SQLite' => ['SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: api_studio_workspace.slug'];
        yield 'MySQL 8' => ["SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'dup' for key 'api_studio_workspace.api_studio_uniq_workspace_slug'"];
        yield 'PostgreSQL' => ['SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "api_studio_uniq_workspace_slug"'];
        yield 'tenant index' => ['duplicate key value violates unique constraint "api_studio_uniq_workspace_tenant_slug"'];
        yield 'SQLite tenant index' => ['UNIQUE constraint failed: api_studio_workspace.tenant_type, api_studio_workspace.tenant_id, api_studio_workspace.slug'];
    }

    #[DataProvider('slugViolations')]
    public function testSlugIndexViolationBecomesATranslatedFormError(string $message): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Already used.');
        $form = $this->form();

        self::assertFalse(($this->controller(self::violation($message), $translator))($form));
        $errors = $form->get('slug')->getErrors();
        self::assertCount(1, $errors);
        self::assertSame('Already used.', $errors[0]->getMessage());
        self::assertSame(['%slug%' => 'dup'], $errors[0]->getMessageParameters());
    }

    public function testWithoutTranslatorTheKeyIsShown(): void
    {
        $form = $this->form();

        self::assertFalse(($this->controller(self::violation('UNIQUE constraint failed: api_studio_workspace.slug')))($form));
        self::assertSame('slug.taken', $form->get('slug')->getErrors()[0]->getMessage());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherViolations(): iterable
    {
        yield 'other index' => ['UNIQUE constraint failed: api_studio_environment_variable.variable_key'];
        yield 'word slug in another index name' => ['duplicate key value violates unique constraint "api_studio_uniq_workspace_slug_archive"'];
        yield 'word slug only' => ['the slug column is mentioned here'];
        yield 'SQLite column list continues' => ['UNIQUE constraint failed: api_studio_workspace.slug, api_studio_workspace.name'];
    }

    #[DataProvider('otherViolations')]
    public function testOtherUniqueViolationsStillThrow(string $message): void
    {
        $violation = self::violation($message);
        try {
            ($this->controller($violation))($this->form());
            self::fail('Not a slug index violation.');
        } catch (UniqueConstraintViolationException $e) {
            self::assertSame($violation, $e);
        }
    }

    public function testFormsWithoutSlugOrEntityStillThrow(): void
    {
        $violation = self::violation('UNIQUE constraint failed: api_studio_workspace.slug');
        try {
            ($this->controller($violation))(Forms::createFormFactory()->create(FormType::class));
            self::fail('No slug field.');
        } catch (UniqueConstraintViolationException) {
        }

        $arrayForm = Forms::createFormFactory()->createBuilder(FormType::class)->add('slug', TextType::class)->getForm();
        $arrayForm->submit(['slug' => 'dup']);
        $this->expectException(UniqueConstraintViolationException::class);
        ($this->controller($violation))($arrayForm);
    }

    public function testSuccessfulFlush(): void
    {
        self::assertTrue(($this->controller(null))($this->form()));
    }

    private static function violation(string $message): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException(new class($message) extends AbstractException {
        }, null);
    }

    /** @return FormInterface<mixed> */
    private function form(): FormInterface
    {
        $form = Forms::createFormFactory()
            ->createBuilder(FormType::class, new ApiWorkspace('W', 'w'), ['data_class' => ApiWorkspace::class])
            ->add('slug', TextType::class)
            ->getForm();
        $form->submit(['slug' => 'dup']);

        return $form;
    }

    /**
     * @return Closure(FormInterface<mixed>): bool
     */
    private function controller(?UniqueConstraintViolationException $failure, ?TranslatorInterface $translator = null): Closure
    {
        $metadata = new ClassMetadata(ApiWorkspace::class);
        $metadata->setPrimaryTable([
            'name'              => 'api_studio_workspace',
            'uniqueConstraints' => [
                'api_studio_uniq_workspace_slug'        => ['columns' => ['slug']],
                'api_studio_uniq_workspace_tenant_slug' => ['columns' => ['tenant_type', 'tenant_id', 'slug']],
                'api_studio_uniq_workspace_name'        => ['columns' => ['name']],
            ],
        ]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturn($metadata);
        if ($failure !== null) {
            $em->method('flush')->willThrowException($failure);
        }
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn($translator !== null);
        $container->method('get')->willReturn($translator);

        $probe = new class($em, $container) {
            use SlugConflictTrait;

            public function __construct(
                private readonly EntityManagerInterface $entityManager,
                private readonly ContainerInterface $container,
            ) {
            }

            /** @param FormInterface<mixed> $form */
            public function flush(FormInterface $form): bool
            {
                return $this->flushUnlessSlugTaken($form);
            }
        };

        return $probe->flush(...);
    }
}
