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
        $v4 = new OutboundHostAllowlist('10.0.0.0/8,10.1.2.3');
        $this->assertFalse($v4->allowsAddress('fd00::a01:203'));
        $this->assertFalse($v4->allowsAddress('fd00::10.1.2.3'));

        $v6 = new OutboundHostAllowlist('fd00::/8');
        $this->assertFalse($v6->allowsAddress('10.1.2.3'));
        $this->assertFalse($v6->allowsAddress('253.0.0.1'));
    }

    public function testIpv4CarryingIpv6AddressesMatchIpv4Entries(): void
    {
        $list = new OutboundHostAllowlist('10.1.2.3,172.30.80.0/24');

        foreach ([
            '::ffff:10.1.2.3',
            '::ffff:a01:203',
            '[::ffff:10.1.2.3]',
            '::a01:203',
            '64:ff9b::a01:203',
            '2002:a01:203::1',
            '::ffff:172.30.80.3',
        ] as $ip) {
            $this->assertTrue($list->allowsAddress($ip), "$ip should match its IPv4 entry");
        }

        foreach (['::ffff:10.1.2.4', '::ffff:127.0.0.1', '::1', '::'] as $ip) {
            $this->assertFalse($list->allowsAddress($ip), "$ip should not match");
        }
    }

    public function testIpv4CarryingIpv6AddressesNeverMatchIpv6Entries(): void
    {
        // Entries that would cover IPv4-carrying addresses are skipped, and
        // the remaining IPv6 entries are never compared with them.
        $list = new OutboundHostAllowlist('fd00::/8,2001:db8::/32');

        foreach (['::ffff:127.0.0.1', '::ffff:169.254.169.254', '::7f00:1', '::ffff:10.1.2.3'] as $ip) {
            $this->assertFalse($list->allowsAddress($ip), "$ip should not match an IPv6 entry");
        }
    }

    public function testIpv4CarryingIpv6EntryIsReadAsIpv4(): void
    {
        $list = new OutboundHostAllowlist('::ffff:10.1.2.3,[::ffff:10.1.2.4],64:ff9b::a01:205');

        $this->assertSame([], $list->getSkippedEntries());
        $this->assertTrue($list->allowsAddress('10.1.2.3'));
        $this->assertTrue($list->allowsAddress('10.1.2.4'));
        $this->assertTrue($list->allowsAddress('10.1.2.5'));
        $this->assertTrue($list->allowsAddress('::ffff:10.1.2.5'));
        $this->assertFalse($list->allowsAddress('10.1.2.6'));
    }

    public function testIpv6RangesThatIncludeIpv4CarryingAddressesAreSkipped(): void
    {
        $entries = [
            '::/8',
            '::/96',
            '::/120',
            '::ffff:0:0/96',
            '::ffff:10.0.0.0/104',
            '64:ff9b::/96',
            '2002::/16',
            '2000::/8',
        ];
        $list = new OutboundHostAllowlist(implode(',', $entries));

        $this->assertTrue($list->isEmpty());
        $this->assertSame($entries, array_column($list->getSkippedEntries(), 0));
        foreach (array_column($list->getSkippedEntries(), 1) as $reason) {
            $this->assertStringContainsString('list the IPv4 address or range instead', $reason);
        }

        foreach (['::1', '::ffff:127.0.0.1', '::ffff:169.254.169.254', '::a9fe:a9fe', '127.0.0.1', '10.0.0.1'] as $ip) {
            $this->assertFalse($list->allowsAddress($ip), "$ip should not be allowed");
        }
    }

    public function testIpv6LoopbackCanBeListedExactly(): void
    {
        $list = new OutboundHostAllowlist('::1');

        $this->assertTrue($list->allowsAddress('::1'));
        $this->assertFalse($list->allowsAddress('::2'));
        $this->assertFalse($list->allowsAddress('127.0.0.1'));
        $this->assertFalse($list->allowsAddress('::ffff:127.0.0.1'));
    }

    // -------------------------------------------------------------------------
    // Ranges that are never matched
    // -------------------------------------------------------------------------

    public function testEntriesInsideExcludedRangesAreSkipped(): void
    {
        $entries = [
            '0.0.0.0',
            '0.0.0.0/8',
            '169.254.169.254',
            '169.254.0.0/16',
            '100.100.100.200',
            '224.0.0.0/8',
            '239.1.2.3',
            '240.0.0.0/8',
            '255.255.255.255',
            '::',
            'fe80::1',
            'fe80::/10',
            '[fe80::]/64',
            'fd00:ec2::254',
            'ff02::1',
            'ff00::/8',
            '::ffff:169.254.169.254',
            '::a9fe:a9fe',
            '::ffff:0.0.0.0',
        ];
        $list = new OutboundHostAllowlist(implode(',', $entries));

        $this->assertTrue($list->isEmpty());
        $this->assertSame($entries, array_column($list->getSkippedEntries(), 0));
        foreach (array_column($list->getSkippedEntries(), 1) as $reason) {
            $this->assertSame('addresses in this range cannot be listed', $reason);
        }
    }

    public function testExcludedAddressesNeverMatch(): void
    {
        $list = new OutboundHostAllowlist('169.0.0.0/8,100.64.0.0/10,fd00::/8,fe00::/8,meta.corp.local');

        $this->assertSame([], $list->getSkippedEntries());

        $this->assertTrue($list->allowsAddress('169.1.2.3'));
        $this->assertFalse($list->allowsAddress('169.254.169.254'));
        $this->assertFalse($list->allowsAddress('::ffff:169.254.169.254'));
        $this->assertTrue($list->allowsAddress('100.64.0.1'));
        $this->assertFalse($list->allowsAddress('100.100.100.200'));
        $this->assertTrue($list->allowsAddress('fd00::1'));
        $this->assertFalse($list->allowsAddress('fd00:ec2::254'));
        $this->assertTrue($list->allowsAddress('fe00::1'));
        $this->assertFalse($list->allowsAddress('fe80::1'));

        // A listed hostname does not reach an excluded address either.
        $this->assertTrue($list->allows('meta.corp.local', '10.1.2.3'));
        $this->assertTrue($list->allows('meta.corp.local', '127.0.0.1'));
        foreach (['169.254.169.254', '0.0.0.0', '::', 'fe80::1', '::ffff:169.254.169.254', '224.0.0.1'] as $ip) {
            $this->assertFalse($list->allows('meta.corp.local', $ip), "meta.corp.local at $ip should not be allowed");
        }
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

    public function testBracketedIpv6RangeEntry(): void
    {
        $list = new OutboundHostAllowlist('[fd00:1234::]/48,[fd00:99::]/128');

        $this->assertSame([], $list->getSkippedEntries());
        $this->assertTrue($list->allowsAddress('fd00:1234::1'));
        $this->assertTrue($list->allowsAddress('fd00:99::'));
        $this->assertFalse($list->allowsAddress('fd00:1235::1'));
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
            '[fd00::]/64x',
            '[10.0.0.0/8]',
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
            '*.corp.local,10.0.0.0/4,10.1.2.3/8,not a host,http://soap.corp.local,10.0.0.0/33,10.0.0.0/x,'
            . '::/8,169.254.0.0/16'
        );
        $reasons = array_column($list->getSkippedEntries(), 1);

        $this->assertCount(9, $reasons);
        $this->assertStringContainsString('wildcard', $reasons[0]);
        $this->assertStringContainsString('/8', $reasons[1]);
        $this->assertStringContainsString('network address', $reasons[2]);
        $this->assertStringContainsString('hostname, IP address or CIDR range', $reasons[3]);
        $this->assertStringContainsString('hostname, IP address or CIDR range', $reasons[4]);
        $this->assertStringContainsString('CIDR range', $reasons[5]);
        $this->assertStringContainsString('CIDR range', $reasons[6]);
        $this->assertStringContainsString('IPv4', $reasons[7]);
        $this->assertStringContainsString('cannot be listed', $reasons[8]);
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
