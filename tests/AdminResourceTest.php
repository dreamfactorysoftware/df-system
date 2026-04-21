<?php

use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Enums\Verbs;
use DreamFactory\Core\Models\User;
use DreamFactory\Core\Utility\Session;
use Illuminate\Support\Arr;

class AdminResourceTest extends \DreamFactory\Core\System\Testing\UserResourceTestCase
{
    const RESOURCE = 'admin';

    protected function adminCheck($records)
    {
        if (isset($records[static::$wrapper])) {
            $records = $records[static::$wrapper];
        }
        foreach ($records as $user) {
            $userModel = User::find($user['id']);

            if (!$userModel->is_sys_admin) {
                return false;
            }
        }

        return true;
    }

    public function testNonAdmin()
    {
        // Count existing admins before adding a non-admin user
        $rs = $this->makeRequest(Verbs::GET, static::RESOURCE);
        $adminCountBefore = count($rs->getContent()[static::$wrapper]);

        $user = $this->user1;
        $this->makeRequest(Verbs::POST, 'user', [ApiOptions::FIELDS => '*', ApiOptions::RELATED => 'lookup_by_user_id'],
            [$user]);

        //Using a new instance here. Prev instance is set for user resource.
        $this->service = ServiceManager::getService('system');

        $rs = $this->makeRequest(Verbs::GET, static::RESOURCE);
        $content = $rs->getContent();

        // Creating a non-admin user should not increase the admin count
        $this->assertEquals($adminCountBefore, count($content[static::$wrapper]));
        // The non-admin user should not appear in the admin list
        $names = array_column($content[static::$wrapper], 'name');
        $this->assertNotContains($this->user1['name'], $names);
    }

    /************************************************
     * Session sub-resource test
     ************************************************/

    public function testSessionNotFound()
    {
        /** @var \DreamFactory\Core\Contracts\ServiceResponseInterface $rs */
        $rs = $this->makeRequest(Verbs::GET, static::RESOURCE . '/session');
        $this->assertEquals('404', $rs->getStatusCode(), 'Testing session not found');
    }

    public function testUnauthorizedSessionRequest()
    {
        $user = $this->user1;
        $this->makeRequest(Verbs::POST, 'user', [ApiOptions::FIELDS => '*', ApiOptions::RELATED => 'lookup_by_user_id'],
            [$user]);

        Session::authenticate(['email' => $user['email'], 'password' => $user['password']]);

        //Using a new instance here. Prev instance is set for user resource.
        $this->service = ServiceManager::getService('system');

        $rs = $this->makeRequest(Verbs::GET, static::RESOURCE . '/session');
        $this->assertEquals('401', $rs->getStatusCode(), 'Testing unauthorized session request');
    }

    public function testLogin()
    {
        $user = $this->createUser(1);

        $payload = ['email' => $user['email'], 'password' => $this->user1['password']];

        $rs = $this->makeRequest(Verbs::POST, static::RESOURCE . '/session', [], $payload);
        $content = $rs->getContent();
        $token = $content['session_token'];
        $tokenMap = DB::table('token_map')->where('token', $token)->get()->all();

        $this->assertEquals($user['first_name'], $content['first_name']);
        $this->assertTrue(!empty($token));
        $this->assertTrue(!empty($tokenMap));
    }

    public function testSessionBadPatchRequest()
    {
        $user = $this->createUser(1);
        $payload = ['name' => 'foo'];

        $rs = $this->makeRequest(Verbs::PATCH, static::RESOURCE . '/session/' . $user['id'], [], $payload);
        $this->assertEquals('400', $rs->getStatusCode(), 'Testing bad session patch request.');
    }

    public function testLogout()
    {
        $user = $this->createUser(1);
        $payload = ['email' => $user['email'], 'password' => $this->user1['password']];
        $rs = $this->makeRequest(Verbs::POST, static::RESOURCE . '/session', [], $payload);
        $content = $rs->getContent();
        $token = $content['session_token'];
        $tokenMap = DB::table('token_map')->where('token', $token)->get()->all();
        $this->assertTrue(!empty($token));
        $this->assertTrue(!empty($tokenMap));

        $rs = $this->makeRequest(Verbs::DELETE, static::RESOURCE . '/session', ['session_token' => $token]);
        $content = $rs->getContent();
        $tokenMap = DB::table('token_map')->where('token', $token)->get()->all();
        $this->assertTrue($content['success']);
        $this->assertTrue(empty($tokenMap));

        $rs = $this->makeRequest(Verbs::GET, static::RESOURCE . '/session');
        $this->assertEquals('404', $rs->getStatusCode(), 'Testing logout');
    }

