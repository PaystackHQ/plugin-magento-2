<?php

namespace Pstk\Paystack\Controller\Page;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\Action;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Payment\Helper\Data as PaymentHelp;

class View extends Action
{
  /**
  * @var JsonFactory
  */
  const CODE = 'pstk_paystack';

  protected $resultJsonFactory;
  protected $method;

  public function __construct(
    Context $context,
    JsonFactory $resultJsonFactory,
    PaymentHelp $a
    )
    {
     $this->method = $a->getMethodInstance(self::CODE);
      $this->resultJsonFactory = $resultJsonFactory;
      parent::__construct($context);
    }

public function getKey(){
  $secret_key = $this->method->getConfigData('live_secret_key');
  if ($this->method->getConfigData('test_mode')) {
      $secret_key = $this->method->getConfigData('test_secret_key');
  }

  return $secret_key;

}

// call to verify transaction
public function verifying($ref)
{
  $result = array();
//The parameter after verify/ is the transaction reference to be verified
$url = 'https://api.paystack.co/transaction/verify/'.$ref;

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt(
//$ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer sk_test_7e06e495c40565daef701e95b3dfeefc88541037']);
$ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer '.$this->getKey()]);
$request = curl_exec($ch);
curl_close($ch);

if ($request) {
$result = json_decode($request, true);
//print_r("The Key=".$this->getKey());
//print_r($result);
if($result){
  if($result['data']){
    //something came in
    if($result['data']['status'] == 'success'){
      // the transaction was successful, you can deliver value
      /*
      @ also remember that if this was a card transaction, you can store the
      @ card authorization to enable you charge the customer subsequently.
      @ The card authorization is in:
      @ $result['data']['authorization']['authorization_code'];
      @ PS: Store the authorization with this email address used for this transaction.
      @ The authorization will only work with this particular email.
      @ If the user changes his email on your system, it will be unusable
      */
      //echo "Transaction was successful";
      return "Transaction was successful";
    }else{
      // the transaction was not successful, do not deliver value'
      // print_r($result);  //uncomment this line to inspect the result, to check why it failed.
      return "Transaction was not successful: Last gateway response was: ".$result['data']['gateway_response'];
    }
  }else{
    return $result['message'];
  }

}else{
  //print_r($result);
  die("Something went wrong while trying to convert the request variable to json. Uncomment the print_r command to see what is in the result variable.");
}
}else{
//var_dump($request);
die("Something went wrong while executing curl. Uncomment the var_dump line above this line to see what the issue is. Please check your CURL command to make sure everything is ok");
}
}



    public function execute()
    {
     $result = $this->resultJsonFactory->create();
      //$public_key = $this->method->getConfigData('live_public_key');
      $data = ['message'=> $this->verifying($_REQUEST["ref"])];
      return $result->setData($data);

      //$resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
      /*
        $response = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $response->setHeader('Content-type', 'text/plain');
        $country = 'mess';
        $response->setContents(
            $this->_jsonHelper->jsonEncode(
                [
                    'message' => $country
                ]
            )
        );
        return $response;*/

      /* $ref= $_REQUEST["ref"];
$response = $this->resultJsonFactory->create();
        $response->setContents(
            $this->_jsonHelper->jsonEncode(
                [
                    'message' => "It has worked o!!"
                ]
            )
        );
        return $response;*/
    }

}
?>
