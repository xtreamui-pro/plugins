<?php
/**
 * Module settings (PrestaShop Configuration) and the factories that wire the
 * platform independent core (src/) to this shop.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class XtreamproConfig
{
    const API_URL = 'XTREAMPRO_API_URL';
    const API_KEY = 'XTREAMPRO_API_KEY';
    const PANEL_URL = 'XTREAMPRO_PANEL_URL';
    const PAID_STATES = 'XTREAMPRO_PAID_STATES';
    const REVOKED_STATES = 'XTREAMPRO_REVOKED_STATES';
    const INSTANCE = 'XTREAMPRO_INSTANCE';
    const PACKAGES = 'XTREAMPRO_PACKAGES';
    const PACKAGES_AT = 'XTREAMPRO_PACKAGES_AT';

    /** The package list is kept this many seconds before it is asked from the panel again. */
    const PACKAGES_TTL = 600;

    /** @return string[] every Configuration key of the module (removed on uninstall) */
    public static function keys()
    {
        return array(
            self::API_URL, self::API_KEY, self::PANEL_URL, self::PAID_STATES, self::REVOKED_STATES,
            self::INSTANCE, self::PACKAGES, self::PACKAGES_AT,
        );
    }

    public static function apiUrl()
    {
        return rtrim(trim((string) Configuration::get(self::API_URL)), '/');
    }

    public static function apiKey()
    {
        return trim((string) Configuration::get(self::API_KEY));
    }

    /** Dashboard address shown to sub-resellers (optional). */
    public static function panelUrl()
    {
        return rtrim(trim((string) Configuration::get(self::PANEL_URL)), '/');
    }

    /** Sign-in link of the dashboard, '' when no panel address is set. */
    public static function panelLoginUrl()
    {
        $base = XtreamproPresenter::safeUrl(self::panelUrl());
        return $base === '' ? '' : $base . '/login';
    }

    public static function isConfigured()
    {
        return self::apiUrl() !== '' && self::apiKey() !== '';
    }

    /** Short token that makes this shop's request ids unique. */
    public static function instance()
    {
        $token = (string) Configuration::get(self::INSTANCE);
        if ($token === '') {
            $token = bin2hex(random_bytes(4));
            Configuration::updateValue(self::INSTANCE, $token);
        }
        return $token;
    }

    /** @return int[] order state ids */
    public static function paidStates()
    {
        return self::ids(self::PAID_STATES);
    }

    /** @return int[] order state ids */
    public static function revokedStates()
    {
        return self::ids(self::REVOKED_STATES);
    }

    private static function ids($key)
    {
        $value = json_decode((string) Configuration::get($key), true);
        return is_array($value) ? array_values(array_unique(array_map('intval', $value))) : array();
    }

    public static function saveIds($key, array $ids)
    {
        return Configuration::updateValue($key, json_encode(array_values(array_unique(array_map('intval', $ids)))));
    }

    // ---- factories -------------------------------------------------------------

    /** Throws XtreamproApiException('CONFIG') when URL or key are missing. */
    public static function client($url = null, $key = null)
    {
        // Only API errors reach the PrestaShop log: the "ok" lines would flood it.
        $logger = function ($message) {
            if (strpos($message, '-> ok') === false) {
                PrestaShopLogger::addLog('Xtream UI Pro API: ' . $message, 3);
            }
        };
        return new XtreamproApiClient(
            $url === null ? self::apiUrl() : $url,
            $key === null ? self::apiKey() : $key,
            $logger
        );
    }

    public static function provisioner()
    {
        $logger = function ($message) {
            PrestaShopLogger::addLog('Xtream UI Pro: ' . $message, 1);
        };
        return new XtreamproProvisioner(self::client(), new XtreamproDbStore(), self::instance(), $logger);
    }

    /**
     * Packages the reseller may sell, kept for ten minutes so the product page
     * does not call the panel on every view.
     *
     * @return array array('list' => package arrays, 'error' => '' or a readable text)
     */
    public static function packages($force = false)
    {
        $cached = json_decode((string) Configuration::get(self::PACKAGES), true);
        $age = time() - (int) Configuration::get(self::PACKAGES_AT);
        if (!$force && is_array($cached) && $age >= 0 && $age < self::PACKAGES_TTL) {
            return array('list' => $cached, 'error' => '');
        }
        if (!self::isConfigured()) {
            return array('list' => is_array($cached) ? $cached : array(), 'error' => 'not configured');
        }
        try {
            $list = array();
            foreach (self::client()->packages() as $pkg) {
                // A package for MAG / Enigma boxes only (`sells` without "line") cannot be sold as a line.
                if (is_array($pkg) && isset($pkg['id']) && XtreamproApiClient::sellsLine($pkg)) {
                    $list[] = $pkg;
                }
            }
            Configuration::updateValue(self::PACKAGES, json_encode($list));
            Configuration::updateValue(self::PACKAGES_AT, time());
            return array('list' => $list, 'error' => '');
        } catch (Exception $e) {
            // The last known list is better than none.
            return array('list' => is_array($cached) ? $cached : array(), 'error' => $e->getMessage());
        }
    }

    /** "Name (10 credits, 1 years)" like the other connectors show it. */
    public static function packageLabel(array $pkg)
    {
        $name = isset($pkg['name']) ? (string) $pkg['name'] : '#' . (int) $pkg['id'];
        if (!empty($pkg['is_official'])) {
            $detail = (isset($pkg['official_credits']) ? (int) $pkg['official_credits'] : '?') . ' credits, '
                . (isset($pkg['official_duration']) ? (int) $pkg['official_duration'] : '?') . ' '
                . (isset($pkg['official_duration_in']) ? (string) $pkg['official_duration_in'] : '');
        } else {
            $detail = 'trial only';
        }
        return $name . ' (' . trim($detail) . ')';
    }
}
