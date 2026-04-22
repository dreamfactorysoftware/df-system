<?php

namespace DreamFactory\Core\System\Components;

use DreamFactory\Core\Exceptions\BadRequestException;

/**
 * SSRF (Server-Side Request Forgery) protection for import URLs.
 *
 * Enforces that any URL accepted as an import source:
 *   - Uses only http or https scheme
 *   - Resolves to a publicly routable IP address
 *   - Does not point at private, loopback, link-local, or reserved ranges
 *
 * All three import surfaces (App, Import, Package) funnel through
 * validateExternalUrl() before the URL is passed to a fetch/download call.
 */
class SsrfValidator
{
    /**
     * Allowed URL schemes.
     */
    const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * CIDR ranges that must never be reachable via an import URL.
     * Covers loopback, link-local, RFC-1918 private space, and documentation
     * ranges that have no legitimate use as external package sources.
     */
    const BLOCKED_CIDRS = [
        // Loopback
        '127.0.0.0/8',
        // Link-local / AWS metadata
        '169.254.0.0/16',
        // RFC 1918 private
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        // Shared address (RFC 6598, carrier-grade NAT)
        '100.64.0.0/10',
        // IETF protocol / documentation / test
        '192.0.0.0/24',
        '192.0.2.0/24',
        '198.51.100.0/24',
        '203.0.113.0/24',
        // Multicast
        '224.0.0.0/4',
        // Reserved / broadcast
        '240.0.0.0/4',
        '255.255.255.255/32',
    ];

    /**
     * Validate that $url is safe to use as a remote import source.
     *
     * @param  string $url  The URL supplied by the API caller.
     * @return string       The original URL, returned for fluent use.
     *
     * @throws BadRequestException  When the URL fails any safety check.
     */
    public static function validateExternalUrl(string $url): string
    {
        // --- 1. Parse the URL ---
        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new BadRequestException(
                'Invalid import URL: URL could not be parsed. ' .
                'Please supply a full URL including scheme and host.'
            );
        }

        // --- 2. Scheme whitelist ---
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new BadRequestException(
                "Invalid import URL: scheme \"$scheme\" is not allowed. " .
                'Only http and https URLs are accepted.'
            );
        }

        // --- 3. Resolve hostname to IP ---
        $host = $parts['host'];

        // Strip IPv6 brackets so dns_get_record / ip2long work correctly.
        $rawHost = ltrim(rtrim($host, ']'), '[');

        // Check for IPv6 loopback / unspecified addresses before DNS lookup.
        if (static::isBlockedIpv6($rawHost)) {
            throw new BadRequestException(
                "Invalid import URL: the host \"$host\" resolves to a reserved or " .
                'private address that is not allowed.'
            );
        }

        // Resolve hostname. dns_get_record returns false on failure; fall back
        // to gethostbyname which returns the original string on failure.
        $resolvedIp = static::resolveHost($rawHost);

        if ($resolvedIp === null) {
            throw new BadRequestException(
                "Invalid import URL: hostname \"$host\" could not be resolved."
            );
        }

        // --- 4. Check resolved IP against blocked CIDR ranges ---
        if (static::isPrivateOrReservedIp($resolvedIp)) {
            throw new BadRequestException(
                "Invalid import URL: the host \"$host\" resolves to a private or " .
                "reserved IP address ($resolvedIp) and cannot be used as an import source."
            );
        }

        return $url;
    }

    /**
     * Resolve a hostname (or bare IP string) to its IPv4 address string.
     * Returns null when resolution fails entirely.
     *
     * Protected so tests can override DNS behaviour.
     *
     * @param  string $host
     * @return string|null
     */
    protected static function resolveHost(string $host): ?string
    {
        // If the caller already gave us a numeric IPv4 address, use it directly.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $host;
        }

        // For IPv6 literals we do our check in isBlockedIpv6().
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $host;
        }

        // DNS lookup.
        $resolved = gethostbyname($host);

        // gethostbyname() returns the original string on failure.
        if ($resolved === $host) {
            return null;
        }

        return $resolved;
    }

    /**
     * Return true when $ip falls inside any of the BLOCKED_CIDRS ranges.
     *
     * @param  string $ip  A valid IPv4 or IPv6 address string.
     * @return bool
     */
    public static function isPrivateOrReservedIp(string $ip): bool
    {
        // IPv6 handled separately.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return static::isBlockedIpv6($ip);
        }

        $long = ip2long($ip);
        if ($long === false) {
            // Unparseable — treat as blocked to be safe.
            return true;
        }

        foreach (self::BLOCKED_CIDRS as $cidr) {
            [$network, $prefix] = explode('/', $cidr);
            $networkLong = ip2long($network);
            $mask = $prefix === '32' ? 0xFFFFFFFF : ~((1 << (32 - (int)$prefix)) - 1);
            // Cast to unsigned to handle PHP's signed 32-bit integers.
            if (($long & $mask) === ($networkLong & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return true for IPv6 addresses that should always be blocked:
     * loopback (::1) and the unspecified address (::).
     *
     * @param  string $ip
     * @return bool
     */
    protected static function isBlockedIpv6(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return false;
        }

        // Expand to full form for reliable comparison.
        $packed = inet_pton($ip);
        if ($packed === false) {
            return true; // Unparseable — block it.
        }

        // ::1 loopback
        $loopback = inet_pton('::1');
        // :: unspecified
        $unspecified = inet_pton('::');
        // fc00::/7 — unique local addresses (private space equivalent)
        $firstByte = ord($packed[0]);

        if ($packed === $loopback || $packed === $unspecified) {
            return true;
        }

        // fc00::/7 covers fc00:: through fdff::
        if (($firstByte & 0xFE) === 0xFC) {
            return true;
        }

        // fe80::/10 — link-local
        $firstTwo = (ord($packed[0]) << 8) | ord($packed[1]);
        if (($firstTwo & 0xFFC0) === 0xFE80) {
            return true;
        }

        return false;
    }
}
