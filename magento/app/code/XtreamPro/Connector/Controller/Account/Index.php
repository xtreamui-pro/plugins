<?php
/**
 * Customer account section "My IPTV" (/xtreampro/account).
 */

namespace XtreamPro\Connector\Controller\Account;

use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\PageFactory;

class Index implements HttpGetActionInterface
{
    /** @var Session */
    private $session;

    /** @var PageFactory */
    private $pages;

    /** @var RedirectFactory */
    private $redirects;

    /** @var UrlInterface */
    private $url;

    public function __construct(Session $session, PageFactory $pages, RedirectFactory $redirects, UrlInterface $url)
    {
        $this->session = $session;
        $this->pages = $pages;
        $this->redirects = $redirects;
        $this->url = $url;
    }

    public function execute(): ResultInterface
    {
        if (!$this->session->isLoggedIn()) {
            // Sign in first, then come back here.
            $this->session->setBeforeAuthUrl($this->url->getUrl('xtreampro/account'));
            return $this->redirects->create()->setPath('customer/account/login');
        }
        return $this->pages->create();
    }
}
