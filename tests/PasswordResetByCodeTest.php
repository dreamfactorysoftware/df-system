<?php

use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Exceptions\UnauthorizedException;
use DreamFactory\Core\Models\User;
use DreamFactory\Core\System\Resources\Password as AdminPassword;
use DreamFactory\Core\System\Resources\UserPasswordResource;
use Illuminate\Database\Eloquent\Model;

/**
 * Password reset by emailed confirmation code.
 *
 * The lookup used whereRaw('BINARY confirm_code = ?'), which is MySQL-only: SQL Server
 * rejects BINARY, so every code reset failed there. It now loads the user by email and
 * compares the code with hash_equals. These tests pin the security properties the BINARY
 * comparison gave (exact, case-sensitive, no trailing-space padding) on any driver.
 * They run on whatever system database the suite uses (MySQL here; SQL Server on the rig).
 */
class PasswordResetByCodeTest extends \DreamFactory\Core\Testing\TestCase
{
    const PREFIX = 'az_t_pw_';
    const CODE = 'AB12CD34EF56GH78IJ90KL12MN34OP56';

    public function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
    }

    public function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    protected function cleanup()
    {
        User::where('email', 'like', static::PREFIX . '%')->delete();
    }

    protected function makeUser($name, $code, $admin = false)
    {
        $user = Model::unguarded(function () use ($name, $admin) {
            return User::create([
                'name'         => $name,
                'first_name'   => 'az',
                'last_name'    => $name,
                'email'        => static::PREFIX . $name . '@azion.test',
                'password'     => 'OldPass#123',
                'is_active'    => true,
                'is_sys_admin' => $admin,
            ]);
        });
        // Set the code directly so tests control it (null, '', 'y' included).
        \DB::table('user')->where('id', $user->id)->update([
            'confirm_code'       => $code,
            'last_modified_date' => date('Y-m-d H:i:s'),
        ]);

        return User::find($user->id);
    }

    protected function assertRejected($exceptionClass, callable $call)
    {
        try {
            $call();
        } catch (\Exception $e) {
            $this->assertInstanceOf($exceptionClass, $e, get_class($e) . ': ' . $e->getMessage());

            return;
        }
        $this->fail('expected ' . $exceptionClass);
    }

    protected function passwordUnchanged(User $user)
    {
        return \Hash::check('OldPass#123', User::find($user->id)->password);
    }

    public function testCorrectCodeResetsPasswordAndClearsCode()
    {
        $user = $this->makeUser('ok', static::CODE);

        $result = UserPasswordResource::changePasswordByCode($user->email, static::CODE, 'NewPass#456_Long', false);

        $this->assertSame(['success' => true], $result);
        $fresh = User::find($user->id);
        $this->assertTrue(\Hash::check('NewPass#456_Long', $fresh->password));
        $this->assertNull($fresh->confirm_code);
    }

    public function testWrongCodeIsNotFound()
    {
        $user = $this->makeUser('wrong', static::CODE);
        $this->assertRejected(NotFoundException::class, function () use ($user) {
            UserPasswordResource::changePasswordByCode($user->email, 'ZZ12CD34EF56GH78IJ90KL12MN34OP56', 'NewPass#456_Long', false);
        });
        $this->assertTrue($this->passwordUnchanged($user));
    }

    public function testWrongCaseCodeIsRejected()
    {
        $user = $this->makeUser('case', static::CODE);
        $this->assertRejected(NotFoundException::class, function () use ($user) {
            UserPasswordResource::changePasswordByCode($user->email, strtolower(static::CODE), 'NewPass#456_Long', false);
        });
        $this->assertTrue($this->passwordUnchanged($user));
    }

    public function testTrailingSpaceVariantIsRejected()
    {
        // PAD SPACE collations treat 'abc' and 'abc ' as equal; BINARY did not, nor may we.
        $stored = substr(static::CODE, 0, 31);
        $user = $this->makeUser('pad', $stored);
        $this->assertRejected(NotFoundException::class, function () use ($user, $stored) {
            UserPasswordResource::changePasswordByCode($user->email, $stored . ' ', 'NewPass#456_Long', false);
        });
        $this->assertTrue($this->passwordUnchanged($user));
    }

    public function testCodeOfAnotherUserIsRejected()
    {
        $this->makeUser('owner', static::CODE);
        $victim = $this->makeUser('victim', 'QQ12CD34EF56GH78IJ90KL12MN34OP56');
        $this->assertRejected(NotFoundException::class, function () use ($victim) {
            UserPasswordResource::changePasswordByCode($victim->email, static::CODE, 'NewPass#456_Long', false);
        });
        $this->assertTrue($this->passwordUnchanged($victim));
    }

    public function testUsersWithoutPendingCodeCannotBeMatched()
    {
        foreach (['null' => null, 'empty' => '', 'confirmed' => 'y'] as $name => $stored) {
            $user = $this->makeUser($name, $stored);
            foreach ([static::CODE, str_repeat('y', 32), str_repeat(' ', 32)] as $code) {
                $this->assertRejected(NotFoundException::class, function () use ($user, $code) {
                    UserPasswordResource::changePasswordByCode($user->email, $code, 'NewPass#456_Long', false);
                });
            }
            foreach (['y', '', null, "\0", static::CODE . "\0"] as $code) {
                $this->assertRejected(BadRequestException::class, function () use ($user, $code) {
                    UserPasswordResource::changePasswordByCode($user->email, $code, 'NewPass#456_Long', false);
                });
            }
            $this->assertTrue($this->passwordUnchanged($user), "user with confirm_code '$name' was reset");
        }
    }

    public function testUnknownEmailIsNotFound()
    {
        $this->assertRejected(NotFoundException::class, function () {
            UserPasswordResource::changePasswordByCode(static::PREFIX . 'nobody@azion.test', static::CODE, 'NewPass#456_Long', false);
        });
    }

    public function testExpiredCodeIsRejected()
    {
        $user = $this->makeUser('expired', static::CODE);
        $ttl = (int)\Config::get('df.confirm_code_ttl', 1440);
        \DB::table('user')->where('id', $user->id)
            ->update(['last_modified_date' => date('Y-m-d H:i:s', time() - ($ttl + 5) * 60)]);

        $this->assertRejected(BadRequestException::class, function () use ($user) {
            UserPasswordResource::changePasswordByCode($user->email, static::CODE, 'NewPass#456_Long', false);
        });
        $this->assertTrue($this->passwordUnchanged($user));
    }

    public function testCodeCannotBeReused()
    {
        $user = $this->makeUser('reuse', static::CODE);
        UserPasswordResource::changePasswordByCode($user->email, static::CODE, 'NewPass#456_Long', false);

        $this->assertRejected(NotFoundException::class, function () use ($user) {
            UserPasswordResource::changePasswordByCode($user->email, static::CODE, 'Again#789_LongPw', false);
        });
        $this->assertTrue(\Hash::check('NewPass#456_Long', User::find($user->id)->password));
    }

    public function testAdminPathOnlyResetsAdmins()
    {
        $admin = $this->makeUser('admin', static::CODE, true);
        $user = $this->makeUser('nonadmin', 'RR12CD34EF56GH78IJ90KL12MN34OP56');

        $this->assertRejected(UnauthorizedException::class, function () use ($user) {
            AdminPassword::changePasswordByCode($user->email, 'RR12CD34EF56GH78IJ90KL12MN34OP56', 'NewPass#456_Long', false);
        });
        $this->assertTrue($this->passwordUnchanged($user));

        AdminPassword::changePasswordByCode($admin->email, static::CODE, 'NewAdmin#456_Lng', false);
        $this->assertTrue(\Hash::check('NewAdmin#456_Lng', User::find($admin->id)->password));
    }

    public function testRestEndpointsForAdminAndUser()
    {
        $admin = $this->makeUser('rest_admin', static::CODE, true);
        $payload = ['email' => $admin->email, 'code' => static::CODE, 'new_password' => 'RestAdmin#456_Lg'];

        $rs = \ServiceManager::handleRequest('system', Verbs::POST, 'admin/password', ['login' => false], [],
            array_merge($payload, ['code' => strtolower(static::CODE)]), null, false, false);
        $this->assertEquals(404, $rs->getStatusCode(), 'wrong-case code over REST');

        $rs = \ServiceManager::handleRequest('system', Verbs::POST, 'admin/password', ['login' => false], [],
            $payload, null, false, false);
        $this->assertEquals(200, $rs->getStatusCode(), json_encode($rs->getContent()));
        $this->assertTrue(\Hash::check('RestAdmin#456_Lg', User::find($admin->id)->password));

        if (!\ServiceManager::getServiceIdByName('user')) {
            return;
        }
        $user = $this->makeUser('rest_user', 'SS12CD34EF56GH78IJ90KL12MN34OP56');
        $rs = \ServiceManager::handleRequest('user', Verbs::POST, 'password', ['login' => false], [],
            ['email' => $user->email, 'code' => 'SS12CD34EF56GH78IJ90KL12MN34OP56', 'new_password' => 'RestUser#456_Lng'],
            null, false, false);
        $this->assertEquals(200, $rs->getStatusCode(), json_encode($rs->getContent()));
        $this->assertTrue(\Hash::check('RestUser#456_Lng', User::find($user->id)->password));
    }

    // Every self-service path enforces the same 16-character minimum as admin-set passwords.
    public function testShortPasswordRejectedByCode()
    {
        $user = $this->makeUser('short_code', static::CODE);
        $this->assertRejected(BadRequestException::class, function () use ($user) {
            UserPasswordResource::changePasswordByCode($user->email, static::CODE, 'Short#123', false);
        });
        $this->assertTrue($this->passwordUnchanged($user));
        $this->assertSame(static::CODE, User::find($user->id)->confirm_code, 'code must stay usable');
    }

    public function testShortPasswordRejectedOnChange()
    {
        $user = $this->makeUser('short_change', null);
        $change = new \ReflectionMethod(UserPasswordResource::class, 'changePassword');
        $change->setAccessible(true);
        $this->assertRejected(BadRequestException::class, function () use ($change, $user) {
            $change->invoke(null, $user, 'OldPass#123', 'Short#123', false);
        });
        $this->assertTrue($this->passwordUnchanged($user));

        $change->invoke(null, $user, 'OldPass#123', 'LongEnough#123456', false);
        $this->assertTrue(\Hash::check('LongEnough#123456', User::find($user->id)->password));
    }

    public function testShortPasswordRejectedBySecurityAnswer()
    {
        $user = $this->makeUser('short_answer', null);
        $byAnswer = new \ReflectionMethod(UserPasswordResource::class, 'changePasswordBySecurityAnswer');
        $byAnswer->setAccessible(true);
        $this->assertRejected(BadRequestException::class, function () use ($byAnswer, $user) {
            $byAnswer->invoke(null, $user->email, 'any answer', 'Short#123', false);
        });
        $this->assertTrue($this->passwordUnchanged($user));
    }
}
