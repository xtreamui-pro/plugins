<?php
namespace Opencart\System\Library\Extension\Xtreampro;

/**
 * Turns the view OrderService returns into the data of the credentials
 * template: dates formatted, links limited to http(s). Used by the order page
 * and by the account page so both show the same block.
 */
class CredentialsView {
	/**
	 * @param array    $view        result of OrderService::getOrderView() / getAccountView()
	 * @param string   $date_format PHP date() format of the expiry
	 * @param callable $order_url   function (int $order_id): string, '' when there is no page
	 */
	public static function build(array $view, string $date_format, callable $order_url): array {
		$data['lines'] = [];

		foreach ($view['lines'] as $line) {
			$links = $line['links'];

			$data['lines'][] = [
				'name'            => $line['name'],
				'username'        => $line['username'],
				'password'        => $line['password'],
				'server'          => self::safeUrl($line['server']),
				'playlist'        => self::safeUrl($links['m3u'] ?? ''),
				'playlist_hls'    => self::safeUrl($links['m3u_hls'] ?? ''),
				'epg'             => self::safeUrl($links['xmltv'] ?? ''),
				'player'          => self::safeUrl($links['web_player'] ?? ''),
				'live'            => $line['live'],
				'revoked'         => $line['revoked'],
				'status'          => $line['status'],
				'expires'         => $line['expires'] > 0 ? date($date_format, $line['expires']) : '',
				'max_connections' => $line['max_connections'],
				'error'           => $line['error'],
				'order_url'       => $order_url($line['order_id'])
			];
		}

		$data['accounts'] = [];

		foreach ($view['accounts'] as $account) {
			$data['accounts'][] = [
				'name'      => $account['name'],
				'username'  => $account['username'],
				'password'  => $account['password'],
				'credits'   => $account['credits'],
				'balance'   => $account['balance'],
				'live'      => $account['live'],
				'revoked'   => $account['revoked'],
				'status'    => $account['status'],
				'login'     => self::safeUrl($account['login']),
				'error'     => $account['error'],
				'order_url' => $order_url($account['order_id'])
			];
		}

		return $data;
	}

	/**
	 * Only http(s) addresses become links.
	 */
	public static function safeUrl(string $url): string {
		return preg_match('~^https?://~i', $url) ? $url : '';
	}
}
