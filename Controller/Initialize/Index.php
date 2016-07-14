<?php
namespace Profibro\Paystack\Controller\Initialize;

use Profibro\Paystack\Controller\AbstractAction;

class Index extends AbstractAction
{

    protected function getSessionAuthUrl(){
        return $this->paystackSession->getTransactionUrl();
    }

    protected function redirectToCheckout(){
        $this->getResponse()->setRedirect($this->_url->getUrl('checkout'));
    }

    public function execute()
    {
        // get Auth url from session
        $authUrl = $this->getSessionAuthUrl();
        if(!$authUrl){
            $this->redirectToCheckout();
            return;
        }
        $this->getResponse()->setRedirect($authUrl);
        // unset URL after redirect
        $this->paystackSession->unsTransactionUrl();
    }
}