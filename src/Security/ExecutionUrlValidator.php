<?php

declare(strict_types=1);

namespace Nowo\ApiStudioBundle\Security;

use Closure;
use Nowo\ApiStudioBundle\Service\RequestExecutor;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpFoundation\IpUtils;

use function array_column;
use function dns_get_record;
use function filter_var;
use function gethostbynamel;
use function is_array;
use function is_string;
use function parse_url;
use function preg_last_error;
use function preg_match;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

use const DNS_AAAA;
use const FILTER_VALIDATE_IP;
use const PHP_URL_HOST;

/**
 * Validates outbound execution URLs: SSRF mitigation and optional host allowlist.
 *
 * This is a pre-flight check only; {@see RequestExecutor} also wraps its HTTP client in
 * {@see NoPrivateNetworkHttpClient}, which re-checks the IP actually connected to
 * (DNS rebinding) and every redirect hop.
 */
final class ExecutionUrlValidator
{
    /**
     * Private, loopback, link-local, CGNAT, reserved, documentation, IPv4-mapped/compatible IPv6 and multicast ranges.
     */
    public const BLOCKED_SUBNETS = [
        ...IpUtils::PRIVATE_SUBNETS,
        '224.0.0.0/4',  // IPv4 multicast
        'ff00::/8',     // IPv6 multicast
    ];

    /** @var Closure(string): list<string> */
    private readonly Closure $resolver;

    /**
     * @param list<string> $allowlist Host patterns ("api.example.com", ".example.com" for subdomains,
     *                                "https://api.example.com/v1" for scheme/port/path prefix) or regex (prefix #).
     *                                Empty = any public URL (still subject to SSRF check).
     * @param (Closure(string): list<string>)|null $resolver resolves a host name to its A/AAAA addresses (tests)
     */
    public function __construct(
        private readonly array $allowlist,
        private readonly ?LoggerInterface $logger = null,
        ?Closure $resolver = null,
    ) {
        $this->resolver = $resolver ?? self::resolveHost(...);
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
     * Blocks private/local/reserved hosts and cloud metadata endpoints. Fails closed when the host does not resolve.
     */
    public function isBlockedForSsrf(string $url): bool
    {
        $host = self::normalizedHost($url);
        if ($host === null) {
            return true;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === 'metadata.google.internal') {
            return true;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver)($host);
        if ($ips === []) {
            return true;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false || IpUtils::checkIp($ip, self::BLOCKED_SUBNETS)) {
                return true;
            }
        }

        return false;
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
            if (self::matchesPlainPattern($pattern, $url)) {
                return true;
            }
        }

        return $this->allowlist === [];
    }

    /**
     * Plain entries compare the parsed host exactly ("api.example.com"), or as a suffix when they start with "."
     * or "*." (".example.com" matches example.com and any subdomain). An entry with a scheme
     * ("https://api.example.com:8443/v1") also pins scheme, port (when given) and a path prefix.
     */
    private static function matchesPlainPattern(string $pattern, string $url): bool
    {
        $host   = self::normalizedHost($url);
        $target = parse_url($url);
        $spec   = parse_url(str_contains($pattern, '://') ? trim($pattern) : '//' . trim($pattern));
        if ($host === null || !is_array($target) || !is_array($spec) || !isset($spec['host'])) {
            return false;
        }

        if (isset($spec['scheme']) && strtolower($spec['scheme']) !== strtolower($target['scheme'] ?? '')) {
            return false;
        }
        if (isset($spec['port']) && $spec['port'] !== ($target['port'] ?? null)) {
            return false;
        }

        $prefix = rtrim($spec['path'] ?? '', '/');
        $path   = $target['path'] ?? '';
        if ($prefix !== '' && $path !== $prefix && !str_starts_with($path, $prefix . '/')) {
            return false;
        }

        $allowed = rtrim(strtolower(trim($spec['host'], '[]')), '.');
        if (str_starts_with($allowed, '*.')) {
            $allowed = substr($allowed, 1);
        }

        if (str_starts_with($allowed, '.')) {
            return $host === substr($allowed, 1) || str_ends_with($host, $allowed);
        }

        return $host === $allowed;
    }

    private static function normalizedHost(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) {
            return null;
        }

        $host = rtrim(strtolower(trim($host, '[]')), '.');

        return $host === '' ? null : $host;
    }

    /**
     * @return list<string>
     */
    private static function resolveHost(string $host): array
    {
        $aaaa = @dns_get_record($host, DNS_AAAA);

        return [...(gethostbynamel($host) ?: []), ...array_column(is_array($aaaa) ? $aaaa : [], 'ipv6')];
    }
}
