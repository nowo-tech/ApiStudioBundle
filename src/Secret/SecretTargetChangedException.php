<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Secret;

use RuntimeException;

/**
 * A request carrying host secrets would leave the origin they were resolved for (1.13, QE-D-L1: a WSDL naming a
 * `soap:address` on another origin). Nothing is sent; the execution answers `secret_target_changed`.
 *
 * @internal
 */
final class SecretTargetChangedException extends RuntimeException
{
}
