<?php
/**
 * "My IPTV" page of the customer account: the lines and the sub-reseller
 * account the signed-in customer bought, with their credentials and play links.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class XtreamproIptvModuleFrontController extends ModuleFrontController
{
    /** Only for signed-in customers. */
    public $auth = true;

    public function initContent()
    {
        parent::initContent();

        $cards = (new XtreamproOrderService())->cardsForCustomer((int) $this->context->customer->id, $this->module->labels());
        $this->context->smarty->assign(array(
            'xp_cards' => $cards,
        ));
        $this->setTemplate('module:xtreampro/views/templates/front/iptv.tpl');
    }

    public function getBreadcrumbLinks()
    {
        $breadcrumb = parent::getBreadcrumbLinks();
        $breadcrumb['links'][] = $this->addMyAccountToBreadcrumb();
        return $breadcrumb;
    }
}
