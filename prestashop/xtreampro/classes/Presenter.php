<?php
/**
 * Turns unit rows (see src/Store.php) into what the pages and mails show.
 *
 * No PrestaShop class is used here, so the harness runs it too. Nothing is
 * escaped in the "cards": the Smarty templates escape every value, and the two
 * mail renderers below escape it themselves.
 *
 * All texts come from $labels (built by the module with $this->trans(), so the
 * translation tools find them): the presenter never has a string of its own.
 */
class XtreamproPresenter
{
    /** The keys a $labels array must have. */
    public static function labelKeys()
    {
        return array(
            'title_lines', 'title_reseller', 'server', 'username', 'password', 'playlist', 'player',
            'credits_added', 'signin', 'st_ok', 'st_error', 'st_revoked', 'st_suspended',
            'notice_revoked', 'notice_error', 'notice_suspended',
            'status', 'expires', 'never_expires', 'connections', 'balance',
        );
    }

    /** A link is shown only when it is http(s): the panel's answer is data, not trusted markup. */
    public static function safeUrl($url)
    {
        $url = (string) $url;
        return preg_match('#^https?://[^\s<>"\']+$#i', $url) ? $url : '';
    }

    public static function statusLabel($status, array $labels)
    {
        $key = 'st_' . $status;
        return isset($labels[$key]) ? $labels[$key] : (string) $status;
    }

    /**
     * @param array[]  $units          Unit rows (with detail_id and unit_no)
     * @param string[] $names          Product name by order detail id
     * @param string   $panelLoginUrl  Dashboard sign-in link for sub-resellers ('' = none)
     * @param string   $fallbackServer API address, used when the panel sent no links
     * @param array    $labels         Translated texts
     * @param bool     $forAdmin       Admins also see the error text and the panel id
     * @param array    $live           Optional fresh data from the panel by "detail-unit": status, exp_date, max_connections, credits
     * @return array[] One card per unit: title, kind, status, status_label, notice, error, panel_id, fields[label,value,url]
     */
    public static function cards(array $units, array $names, $panelLoginUrl, $fallbackServer, array $labels, $forAdmin = false, array $live = array())
    {
        // A product bought several times shows the unit number in its title.
        $perDetail = array();
        foreach ($units as $u) {
            $perDetail[$u['detail_id']] = isset($perDetail[$u['detail_id']]) ? $perDetail[$u['detail_id']] + 1 : 1;
        }

        $cards = array();
        foreach ($units as $u) {
            $title = isset($names[$u['detail_id']]) ? (string) $names[$u['detail_id']] : '';
            if ($perDetail[$u['detail_id']] > 1) {
                $title .= ' (' . (int) $u['unit_no'] . ')';
            }
            $card = array(
                'title'        => $title,
                'kind'         => $u['kind'],
                'status'       => $u['status'],
                'status_label' => self::statusLabel($u['status'], $labels),
                'notice'       => '',
                'error'        => $forAdmin ? (string) $u['error'] : '',
                'panel_id'     => (string) $u['panel_id'],
                'fields'       => array(),
            );

            if ($u['status'] === 'revoked') {
                $card['notice'] = $labels['notice_revoked'];
            } elseif ($u['status'] === 'error') {
                $card['notice'] = $labels['notice_error'];
            } elseif ($u['status'] === 'suspended') {
                $card['notice'] = $labels['notice_suspended'];
            } elseif ($u['kind'] === 'reseller') {
                $card['fields'][] = self::field($labels['username'], $u['username']);
                if ((string) $u['password'] !== '') {
                    $card['fields'][] = self::field($labels['password'], $u['password']);
                }
                $card['fields'][] = self::field($labels['credits_added'], (string) (int) $u['credits']);
                if ($panelLoginUrl !== '') {
                    $card['fields'][] = self::field($labels['signin'], $panelLoginUrl, $panelLoginUrl);
                }
                $fresh = isset($live[$u['detail_id'] . '-' . $u['unit_no']]) ? $live[$u['detail_id'] . '-' . $u['unit_no']] : array();
                if (isset($fresh['credits'])) {
                    $card['fields'][] = self::field($labels['balance'], (string) (int) $fresh['credits']);
                }
            } else {
                $links = isset($u['links']) && is_array($u['links']) ? $u['links'] : array();
                $server = isset($links['server']) && $links['server'] !== '' ? $links['server'] : $fallbackServer;
                $card['fields'][] = self::field($labels['server'], $server);
                $card['fields'][] = self::field($labels['username'], $u['username']);
                $card['fields'][] = self::field($labels['password'], $u['password']);
                if (!empty($links['m3u'])) {
                    $card['fields'][] = self::field($labels['playlist'], $links['m3u'], $links['m3u']);
                }
                if (!empty($links['web_player'])) {
                    $card['fields'][] = self::field($labels['player'], $links['web_player'], $links['web_player']);
                }
                $fresh = isset($live[$u['detail_id'] . '-' . $u['unit_no']]) ? $live[$u['detail_id'] . '-' . $u['unit_no']] : array();
                if (isset($fresh['status'])) {
                    $card['fields'][] = self::field($labels['status'], $fresh['status']);
                    $exp = isset($fresh['exp_date']) ? (int) $fresh['exp_date'] : 0;
                    $card['fields'][] = self::field($labels['expires'], $exp > 0 ? gmdate('Y-m-d H:i', $exp) . ' UTC' : $labels['never_expires']);
                    if (isset($fresh['max_connections'])) {
                        $card['fields'][] = self::field($labels['connections'], (string) (int) $fresh['max_connections']);
                    }
                }
            }
            $cards[] = $card;
        }
        return $cards;
    }

