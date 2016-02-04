<?php

namespace Isotope\Model\Payment;

use Contao\Request;
use Isotope\Interfaces\IsotopePayment;
use Isotope\Interfaces\IsotopeProductCollection;
use Isotope\Model\Payment;
use Isotope\Model\ProductCollection\Order;
use Isotope\Module\Checkout;
use Isotope\Template;

/**
 * Base class for Open Payment Platform
 *
 * @property string $opp_user_id
 * @property string $opp_password
 * @property string $opp_entity_id
 */
class OpenPaymentPlatform extends Payment implements IsotopePayment
{
    /**
     * Return the checkout form.
     *
     * @param IsotopeProductCollection|Order $objOrder  The order being places
     * @param \Module|Checkout               $objModule The checkout module instance
     *
     * @return string
     */
    public function checkoutForm(IsotopeProductCollection $objOrder, \Module $objModule)
    {
        $base    = $this->getBaseUrl();
        $request = $this->prepareRequest('PA', $objOrder);
        $request->send($base . '/v1/checkouts');

        $response = json_decode($request->response, true);
        $this->storeApiResponse($response, $objOrder);

        if ('000.200.100' !== $response['result']['code']) {
            \System::log(
                sprintf('Payment for order ID %s could not be initialized. See log files for more information.', $objOrder->id),
                __METHOD__,
                TL_ERROR
            );

            log_message(print_r($response, true), 'open_payment.log');

            $objModule->redirectToStep('failed');
        }

        /** @var Template|object $template */
        $template = new Template('iso_payment_opp');
        $template->base   = $base;
        $template->action = $objModule->generateUrlForStep('complete', $objOrder);
        $template->checkoutId = $response['id'];

        return $template->parse();
    }

    /**
     * Process payment on checkout confirmation page.
     *
     * @param   IsotopeProductCollection|Order $objOrder  The order being places
     * @param   \Module                        $objModule The checkout module instance
     *
     * @return  mixed
     */
    public function processPayment(IsotopeProductCollection $objOrder, \Module $objModule)
    {
        $ndc = \Input::get('id');

        $request = new Request();
        $request->send($this->getBaseUrl() . '/v1/checkouts/' . $ndc . '/payment');

        $response = json_decode($request->response, true);
        $this->storeApiResponse($response, $objOrder);

        if ('000.100.110' !== $response['result']['code']
            || 'PA' !== $response['paymentType']
            || $ndc !== $response['ndc']
            || $objOrder->getTotal() != $response['amount']
            || $objOrder->currency != $response['currency']
        ) {
            \System::log(
                sprintf('Payment data for order ID %s could not be verified. See log files for more information.', $objOrder->id),
                __METHOD__,
                TL_ERROR
            );

            log_message(\Environment::get('request'), 'open_payment.log');
            log_message(print_r($response, true), 'open_payment.log');

            return false;
        }

        // Capture payment
        if ('capture' === $this->trans_type) {
            $request = $this->prepareRequest('CP', $objOrder);
            $request->send($this->getBaseUrl() . '/v1/payments/' . $response['id']);

            $response = json_decode($request->response, true);
            $this->storeApiResponse($response, $objOrder);

            if ('000.100.110' !== $response['result']['code']
                || 'CP' !== $response['paymentType']
                || $objOrder->getTotal() != $response['amount']
                || $objOrder->currency != $response['currency']
            ) {
                \System::log(
                    sprintf(
                        'Payment for order ID %s could not be captured. See log files for more information.',
                        $objOrder->id
                    ),
                    __METHOD__,
                    TL_ERROR
                );

                log_message(print_r($response, true), 'open_payment.log');
            }
        }

        return true;
    }

    private function getBaseUrl()
    {
        return 'https://' . ($this->debug ? 'test.' : '') . 'oppwa.com';
    }

    /**
     * @param string                         $type
     * @param IsotopeProductCollection|Order $objOrder
     *
     * @return Request
     */
    private function prepareRequest($type, IsotopeProductCollection $objOrder)
    {
        $params = [
            'authentication.userId'   => $this->opp_user_id,
            'authentication.password' => $this->opp_password,
            'authentication.entityId' => $this->opp_entity_id,
            'amount'                  => number_format($objOrder->getTotal(), 2, '.', ''),
            'currency'                => $objOrder->currency,
            'paymentType'             => $type
        ];

        $request = new Request();
        $request->setHeader('Content-Type', 'application/x-www-form-urlencoded');
        $request->method = 'post';
        $request->data = http_build_query($params);

        return $request;
    }

    /**
     * @param array                          $data
     * @param IsotopeProductCollection|Order $objOrder
     */
    private function storeApiResponse(array $data, IsotopeProductCollection $objOrder)
    {
        $payments = deserialize($objOrder->payment_data, true);

        if (!is_array($payments['OPP'])) {
            $payments['OPP'] = array();
        }

        $payments['OPP'][] = $data;

        $objOrder->payment_data = $payments;
        $objOrder->save();
    }
}
