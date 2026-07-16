<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

use Psr\Log\LoggerInterface;

use function filter_var;
use function gethostbyname;
use function is_string;
use function preg_last_error;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strtolower;

use const FILTER_FLAG_IPV4;
use const FILTER_FLAG_IPV6;
use const FILTER_VALIDATE_IP;
use const PHP_URL_HOST;

/**
 * Validates outbound execution URLs: SSRF mitigation and optional host allowlist.
 */
final class ExecutionUrlValidator
{
    /**
     * @param list<string> $allowlist Substring patterns or regex (prefix #). Empty = any public URL (still subject to SSRF check).
     */
    public function __construct(
        private readonly array $allowlist,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Returns true when the URL must not be fetched (SSRF or allowlist violation).
     */
    public function isBlocked(string $url): bool
    {
        if ($this->isBlockedForSsrf($url)) {
            return true;
        }

        return !$this->isAllowedByAllowlist($url);
    }

    /**
     * Blocks private/local hosts and cloud metadata endpoints.
     */
    public function isBlockedForSsrf(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return true;
        }

        $host = trim($host, '[]');
        if ($host === '') {
            return true;
        }

        $hostLower = strtolower($host);
        if ($hostLower === 'localhost'
            || $hostLower === '::1'
            || str_starts_with($hostLower, 'fe80:')
            || $hostLower === 'metadata.google.internal'
        ) {
            return true;
        }

        $ip = $host;
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            $resolved = gethostbyname($host);
            if ($resolved === $host) {
                return false;
            }
            $ip = $resolved;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                // @codeCoverageIgnoreStart
                return true;
                // @codeCoverageIgnoreEnd
            }
            $u = (float) sprintf('%u', $long);

            // 127.0.0.0/8, 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 169.254.0.0/16
            return ($u >= 2130706432 && $u <= 2147483647)
                || ($u >= 167772160 && $u <= 184549375)
                || ($u >= 2886729728 && $u <= 2887778303)
                || ($u >= 3232235520 && $u <= 3232301055)
                || ($u >= 2851995648 && $u <= 2852061183);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return str_starts_with($ip, '::1') || str_starts_with($ip, 'fe80:');
        }

        // @codeCoverageIgnoreStart
        return false;
        // @codeCoverageIgnoreEnd
    }

    /**
     * When allowlist is empty, returns true (caller must still enforce SSRF).
     */
    public function isAllowedByAllowlist(string $url): bool
    {
        foreach ($this->allowlist as $pattern) {
            if ($pattern === '') {
                continue;
            }
            if (str_starts_with($pattern, '#')) {
                $matched = @preg_match($pattern, $url);
                if ($matched === 1) {
                    return true;
                }
                if ($matched === false && $this->logger instanceof LoggerInterface) {
                    $this->logger->warning('Invalid regex in execution_url_allowlist, pattern skipped', [
                        'pattern'    => $pattern,
                        'preg_error' => preg_last_error(),
                    ]);
                }
                continue;
            }
            if (str_contains($url, $pattern)) {
                return true;
            }
        }

        return $this->allowlist === [];
    }
}
