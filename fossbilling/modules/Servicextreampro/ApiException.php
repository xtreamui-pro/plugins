<?php

declare(strict_types=1);
/**
 * Xtream UI Pro - error of a Reseller API call. getMessage() is always a
 * readable English sentence (FOSSBilling shows it to the admin as it is); the
 * raw code (for example INSUFFICIENT_CREDITS) is in getErrorCode().
 *
 * @version 1.1.0
 */

namespace Box\Mod\Servicextreampro;

class ApiException extends \FOSSBilling\InformationException
{
    private string $errorCode;
    private int $httpStatus;

    /**
     * @param string $detail Sentence appended to the readable text, e.g. the amounts of a refused sale
     */
    public function __construct(string $errorCode, int $httpStatus = 0, string $detail = '')
    {
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
        $text = self::describe($errorCode);
        parent::__construct($detail === '' ? $text : $text . ' ' . $detail);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /** Readable text for an API (or client side) error code. */
    public static function describe(string $code): string
    {
        $messages = [
            'INVALID_API_KEY' => 'The panel rejected the API key. Check it on the Xtream UI Pro settings page.',
            'FORBIDDEN' => 'The API key does not belong to a reseller account, or the reseller is not allowed to do this (for sub-reseller accounts: the reseller\'s group may not create sub-resellers, or the hierarchy is too deep).',
            'RESOURCE_NOT_FOUND' => 'The line or sub-reseller account was not found in the panel (it may have been deleted there).',
            'INVALID_REQUEST' => 'The panel rejected the request, for example a username or password shorter than the reseller group allows, or an invalid email address.',
            'INVALID_PACKAGE' => 'The selected package does not exist, is not available to this reseller, or cannot be sold this way (for example a package for MAG / Enigma boxes only sold as a line).',
            'INSUFFICIENT_CREDITS' => 'The reseller account has not enough credits.',
            'CONFLICT' => 'The username (or, for sub-reseller accounts, the email address) is already taken in the panel, or the request id was already used for another operation.',
            'REQUEST_ID_SPENT' => 'This sale was already made and its line has since been deleted on the panel, so the same request id cannot sell another line. Cancel the order and place a new one.',
            'READ_ONLY_KEY' => 'The API key is read-only. Create a key that may change things on the panel\'s API key page.',
            'POST_REQUIRED' => 'The panel refused the request because it was not sent as POST.',
            'RATE_LIMITED' => 'The panel is rate limiting this API key. Try again in a minute.',
            'UNKNOWN_ACTION' => 'The panel does not know this API action. Is the panel up to date?',
            'SERVER_ERROR' => 'The panel reported an internal error.',
            'CONNECTION_FAILED' => 'Could not connect to the panel. Check the panel URL on the Xtream UI Pro settings page.',
            'BAD_RESPONSE' => 'The panel answered with something that is not a valid API response. Check the panel URL.',
            'CONFIG' => 'Xtream UI Pro is not configured: set the panel URL and the API key on the Xtream UI Pro settings page.',
        ];

        return $messages[$code] ?? ('The panel returned an error: ' . $code);
    }
}
