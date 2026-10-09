<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ApiStudioBundle\Doctrine\SecretVariableEncryptionListener;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Security\SecretValueCipher;
use PHPUnit\Framework\TestCase;

use function dirname;
use function extension_loaded;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

final class SecretVariableEncryptionListenerTest extends TestCase
{
    private SecretValueCipher $cipher;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }

        $this->cipher = new SecretValueCipher('test-key-material');
    }

    public function testNewSecretIsStoredEncryptedAndKeptInPlaintextInMemory(): void
    {
        $em       = $this->createEntityManager();
        $variable = $this->createVariable($em, 'api_token', 'plain-1', true);

        self::assertSame('plain-1', $variable->getValue());
        self::assertTrue($this->cipher->isEncrypted($this->storedValue($em, $variable)));
        self::assertSame('plain-1', $this->cipher->decrypt($this->storedValue($em, $variable)));
    }

    public function testEditedSecretIsStoredEncrypted(): void
    {
        $em       = $this->createEntityManager();
        $variable = $this->createVariable($em, 'api_token', 'plain-1', true);
        $id       = $variable->getId();
        $em->clear();

        $loaded = $em->find(ApiEnvironmentVariable::class, $id);
        self::assertInstanceOf(ApiEnvironmentVariable::class, $loaded);
        self::assertSame('plain-1', $loaded->getValue());

        $loaded->setValue('plain-2');
        $em->flush();

        $stored = $this->storedValue($em, $loaded);
        self::assertTrue($this->cipher->isEncrypted($stored), 'Edited secret must not be stored in plaintext.');
        self::assertSame('plain-2', $this->cipher->decrypt($stored));
        self::assertSame('plain-2', $loaded->getValue());
    }

    public function testConsecutiveRequestsWithoutClearSeePlaintextOnTheManagedEntity(): void
    {
        $em          = $this->createEntityManager();
        $variable    = $this->createVariable($em, 'api_token', 'first', true);
        $environment = $variable->getEnvironment();
        self::assertInstanceOf(ApiEnvironment::class, $environment);

        // Request 1 edits the secret; no reset/clear happens before request 2.
        $variable->setValue('second');
        $em->flush();

        // Request 2 resolves variables from the same managed entities.
        self::assertSame(['api_token' => 'second'], $environment->getVariableMap());
        self::assertSame('second', $this->cipher->decrypt($this->storedValue($em, $variable)));

        // A flush with no user change must keep the row encrypted.
        $em->flush();
        self::assertTrue($this->cipher->isEncrypted($this->storedValue($em, $variable)));
        self::assertSame(['api_token' => 'second'], $environment->getVariableMap());
    }

    public function testMarkingExistingVariableAsSecretEncryptsUnchangedValue(): void
    {
        $em       = $this->createEntityManager();
        $variable = $this->createVariable($em, 'api_token', 'was-public', false);
        self::assertSame('was-public', $this->storedValue($em, $variable));

        $variable->setSecret(true);
        $em->flush();

        self::assertSame('was-public', $this->cipher->decrypt($this->storedValue($em, $variable)));
        self::assertTrue($this->cipher->isEncrypted($this->storedValue($em, $variable)));
        self::assertSame('was-public', $variable->getValue());
    }

    public function testMarkingNewVariableAsSecretAfterPersistEncryptsIt(): void
    {
        $em        = $this->createEntityManager();
        $workspace = new ApiWorkspace('Ws', 'ws');
        $env       = new ApiEnvironment('Env', 'env');
        $env->setWorkspace($workspace);
        $variable = new ApiEnvironmentVariable('late_secret', 'late');
        $env->addVariable($variable);
        $em->persist($workspace);
        $em->persist($env);
        $em->persist($variable);

        $variable->setSecret(true);
        $em->flush();

        self::assertTrue($this->cipher->isEncrypted($this->storedValue($em, $variable)));
        self::assertSame('late', $variable->getValue());
    }

    public function testUnmarkingSecretStoresPlaintext(): void
    {
        $em       = $this->createEntityManager();
        $variable = $this->createVariable($em, 'api_token', 'secret-value', true);
        $id       = $variable->getId();
        $em->clear();

        $loaded = $em->find(ApiEnvironmentVariable::class, $id);
        self::assertInstanceOf(ApiEnvironmentVariable::class, $loaded);
        $loaded->setSecret(false);
        $em->flush();

        self::assertSame('secret-value', $this->storedValue($em, $loaded));
    }

    public function testDisabledListenerStoresPlaintext(): void
    {
        $em       = $this->createEntityManager(false);
        $variable = $this->createVariable($em, 'api_token', 'plain', true);

        $variable->setValue('plain-2');
        $em->flush();

        self::assertSame('plain-2', $this->storedValue($em, $variable));
    }

    public function testNonSecretVariablesAreNotTouched(): void
    {
        $em       = $this->createEntityManager();
        $variable = $this->createVariable($em, 'base_url', 'https://a.example', false);

        $variable->setValue('https://b.example');
        $em->flush();

        self::assertSame('https://b.example', $this->storedValue($em, $variable));
    }

    private function createEntityManager(bool $enabled = true): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            [dirname(__DIR__, 3) . '/src/Entity'],
            true,
            sys_get_temp_dir() . '/nowo_api_studio_test_proxies',
        );
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $em         = new EntityManager($connection, $config);

        $listener = new SecretVariableEncryptionListener($this->cipher, $enabled);
        $em->getEventManager()->addEventListener(
            [Events::prePersist, Events::postPersist, Events::onFlush, Events::preUpdate, Events::postUpdate, Events::postLoad],
            $listener,
        );

        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        return $em;
    }

    private function createVariable(EntityManager $em, string $key, string $value, bool $secret): ApiEnvironmentVariable
    {
        $workspace = new ApiWorkspace('Ws', 'ws');
        $env       = new ApiEnvironment('Env', 'env');
        $env->setWorkspace($workspace);
        $variable = (new ApiEnvironmentVariable($key, $value))->setSecret($secret);
        $env->addVariable($variable);

        $em->persist($workspace);
        $em->persist($env);
        $em->persist($variable);
        $em->flush();

        return $variable;
    }

    private function storedValue(EntityManager $em, ApiEnvironmentVariable $variable): string
    {
        $table = $em->getClassMetadata(ApiEnvironmentVariable::class)->getTableName();

        return (string) $em->getConnection()->fetchOne('SELECT value FROM ' . $table . ' WHERE id = ?', [$variable->getId()]);
    }
}
