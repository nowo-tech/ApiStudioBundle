<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\ApiStudioBundle\Doctrine\SecretVariableEncryptionListener;
use Nowo\ApiStudioBundle\Entity\ApiEnvironment;
use Nowo\ApiStudioBundle\Entity\ApiEnvironmentVariable;
use Nowo\ApiStudioBundle\Entity\ApiWorkspace;
use Nowo\ApiStudioBundle\Security\SecretValueCipher;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

use function base64_encode;
use function dirname;
use function extension_loaded;
use function hash;
use function json_encode;
use function random_bytes;
use function sodium_crypto_secretbox;
use function str_repeat;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;
use const SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

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

    public function testSecretThatLooksEncryptedIsNotStoredInClearAndReloads(): void
    {
        // BW audit L4: a value starting with the cipher prefix (typed, imported, persisted by a script).
        $em       = $this->createEntityManager();
        $variable = $this->createVariable($em, 'api_token', 'nowo_as_enc_v1:not-really-encrypted', true);
        $id       = $variable->getId();

        $stored = $this->storedValue($em, $variable);
        self::assertNotSame('nowo_as_enc_v1:not-really-encrypted', $stored, 'Never stored in clear.');
        self::assertSame('nowo_as_enc_v1:not-really-encrypted', $this->cipher->openStored($stored));

        $variable->setValue('nowo_as_enc_v1:edited');
        $em->flush();
        self::assertSame('nowo_as_enc_v1:edited', $this->cipher->openStored($this->storedValue($em, $variable)));

        $em->clear();
        $loaded = $em->find(ApiEnvironmentVariable::class, $id);
        self::assertInstanceOf(ApiEnvironmentVariable::class, $loaded);
        self::assertSame('nowo_as_enc_v1:edited', $loaded->getValue());
    }

    public function testTypedValueThatIsAWellFormedPayloadReadsBackAsTyped(): void
    {
        // EQ-I1: a typed value shaped like a payload (even a real one of this key) is a value, never a payload.
        $typed    = $this->cipher->encrypt('someone-elses-secret');
        $foreign  = (new SecretValueCipher('placeholder-other-key'))->encrypt('x');
        $em       = $this->createEntityManager();
        $variable = $this->createVariable($em, 'api_token', $typed, true);
        $other    = $this->addVariable($em, $variable, 'other_token', $foreign);
        self::assertNotSame($typed, $this->storedValue($em, $variable), 'EQ-L1 side effect: never stored as given.');
        $em->clear();

        $loaded = $em->find(ApiEnvironmentVariable::class, $variable->getId());
        self::assertInstanceOf(ApiEnvironmentVariable::class, $loaded);
        self::assertFalse($loaded->isUnreadable());
        self::assertSame($typed, $loaded->getValue());

        $loadedOther = $em->find(ApiEnvironmentVariable::class, $other->getId());
        self::assertInstanceOf(ApiEnvironmentVariable::class, $loadedOther);
        self::assertFalse($loadedOther->isUnreadable(), 'a typed foreign payload is not "needs re-entry"');
        self::assertSame($foreign, $loadedOther->getValue());
    }

    public function testWithEncryptionOffAPayloadShapedValueIsStillEncryptedAndReadsBack(): void
    {
        $typed    = 'nowo_as_enc_v1:' . base64_encode(str_repeat('y', 48));
        $em       = $this->createEntityManager(false);
        $variable = $this->createVariable($em, 'api_token', $typed, true);
        $plain    = $this->addVariable($em, $variable, 'plain_token', 'just-text');
        self::assertSame('just-text', $this->storedValue($em, $plain), 'encryption off: plain values stay plain');
        $em->clear();

        $loaded = $em->find(ApiEnvironmentVariable::class, $variable->getId());
        self::assertInstanceOf(ApiEnvironmentVariable::class, $loaded);
        self::assertFalse($loaded->isUnreadable());
        self::assertSame($typed, $loaded->getValue());
    }

    public function testPastingTheStoredPayloadKeepsTheVariableUnreadable(): void
    {
        // EQ-I3: the payload itself is not a new value; the flag stays and the ciphertext is never handed out.
        [$em, $loaded] = $this->loadUnreadable('nowo_as_enc_v1:corrupt');
        $loaded->setValue($loaded->getStoredValue());

        self::assertTrue($loaded->isUnreadable());
        self::assertSame('', $loaded->getValue());
        $em->flush();
        self::assertSame('nowo_as_enc_v1:corrupt', $this->storedValue($em, $loaded));
    }

    public function testUndecryptableRowDoesNotBreakLoading(): void
    {
        $em       = $this->createEntityManager();
        $variable = $this->createVariable($em, 'api_token', 'plain', true);
        $id       = $variable->getId();
        $table    = $em->getClassMetadata(ApiEnvironmentVariable::class)->getTableName();
        $em->getConnection()->executeStatement('UPDATE ' . $table . ' SET value = ? WHERE id = ?', ['nowo_as_enc_v1:corrupt', $id]);
        $em->clear();

        $loaded = $em->find(ApiEnvironmentVariable::class, $id);

        self::assertInstanceOf(ApiEnvironmentVariable::class, $loaded);
        self::assertTrue($loaded->isUnreadable(), 'Loads (no 500) and is flagged for re-entry.');
        self::assertSame('', $loaded->getValue(), 'The payload is never handed out as a value.');
        self::assertSame('nowo_as_enc_v1:corrupt', $loaded->getStoredValue());
        $environment = $loaded->getEnvironment();
        self::assertInstanceOf(ApiEnvironment::class, $environment);
        self::assertSame([], $environment->getVariableMap());
        self::assertSame(['api_token'], $environment->getUnreadableVariableKeys());
    }

    public function testUnreadableSecretIsNeverWrappedAgainWhenTheVariableIsSaved(): void
    {
        [$em, $loaded] = $this->loadUnreadable('nowo_as_enc_v1:' . base64_encode(str_repeat('x', 40)));
        $before        = $this->storedValue($em, $loaded);

        // Another field changes and the write-only secret field submits '' ("unchanged"): the payload stays as is.
        $loaded->setDescription('rotated key?');
        $loaded->setValue('');
        $em->flush();

        self::assertSame($before, $this->storedValue($em, $loaded), 'Byte-identical: never re-encrypted as ciphertext.');
        self::assertTrue($loaded->isUnreadable());
        self::assertSame('rotated key?', $loaded->getDescription());
    }

    public function testReenteredValueReplacesTheUnreadablePayload(): void
    {
        [$em, $loaded] = $this->loadUnreadable('nowo_as_enc_v1:corrupt');

        $loaded->setValue('fresh-value');
        $em->flush();

        self::assertFalse($loaded->isUnreadable());
        self::assertSame('fresh-value', $loaded->getValue());
        self::assertSame('fresh-value', $this->cipher->decrypt($this->storedValue($em, $loaded)));
    }

    public function testUnreadableSecretCannotBeTurnedIntoAPlainValue(): void
    {
        [$em, $loaded] = $this->loadUnreadable('nowo_as_enc_v1:corrupt');

        $loaded->setSecret(false);
        $em->flush();

        self::assertTrue($loaded->isSecret());
        self::assertTrue($loaded->isSecretChangeRefused());
        self::assertSame('nowo_as_enc_v1:corrupt', $this->storedValue($em, $loaded));
    }

    public function testUnreadableSecretIsLoggedWithoutItsValue(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, array<string, mixed>}> */
            public array $logs = [];

            /** @param array<string, mixed> $context */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->logs[] = [(string) $message, $context];
            }
        };

        $this->loadUnreadable('nowo_as_enc_v1:corrupt', true, $logger);

        self::assertCount(1, $logger->logs);
        self::assertSame('secret_unreadable', $logger->logs[0][1]['action']);
        self::assertStringNotContainsString('corrupt', (string) json_encode($logger->logs));
    }

    public function testPayloadsAreDecryptedOrFlaggedWithEncryptionTurnedOff(): void
    {
        [, $loaded] = $this->loadUnreadable($this->cipher->encrypt('still-readable'), false);
        self::assertFalse($loaded->isUnreadable());
        self::assertSame('still-readable', $loaded->getValue(), 'A payload is never sent as the value, even with encryption off.');

        [, $broken] = $this->loadUnreadable('nowo_as_enc_v1:corrupt', false);
        self::assertTrue($broken->isUnreadable());
    }

    public function testPayloadWrappedTwiceByAnOlderReleaseIsUnwrappedOrFlagged(): void
    {
        // Outer layer built by hand: encrypt() returns a payload of its own key unchanged.
        $nonce         = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $outer         = SecretValueCipher::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($this->cipher->encrypt('inner-value'), $nonce, hash('sha256', 'test-key-material', true)));
        [, $recovered] = $this->loadUnreadable($outer, true);
        self::assertFalse($recovered->isUnreadable());
        self::assertSame('inner-value', $recovered->getValue());

        $wrappedForeign = $this->cipher->encrypt((new SecretValueCipher('placeholder-old-key'))->encrypt('lost'));
        // encrypt() keeps a payload of this key as is: a foreign payload is wrapped, as the older listener did.
        [, $lost] = $this->loadUnreadable($wrappedForeign, true);
        self::assertTrue($lost->isUnreadable());
        self::assertSame('', $lost->getValue());
    }

    /**
     * @return array{EntityManager, ApiEnvironmentVariable}
     */
    private function loadUnreadable(string $stored, bool $enabled = true, ?LoggerInterface $logger = null): array
    {
        $em       = $this->createEntityManager($enabled, $logger);
        $variable = $this->createVariable($em, 'api_token', 'plain', true);
        $id       = $variable->getId();
        $table    = $em->getClassMetadata(ApiEnvironmentVariable::class)->getTableName();
        $em->getConnection()->executeStatement('UPDATE ' . $table . ' SET value = ? WHERE id = ?', [$stored, $id]);
        $em->clear();

        $loaded = $em->find(ApiEnvironmentVariable::class, $id);
        self::assertInstanceOf(ApiEnvironmentVariable::class, $loaded);

        return [$em, $loaded];
    }

    public function testPreUpdateLeavesNonStringNewValuesAlone(): void
    {
        $variable  = (new ApiEnvironmentVariable('api_token', 'plain'))->setSecret(true);
        $changeSet = ['value' => ['plain', null]];
        $args      = new PreUpdateEventArgs($variable, $this->createStub(EntityManagerInterface::class), $changeSet);

        (new SecretVariableEncryptionListener($this->cipher, true))->preUpdate($args);

        self::assertNull($args->getNewValue('value'));
    }

    private function createEntityManager(bool $enabled = true, ?LoggerInterface $logger = null): EntityManager
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

        $listener = new SecretVariableEncryptionListener($this->cipher, $enabled, $logger);
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

    private function addVariable(EntityManager $em, ApiEnvironmentVariable $sibling, string $key, string $value): ApiEnvironmentVariable
    {
        $environment = $sibling->getEnvironment();
        self::assertInstanceOf(ApiEnvironment::class, $environment);
        $variable = (new ApiEnvironmentVariable($key, $value))->setSecret(true);
        $environment->addVariable($variable);
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
