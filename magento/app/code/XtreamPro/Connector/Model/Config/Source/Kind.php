<?php
/**
 * Xtream UI Pro connector - "Xtream UI Pro product type" of a product.
 */

namespace XtreamPro\Connector\Model\Config\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;

class Kind extends AbstractSource
{
    const NONE = '';
    const LINE = 'line';
    const RESELLER = 'reseller';

    /** @inheritdoc */
    public function getAllOptions()
    {
        if ($this->_options === null) {
            $this->_options = [
                ['value' => self::NONE, 'label' => __('Not an Xtream UI Pro product')],
                ['value' => self::LINE, 'label' => __('IPTV line')],
                ['value' => self::RESELLER, 'label' => __('Sub-reseller account / credits')],
            ];
        }
        return $this->_options;
    }
}
