<?php
/**
 * Admin: provision the open units of one order again (POST, form key, ACL).
 */

namespace XtreamPro\Connector\Controller\Adminhtml\Provision;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use XtreamPro\Connector\Model\OrderService;

class Run extends Action implements HttpPostActionInterface
{
    const ADMIN_RESOURCE = 'XtreamPro_Connector::provision';

    /** @var OrderRepositoryInterface */
    private $orders;

    /** @var OrderService */
    private $service;

    public function __construct(Context $context, OrderRepositoryInterface $orders, OrderService $service)
    {
        parent::__construct($context);
        $this->orders = $orders;
        $this->service = $service;
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create();
        $orderId = (int) $this->getRequest()->getParam('order_id');
        try {
            $order = $this->orders->get($orderId);
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage(__('This order no longer exists.'));
            return $redirect->setPath('sales/order');
        }

        // Claim again (the product may have been fixed or the pay event missed), then work through the open units.
        $this->service->claim($order, $order->getAllItems());
        $result = $this->service->process($order, true);

        if ($result['done'] > 0) {
            $this->messageManager->addSuccessMessage(__('%1 Xtream UI Pro unit(s) processed.', $result['done']));
        }
        foreach (array_unique($result['errors']) as $error) {
            $this->messageManager->addErrorMessage($error);
        }
        if ($result['done'] === 0 && !$result['errors']) {
            $this->messageManager->addNoticeMessage(__('Nothing to do: every Xtream UI Pro unit of this order is already done.'));
        }
        return $redirect->setPath('sales/order/view', ['order_id' => $orderId]);
    }
}
