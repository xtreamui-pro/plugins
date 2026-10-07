<?php
/**
 * Xtream UI Pro connector - package dropdown of the product form, loaded from the panel.
 */

namespace XtreamPro\Connector\Model\Config\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use XtreamPro\Connector\Model\PackageList;

class Package extends AbstractSource
{
    /** @var PackageList */
    private $packages;

    public function __construct(PackageList $packages)
    {
        $this->packages = $packages;
    }

    /** @inheritdoc */
    public function getAllOptions()
    {
        if ($this->_options === null) {
            $options = [['value' => '', 'label' => __('-- None --')]];
            foreach ($this->packages->all() as $package) {
                $options[] = ['value' => (string) $package['id'], 'label' => $package['label'] . ' #' . $package['id']];
            }
            $this->_options = $options;
        }
        return $this->_options;
    }
}
