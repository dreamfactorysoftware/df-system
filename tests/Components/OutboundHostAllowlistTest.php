<?php

namespace DreamFactory\Core\System\Tests\Components;

use DreamFactory\Core\System\Components\OutboundHostAllowlist;
use PHPUnit\Framework\TestCase;

/**
 * Parsing and matching of DF_OUTBOUND_ALLOWED_HOSTS entries.
 * Runs without a booted application.
 */
class OutboundHostAllowlistTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Empty settings
    // -------------------------------------------------------------------------

    public function testEmptySettingMatchesNothing(): void
    {
        foreach ([null, '', '   ', ',', ' , ,, ', []] as $value) {
            $list = new OutboundHostAllowlist($value);
            $this->assertTrue($list->isEmpty());
            $this->assertSame([], $list->getSkippedEntries());
            $this->assertFalse($list->allows('localhost', '127.0.0.1'));
            $this->assertFalse($list->allows('soap.corp.local', '10.0.0.1'));
        }
    }

    public function testFromConfigWithoutApplicationIsEmpty(): void
    {
        $this->assertTrue(OutboundHostAllowlist::fromConfig()->isEmpty());
    }

    // -------------------------------------------------------------------------
    // Hostname entries
    // -------------------------------------------------------------------------

    public function testHostnameEntryMatchesSameHostname(): void
    {
        $list = new OutboundHostAllowlist('soap.corp.local');

        $this->assertTrue($list->allowsHostname('soap.corp.local'));
        $this->assertTrue($list->allowsHostname('SOAP.Corp.Local'));
        $this->assertTrue($list->allowsHostname('soap.corp.local.'));
        $this->assertTrue($list->allows('soap.corp.local', '10.1.2.3'));
    }

    public function testHostnameEntryDoesNotMatchOtherHostnames(): void
    {
        $list = new OutboundHostAllowlist('soap.corp.local');

        foreach ([
            'corp.local',
            'api.soap.corp.local',
            'soap.corp.local.example.com',
            'xsoap.corp.local',
            'soap.corp.loca',
            'soap-corp.local',
            'soap.corp.local..',
            ' soap.corp.local',
            'soap.corp.local ',
            "soap.corp.local\n",
            'soap',
            '',
        ] as $host) {
            $this->assertFalse($list->allowsHostname($host), "\"$host\" should not match soap.corp.local");
        }
    }

    public function testHostnameEntryDoesNotMatchByAddress(): void
    {
        // soap.corp.local may resolve to 10.1.2.3, but another name that
        // resolves to the same address is not on the list.
        $list = new OutboundHostAllowlist('soap.corp.local');

        $this->assertFalse($list->allowsAddress('10.1.2.3'));
        $this->assertFalse($list->allows('other.corp.local', '10.1.2.3'));
        $this->assertFalse($list->allows('10.1.2.3', '10.1.2.3'));
    }

    public function testEntryWithTrailingDotIsNormalized(): void
    {
        $list = new OutboundHostAllowlist('Soap.Corp.Local.');

        $this->assertTrue($list->allowsHostname('soap.corp.local'));
        $this->assertFalse($list->allowsHostname('soap.corp'));
    }

    public function testSingleLabelAndUnderscoreHostnames(): void
    {
        $list = new OutboundHostAllowlist('intranet,soap_backend_1');

        $this->assertTrue($list->allowsHostname('intranet'));
        $this->assertTrue($list->allowsHostname('SOAP_BACKEND_1'));
        $this->assertFalse($list->allowsHostname('intranet.corp.local'));
        $this->assertFalse($list->allowsHostname('soap_backend_2'));
    }

    public function testIpLiteralHostIsNeverMatchedAsHostname(): void
    {
        $list = new OutboundHostAllowlist('soap.corp.local');

        $this->assertFalse($list->allowsHostname('127.0.0.1'));
        $this->assertFalse($list->allowsHostname('::1'));
        $this->assertFalse($list->allowsHostname('[::1]'));
    }

    // -------------------------------------------------------------------------
    // Address entries
    // -------------------------------------------------------------------------

    public function testIpv4AddressEntryMatchesResolvedAddress(): void
    {
        $list = new OutboundHostAllowlist('10.20.30.40');

        $this->assertTrue($list->allowsAddress('10.20.30.40'));
        $this->assertTrue($list->allows('any-name.corp.local', '10.20.30.40'));
        $this->assertFalse($list->allowsAddress('10.20.30.41'));
        $this->assertFalse($list->allowsAddress('10.20.30.4'));
        $this->assertFalse($list->allowsHostname('10.20.30.40'));
    }

    public function testIpv6AddressEntryMatchesResolvedAddress(): void
    {
        $list = new OutboundHostAllowlist('fd00::5,[fd00::6]');

        $this->assertTrue($list->allowsAddress('fd00::5'));
        $this->assertTrue($list->allowsAddress('fd00:0:0:0:0:0:0:5'));
        $this->assertTrue($list->allowsAddress('[fd00::5]'));
        $this->assertTrue($list->allowsAddress('fd00::6'));
        $this->assertFalse($list->allowsAddress('fd00::7'));
    }

    public function testAddressFamiliesDoNotCrossMatch(): void
    {
        $list = new OutboundHostAllowlist('10.0.0.0/8,10.1.2.3');

        $this->assertFalse($list->allowsAddress('::ffff:10.1.2.3'));
        $this->assertFalse($list->allowsAddress('::a01:203'));
    }

    public function testNonAddressInputDoesNotMatch(): void
    {
        $list = new OutboundHostAllowlist('10.0.0.0/8');

        $this->assertFalse($list->allowsAddress(''));
        $this->assertFalse($list->allowsAddress('not-an-ip'));
        $this->assertFalse($list->allowsAddress('10.0.0'));
        $this->assertFalse($list->allows('', ''));
    }

    // -------------------------------------------------------------------------
    // CIDR entries
    // -------------------------------------------------------------------------

    public function testIpv4RangeEntry(): void
    {
        $list = new OutboundHostAllowlist('10.20.0.0/16');

        $this->assertTrue($list->allowsAddress('10.20.0.0'));
        $this->assertTrue($list->allowsAddress('10.20.0.1'));
        $this->assertTrue($list->allowsAddress('10.20.255.255'));
        $this->assertFalse($list->allowsAddress('10.21.0.0'));
        $this->assertFalse($list->allowsAddress('10.19.255.255'));
        $this->assertFalse($list->allowsAddress('172.16.0.1'));
        $this->assertFalse($list->allowsAddress('127.0.0.1'));
    }

    public function testIpv4RangeOffOctetBoundary(): void
    {
        $list = new OutboundHostAllowlist('172.16.32.0/20');

        $this->assertTrue($list->allowsAddress('172.16.32.0'));
        $this->assertTrue($list->allowsAddress('172.16.47.255'));
        $this->assertFalse($list->allowsAddress('172.16.48.0'));
        $this->assertFalse($list->allowsAddress('172.16.31.255'));
    }

    public function testIpv4RangeLimits(): void
    {
        $wide = new OutboundHostAllowlist('10.0.0.0/8');
        $this->assertTrue($wide->allowsAddress('10.255.255.255'));
        $this->assertFalse($wide->allowsAddress('11.0.0.0'));

        $single = new OutboundHostAllowlist('192.168.10.5/32');
        $this->assertTrue($single->allowsAddress('192.168.10.5'));
        $this->assertFalse($single->allowsAddress('192.168.10.4'));
    }

    public function testIpv6RangeEntry(): void
    {
        $list = new OutboundHostAllowlist('fd00:1234::/48');

        $this->assertTrue($list->allowsAddress('fd00:1234::1'));
        $this->assertTrue($list->allowsAddress('fd00:1234:0:ffff::1'));
        $this->assertFalse($list->allowsAddress('fd00:1235::1'));
        $this->assertFalse($list->allowsAddress('fd00::1'));

        $single = new OutboundHostAllowlist('fd00::1/128');
        $this->assertTrue($single->allowsAddress('fd00::1'));
        $this->assertFalse($single->allowsAddress('fd00::2'));
    }

    // -------------------------------------------------------------------------
    // Lists
    // -------------------------------------------------------------------------

    public function testMixedListWithWhitespace(): void
    {
        $list = new OutboundHostAllowlist(' soap.corp.local , 10.20.0.0/16 ,, [fd00::5] ');

        $this->assertFalse($list->isEmpty());
        $this->assertSame([], $list->getSkippedEntries());
        $this->assertTrue($list->allowsHostname('soap.corp.local'));
        $this->assertTrue($list->allowsAddress('10.20.1.1'));
        $this->assertTrue($list->allowsAddress('fd00::5'));
        $this->assertFalse($list->allowsAddress('10.21.1.1'));
    }

    public function testArrayValue(): void
    {
        $list = new OutboundHostAllowlist(['soap.corp.local', ' 10.0.0.0/8 ', 42, null, ['x']]);

        $this->assertTrue($list->allowsHostname('soap.corp.local'));
        $this->assertTrue($list->allowsAddress('10.9.8.7'));
        $this->assertCount(3, $list->getSkippedEntries());
    }

    // -------------------------------------------------------------------------
    // Entries that are skipped
    // -------------------------------------------------------------------------

    public function testMalformedEntriesAreSkipped(): void
    {
        $malformed = [
            '*',
            '*.corp.local',
            'soap.*.local',
            '10.0.*.*',
            'http://soap.corp.local',
            'soap.corp.local:8443',
            'soap.corp.local/path',
            'soap corp local',
            '-soap.corp.local',
            'soap-.corp.local',
            'soap..corp.local',
            '.corp.local',
            'soap.corp.local..',
            str_repeat('a', 64) . '.corp.local',
            str_repeat('abcdefghi.', 26) . 'corp',
            'bücher.corp.local',
            'fe80::1%eth0',
            '167772161',
            '0x0a000001',
            '10.1',
            '10.0.1',
            '10.0.0.0/33',
            'fd00::/129',
            '10.0.0.0/abc',
            '10.0.0.0/',
            '/8',
            '10.0.0.0/8/8',
            '10.0.0.0/08x',
            'soap.corp.local/24',
            '[fd00::]/64',
            '0.0.0.0/0',
            '::/0',
            '10.0.0.0/7',
            'fc00::/7',
            '10.1.2.3/8',
            '10.20.0.1/16',
            'fd00::1/64',
        ];

        $list = new OutboundHostAllowlist(implode(',', $malformed));

        $this->assertTrue($list->isEmpty(), 'No malformed entry should be used');
        $skipped = array_column($list->getSkippedEntries(), 0);
        $this->assertSame($malformed, $skipped);

        foreach (['127.0.0.1', '10.0.0.1', '10.1.2.3', '10.20.0.1', 'fd00::1', 'fe80::1', '0.0.0.0', '::1'] as $ip) {
            $this->assertFalse($list->allowsAddress($ip), "$ip should not be allowed");
        }
        foreach (['soap.corp.local', 'corp.local', 'localhost'] as $host) {
            $this->assertFalse($list->allowsHostname($host), "$host should not be allowed");
        }
    }

    public function testSkippedEntriesDoNotAffectValidOnes(): void
    {
        $list = new OutboundHostAllowlist('*.corp.local,soap.corp.local,0.0.0.0/0,10.20.0.0/16,10.1.2.3/8');

        $this->assertTrue($list->allowsHostname('soap.corp.local'));
        $this->assertFalse($list->allowsHostname('api.corp.local'));
        $this->assertTrue($list->allowsAddress('10.20.0.9'));
        $this->assertFalse($list->allowsAddress('10.1.2.3'));
        $this->assertFalse($list->allowsAddress('8.8.8.8'));
        $this->assertSame(
            ['*.corp.local', '0.0.0.0/0', '10.1.2.3/8'],
            array_column($list->getSkippedEntries(), 0)
        );
    }

    public function testSkippedEntriesCarryReason(): void
    {
        $list = new OutboundHostAllowlist(
            '*.corp.local,10.0.0.0/4,10.1.2.3/8,not a host,http://soap.corp.local,10.0.0.0/33,10.0.0.0/x'
        );
        $reasons = array_column($list->getSkippedEntries(), 1);

        $this->assertCount(7, $reasons);
        $this->assertStringContainsString('wildcard', $reasons[0]);
        $this->assertStringContainsString('/8', $reasons[1]);
        $this->assertStringContainsString('network address', $reasons[2]);
        $this->assertStringContainsString('hostname, IP address or CIDR range', $reasons[3]);
        $this->assertStringContainsString('hostname, IP address or CIDR range', $reasons[4]);
        $this->assertStringContainsString('CIDR range', $reasons[5]);
        $this->assertStringContainsString('CIDR range', $reasons[6]);
    }

    // -------------------------------------------------------------------------
    // Hostname normalization
    // -------------------------------------------------------------------------

    public function testNormalizeHostname(): void
    {
        $this->assertSame('soap.corp.local', OutboundHostAllowlist::normalizeHostname('SOAP.corp.LOCAL'));
        $this->assertSame('soap.corp.local', OutboundHostAllowlist::normalizeHostname('soap.corp.local.'));
        $this->assertSame('soap.corp.local.', OutboundHostAllowlist::normalizeHostname('soap.corp.local..'));
        $this->assertSame('', OutboundHostAllowlist::normalizeHostname('.'));
    }
}
