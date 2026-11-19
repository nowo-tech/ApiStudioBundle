<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Tests\Functional\Scope;

use Nowo\ApiStudioBundle\Secret\ApiStudioSecretRef;
use Nowo\ApiStudioBundle\Secret\ApiStudioSecretSourceInterface;
use Nowo\ApiStudioBundle\Secret\ApiStudioSecretValue;

/**
 * A host secret source that resolves nothing (the scope tests only store references).
 */
final class TestScopeSecretSource implements ApiStudioSecretSourceInterface
{
    public function resolve(ApiStudioSecretRef $ref): ?ApiStudioSecretValue
    {
        return null;
    }
}
