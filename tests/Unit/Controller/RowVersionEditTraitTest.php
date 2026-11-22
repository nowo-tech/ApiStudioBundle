<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\ApiStudioBundle\Concurrency\StaleEditException;
use Nowo\ApiStudioBundle\Controller\RowVersionEditTrait;
use Nowo\ApiStudioBundle\Controller\SlugConflictTrait;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\EventListener\ConcurrencyConflictSubscriber;
use Nowo\ApiStudioBundle\Form\RowVersionField;
use Nowo\ApiStudioBundle\Tests\Support\ScopeOrmTrait;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * 1.20 edit saves on SQLite, outside a kernel: a refused unique key keeps the row's version, the stale answer drops the
 * refused changes (orphan removals included), and a row deleted meanwhile answers 404.
 */
final class RowVersionEditTraitTest extends TestCase
{
    use ScopeOrmTrait;

    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        $this->manager = $this->scopeEntityManager($this->sqliteScopeRegistry(false));
    }

    public function testARefusedSlugRollsBackAndKeepsTheVersion(): void
    {
        $taken     = new ApiWorkspace('Taken', 'taken');
        $workspace = new ApiWorkspace('Mine', 'mine');
        $this->manager->persist($taken);
        $this->manager->persist($workspace);
        $this->manager->flush();

        $form = $this->form($workspace, ['slug' => 'taken', RowVersionField::NAME => '1']);
        self::assertFalse($this->probe()->save($form, $workspace));

        self::assertSame(1, $workspace->getRowVersion());
        self::assertSame(0, $this->manager->getConnection()->getTransactionNestingLevel());
        self::assertSame(1, (int) $this->manager->getConnection()->fetchOne("SELECT row_version FROM api_studio_workspace WHERE slug = 'mine'"));
    }

    public function testTheStaleAnswerDropsTheRefusedChanges(): void
    {
        $workspace   = new ApiWorkspace('W', 'w');
        $environment = (new ApiEnvironment('Prod', 'prod'))->setWorkspace($workspace);
        $variable    = new ApiEnvironmentVariable('token', 'stored');
        $environment->addVariable($variable);
        $this->manager->persist($workspace);
        $this->manager->persist($environment);
        $this->manager->flush();

        $environment->setName('Refused');
        $variable->setValue('refused');
        $environment->getVariables()->removeElement($variable);
        $environment->addVariable(new ApiEnvironmentVariable('added', 'never stored'));
        $request = new Request();
        $request->attributes->set('_route', 'nowo_api_studio_environment_edit');

        [$status, $headers] = $this->probe()->answer($request, new StaleEditException(), $environment, $variable);
        self::assertSame(409, $status);
        self::assertSame('CONFLICT_001', $headers['X-Conflict-Code']);

        self::assertSame('Prod', $environment->getName());
        self::assertSame('stored', $variable->getValue());
        self::assertCount(1, $environment->getVariables());
        // Nothing refused stays pending: a later flush writes nothing (the removed variable is still stored).
        $this->manager->flush();
        self::assertSame(1, (int) $this->manager->getConnection()->fetchOne('SELECT COUNT(*) FROM api_studio_environment_variable'));
    }

    public function testARowDeletedMeanwhileAnswers404(): void
    {
        $workspace = new ApiWorkspace('W', 'w');
        $this->manager->persist($workspace);
        $this->manager->flush();
        $this->manager->getConnection()->executeStatement('DELETE FROM api_studio_workspace');

        $this->expectException(NotFoundHttpException::class);
        $this->probe()->answer(new Request(), new StaleEditException(), $workspace);
    }

    /**
     * @param array<string, string> $data
     *
     * @return FormInterface<mixed>
     */
    private function form(ApiWorkspace $workspace, array $data): FormInterface
    {
        $builder = Forms::createFormFactory()->createBuilder(FormType::class, $workspace, ['data_class' => ApiWorkspace::class]);
        $builder->add('slug', TextType::class);
        RowVersionField::add($builder, [RowVersionField::OPTION => $workspace->getRowVersion()]);
        $form = $builder->getForm();
        $form->submit($data);

        return $form;
    }

    private function probe(): RowVersionEditProbe
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->method('get')->willReturn(new ConcurrencyConflictSubscriber());

        return new RowVersionEditProbe($this->manager, $container);
    }
}

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
    public function save(FormInterface $form, ApiWorkspace $row): bool
    {
        return $this->saveEdit($form, $row);
    }

    /** @return array{int, array<string, string>} */
    public function answer(Request $request, StaleEditException $stale, ApiWorkspace|ApiEnvironment $row, object ...$written): array
    {
        return $this->answerStaleEdit($request, $stale, $row, ...$written);
    }

    public function createNotFoundException(string $message = 'Not Found', ?Throwable $previous = null): NotFoundHttpException
    {
        return new NotFoundHttpException($message, $previous);
    }
}
