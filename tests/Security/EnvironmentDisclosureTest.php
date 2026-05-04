<?php

namespace DreamFactory\Core\System\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Security: /system/environment must not leak the license key or full host
 * fingerprint to non-admin authenticated users.
 *
 * The April 2026 audit (df-system P1/P2) found that:
 *   - $result['platform']['license_key'] was returned to every authenticated
 *     user (including default-role API key callers).
 *   - $result['server'] returned php_uname() s/r/v/n/m — OS, kernel, hostname,
 *     architecture — to every authenticated user. Host recon by any caller
 *     with a valid API key.
 *
 * After the fix, both blocks live inside the existing
 * `if (SessionUtilities::isSysAdmin())` branch.
 */
class EnvironmentDisclosureTest extends TestCase
{
    private string $sourcePath;
    private string $contents;

    protected function setUp(): void
    {
        $this->sourcePath = __DIR__ . '/../../src/Resources/Environment.php';
        $this->assertFileExists($this->sourcePath);
        $this->contents = file_get_contents($this->sourcePath);
    }

    public function testLicenseKeyIsAdminOnly(): void
    {
        // Find the line that assigns license_key from app.license_key.
        $licenseLineMatches = [];
        $found = preg_match(
            "/['\"]license_key['\"]\]?\s*(=>|=)\s*\\\\?Config::get\(\s*['\"]app\.license_key['\"]/",
            $this->contents,
            $licenseLineMatches,
            PREG_OFFSET_CAPTURE
        );
        $this->assertSame(1, $found,
            'license_key assignment must still be locatable in source'
        );
        $licenseAssignmentPos = $licenseLineMatches[0][1];

        // It must be inside the isSysAdmin() block — i.e., positioned AFTER
        // the `if (SessionUtilities::isSysAdmin())` line.
        $sysAdminPos = strpos($this->contents, 'isSysAdmin()');
        $this->assertNotFalse($sysAdminPos, 'isSysAdmin() guard must exist');
        $this->assertGreaterThan(
            $sysAdminPos,
            $licenseAssignmentPos,
            'license_key must be assigned inside the isSysAdmin() block, not in the '
            . 'common authenticated-user block (audit P1)'
        );
    }

    public function testServerFingerprintIsAdminOnly(): void
    {
        // The php_uname-driven server fingerprint must also live inside
        // the isSysAdmin() branch.
        $unameMatches = [];
        $found = preg_match(
            "/php_uname\s*\(\s*['\"]s['\"]\s*\)/",
            $this->contents,
            $unameMatches,
            PREG_OFFSET_CAPTURE
        );
        $this->assertSame(1, $found,
            'php_uname fingerprint block must still be locatable in source'
        );
        $unamePos = $unameMatches[0][1];

        $sysAdminPos = strpos($this->contents, 'isSysAdmin()');
        $this->assertNotFalse($sysAdminPos);
        $this->assertGreaterThan(
            $sysAdminPos,
            $unamePos,
            "Server fingerprint (php_uname) must be inside the isSysAdmin() block. "
            . 'Returning OS / kernel / hostname / arch to all authenticated users '
            . 'is host recon (audit P2).'
        );
    }
}
