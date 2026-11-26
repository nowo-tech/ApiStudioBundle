<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\DependencyInjection;

use Nowo\ApiStudioBundle\DependencyInjection\ApiStudioExtension;
use Nowo\ApiStudioBundle\Integration\UserKit\ApiStudioUserKitEraser;
use Nowo\ApiStudioBundle\Integration\UserKit\ApiStudioUserKitExportProvider;
use Nowo\ApiStudioBundle\Privacy\ApiStudioHistoryRetentionInterface;
use Nowo\ApiStudioBundle\Privacy\ApiStudioPersonalDataEraserInterface;
use Nowo\ApiStudioBundle\Privacy\ApiStudioPersonalDataExporterInterface;
use Nowo\ApiStudioBundle\Privacy\DoctrineApiStudioHistoryRetention;
use Nowo\ApiStudioBundle\Privacy\DoctrineApiStudioPersonalData;
use Nowo\ApiStudioBundle\Privacy\PurgeApiStudioHistory;
use Nowo\ApiStudioBundle\Privacy\PurgeApiStudioHistoryHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * 1.24 configuration: retention defaults (90 days, batches of 500), the ports always registered, the Messenger handler,
 * and the UserKit eraser + export section registered together (every eraser has its export) unless
 * `personal_data.enabled: false`, which is a literal boolean.
 */
final class PersonalDataConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $container = $this->load([]);

        self::assertSame(90, $container->getParameter('nowo_api_studio.history.retention_days'));
        self::assertSame(500, $container->getParameter('nowo_api_studio.history.purge_batch_size'));
        self::assertTrue($container->getParameter('nowo_api_studio.personal_data.enabled'));
        self::assertSame(DoctrineApiStudioPersonalData::class, (string) $container->getAlias(ApiStudioPersonalDataEraserInterface::class));
        self::assertSame(DoctrineApiStudioPersonalData::class, (string) $container->getAlias(ApiStudioPersonalDataExporterInterface::class));
        self::assertSame(DoctrineApiStudioHistoryRetention::class, (string) $container->getAlias(ApiStudioHistoryRetentionInterface::class));
        self::assertTrue($container->getAlias(ApiStudioHistoryRetentionInterface::class)->isPublic());
        self::assertSame(90, $container->getDefinition(DoctrineApiStudioHistoryRetention::class)->getArgument('$retentionDays'));
        self::assertSame(500, $container->getDefinition(DoctrineApiStudioPersonalData::class)->getArgument('$batchSize'));
        self::assertSame([['handles' => PurgeApiStudioHistory::class]], $container->getDefinition(PurgeApiStudioHistoryHandler::class)->getTag('messenger.message_handler'));
    }

    public function testTheUserKitEraserAndItsExportAreRegisteredTogether(): void
    {
        $container = $this->load([]);

        self::assertSame([['priority' => ApiStudioUserKitEraser::PRIORITY]], $container->getDefinition(ApiStudioUserKitEraser::class)->getTag('nowo_user_kit.personal_data.eraser'));
        self::assertSame([['priority' => ApiStudioUserKitExportProvider::PRIORITY]], $container->getDefinition(ApiStudioUserKitExportProvider::class)->getTag('nowo_user_kit.personal_data.export_provider'));
    }

    public function testTheUserKitAdaptersCanBeTurnedOff(): void
    {
        $container = $this->load([['personal_data' => ['enabled' => false]]]);

        self::assertFalse($container->hasDefinition(ApiStudioUserKitEraser::class));
        self::assertFalse($container->hasDefinition(ApiStudioUserKitExportProvider::class));
        self::assertTrue($container->hasAlias(ApiStudioPersonalDataEraserInterface::class), 'the ports stay');
    }

    public function testRetentionIsConfigurable(): void
    {
        $container = $this->load([['history' => ['retention_days' => 0, 'purge_batch_size' => 50]]]);

        self::assertSame(0, $container->getDefinition(DoctrineApiStudioHistoryRetention::class)->getArgument('$retentionDays'));
        self::assertSame(50, $container->getDefinition(DoctrineApiStudioHistoryRetention::class)->getArgument('$batchSize'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidValues(): iterable
    {
        yield 'negative days' => [['history' => ['retention_days' => -1]]];
        yield 'over ten years' => [['history' => ['retention_days' => 3651]]];
        yield 'tiny batch' => [['history' => ['purge_batch_size' => 9]]];
        yield 'huge batch' => [['history' => ['purge_batch_size' => 5001]]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidValues')]
    public function testOutOfRangeValuesAreRefused(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load([$config]);
    }

    public function testThePersonalDataSwitchIsALiteralBoolean(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.environment', 'prod');
        $container->registerExtension(new ApiStudioExtension());
        $container->loadFromExtension('nowo_api_studio', ['personal_data' => ['enabled' => '%env(bool:API_STUDIO_GDPR)%']]);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('personal_data.enabled must be a literal boolean');
        (new MergeExtensionConfigurationPass())->process($container);
    }

    /**
     * @param list<array<string, mixed>> $configs
     */
    private function load(array $configs): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new ApiStudioExtension())->load($configs, $container);

        return $container;
    }
}
