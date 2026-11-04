<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Controller;

use Closure;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Controller\SlugConflictTrait;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The race safety net: a slug taken between validation and flush becomes a form error, anything else still throws.
 */
final class SlugConflictTraitTest extends TestCase
{
    public function testSlugIndexViolationBecomesATranslatedFormError(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Already used.');
        $form = $this->form();

        self::assertFalse(($this->controller(self::violation('UNIQUE constraint failed: api_studio_workspace.slug'), $translator))($form));
        $errors = $form->get('slug')->getErrors();
        self::assertCount(1, $errors);
        self::assertSame('Already used.', $errors[0]->getMessage());
        self::assertSame(['%slug%' => 'dup'], $errors[0]->getMessageParameters());
    }

    public function testWithoutTranslatorTheKeyIsShown(): void
    {
        $form = $this->form();

        self::assertFalse(($this->controller(self::violation('Duplicate entry for key api_studio_uniq_workspace_slug')))($form));
        self::assertSame('slug.taken', $form->get('slug')->getErrors()[0]->getMessage());
    }

    public function testOtherUniqueViolationsAndFormsWithoutSlugStillThrow(): void
    {
        $other = self::violation('UNIQUE constraint failed: api_studio_environment_variable.variable_key');
        try {
            ($this->controller($other))($this->form());
            self::fail('A violation on another index is not a slug conflict.');
        } catch (UniqueConstraintViolationException $e) {
            self::assertSame($other, $e);
        }

        $this->expectException(UniqueConstraintViolationException::class);
        ($this->controller(self::violation('slug')))(Forms::createFormFactory()->create(FormType::class));
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
        $form = Forms::createFormFactory()->createBuilder(FormType::class)->add('slug', TextType::class)->getForm();
        $form->submit(['slug' => 'dup']);

        return $form;
    }

    /**
     * @return Closure(FormInterface<mixed>): bool
     */
    private function controller(?UniqueConstraintViolationException $failure, ?TranslatorInterface $translator = null): Closure
    {
        $em = $this->createStub(EntityManagerInterface::class);
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
