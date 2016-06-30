<?php
/**
 * Payment CC Types Source Model
 *
 * @category    Profibro
 * @package     Profibro_Paystack
 * @author      Ibrahim Lawal
 * @copyright   Ibrahim Lawal (http://ibrahim.lawal.me)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Profibro\Paystack\Model\Source;

class Cctype extends \Magento\Payment\Model\Source\Cctype
{
    /**
     * @return array
     */
    public function getAllowedTypes()
    {
        return array('VI', 'MC', 'Verve');
    }
}
