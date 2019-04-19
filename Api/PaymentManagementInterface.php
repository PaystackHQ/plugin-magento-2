<?php
namespace Pstk\Paystack\Api;

/**
 * PaymentInterface
 *
 * @api
 * @since 100.0.2
 */
interface PaymentManagementInterface
{
    /**
     * @param string $reference
     * @return bool
     */
    public function verifyPayment(
        $reference
    );
}
