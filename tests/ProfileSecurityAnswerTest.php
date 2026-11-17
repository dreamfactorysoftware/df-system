<?php

use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Models\User;
use DreamFactory\Core\Utility\Session;
use Illuminate\Support\Arr;
use DreamFactory\Core\Testing\TestCase;

/**
 * Regression coverage for security question and answer changes.
 *
 * A session-only caller must not be able to change the security question or
 * answer without re-authenticating with the current password. The security
 * answer gates the /user/password reset flow, so a stolen session token that
 * could rewrite it would amount to full account takeover.
 *
 * The resource under test is DreamFactory\Core\System\Resources\UserProfileResource,
 * exposed as the "profile" resource of the "user" service.
 */
class ProfileSecurityAnswerTest extends TestCase
{
    const RESOURCE = 'profile';

    protected $serviceId = 'user';

    protected $user1 = [
        'name'              => 'John Doe',
        'first_name'        => 'John',
        'last_name'         => 'Doe',
        'email'             => 'jdoe@dreamfactory.com',
        'password'          => 'Test1234!@#$5678',
        'security_question' => 'Make of your first car?',
        'security_answer'   => 'mazda',
        'is_active'         => true
    ];

    public function tearDown(): void
    {
        $this->deleteUser(1);

        parent::tearDown();
    }

    /**
     * Step (1) of the takeover chain must now fail: a session token alone
     * cannot overwrite the security answer.
     */
    public function testChangeSecurityAnswerWithoutPasswordIsRejected()
    {
        $user = $this->createUser(1);
        $userModel = User::find($user['id']);
        Session::setUserInfoWithJWT($userModel);

        $payload = [
            'security_answer' => 'HIJACKED'
            // no current_password
        ];

        $rs = $this->makeRequest(Verbs::POST, static::RESOURCE, [], $payload);
        $this->assertEquals(400, $rs->getStatusCode());

        // The stored answer is untouched: still the original.
        $userModel = User::find($user['id']);
        $this->assertTrue(Hash::check('mazda', $userModel->security_answer));
        $this->assertFalse(Hash::check('HIJACKED', $userModel->security_answer));
    }

    /**
     * A wrong current password is rejected too.
     */
    public function testChangeSecurityAnswerWithWrongPasswordIsRejected()
    {
        $user = $this->createUser(1);
        $userModel = User::find($user['id']);
        Session::setUserInfoWithJWT($userModel);

        $payload = [
            'security_answer'  => 'HIJACKED',
            'current_password' => 'not-the-password'
        ];

        $rs = $this->makeRequest(Verbs::POST, static::RESOURCE, [], $payload);
        $this->assertEquals(400, $rs->getStatusCode());

        $userModel = User::find($user['id']);
        $this->assertTrue(Hash::check('mazda', $userModel->security_answer));
    }

    /**
     * A legitimate, password-confirmed change still works.
     */
    public function testChangeSecurityAnswerWithCorrectPasswordSucceeds()
    {
        $user = $this->createUser(1);
        $userModel = User::find($user['id']);
        Session::setUserInfoWithJWT($userModel);

        $payload = [
            'security_question' => 'New question?',
            'security_answer'   => 'newanswer',
            'current_password'  => $this->user1['password']
        ];

        $rs = $this->makeRequest(Verbs::POST, static::RESOURCE, [], $payload);
        $this->assertTrue(Arr::get($rs->getContent(), 'success'));

        $userModel = User::find($user['id']);
        $this->assertTrue(Hash::check('newanswer', $userModel->security_answer));
        $this->assertEquals('New question?', $userModel->security_question);
    }

    /**
     * An unrelated profile update must NOT demand the current password, even
     * when the unchanged security_question is echoed back (as the admin UI
     * does, since GET /profile returns it).
     */
    public function testUnrelatedUpdateDoesNotRequirePassword()
    {
        $user = $this->createUser(1);
        $userModel = User::find($user['id']);
        Session::setUserInfoWithJWT($userModel);

        $payload = [
            'phone'             => '123-475-7383',
            'security_question' => $this->user1['security_question'] // unchanged echo
            // no current_password, no security_answer
        ];

        $rs = $this->makeRequest(Verbs::POST, static::RESOURCE, [], $payload);
        $this->assertTrue(Arr::get($rs->getContent(), 'success'));

        // Security answer stays as it was.
        $userModel = User::find($user['id']);
        $this->assertTrue(Hash::check('mazda', $userModel->security_answer));
        $this->assertEquals('123-475-7383', $userModel->phone);
    }

    /************************************************
     * Helper methods
     ************************************************/

    protected function createUser($num)
    {
        $user = $this->{'user' . $num};
        $payload = json_encode([$user], JSON_UNESCAPED_SLASHES);

        $this->service = ServiceManager::getService('system');
        $rs = $this->makeRequest(
            Verbs::POST,
            'user',
            [ApiOptions::FIELDS => '*', ApiOptions::RELATED => 'lookup_by_user_id'],
            $payload
        );
        $this->service = ServiceManager::getService($this->serviceId);

        $data = $rs->getContent();

        return Arr::get($data, static::$wrapper . '.0');
    }

    protected function deleteUser($num)
    {
        $user = $this->{'user' . $num};
        $email = Arr::get($user, 'email');
        User::whereEmail($email)->delete();
    }
}
