<?php

namespace DreamFactory\Core\System\Tests\Security;

use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Models\User;
use DreamFactory\Core\System\Resources\Password;
use DreamFactory\Core\System\Resources\UserPasswordResource;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * Security: a failure to send the password reset email must not change the
 * response of the unauthenticated reset request.
 *
 * Unknown addresses always answered 200 {"success":true,"security_question":null},
 * but a registered account whose reset email could not be sent answered 500
 * "Error processing password reset ... /usr/sbin/sendmail". The shipped docker
 * image has no sendmail binary, so on a default install that 500 marked every
 * registered address. The failure is now logged and the response is the same
 * envelope as for an unknown address.
 *
 * These tests do not need a running DreamFactory application or database:
 * the email transport is replaced by an anonymous subclass and the Log
 * facade by a recording logger.
 */
class PasswordResetMailFailureTest extends TestCase
{
    /** @var object */
    private $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = new class {
            public $errors = [];

            public function error($message, array $context = [])
            {
                $this->errors[] = $message;
            }

            public function __call($method, $args)
            {
                // other levels are not interesting here
            }
        };

        $container = new Container();
        $container->instance('log', $this->log);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    /**
     * A resource whose email transport does what $send says: throw the given
     * exception, or return the given value.
     *
     * @param \Throwable|bool $send
     */
    private function resource($send)
    {
        return new class($send) extends UserPasswordResource {
            private $send;

            public function __construct($send)
            {
                // skip BaseRestResource wiring; no service is needed here
                $this->send = $send;
            }

            public function getServiceName()
            {
                return 'user';
            }

            protected function sendPasswordResetEmail(User $user)
            {
                if ($this->send instanceof \Throwable) {
                    throw $this->send;
                }

                return $this->send;
            }

            public function callDeliver(User $user)
            {
                return $this->deliverPasswordResetEmail($user);
            }

            public static function callResponse()
            {
                return static::resetRequestedResponse();
            }
        };
    }

    private function user(): User
    {
        $user = new User();
        $user->setRawAttributes([
            'id'           => 4242,
            'email'        => 'someone@example.test',
            'confirm_code' => 'CONFIRMCODE0123456789abcdefghijk',
        ]);

        return $user;
    }

    public function testTransportFailureIsLoggedAndSwallowed(): void
    {
        $ex = new InternalServerErrorException(
            "Error processing password reset.\nConnection to \"process /usr/sbin/sendmail -bs\" has been closed unexpectedly."
        );

        $this->assertFalse($this->resource($ex)->callDeliver($this->user()));

        $this->assertCount(1, $this->log->errors);
        $logged = $this->log->errors[0];
        $this->assertStringContainsString('user id 4242', $logged);
        $this->assertStringContainsString('/usr/sbin/sendmail', $logged);
        $this->assertStringNotContainsString('someone@example.test', $logged);
        $this->assertStringNotContainsString('CONFIRMCODE', $logged);
    }

    public function testAnyThrowableFromTheTransportIsSwallowed(): void
    {
        $this->assertFalse($this->resource(new \TypeError('boom'))->callDeliver($this->user()));
        $this->assertCount(1, $this->log->errors);
        $this->assertStringContainsString('boom', $this->log->errors[0]);
    }

    public function testMissingEmailServiceIsLoggedAndSwallowed(): void
    {
        // sendPasswordResetEmail() returns false when the service has no
        // password email service configured; that used to be a 500 too.
        $this->assertFalse($this->resource(false)->callDeliver($this->user()));

        $this->assertCount(1, $this->log->errors);
        $this->assertStringContainsString('user id 4242', $this->log->errors[0]);
        $this->assertStringContainsString('no password reset email service', $this->log->errors[0]);
        $this->assertStringNotContainsString('someone@example.test', $this->log->errors[0]);
    }

    public function testSuccessfulDeliveryLogsNothing(): void
    {
        $this->assertTrue($this->resource(true)->callDeliver($this->user()));
        $this->assertSame([], $this->log->errors);
    }

    public function testResetResponseIsTheUniformEnvelope(): void
    {
        $resource = $this->resource(true);

        // Byte-identical JSON is what keeps the cases indistinguishable.
        $this->assertSame(
            '{"success":true,"security_question":null}',
            json_encode($resource::callResponse())
        );
    }

    public function testPasswordResetNoLongerThrowsOnDeliveryFailure(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(UserPasswordResource::class))->getFileName()
        );

        // The reset path must go through the non-throwing wrapper, and the
        // old "contact your administrator" 500 must be gone.
        $method = $this->methodSource($source, 'passwordReset');
        $this->assertStringContainsString('deliverPasswordResetEmail(', $method);
        $this->assertStringNotContainsString('->sendPasswordResetEmail(', $method);
        $this->assertStringNotContainsString('InternalServerErrorException', $method);
    }

    public function testAdminEndpointAnswersOrdinaryAccountsWithTheUniformEnvelope(): void
    {
        $source = file_get_contents((new \ReflectionClass(Password::class))->getFileName());
        $method = $this->methodSource($source, 'passwordReset');

        $this->assertStringContainsString('!$user->is_sys_admin', $method);
        $this->assertStringContainsString('return static::resetRequestedResponse();', $method);
    }

    private function methodSource(string $source, string $name): string
    {
        $start = strpos($source, 'function ' . $name . '(');
        $this->assertNotFalse($start, "method $name not found");
        $next = strpos($source, "\n    /**", $start);

        return substr($source, $start, $next === false ? null : $next - $start);
    }
}
