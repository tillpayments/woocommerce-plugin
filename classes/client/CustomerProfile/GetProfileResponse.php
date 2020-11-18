<?php

namespace TillPayments\Client\CustomerProfile;

use TillPayments\Client\Json\ResponseObject;

/**
 * Class GetProfileResponse
 *
 * @package TillPayments\Client\CustomerProfile
 *
 * @property bool $profileExists
 * @property string $profileGuid
 * @property string $customerIdentification
 * @property string $preferredMethod
 * @property CustomerData $customer
 * @property PaymentInstrument[] $paymentInstruments
 */
class GetProfileResponse extends ResponseObject {

}
