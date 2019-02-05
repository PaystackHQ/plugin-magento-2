<?php
namespace Pstk\Paystack\Api;

/**
 * PaymentInterface
 *
 * @api
 * @since 100.0.2
 */
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
