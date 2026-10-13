<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function count;
use function dirname;
use function in_array;
use function is_array;

use const T_CLASS;
use const T_DOUBLE_COLON;
use const T_ENUM;
use const T_INTERFACE;
use const T_NEW;
use const T_STRING;
use const T_TRAIT;
use const T_WHITESPACE;

/**
 * Directory resource loaders (services.yaml) only register the class matching each file name (PSR-4),
 * so every source file must declare exactly one class, interface, trait or enum.
 */
final class SourceLayoutTest extends TestCase
{
    public function testEachSourceFileDeclaresASingleClassLike(): void
    {
        $offenders = [];
        $files     = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src'));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $declared = $this->declaredClassLikes((string) file_get_contents($file->getPathname()));
            if (count($declared) > 1) {
                $offenders[] = $file->getPathname() . ': ' . implode(', ', $declared);
            } elseif (count($declared) === 1 && $declared[0] !== $file->getBasename('.php')) {
                $offenders[] = $file->getPathname() . ': declares ' . $declared[0];
            }
        }

        self::assertSame([], $offenders);
    }

    /** @return list<string> */
    private function declaredClassLikes(string $code): array
    {
        $tokens   = token_get_all($code);
        $declared = [];
        $count    = count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!is_array($token) || !in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                continue;
            }

            // Skip `Foo::class` and anonymous classes (`new class`).
            $previous = $i - 1;
            while ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_WHITESPACE) {
                --$previous;
            }
            if ($previous >= 0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0], [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }

            for ($j = $i + 1; $j < $count; ++$j) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $declared[] = $tokens[$j][1];

                    break;
                }
                if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_WHITESPACE) {
                    break;
                }
            }
        }

        return $declared;
    }
}
