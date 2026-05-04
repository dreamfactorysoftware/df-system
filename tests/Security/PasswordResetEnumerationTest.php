<?php

namespace DreamFactory\Core\System\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Security: password-reset endpoint must not leak whether an email is
 * registered.
 *
 * Phase 2 audit (df-system) found:
 *
 *     if (null === $user) {
 *         throw new NotFoundException("The supplied email was not found in the system.");
 *     }
 *
 * That distinct 404 + message lets an unauthenticated attacker enumerate
 * registered users by submitting candidate emails and reading the response.
 *
 * After the fix, when the email is not registered the endpoint returns
 * the same `{success: true}` shape it returns for a successful reset
 * (without actually sending any mail). The attacker cannot distinguish
 * "registered, reset email sent" from "not registered, no-op". The
 * security-question response for users that have one set is a known
 * partial enumeration vector flagged for product review.
 */
class PasswordResetEnumerationTest extends TestCase
{
    private string $sourcePath;
    private string $contents;

    protected function setUp(): void
    {
        $this->sourcePath = __DIR__ . '/../../src/Resources/UserPasswordResource.php';
        $this->assertFileExists($this->sourcePath);
        $this->contents = file_get_contents($this->sourcePath);
    }

    public function testPasswordResetDoesNotThrowOnUnknownEmail(): void
    {
        // Slice passwordReset() body and assert it does not contain the
        // distinct NotFoundException text that leaks user existence.
        $start = strpos($this->contents, 'function passwordReset($email)');
        $this->assertNotFalse($start);
        $end = strpos($this->contents, "\n    /**", $start + 10);
        $body = substr($this->contents, $start, $end === false ? null : ($end - $start));

        $this->assertDoesNotMatchRegularExpression(
            '/throw\s+new\s+NotFoundException\([^)]*was\s+not\s+found/i',
            $body,
            'passwordReset() must not throw NotFoundException with a "not found" '
            . 'message — that lets unauthenticated callers enumerate registered '
            . 'emails by probing the endpoint.'
        );
    }

    public function testPasswordResetReturnsSuccessShapeForUnknownEmail(): void
    {
        $start = strpos($this->contents, 'function passwordReset($email)');
        $end = strpos($this->contents, "\n    /**", $start + 10);
        $body = substr($this->contents, $start, $end === false ? null : ($end - $start));

        // The fix should branch on `null === $user` and return the same
        // {success: true} shape the success path returns.
        $this->assertMatchesRegularExpression(
            "/null\s*===\s*\\\$user.*'success'\s*=>\s*true/s",
            $body,
            'passwordReset() must return the success shape when the email is '
            . 'unknown, without actually sending a reset email.'
        );
    }
}
