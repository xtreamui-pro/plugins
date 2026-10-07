<?php
/**
 * Admin order view: "Provision Xtream UI Pro again" button.
 *
 * Shown when the order has IPTV units that failed or are still open (or a
 * paid IPTV order that has no units yet), and only to admins who may use the
 * XtreamPro_Connector::provision resource. It POSTs (with the form key) to
 * xtreampro/provision/run.
 */

namespace XtreamPro\Connector\Plugin;

use Magento\Framework\AuthorizationInterface;
use Magento\Sales\Block\Adminhtml\Order\View;
use XtreamPro\Connector\Model\OrderService;

class AddProvisionButton
{
    /** @var AuthorizationInterface */
    private $authorization;

    /** @var OrderService */
    private $service;

    public function __construct(AuthorizationInterface $authorization, OrderService $service)
    {
        $this->authorization = $authorization;
        $this->service = $service;
    }

    public function beforeSetLayout(View $subject)
    {
        $order = $subject->getOrder();
        if (!$order || !$this->authorization->isAllowed('XtreamPro_Connector::provision') || !$this->service->needsAttention($order)) {
            return null;
        }
        $url = $subject->getUrl('xtreampro/provision/run', ['order_id' => $order->getId()]);
        // A real POST with the form key: the action changes data, so a plain link would not do.
        $js = "require(['jquery'], function ($) {"
            . "$('<form>', {method: 'post', action: '" . $this->escapeJs($url) . "'})"
            . ".append($('<input>', {type: 'hidden', name: 'form_key', value: window.FORM_KEY}))"
            . ".appendTo('body').trigger('submit');});";
        $subject->addButton('xtreampro_provision', [
            'label'   => __('Provision Xtream UI Pro again'),
            'class'   => 'action-default',
            'onclick' => $js,
        ]);
        return null;
    }

    private function escapeJs(string $value): string
    {
        return addcslashes($value, "\\'\"\n\r<>&");
    }
}
