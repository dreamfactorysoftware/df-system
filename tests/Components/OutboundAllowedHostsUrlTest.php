<?php

namespace DreamFactory\Core\System\Tests\Components;

use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\System\Components\OutboundHostAllowlist;
use DreamFactory\Core\System\Components\SsrfValidator;
use PHPUnit\Framework\TestCase;

/**
 * External URL validation with DF_OUTBOUND_ALLOWED_HOSTS entries.
 * DNS answers come from a fixed table, so no lookups leave the test.
 */
class OutboundAllowedHostsUrlTest extends TestCase
{
    /**
     * Fixed DNS answers for the hostnames used below.
     */
    const DNS = [
        'soap.corp.local'       => '10.1.2.3',
        'other.corp.local'      => '10.1.2.3',
        'api.soap.corp.local'   => '10.1.2.4',
        'billing.corp.local'    => '10.20.5.6',
        'linklocal.corp.local'  => '169.254.1.1',
        'localhost'             => '127.0.0.1',
        'public.example.com'    => '93.184.216.34',
    ];

    /**
     * @var string class name of a validator that uses the configured entries and DNS table
     */
    private $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $validator = new class extends SsrfValidator {
            public static $entries = '';
            public static $dns = [];

            protected static function allowlist(): OutboundHostAllowlist
            {
                return new OutboundHostAllowlist(static::$entries);
            }

            protected static function resolveHost(string $host): ?string
            {
                if (filter_var($host, FILTER_VALIDATE_IP)) {
                    return $host;
                }

                return static::$dns[OutboundHostAllowlist::normalizeHostname($host)] ?? null;
            }
        };

