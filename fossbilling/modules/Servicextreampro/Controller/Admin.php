<?php

declare(strict_types=1);
/**
 * Xtream UI Pro - admin page (Extensions > Xtream UI Pro).
 *
 * @version 1.1.0
 */

namespace Box\Mod\Servicextreampro\Controller;

class Admin implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function fetchNavigation(): array
    {
        return [
            'subpages' => [
                [
                    'location' => 'extensions',
                    'label' => __trans('Xtream UI Pro'),
                    'index' => 2100,
                    'uri' => $this->di['url']->adminLink('servicextreampro'),
                    'class' => '',
                ],
            ],
        ];
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/servicextreampro', 'get_index', [], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_servicextreampro_index');
    }
}
