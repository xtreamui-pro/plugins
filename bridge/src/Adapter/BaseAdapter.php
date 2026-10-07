<?php
/** Shared helpers of the adapters. */

declare(strict_types=1);

namespace XtreamPro\Bridge\Adapter;

use XtreamPro\Bridge\Config;

abstract class BaseAdapter implements Adapter
{
    protected Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /** The webhook secret of this platform, "" when not configured. */
    protected function secret(): string
    {
        return $this->config->string('secrets.' . $this->name());
    }

    protected static function header(array $headers, string $name): string
    {
        return trim((string) ($headers[strtolower($name)] ?? ''));
    }

    protected static function event(string $type, array $fields): array
    {
        return $fields + [
            'type'      => $type,
            'order_id'  => '',
            'item_id'   => '*',
            'quantity'  => null,
            'sku'       => '',
            'alt_keys'  => [],
            'email'     => '',
            'name'      => '',
            'id'        => '',
            'take_back' => true,
        ];
    }

    /** Whole number >= 1 from a JSON number or numeric string. */
    protected static function qty($value): int
    {
        return is_numeric($value) ? max(1, (int) round((float) $value)) : 1;
    }

    protected static function str($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** Value at a path of keys in nested arrays, or null. */
    protected static function dig(array $data, string ...$path)
    {
        foreach ($path as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }
        return $data;
    }
}
