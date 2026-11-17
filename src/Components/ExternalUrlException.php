<?php

namespace DreamFactory\Core\System\Components;

use DreamFactory\Core\Exceptions\BadRequestException;

/**
 * A 400 from SsrfValidator::validateExternalUrl() that also says which check
 * refused the URL, so callers can word their own message. The status, message
 * and response body are the same as a plain BadRequestException.
 */
class ExternalUrlException extends BadRequestException
{
    /**
     * The URL could not be parsed, has no host, or does not use http or https.
     */
    const REASON_URL = 'url';

    /**
     * The host name did not resolve to an address.
     */
    const REASON_RESOLVE = 'resolve';

    /**
     * The host resolved to an address that is not accepted.
     */
    const REASON_ADDRESS = 'address';

    /**
     * @var string One of the REASON_* constants.
     */
    protected $reason;

    /**
     * @param string $reason  One of the REASON_* constants.
     * @param string $message The error message.
     */
    public function __construct(string $reason, string $message)
    {
        $this->reason = $reason;
        parent::__construct($message);
    }

    /**
     * @return string One of the REASON_* constants.
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
