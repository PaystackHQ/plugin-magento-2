<?php
namespace Profibro\Paystack\Controller\Initialize;

use Profibro\Paystack\Controller\AbstractAction;

class Index extends AbstractAction
{
    public function execute()
    {
        $callback_url = $this->_url->getUrl('*/verify');
        die($callback_url);
        $params = [
            'email'=>'ibrahim@lawal.me',
            'amount'=>100000,
            'callback_url'=>$callback_url,
        ];
        $p = $this->_paystack->transaction->initialize($params);
        $this->getResponse()->setRedirect($p->data->authorization_url);
    }
}