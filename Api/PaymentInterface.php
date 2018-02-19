<?php
namespace Pstk\Paystack\Api;

interface PaymentInterface
{
    /**
     * @param string $reference
     * @return bool
     */
    public function verifyPayment(
        $reference
    );
}
