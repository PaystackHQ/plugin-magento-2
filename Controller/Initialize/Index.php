<?php
namespace Profibro\Paystack\Controller\Initialize;

use Profibro\Paystack\Controller\AbstractAction;

class Index extends AbstractAction
{

    protected function getSessionRequestData(){
        return $this->paystackSession->getRequestData();
    }

    protected function redirectToCheckout(){
        $this->getResponse()->setRedirect($this->_url->getUrl('checkout'));
    }

    public function execute()
    {
        // get request from session
        $paystackRequestData = $this->getSessionRequestData();
        // initialize transaction
    	$url	= $this->_paystack
                ->transaction
                ->initialize($requestData);
        if(!$url){
            $this->redirectToCheckout();
            return;
        }
        $this->getResponse()->setRedirect($url->data->authorization_url);
    }
}