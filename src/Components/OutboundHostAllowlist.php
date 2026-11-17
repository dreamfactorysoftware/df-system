<?php

namespace DreamFactory\Core\System\Components;

use Illuminate\Support\Facades\Log;

/**
 * Destinations on the internal network that remote URLs (import sources,
 * SOAP endpoints) may point at, as configured by the operator through
 * DF_OUTBOUND_ALLOWED_HOSTS (config key df.outbound_allowed_hosts).
 *
 * The setting is a comma-separated list. Each entry is exactly one of:
 *
 *   - a hostname, e.g. soap.corp.local. It matches a URL whose host is that
 *     same name (case-insensitive, one trailing dot ignored). It never matches
 *     another name, a subdomain, or a different name that resolves to the same
 *     address.
 *   - an IPv4 or IPv6 address, e.g. 10.20.30.40. It matches when the URL host
 *     resolves to that address.
 *   - a CIDR range, e.g. 10.20.0.0/16 or fd00:1234::/48. It matches when the
 *     URL host resolves to an address inside the range. The prefix must be
 *     /8 or longer and the address must be the network address of the range.
 *
 * Wildcards are not supported. Entries that fit none of the forms above are
 * skipped with a warning in the log. An empty setting matches nothing.
 */
class OutboundHostAllowlist
{
    /**
     * Shortest prefix length accepted for a CIDR entry (IPv4 and IPv6).
     */
    const MIN_PREFIX_LENGTH = 8;

    /**
     * Longest accepted hostname, per RFC 1035.
     */
    const MAX_HOSTNAME_LENGTH = 253;

    /**
     * @var array<string, true> normalized hostnames
     */
    protected $hostnames = [];

    /**
     * @var array<int, array{0: string, 1: int}> packed network address and prefix length
     */
    protected $networks = [];

    /**
     * @var array<int, array{0: string, 1: string}> skipped entries and why
     */
    protected $skipped = [];

    /**
     * @var array{0: string, 1: OutboundHostAllowlist}|null the last configured value and its parsed list
     */
    protected static $configured = null;

