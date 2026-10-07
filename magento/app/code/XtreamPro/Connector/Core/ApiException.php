<?php
/**
 * Xtream UI Pro connector for Magento 2 - platform independent core.
 *
 * Nothing in the Core folder may refer to a Magento class: the end-to-end
 * harness (plugins/e2e/magento-harness.php) loads these files without Magento.
 *
 * @version 1.1.0
 */

namespace XtreamPro\Connector\Core;

/**
 * Error raised for every failure: transport problem, bad response or an API
 * error code. getMessage() is always a readable English sentence; the raw
 * code (for example INSUFFICIENT_CREDITS) is in getErrorCode().
 */
class ApiException extends \Exception
{
    /** @var string */
    private $errorCode;

    /** @var int */
    private $httpStatus;

    /**
     * @param string $errorCode  API error code or one of the client side codes below.
     * @param int    $httpStatus HTTP status of the answer, 0 when there was none.
     * @param string $detail     Optional extra sentence appended to the message.
     */
    public function __construct($errorCode, $httpStatus = 0, $detail = '')
    {
        $this->errorCode = (string) $errorCode;
        $this->httpStatus = (int) $httpStatus;
        $message = self::describe($this->errorCode);
        if ($detail !== '') {
            $message .= ' ' . $detail;
        }
        parent::__construct($message);
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }

    public function getHttpStatus()
    {
        return $this->httpStatus;
    }

    /**
     * Readable text for an API (or client side) error code.
     *
     * @param string $code
     * @return string
     */
    public static function describe($code)
    {
        $messages = array(
            'INVALID_API_KEY'      => 'The panel rejected the API key. Check the API key in Stores > Configuration > Services > Xtream UI Pro.',
            'FORBIDDEN'            => 'The API key does not belong to a reseller account, or the reseller is not allowed to do this (for sub-reseller accounts: the reseller\'s group may not create sub-resellers, or the hierarchy is too deep).',
            'RESOURCE_NOT_FOUND'   => 'The line, sub-reseller account or package was not found in the panel (it may have been deleted there).',
            'INVALID_REQUEST'      => 'The panel rejected the request, for example a username or password shorter than the reseller group allows, or an invalid email address.',
            'INVALID_PACKAGE'      => 'The selected package does not exist, is not available to this reseller, or cannot be sold this way (for example a package for MAG / Enigma boxes only sold as a line).',
            'INSUFFICIENT_CREDITS' => 'The reseller account has not enough credits.',
            'CONFLICT'             => 'The username (or, for sub-reseller accounts, the email address) is already taken in the panel, or the request id was already used for another operation.',
            'REQUEST_ID_SPENT'     => 'This sale was already made and its line has since been deleted on the panel, so the same request id cannot sell another line. Cancel the order and place a new one.',
            'READ_ONLY_KEY'        => 'The API key is read-only. Create a key that may change things on the panel\'s API key page.',
            'POST_REQUIRED'        => 'The panel refused the request because it was not sent as POST.',
            'RATE_LIMITED'         => 'The panel is rate limiting this API key. Try again in a minute.',
            'UNKNOWN_ACTION'       => 'The panel does not know this API action. Is the panel up to date?',
            'SERVER_ERROR'         => 'The panel reported an internal error.',
            'CONNECTION_FAILED'    => 'Could not connect to the panel. Check the API URL, the port and the certificate.',
            'BAD_RESPONSE'         => 'The panel answered with something that is not a valid API response. Check the API URL.',
            'REDIRECT'             => 'The panel address redirects somewhere else. Use the final address (usually https://) as API URL.',
            'CONFIG'               => 'The connector is not configured: set the API URL and the API key in Stores > Configuration > Services > Xtream UI Pro.',
        );

        if (isset($messages[$code])) {
            return $messages[$code];
        }
        return 'The panel returned an error: ' . $code;
    }
}
