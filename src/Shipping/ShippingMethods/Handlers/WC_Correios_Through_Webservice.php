<?php

namespace CFPP\Shipping\ShippingMethods\Handlers;

use CFPP\Exceptions\HandlerException;
use CFPP\Shipping\Payload;
use CFPP\Shipping\ShippingMethods\Handler;
use CFPP\Shipping\ShippingMethods\Traits\ValidationRules;

class WC_Correios_Through_Webservice extends Handler
{
    /**
     * Calculates shipping costs for a Payload object using reflection class on
     * WooCommerce Correios plugin
     *
     * @param Payload $payload
     * @return array|mixed
     * @throws HandlerException
     */
    public function calculate(Payload $payload)
    {
        // Calculate costs
        $reflection_response = $this->getReflectionResponse($this->shipping_method, $this->generateCorreiosPackage($payload));

        // Return response
        if ($reflection_response['Erro'] == "0") {
            return $this->response->success(
                $reflection_response['Valor'],
                $reflection_response['PrazoEntrega'],
                $reflection_response
            );
        } else {
            return $this->response->error($reflection_response['MsgErro']);
        }
    }

    /**
     * Generate a Correios Package, needed by WooCommerce Correios
     *
     * @param Payload $payload
     * @return array
     */
    public function generateCorreiosPackage(Payload $payload)
    {
        $total_cost = $payload->getProduct()->get_price() * $payload->getQuantity();

        $package = array();
        $package['destination'] = array();
        $package['destination']['postcode'] = $payload->getPostcode();
        $package['contents_cost'] = $total_cost;
        $package['contents'] = array(
            $payload->getProduct()->get_id() => array(
                'data' => $payload->getProduct(),
                'quantity' => $payload->getQuantity()
            )
        );

        return $package;
    }

    /**
     * @param \WC_Correios_Shipping $shipping_method
     * @param $package
     * @return array
     * @throws HandlerException
     */
    private function getReflectionResponse(\WC_Correios_Shipping $shipping_method, $package)
    {
        $r_class_name = get_class($shipping_method);

        try {
            $r_get_rate = new \ReflectionMethod($r_class_name, 'get_rate');
        } catch (\ReflectionException $e) {
            throw HandlerException::unable_to_reflect_exception($r_class_name);
        }

        $r_get_rate->setAccessible(true);
        $r_response = $r_get_rate->invoke(new $r_class_name($this->shipping_method->instance_id), $package);

        if ($r_response instanceof \SimpleXMLElement === false) {
            throw HandlerException::unexpected_reflection_response_exception();

        }
        return (array) $r_response;
    }

    /**
     * Runs before Validate() method
     */
    public function beforeValidate()
    {
        add_filter('cfpp_handler_rules_wc_correios_shipping_pac', [$this, 'validatePacSedex']);
        add_filter('cfpp_handler_rules_wc_correios_shipping_sedex', [$this, 'validatePacSedex']);
    }

    /**
     * Default validation rules for PAC and SEDEX
     *
     * @param \CFPP\Shipping\ShippingMethods\Traits\ValidationRules $rules
     * @return \CFPP\Shipping\ShippingMethods\Traits\ValidationRules
     */
    public function validatePacSedex(ValidationRules $rules)
    {
        // Swapping height and length due to WooCommerce Correios bug
        // @see https://github.com/claudiosanches/woocommerce-correios/pull/130
        $rules->setDefault('height', 16, 105);
        $rules->setDefault('width', 11, 105);
        $rules->setDefault('length', 2, 105);
        $rules->setDefault('weight', null, 30);
        $rules->setDefault('sum_height_width_length', 29, 200);
        return $rules;
    }
}
