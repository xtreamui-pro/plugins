<?php
/**
 * "Test connection" button row of the system configuration.
 */

namespace XtreamPro\Connector\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class TestConnection extends Field
{
    protected $_template = 'XtreamPro_Connector::system/config/test_connection.phtml';

    /** No scope checkboxes on the button row. */
    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    public function getAjaxUrl(): string
    {
        return $this->getUrl('xtreampro/connection/test');
    }
}