        $this->validator = get_class($validator);
        $this->validator::$dns = self::DNS;
        $this->validator::$entries = '';
    }

    private function withEntries(string $entries): void
    {
        $this->validator::$entries = $entries;
    }

    private function assertAllowed(string $url): void
    {
        $this->assertSame($url, $this->validator::validateExternalUrl($url), "$url should be accepted");
    }

    private function assertRejected(string $url, string $messageFragment = ''): void
    {
        try {
            $this->validator::validateExternalUrl($url);
            $this->fail("$url should be rejected");
        } catch (BadRequestException $e) {
            if ($messageFragment !== '') {
                $this->assertStringContainsStringIgnoringCase($messageFragment, $e->getMessage());
            }
        }
    }

    // -------------------------------------------------------------------------
    // Default
    // -------------------------------------------------------------------------

    public function testEmptyListKeepsCurrentBehaviour(): void
    {
        $this->assertRejected('http://soap.corp.local/ws', 'private or reserved');
        $this->assertRejected('http://10.1.2.3/ws', 'private or reserved');
        $this->assertRejected('http://localhost:8080/ws', 'private or reserved');
        $this->assertRejected('http://[::1]/ws', 'reserved or private');
        $this->assertAllowed('https://public.example.com/ws');
    }

    public function testValidatorWithoutApplicationUsesEmptyList(): void
    {
        // The stock validator reads df.outbound_allowed_hosts; outside an
        // application there is no config, so the list is empty.
        try {
            SsrfValidator::validateExternalUrl('http://10.1.2.3/ws');
            $this->fail('Expected the private address to be rejected');
        } catch (BadRequestException $e) {
            $this->assertStringContainsString('private or reserved', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Hostname entries
    // -------------------------------------------------------------------------

    public function testListedHostnameIsAccepted(): void
    {
        $this->withEntries('soap.corp.local');

        $this->assertAllowed('http://soap.corp.local/ws');
        $this->assertAllowed('https://soap.corp.local:8443/ws?wsdl');
        $this->assertAllowed('http://SOAP.Corp.Local./ws');
    }

    public function testListedHostnameDoesNotAcceptOtherHostnames(): void
    {
        $this->withEntries('soap.corp.local');

        // Same address as soap.corp.local, different name.
        $this->assertRejected('http://other.corp.local/ws', 'private or reserved');
        // Subdomain of the listed name.
        $this->assertRejected('http://api.soap.corp.local/ws', 'private or reserved');
        // The address soap.corp.local resolves to, used directly.
        $this->assertRejected('http://10.1.2.3/ws', 'private or reserved');
        $this->assertRejected('http://localhost/ws', 'private or reserved');
    }

    // -------------------------------------------------------------------------
    // Address and range entries
    // -------------------------------------------------------------------------

    public function testListedAddressMatchesResolvedAddress(): void
    {
        $this->withEntries('10.1.2.3');

        $this->assertAllowed('http://10.1.2.3/ws');
        $this->assertAllowed('http://10.1.2.3:8080/ws');
        // Both names resolve to the listed address.
        $this->assertAllowed('http://soap.corp.local/ws');
        $this->assertAllowed('http://other.corp.local/ws');

        $this->assertRejected('http://api.soap.corp.local/ws', 'private or reserved');
        $this->assertRejected('http://10.1.2.4/ws', 'private or reserved');
    }

    public function testListedRangeMatchesResolvedAddress(): void
    {
        $this->withEntries('10.20.0.0/16');

        $this->assertAllowed('http://10.20.5.6/ws');
        $this->assertAllowed('http://billing.corp.local/ws');
        // Alternate notation of 10.20.5.6 is canonicalized before matching.
        $this->assertAllowed('http://0x0a140506/ws');

        $this->assertRejected('http://10.21.0.1/ws', 'private or reserved');
        $this->assertRejected('http://soap.corp.local/ws', 'private or reserved');
        $this->assertRejected('http://169.254.1.1/ws', 'private or reserved');
        $this->assertRejected('http://linklocal.corp.local/ws', 'private or reserved');
        $this->assertRejected('http://127.0.0.1/', 'private or reserved');
    }

    public function testListedIpv6Entries(): void
    {
        $this->withEntries('fd00:1234::/48,::1');

        $this->assertAllowed('http://[fd00:1234::10]/ws');
        $this->assertAllowed('http://[::1]:8080/ws');

        $this->assertRejected('http://[fd00:1235::10]/ws', 'reserved or private');
        $this->assertRejected('http://[fe80::1]/ws', 'reserved or private');
        $this->assertRejected('http://127.0.0.1/ws', 'private or reserved');
    }

    public function testSkippedEntriesAcceptNothing(): void
    {
        $this->withEntries('*.corp.local,*,0.0.0.0/0,::/0,10.1.2.3/8,soap.corp.local:8080');

        $this->assertRejected('http://soap.corp.local/ws', 'private or reserved');
        $this->assertRejected('http://10.1.2.3/ws', 'private or reserved');
        $this->assertRejected('http://127.0.0.1/ws', 'private or reserved');
        $this->assertRejected('http://[::1]/ws', 'reserved or private');
    }

    // -------------------------------------------------------------------------
    // Checks the list does not change
    // -------------------------------------------------------------------------

    public function testSchemeRulesStillApply(): void
    {
        $this->withEntries('soap.corp.local,10.0.0.0/8,127.0.0.1');

        $this->assertRejected('ftp://soap.corp.local/ws', 'scheme');
        $this->assertRejected('gopher://10.1.2.3/', 'scheme');
        $this->assertRejected('file:///etc/hosts');
        $this->assertRejected('https:///ws', 'could not be parsed');
    }

    public function testUnresolvableListedHostnameIsRejected(): void
    {
        $this->withEntries('missing.corp.local');

        $this->assertRejected('http://missing.corp.local/ws', 'could not be resolved');
    }

    public function testPublicHostsAreUnaffected(): void
    {
        $this->withEntries('soap.corp.local');
        $this->assertAllowed('https://public.example.com/ws');
        $this->assertAllowed('https://93.184.216.34/ws');
    }
}
