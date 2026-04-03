<?php

namespace DreamFactory\Core\System\Tests\Security;

use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\System\Components\SsrfValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SSRF protection on import URL validation.
 *
 * These tests exercise SsrfValidator directly and do not require a running
 * DreamFactory application or database.  They verify:
 *   - Valid external HTTPS URLs pass
 *   - Dangerous schemes are rejected (file://, ftp://, gopher://, etc.)
 *   - Private and reserved IPv4 ranges are rejected
 *   - Loopback addresses (IPv4 and IPv6) are rejected
 *   - Link-local (169.254.x.x / fe80::) addresses are rejected
 *   - Localhost name variants are rejected
 *   - IPv6 private / loopback addresses are rejected
 */
class SsrfValidationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Assert that validateExternalUrl() throws BadRequestException for $url.
     */
    private function assertUrlBlocked(string $url, string $messageFragment = ''): void
    {
        try {
            SsrfValidator::validateExternalUrl($url);
            $this->fail("Expected BadRequestException for URL: $url");
        } catch (BadRequestException $e) {
            $this->assertInstanceOf(BadRequestException::class, $e);
            if ($messageFragment !== '') {
                $this->assertStringContainsStringIgnoringCase(
                    $messageFragment,
                    $e->getMessage(),
                    "Exception message did not contain \"$messageFragment\" for URL: $url"
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Valid URLs — should pass without exception
    // -------------------------------------------------------------------------

    public function testValidHttpsUrlPasses(): void
    {
        // We cannot make real DNS calls in unit tests, so we test the SSRF
        // validator logic via a test double that skips actual DNS resolution.
        // For the public-URL happy path we validate that the method returns
        // the original URL string (no exception thrown) when given a clearly
        // external address.
        //
        // Because gethostbyname() would fail in an offline CI environment we
        // test by calling the IP-check helper directly with a known-good IP
        // rather than end-to-end through validateExternalUrl().
        $this->assertFalse(
            SsrfValidator::isPrivateOrReservedIp('93.184.216.34'),  // example.com
            '93.184.216.34 (example.com) should not be flagged as private'
        );
        $this->assertFalse(
            SsrfValidator::isPrivateOrReservedIp('8.8.8.8'),
            '8.8.8.8 (Google DNS) should not be flagged as private'
        );
        $this->assertFalse(
            SsrfValidator::isPrivateOrReservedIp('1.1.1.1'),
            '1.1.1.1 (Cloudflare DNS) should not be flagged as private'
        );
    }

    public function testValidHttpUrlPasses(): void
    {
        // Same rationale as testValidHttpsUrlPasses — verify the IP is clean.
        $this->assertFalse(
            SsrfValidator::isPrivateOrReservedIp('151.101.1.140'),  // fastly CDN range
            'Public CDN IP should not be flagged as private'
        );
    }

    // -------------------------------------------------------------------------
    // Scheme validation
    // -------------------------------------------------------------------------

    public function testFileSchemeIsRejected(): void
    {
        $this->assertUrlBlocked('file:///etc/passwd', 'scheme');
        $this->assertUrlBlocked('file:///etc/shadow', 'scheme');
        $this->assertUrlBlocked('file://localhost/etc/hosts', 'scheme');
    }

    public function testFtpSchemeIsRejected(): void
    {
        $this->assertUrlBlocked('ftp://example.com/package.zip', 'scheme');
    }

    public function testGopherSchemeIsRejected(): void
    {
        $this->assertUrlBlocked('gopher://example.com/', 'scheme');
    }

    public function testDictSchemeIsRejected(): void
    {
        $this->assertUrlBlocked('dict://example.com/', 'scheme');
    }

    public function testLdapSchemeIsRejected(): void
    {
        $this->assertUrlBlocked('ldap://internal.corp/dc=example,dc=com', 'scheme');
    }

    public function testSftpSchemeIsRejected(): void
    {
        $this->assertUrlBlocked('sftp://example.com/package.zip', 'scheme');
    }

    public function testJavascriptSchemeIsRejected(): void
    {
        $this->assertUrlBlocked('javascript:alert(1)', 'scheme');
    }

    public function testDataSchemeIsRejected(): void
    {
        $this->assertUrlBlocked('data:text/plain,hello', 'scheme');
    }

    // -------------------------------------------------------------------------
    // Private/reserved IPv4 ranges
    // -------------------------------------------------------------------------

    public function testLoopbackIpv4IsRejected(): void
    {
        // 127.0.0.0/8
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('127.0.0.1'));
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('127.255.255.255'));
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('127.0.0.2'));
    }

    public function testLinkLocalIsRejected(): void
    {
        // 169.254.0.0/16 — cloud metadata endpoints live here
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('169.254.169.254'));  // AWS/GCP metadata
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('169.254.0.1'));
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('169.254.255.255'));
    }

    public function testAwsMetadataAddressIsRejected(): void
    {
        // Explicit test for the canonical AWS IMDS address.
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('169.254.169.254'));
    }

    public function testRfc1918TenBlockIsRejected(): void
    {
        // 10.0.0.0/8
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('10.0.0.1'));
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('10.255.255.255'));
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('10.1.2.3'));
    }

    public function testRfc1918OneSevenTwoBlockIsRejected(): void
    {
        // 172.16.0.0/12 covers 172.16.x.x through 172.31.x.x
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('172.16.0.1'));
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('172.31.255.255'));
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('172.20.0.1'));
        // 172.15.x and 172.32.x are public
        $this->assertFalse(SsrfValidator::isPrivateOrReservedIp('172.15.255.255'));
        $this->assertFalse(SsrfValidator::isPrivateOrReservedIp('172.32.0.1'));
    }

    public function testRfc1918OneNineeTwoBlockIsRejected(): void
    {
        // 192.168.0.0/16
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('192.168.0.1'));
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('192.168.1.1'));
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('192.168.255.255'));
    }

    public function testCgnatRangeIsRejected(): void
    {
        // 100.64.0.0/10 — carrier-grade NAT
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('100.64.0.1'));
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('100.127.255.255'));
    }

    public function testMulticastRangeIsRejected(): void
    {
        // 224.0.0.0/4
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('224.0.0.1'));
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('239.255.255.255'));
    }

    public function testReservedRangeIsRejected(): void
    {
        // 240.0.0.0/4
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('240.0.0.1'));
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('255.255.255.254'));
    }

    public function testBroadcastAddressIsRejected(): void
    {
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('255.255.255.255'));
    }

    // -------------------------------------------------------------------------
    // Literal IP in URL (scheme validation + IP check together)
    // -------------------------------------------------------------------------

    public function testHttpUrlWithPrivateIpIsRejected(): void
    {
        $this->assertUrlBlocked('http://10.0.0.1/package.zip', 'private or reserved');
        $this->assertUrlBlocked('http://192.168.1.1/package.zip', 'private or reserved');
        $this->assertUrlBlocked('http://172.16.0.1/package.zip', 'private or reserved');
        $this->assertUrlBlocked('http://127.0.0.1/package.zip', 'private or reserved');
        $this->assertUrlBlocked('http://169.254.169.254/latest/meta-data/', 'private or reserved');
    }

    public function testHttpsUrlWithPrivateIpIsRejected(): void
    {
        $this->assertUrlBlocked('https://10.0.0.1/package.zip', 'private or reserved');
        $this->assertUrlBlocked('https://192.168.0.100/package.zip', 'private or reserved');
    }

    // -------------------------------------------------------------------------
    // Localhost name variants
    // -------------------------------------------------------------------------

    public function testLocalhostNameIsRejected(): void
    {
        // gethostbyname('localhost') returns 127.0.0.1 on any sane system.
        $this->assertUrlBlocked('http://localhost/package.zip', 'private or reserved');
        $this->assertUrlBlocked('https://localhost/package.zip', 'private or reserved');
    }

    public function testLocalhostWithPortIsRejected(): void
    {
        $this->assertUrlBlocked('http://localhost:8080/package.zip', 'private or reserved');
    }

    // -------------------------------------------------------------------------
    // IPv6 addresses
    // -------------------------------------------------------------------------

    public function testIpv6LoopbackIsRejected(): void
    {
        // ::1
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('::1'));
    }

    public function testIpv6UnspecifiedIsRejected(): void
    {
        // ::
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('::'));
    }

    public function testIpv6LinkLocalIsRejected(): void
    {
        // fe80::/10
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('fe80::1'));
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('fe80::dead:beef'));
    }

    public function testIpv6UniqueLocalIsRejected(): void
    {
        // fc00::/7 covers fc00:: and fd00::
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('fc00::1'));
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('fd00::1'));
        $this->assertTrue(SsrfValidator::isPrivateOrReservedIp('fdff::ffff'));
    }

    public function testIpv6LoopbackInUrlIsRejected(): void
    {
        $this->assertUrlBlocked('http://[::1]/package.zip');
    }

    // -------------------------------------------------------------------------
    // Malformed / missing URL components
    // -------------------------------------------------------------------------

    public function testEmptyStringIsRejected(): void
    {
        $this->assertUrlBlocked('', 'could not be parsed');
    }

    public function testUrlWithNoSchemeIsRejected(): void
    {
        $this->assertUrlBlocked('//example.com/package.zip', 'scheme');
    }

    public function testUrlWithNoHostIsRejected(): void
    {
        $this->assertUrlBlocked('https:///package.zip', 'could not be parsed');
    }

    // -------------------------------------------------------------------------
    // DNS rebinding protection — verify resolved IP is checked, not just host
    // -------------------------------------------------------------------------

    /**
     * If a hostname resolves to a private IP the request must be blocked,
     * regardless of how the hostname looks.  We test this by reaching into
     * isPrivateOrReservedIp() with an IP that would be returned by a
     * rebinding attack.
     *
     * Full end-to-end testing requires mocking DNS, which is beyond the scope
     * of pure unit tests without a mocking framework; the behaviour is covered
     * by the integration between resolveHost() and isPrivateOrReservedIp()
     * inside validateExternalUrl(), and is verified here at the component level.
     */
    public function testDnsRebindingProtection(): void
    {
        // Simulate: attacker-controlled DNS returns 169.254.169.254 for a
        // seemingly benign hostname.
        $resolvedIp = '169.254.169.254';
        $this->assertTrue(
            SsrfValidator::isPrivateOrReservedIp($resolvedIp),
            'IP returned by a rebinding attack (169.254.169.254) must be blocked'
        );

        $resolvedIp = '10.0.0.1';
        $this->assertTrue(
            SsrfValidator::isPrivateOrReservedIp($resolvedIp),
            'IP returned by a rebinding attack (10.0.0.1) must be blocked'
        );
    }

    // -------------------------------------------------------------------------
    // Valid external IPs — should NOT be blocked
    // -------------------------------------------------------------------------

    public function testPublicIpsAreNotBlocked(): void
    {
        $publicIps = [
            '8.8.8.8',         // Google DNS
            '1.1.1.1',         // Cloudflare DNS
            '93.184.216.34',   // example.com
            '104.16.0.0',      // Cloudflare CDN
            '151.101.1.140',   // Fastly
            '52.84.0.0',       // AWS CloudFront (public range)
        ];

        foreach ($publicIps as $ip) {
            $this->assertFalse(
                SsrfValidator::isPrivateOrReservedIp($ip),
                "Public IP $ip should not be flagged as private/reserved"
            );
        }
    }
}