    /************************************************
     * Password sub-resource test
     ************************************************/

    public function testGET()
    {
        $rs = $this->makeRequest(Verbs::GET, static::RESOURCE . '/password');
        $this->assertEquals('400', $rs->getStatusCode(), 'Testing GET admin/password');
    }

    public function testDELETE()
    {
        $rs = $this->makeRequest(Verbs::DELETE, static::RESOURCE . '/password');
        $this->assertEquals('400', $rs->getStatusCode(), 'Testing DELETE admin/password');
    }

    public function testPasswordChange()
    {
        $user = $this->createUser(1);

        $this->makeRequest(
            Verbs::POST,
            static::RESOURCE . '/session',
            [],
            ['email' => $user['email'], 'password' => $this->user1['password']]
        );
        $rs = $this->makeRequest(
            Verbs::POST,
            static::RESOURCE . '/password',
            [],
            ['old_password' => $this->user1['password'], 'new_password' => 'NewPass1234!@#$5']
        );
        $content = $rs->getContent();
        $this->assertTrue($content['success']);

        $this->makeRequest(Verbs::DELETE, static::RESOURCE . '/session');

        $rs = $this->makeRequest(
            Verbs::POST,
            static::RESOURCE . '/session',
            [],
            ['email' => $user['email'], 'password' => 'NewPass1234!@#$5']
        );
        $content = $rs->getContent();
        $token = $content['session_token'];
        $tokenMap = DB::table('token_map')->where('token', $token)->get()->all();
        $this->assertTrue(!empty($token));
        $this->assertTrue(!empty($tokenMap));
    }

    public function testPasswordResetUsingSecurityQuestion()
    {
        $user = $this->createUser(1);

        $rs =
            $this->makeRequest(Verbs::POST, static::RESOURCE . '/password', ['reset' => 'true'],
                ['email' => $user['email']]);
        $content = $rs->getContent();

        $this->assertEquals($this->user1['security_question'], $content['security_question']);

        $rs = $this->makeRequest(
            Verbs::POST,
            static::RESOURCE . '/password',
            [],
            [
                'email'           => $user['email'],
                'security_answer' => $this->user1['security_answer'],
                'new_password'    => 'ResetPass1234!@#'
            ]
        );
        $content = $rs->getContent();
        $this->assertTrue($content['success']);

        $rs =
            $this->makeRequest(Verbs::POST, static::RESOURCE . '/session', [],
                ['email' => $user['email'], 'password' => 'ResetPass1234!@#']);
        $content = $rs->getContent();
        $token = $content['session_token'];
        $tokenMap = DB::table('token_map')->where('token', $token)->get()->all();
        $this->assertTrue(!empty($token));
        $this->assertTrue(!empty($tokenMap));
    }

    public function testPasswordResetUsingConfirmationCode()
    {
        Arr::set($this->user2, 'email', 'arif@dreamfactory.com');
        $user = $this->createUser(2);

        // Set confirm_code directly — the Local email service uses SendmailTransport
        // which requires sendmail binary (unavailable in Docker test environment).
        // This tests the confirmation code reset flow without the email delivery step.
        /** @var User $userModel */
        $userModel = User::find($user['id']);
        $userModel->confirm_code = \Illuminate\Support\Str::random(32);
        $userModel->save();
        $code = $userModel->confirm_code;

        $rs = $this->makeRequest(
            Verbs::POST,
            static::RESOURCE . '/password',
            ['login' => 'true'],
            ['email' => $user['email'], 'code' => $code, 'new_password' => 'ResetPass1234!@#']
        );
        $content = $rs->getContent();
        $this->assertTrue($content['success']);
        $this->assertTrue(\DreamFactory\Core\Utility\Session::isAuthenticated());

        $userModel = User::find($user['id']);
        // confirm_code is set to null (not 'y') after successful reset — security fix
        $this->assertNull($userModel->confirm_code);

        $rs = $this->makeRequest(Verbs::POST, static::RESOURCE . '/session', [],
            ['email' => $user['email'], 'password' => 'ResetPass1234!@#']);
        $content = $rs->getContent();
        $token = $content['session_token'];
        $tokenMap = DB::table('token_map')->where('token', $token)->get()->all();
        $this->assertTrue(!empty($token));
        $this->assertTrue(!empty($tokenMap));
    }
}