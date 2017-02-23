<?php
namespace Profibro\Paystack\Api;

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