    /**
     * @param string|array|null $entries A comma-separated string, or a list of entries.
     */
    public function __construct($entries = null)
    {
        if (is_string($entries)) {
            $entries = explode(',', $entries);
        } elseif (!is_array($entries)) {
            $entries = [];
        }

        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                $this->skip(is_scalar($entry) ? (string)$entry : gettype($entry), 'not a string');
                continue;
            }
            $this->addEntry(trim($entry));
        }
    }

    /**
     * The list configured in df.outbound_allowed_hosts. Parsed once per value, so
     * skipped entries are logged once rather than on every check.
     *
     * @return OutboundHostAllowlist
     */
    public static function fromConfig(): OutboundHostAllowlist
    {
        $value = static::configuredValue();
        try {
            $key = serialize($value);
        } catch (\Throwable $e) {
            $value = null;
            $key = serialize(null);
        }

        if (static::$configured === null || static::$configured[0] !== $key) {
            $list = new static($value);
            $list->logSkipped();
            static::$configured = [$key, $list];
        }

        return static::$configured[1];
    }

    /**
     * True when the destination is on the list: $host matches a hostname entry,
     * or $ip (the address $host resolved to) matches an address or range entry.
     *
     * @param string $host The URL host, without IPv6 brackets.
     * @param string $ip   The address the host resolved to.
     *
     * @return bool
     */
    public function allows(string $host, string $ip): bool
    {
        return $this->allowsAddress($ip) || $this->allowsHostname($host);
    }

    /**
     * True when $host matches a hostname entry exactly. IP literals never match
     * here; they are matched by address.
     *
     * @param string $host
     *
     * @return bool
     */
    public function allowsHostname(string $host): bool
    {
        if (empty($this->hostnames)) {
            return false;
        }

        $host = static::normalizeHostname($host);
        if ($host === '' || filter_var(static::stripBrackets($host), FILTER_VALIDATE_IP)) {
            return false;
        }

        return isset($this->hostnames[$host]);
    }

    /**
     * True when $ip is one of the listed addresses or inside one of the listed
     * ranges. Addresses only match entries of the same family.
     *
     * @param string $ip
     *
     * @return bool
     */
    public function allowsAddress(string $ip): bool
    {
        if (empty($this->networks)) {
            return false;
        }

        $ip = static::stripBrackets(trim($ip));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        foreach ($this->networks as [$network, $prefix]) {
            if (strlen($network) === strlen($packed) && static::maskAddress($packed, $prefix) === $network) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return bool True when the list has no usable entries.
     */
    public function isEmpty(): bool
    {
        return empty($this->hostnames) && empty($this->networks);
    }

    /**
     * Entries that were not used, each as [entry, reason].
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public function getSkippedEntries(): array
    {
        return $this->skipped;
    }

    /**
     * Lower-case a hostname and drop one trailing dot (the root label), so that
     * "Soap.Corp.Local." and "soap.corp.local" compare equal.
     *
     * @param string $host
     *
     * @return string
     */
    public static function normalizeHostname(string $host): string
    {
        $host = strtolower($host);
        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        return $host;
    }

    /**
     * Parse one entry into a hostname, address or range.
     *
     * @param string $entry
     */
    protected function addEntry(string $entry): void
    {
        if ($entry === '') {
            return;
        }

        if (str_contains($entry, '*')) {
            $this->skip($entry, 'wildcards are not supported, list each hostname or use a CIDR range');

            return;
        }

        if (str_contains($entry, '/')) {
            $this->addRange($entry);

            return;
        }

        $address = static::stripBrackets($entry);
        if (filter_var($address, FILTER_VALIDATE_IP)) {
            $packed = inet_pton($address);
            $this->networks[] = [$packed, strlen($packed) * 8];

            return;
        }

        $host = static::normalizeHostname($entry);
        if (static::isValidHostname($host)) {
            $this->hostnames[$host] = true;

            return;
        }

        $this->skip($entry, 'not a hostname, IP address or CIDR range');
    }

    /**
     * Parse a CIDR entry such as 10.20.0.0/16.
     *
     * @param string $entry
     */
    protected function addRange(string $entry): void
    {
        $parts = explode('/', $entry);
        if (count($parts) !== 2 || !filter_var($parts[0], FILTER_VALIDATE_IP)) {
            $this->skip($entry, 'not a hostname, IP address or CIDR range');

            return;
        }

        if (!preg_match('/^\d{1,3}\z/', $parts[1])) {
            $this->skip($entry, 'not a valid CIDR range');

            return;
        }

        $packed = inet_pton($parts[0]);
        $prefix = (int)$parts[1];

        if ($packed === false || $prefix > strlen($packed) * 8) {
            $this->skip($entry, 'not a valid CIDR range');

            return;
        }

        if ($prefix < static::MIN_PREFIX_LENGTH) {
            $this->skip($entry, 'ranges must be /' . static::MIN_PREFIX_LENGTH . ' or narrower');

            return;
        }

        if (static::maskAddress($packed, $prefix) !== $packed) {
            $this->skip($entry, 'the address is not the network address of the range');

            return;
        }

        $this->networks[] = [$packed, $prefix];
    }

    /**
     * Letters, digits, hyphens and underscores in dot-separated labels of up to
     * 63 characters. All-numeric names (alternate IPv4 notations such as
     * 167772161 or 10.1) are not hostnames.
     *
     * @param string $host A normalized hostname.
     *
     * @return bool
     */
    protected static function isValidHostname(string $host): bool
    {
        if ($host === '' || strlen($host) > static::MAX_HOSTNAME_LENGTH) {
            return false;
        }

        $label = '[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?';
        if (!preg_match('/^' . $label . '(?:\.' . $label . ')*\z/', $host)) {
            return false;
        }

        $numeric = '(?:0x[0-9a-f]*|[0-9]+)';
        if (preg_match('/^' . $numeric . '(?:\.' . $numeric . ')*\z/i', $host)) {
            return false;
        }

        return true;
    }

    /**
     * Zero every bit of a packed address after the first $prefix bits.
     *
     * @param string $packed
     * @param int    $prefix
     *
     * @return string
     */
    protected static function maskAddress(string $packed, int $prefix): string
    {
        $masked = '';
        $length = strlen($packed);
        for ($i = 0; $i < $length; $i++) {
            $bits = max(0, min(8, $prefix - $i * 8));
            $mask = (0xFF << (8 - $bits)) & 0xFF;
            $masked .= chr(ord($packed[$i]) & $mask);
        }

        return $masked;
    }

    /**
     * @param string $value
     *
     * @return string $value without surrounding IPv6 brackets.
     */
    protected static function stripBrackets(string $value): string
    {
        if (strlen($value) > 2 && $value[0] === '[' && str_ends_with($value, ']')) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    /**
     * The raw df.outbound_allowed_hosts value, or null outside a booted application.
     *
     * @return mixed
     */
    protected static function configuredValue()
    {
        try {
            return config('df.outbound_allowed_hosts');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param string $entry
     * @param string $reason
     */
    protected function skip(string $entry, string $reason): void
    {
        $this->skipped[] = [$entry, $reason];
    }

    /**
     * Write one warning per skipped entry.
     */
    protected function logSkipped(): void
    {
        foreach ($this->skipped as [$entry, $reason]) {
            try {
                Log::warning(sprintf(
                    'DF_OUTBOUND_ALLOWED_HOSTS: ignoring entry "%s": %s.',
                    substr($entry, 0, 255),
                    $reason
                ));
            } catch (\Throwable $e) {
                // No logger outside a booted application.
            }
        }
    }
}
