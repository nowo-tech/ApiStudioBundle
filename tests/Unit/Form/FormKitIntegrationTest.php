<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Form\FormTypeInterface;

use function basename;
use function class_exists;
use function class_uses;
use function count;
use function dirname;
use function glob;

/**
 * REQ-TWIG-005 rule 11: every form type of the bundle pages is a FormKit type (profile `api_studio`).
 */
final class FormKitIntegrationTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function formTypes(): iterable
    {
        foreach (glob(dirname(__DIR__, 3) . '/src/Form/*Type.php') ?: [] as $file) {
            $class = 'Nowo\\ApiStudioBundle\\Form\\' . basename($file, '.php');

            yield basename($file, '.php') => [$class];
        }
    }

    #[DataProvider('formTypes')]
    public function testFormTypeIsAFormKitType(string $class): void
    {
        self::assertTrue(class_exists($class), $class);
        $reflection = new ReflectionClass($class);

        self::assertTrue($reflection->implementsInterface(FormTypeInterface::class));
        $attributes = $reflection->getAttributes(FormKitConfig::class);
        self::assertCount(1, $attributes, $class . ' needs #[FormKitConfig].');
        self::assertSame('api_studio', $attributes[0]->newInstance()->name);
        self::assertContains(FormOptionsTrait::class, class_uses($class), $class . ' needs FormOptionsTrait.');
    }

    public function testEveryFormTypeIsCovered(): void
    {
        self::assertGreaterThanOrEqual(11, count(glob(dirname(__DIR__, 3) . '/src/Form/*Type.php') ?: []));
    }
}
