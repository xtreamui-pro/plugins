<?php
namespace Opencart\System\Library\Extension\Xtreampro;

/**
 * Error raised for every failure: transport problem, bad response, an API
 * error code or a problem the provisioner found itself. getMessage() is always
 * a readable English sentence; the raw code (for example INSUFFICIENT_CREDITS)
 * is in getErrorCode().
 *
 * Platform independent: no OpenCart class is used here.
 */
class ApiException extends \Exception {
	private string $error_code;
	private int $http_status;

	/**
	 * @param string $detail Sentence appended to the readable text, e.g. the amounts of a refused sale
	 */
	public function __construct(string $error_code, int $http_status = 0, string $detail = '') {
		$this->error_code = $error_code;
		$this->http_status = $http_status;

		$text = self::describe($error_code);

		parent::__construct($detail === '' ? $text : $text . ' ' . $detail);
	}

	public function getErrorCode(): string {
		return $this->error_code;
	}

	public function getHttpStatus(): int {
		return $this->http_status;
	}

	/**
	 * Readable text for an API (or client side) error code.
	 */
	public static function describe(string $code): string {
		$messages = [
			'INVALID_API_KEY'      => 'The panel rejected the API key. Check the API key in the settings of the Xtream UI Pro extension.',
			'FORBIDDEN'            => 'The API key does not belong to a reseller account, or the reseller is not allowed to do this (for sub-reseller accounts: the reseller\'s group may not create sub-resellers, or the hierarchy is too deep).',
			'RESOURCE_NOT_FOUND'   => 'The line or sub-reseller account was not found in the panel (it may have been deleted there).',
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
			'CONNECTION_FAILED'    => 'Could not connect to the panel. Check the API URL and that its certificate is valid.',
			'BAD_RESPONSE'         => 'The panel answered with something that is not a valid API response. Check the API URL.',
			'CONFIG'               => 'The extension is not configured: the API URL or the API key is missing.',
			'NO_PACKAGE'           => 'No package is selected in the product settings.',
			'GUEST_NOT_ALLOWED'    => 'Sub-reseller accounts can only be sold to customers with an account: the order was placed as a guest.',
			'ACCOUNT_DISABLED'     => 'The customer\'s sub-reseller account is disabled in the panel. Enable it there, then provision the order again.',
			'NO_CREDITS_SET'       => 'The credits of this product are not a whole number of 0 or more.'
		];

		if (isset($messages[$code])) {
			return $messages[$code];
		}

		return 'The panel returned an error: ' . $code;
	}
}
