<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Service\ImportExport;

use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Service\ImportExport\DocumentParser;
use Nowo\ApiStudioBundle\Service\ImportExport\EnvironmentVariableExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;

use function file_exists;
use function sys_get_temp_dir;
use function unlink;

/**
 * BW audit M1: the .env export is loaded by Symfony Dotenv (or a shell); nothing in it may run a command, expand a
 * variable or add a line. Every value must round-trip literally.
 */
final class DotEnvExportSecurityTest extends TestCase
{
    private string $marker;

    protected function setUp(): void
    {
        $this->marker = sys_get_temp_dir() . '/apistudio-dotenv-pwned';
        if (file_exists($this->marker)) {
            unlink($this->marker);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileValues(): iterable
    {
        yield 'command substitution' => ['$(touch ' . sys_get_temp_dir() . '/apistudio-dotenv-pwned)'];
        yield 'backticks' => ['`touch ' . sys_get_temp_dir() . '/apistudio-dotenv-pwned`'];
        yield 'variable expansion' => ['${HOME}'];
        yield 'bare variable' => ['$HOME/x'];
        yield 'default expansion' => ['${UNSET_VAR:-fallback}'];
        yield 'single quote' => ["it's"];
        yield 'single quote and command' => ["it's \$(touch " . sys_get_temp_dir() . '/apistudio-dotenv-pwned)'];
        yield 'double quote' => ['say "hi"'];
        yield 'backslashes' => ['C:\\path\\to\\n'];
        yield 'line break' => ["first\nAPP_SECRET=attacker"];
        yield 'quote and line break' => ["it's\nAPP_SECRET=attacker"];
        yield 'carriage return' => ["a\rb"];
        yield 'hash' => ['value # not a comment'];
        yield 'spaces' => ['  padded  '];
        yield 'empty' => [''];
        yield 'plain' => ['plain-value_123'];
    }

    #[DataProvider('hostileValues')]
    public function testValueRoundTripsLiterallyThroughDotenv(string $value): void
    {
        $env = new ApiEnvironment('dev', 'dev');
        $env->addVariable(new ApiEnvironmentVariable('token', $value));

        $parsed = (new Dotenv())->parse($this->export($env));

        self::assertSame(['TOKEN' => $value], $parsed);
        self::assertFileDoesNotExist($this->marker, 'No command may run while the export is loaded.');
    }

    public function testCommentsAndNamesCannotInjectLines(): void
    {
        $env      = new ApiEnvironment("dev\nAPP_SECRET=from-name", 'dev');
        $variable = new ApiEnvironmentVariable('plain', 'x');
        $variable->setDescription("harmless\nAPP_SECRET=from-description\r\nDATABASE_URL=x");
        $env->addVariable($variable);

        $export = $this->export($env);
        $parsed = (new Dotenv())->parse($export);

        self::assertSame(['PLAIN' => 'x'], $parsed);
        self::assertStringContainsString('# Environment: dev APP_SECRET=from-name', $export);
        self::assertStringContainsString('# harmless APP_SECRET=from-description DATABASE_URL=x', $export);
    }

    public function testWorkspaceExportIsSafeToo(): void
    {
        $workspace = new ApiWorkspace('Platform', 'platform');
        $env       = new ApiEnvironment("prod\n\$(id)", 'prod');
        $env->addVariable(new ApiEnvironmentVariable('api.key', '$(touch ' . $this->marker . ')'));
        $workspace->addEnvironment($env);

        $export = (new EnvironmentVariableExporter(new DocumentParser()))->renderWorkspace($workspace, 'env');
        $parsed = (new Dotenv())->parse($export);

        self::assertSame(['API_KEY' => '$(touch ' . $this->marker . ')'], $parsed);
        self::assertFileDoesNotExist($this->marker);
    }

    public function testKeysThatAreNotEnvironmentNamesAreSkipped(): void
    {
        $env = new ApiEnvironment('dev', 'dev');
        $env->addVariable(new ApiEnvironmentVariable('1st-key', 'a'));
        $env->addVariable(new ApiEnvironmentVariable('ok-key', 'b'));

        $export = $this->export($env);

        self::assertSame(['OK_KEY' => 'b'], (new Dotenv())->parse($export));
        self::assertStringContainsString('# skipped: 1st-key is not a valid environment variable name', $export);
    }

    public function testValueThatCannotBeWrittenSafelyIsSkipped(): void
    {
        $env = new ApiEnvironment('dev', 'dev');
        $env->addVariable(new ApiEnvironmentVariable('token', "it's `id`"));
        $env->addVariable(new ApiEnvironmentVariable('other', 'kept'));

        $export = $this->export($env);

        self::assertSame(['OTHER' => 'kept'], (new Dotenv())->parse($export));
        self::assertStringContainsString('# skipped: TOKEN cannot be written safely', $export);
    }

    private function export(ApiEnvironment $environment): string
    {
        return (new EnvironmentVariableExporter(new DocumentParser()))->render($environment, 'env');
    }
}
