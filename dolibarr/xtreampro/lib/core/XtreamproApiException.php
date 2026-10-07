<?php
/**
 * Xtream UI Pro for Dolibarr - platform independent core.
 *
 * Nothing in lib/core/ refers to Dolibarr classes or functions: it can be loaded
 * (and is tested, see plugins/e2e/dolibarr-harness.php) on its own.
 */

/**
 * Error raised for every failure: transport problem, bad response or an API
 * error code. getMessage() is always a readable English sentence; the raw code
 * (for example INSUFFICIENT_CREDITS) is in getErrorCode().
 */
class XtreamproApiException extends Exception
{
	/** @var string */
	private $errorCode;

	/** @var int */
	private $httpStatus;

	/**
	 * @param string $errorCode  API error code or a client side code (CONNECTION_FAILED, ...)
	 * @param int    $httpStatus HTTP status, 0 when there was no answer
	 * @param string $detail     Sentence appended to the readable text, e.g. the amounts of a refused sale
	 */
	public function __construct($errorCode, $httpStatus = 0, $detail = '')
	{
		$this->errorCode = (string) $errorCode;
		$this->httpStatus = (int) $httpStatus;
		$text = self::describe($this->errorCode);
		parent::__construct($detail === '' ? $text : $text . ' ' . $detail);
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
	 * @param string $code Error code
	 * @return string
	 */
	public static function describe($code)
	{
		$messages = array(
			'INVALID_API_KEY'      => 'The panel rejected the API key. Check the API key in the Xtream UI Pro module setup.',
			'FORBIDDEN'            => 'The API key does not belong to a reseller account, or the reseller is not allowed to do this (for sub-reseller accounts: the reseller\'s group may not create sub-resellers, or the hierarchy is too deep).',
			'RESOURCE_NOT_FOUND'   => 'The line or sub-reseller account was not found in the panel (it may have been deleted there).',
			'INVALID_REQUEST'      => 'The panel rejected the request, for example a username or password shorter than the reseller group allows, or an invalid email address.',
			'INVALID_PACKAGE'      => 'The selected package does not exist, is not available to this reseller, or cannot be sold this way (for example a package for MAG / Enigma boxes only sold as a line).',
			'INSUFFICIENT_CREDITS' => 'The reseller account has not enough credits.',
			'CONFLICT'             => 'The username (or, for sub-reseller accounts, the email address) is already taken in the panel, or the request id was already used for another operation.',
			'REQUEST_ID_SPENT'     => 'This sale was already made and its line has since been deleted on the panel, so the same request id cannot sell another line. Terminate the line here, then retry the provisioning.',
			'READ_ONLY_KEY'        => 'The API key is read-only. Create a key that may change things on the panel\'s API key page.',
			'POST_REQUIRED'        => 'The panel refused the request because it was not sent as POST.',
			'RATE_LIMITED'         => 'The panel is rate limiting this API key. Try again in a minute.',
			'UNKNOWN_ACTION'       => 'The panel does not know this API action. Is the panel up to date?',
			'SERVER_ERROR'         => 'The panel reported an internal error.',
			'CONNECTION_FAILED'    => 'Could not connect to the panel. Check the API URL.',
			'BAD_RESPONSE'         => 'The panel answered with something that is not a valid API response. Check the API URL.',
			'CONFIG'               => 'The module is not configured correctly (API URL or API key missing).',
		);

		if (isset($messages[$code])) {
			return $messages[$code];
		}
		return 'The panel returned an error: ' . $code;
	}
}
