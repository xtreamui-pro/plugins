<?php
/**
 * Credentials of the buyer: on the order view page (scope "order") and in the
 * customer account section "My IPTV" (scope "customer"). The scope comes from
 * the layout XML.
 */

namespace XtreamPro\Connector\Block;

use Magento\Customer\Model\Session;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use XtreamPro\Connector\Model\CredentialsView;

class Credentials extends Template
{
    /** @var CredentialsView */
    private $view;

    /** @var Session */
    private $session;

    /** @var Registry */
    private $registry;

    public function __construct(Context $context, CredentialsView $view, Session $session, Registry $registry, array $data = [])
    {
        parent::__construct($context, $data);
        $this->view = $view;
        $this->session = $session;
        $this->registry = $registry;
    }

    public function isAccountPage(): bool
    {
        return $this->getData('scope') === 'customer';
    }

    /**
     * @return array[] Rows for the template, empty when the viewer may not see this order.
     */
    public function getRows(): array
    {
        if ($this->isAccountPage()) {
            $customerId = (int) $this->session->getCustomerId();
            return $customerId > 0 ? $this->view->forCustomer($customerId) : [];
        }
        $order = $this->registry->registry('current_order');
        if (!$order || !$order->getId()) {
            return [];
        }
        // The order page controllers already checked the viewer; this is a second look:
        // an order of a registered customer is only shown to that customer.
        $owner = (int) $order->getCustomerId();
        if ($owner > 0 && $owner !== (int) $this->session->getCustomerId()) {
            return [];
        }
        return $this->view->forOrder((int) $order->getId());
    }

    /** Live credit balance for the account page, null when unknown. */
    public function getBalance(): ?int
    {
        return $this->isAccountPage() ? $this->view->balance((int) $this->session->getCustomerId()) : null;
    }

    public function getPanelLoginUrl(): string
    {
        return $this->view->panelLoginUrl();
    }

    /** The server address shown for a line: the "server" link the panel returned. */
    public function getServerUrl(array $row): string
    {
        return isset($row['links']['server']) ? (string) $row['links']['server'] : '';
    }

    /** Only plain http(s) links are turned into anchors. */
    public function isSafeUrl(string $url): bool
    {
        return (bool) preg_match('#^https?://#i', $url);
    }
}
