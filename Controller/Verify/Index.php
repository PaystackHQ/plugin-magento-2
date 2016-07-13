<?php
namespace Profibro\Paystack\Controller\Verify;

use Profibro\Paystack\Controller\AbstractAction;

class Index extends AbstractAction
{
    public function execute()
    {
        $trxref = filter_input(INPUT_GET, 'trxref');
        $response = $this->_paystack->transaction->verify(['reference'=>$trxref]);
        die(json_encode($response));
    }
}