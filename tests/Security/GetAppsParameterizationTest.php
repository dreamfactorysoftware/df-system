<?php

namespace DreamFactory\Core\System\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Security: getApps() must use parameterized whereIn(), not whereRaw with
 * implode'd integer lists.
 *
 * The April 2026 audit (df-system P1) found:
 *
 *     $appIdsString = implode(',', $appIds);
 *     ...whereRaw("(app.id IN ($appIdsString) OR role_id > 0) AND type NOT IN ($typeString)")
 *
 * The IDs come from a DB lookup so direct exploitation is gated, but the
 * pattern is dangerous: any future change that allows attacker influence on
 * UserAppRole.app_id (e.g., via a related-record write) becomes a SQL
 * injection. The parameterized form removes the footgun entirely.
 */
class GetAppsParameterizationTest extends TestCase
{
    private string $sourcePath;
    private string $contents;

    protected function setUp(): void
    {
        $this->sourcePath = __DIR__ . '/../../src/Resources/Environment.php';
        $this->assertFileExists($this->sourcePath);
        $this->contents = file_get_contents($this->sourcePath);
    }

    public function testGetAppsDoesNotUseImplodedWhereRaw(): void
    {
        // Forbid the specific anti-pattern: whereRaw with an interpolated
        // implode'd ID string.
        $this->assertDoesNotMatchRegularExpression(
            '/whereRaw\s*\([^)]*\(\s*\$appIdsString\b/',
            $this->contents,
            'getApps() must not interpolate an implode\'d id-list into whereRaw(); '
            . 'use whereIn(\'app.id\', $appIds) with parameterized bindings.'
        );
    }

    public function testGetAppsUsesWhereInForAppIds(): void
    {
        // The fix should call whereIn for app id filtering.
        $this->assertMatchesRegularExpression(
            "/whereIn\s*\(\s*['\"](?:app\.)?id['\"]\s*,/",
            $this->contents,
            'getApps() must use whereIn(\'app.id\', $appIds) for parameterized binding'
        );
    }
}