    private static function field($label, $value, $url = '')
    {
        return array('label' => (string) $label, 'value' => (string) $value, 'url' => self::safeUrl($url));
    }

    // ---- mail ---------------------------------------------------------------

    private static function esc($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }

    /** Cards that carry credentials, grouped as the mail shows them. */
    private static function withFields(array $cards)
    {
        $out = array('line' => array(), 'reseller' => array());
        foreach ($cards as $c) {
            if ($c['fields']) {
                $out[$c['kind']][] = $c;
            }
        }
        return $out;
    }

    /** HTML block for a mail template (the value of {xtreampro_credentials}). */
    public static function mailHtml(array $cards, array $labels)
    {
        $groups = self::withFields($cards);
        $cell = ' style="text-align:left;padding:6px 8px;border:1px solid #dddddd;"';
        $html = '';
        foreach (array('line' => 'title_lines', 'reseller' => 'title_reseller') as $kind => $titleKey) {
            if (!$groups[$kind]) {
                continue;
            }
            $html .= '<h3 style="margin:16px 0 6px 0;">' . self::esc($labels[$titleKey]) . '</h3>';
            foreach ($groups[$kind] as $c) {
                $html .= '<table style="width:100%;border-collapse:collapse;margin-bottom:10px;"><tbody>';
                $html .= '<tr><th' . $cell . ' colspan="2">' . self::esc($c['title']) . '</th></tr>';
                foreach ($c['fields'] as $f) {
                    $value = self::esc($f['value']);
                    if ($f['url'] !== '') {
                        $value = '<a href="' . self::esc($f['url']) . '">' . $value . '</a>';
                    }
                    $html .= '<tr><th' . $cell . '>' . self::esc($f['label']) . '</th><td' . $cell . '>' . $value . '</td></tr>';
                }
                $html .= '</tbody></table>';
            }
        }
        return $html;
    }

    /** Plain text block for a mail template (the value of {xtreampro_credentials_txt}). */
    public static function mailText(array $cards, array $labels)
    {
        $groups = self::withFields($cards);
        $text = '';
        foreach (array('line' => 'title_lines', 'reseller' => 'title_reseller') as $kind => $titleKey) {
            if (!$groups[$kind]) {
                continue;
            }
            $text .= "\n" . $labels[$titleKey] . "\n";
            foreach ($groups[$kind] as $c) {
                $text .= "\n" . $c['title'] . "\n";
                foreach ($c['fields'] as $f) {
                    // One value per line, no markup: new lines inside a value cannot add lines.
                    $text .= $f['label'] . ': ' . preg_replace('/\s+/', ' ', $f['value']) . "\n";
                }
            }
        }
        return $text;
    }
}
