<?php
namespace Pstk\Paystack\Api;

interface PaymentInterface
{
    /**
     * @api
     * @param string $reference
     * @return bool
     */
    public function verifyPayment(
        $reference
    );
}
