<?php

namespace CFPP\Shipping\ShippingMethods\Traits;

use CFPP\Common\Cep;

trait WC_Correios_Webservice_Trait
{
    /**
    *   Instance of Correios Web Service
    */
    public function correiosWebService($request)
    {
        $correiosWebService = new \WC_Correios_Webservice($this->shipping_method->id, $this->shipping_method->instance_id);

        $correiosWebService->set_debug = 'no';
        $correiosWebService->set_service($this->shipping_method->get_code());

        $correiosWebService->set_height($request['height']);
        $correiosWebService->set_width($request['width']);
        $correiosWebService->set_length($request['length']);
        $correiosWebService->set_weight($request['weight']);

        $correiosWebService->set_origin_postcode(Cep::getOriginCep());
        $correiosWebService->set_destination_postcode($request['destination_postcode']);

        // Valor Declarado
        if ($this->checkDeclaredValue($request)) {
            $correiosWebService->set_declared_value($request['price'] * $request['quantity']);
        }

        // Mão Própria
        $correiosWebService->set_own_hands($this->checkOwnHands());

        // Aviso de recebimento
        $correiosWebService->set_receipt_notice($this->checkReceiptNotice());

        // Login e Senha para usuário corporativos
        if ($this->checkLoginPassword()) {
            $correiosWebService->set_login($this->shipping_method->login);
            $correiosWebService->set_password($this->shipping_method->password);
        }

        // Dimensões mínimas
        $correiosWebService->set_minimum_height($this->shipping_method->minimum_height);
        $correiosWebService->set_minimum_width($this->shipping_method->minimum_width);
        $correiosWebService->set_minimum_length($this->shipping_method->minimum_length);

        // Peso extra
        $correiosWebService->set_extra_weight($this->shipping_method->extra_weight);

        // Call the WebService
        $entrega = $correiosWebService->get_shipping();

        // Check if WebService response is valid
        $response = $this->checkIsValidWebServiceResponse($entrega);

        if ($response['success'] == false) {
            return array(
                'status' => 'error',
                'debug' => $response['message']
            );
        }


        // Normalize Shipping Price
        $price = wc_correios_normalize_price(esc_attr((string) $entrega->Valor));

        /*
        // Prepara o Prazo de Entrega, com dias adicionais, se houver configurado
        $prazo = $this->prepareEstimatedDeliveryDate($entrega);
        $entrega->PrazoEntrega = $prazo['PrazoEntrega'];
        $entrega->DiasAdicionais = $prazo['DiasAdicionais'];

        // Custo Adicional
        $original_price = $entrega->Valor;
        $costs = $this->checkAdditionalCosts($original_price);
        $price = $costs['price'];
        $entrega->Fee = $costs['fee'];
        */

        return array(
            'price' => $price,
            'days' => (int) $entrega->PrazoEntrega,
            'debug' => $entrega
        );
    }

    /**
     * Check if we should add a Declared Value
     */
    private function checkDeclaredValue($request)
    {
        return property_exists($this->shipping_method, 'declare_value') &&
               $this->shipping_method->declare_value == 'yes' &&
               ($request['price'] * $request['quantity']) >= 18.50;
    }

    /**
     * Check if we should add Own Hands
     */
    private function checkOwnHands()
    {
        return property_exists($this->shipping_method, 'own_hands') &&
               $this->shipping_method->own_hands == 'yes'
               ? 'S' : 'N';
    }

    /**
     * Check if we should add Receipt Notice
     */
    private function checkReceiptNotice()
    {
        return property_exists($this->shipping_method, 'receipt_notice') &&
               $this->shipping_method->receipt_notice == 'yes'
               ? 'S' : 'N';
    }

    /**
     * Normalize product shipping
     */
    private function normalizeShippingPrice($price)
    {
        return floatval(str_replace(',', '.', str_replace('.', '', $price)));
    }

    /**
     * Prepare estimated delivery date, according to additional delivery days, etc
     */
    private function prepareEstimatedDeliveryDate($entrega)
    {
        $dias_adicionais = 0;
        if (property_exists($this->shipping_method, 'additional_time') &&
            is_numeric($this->shipping_method->additional_time)
        ) {
            $dias_adicionais = $this->shipping_method->additional_time;
        }
        return array(
            'PrazoEntrega' => $entrega->PrazoEntrega + $dias_adicionais,
            'DiasAdicionais' => $dias_adicionais
        );
    }

    /**
     * Maybe add additional costs
     */
    private function checkAdditionalCosts($price)
    {
        // Custo adicional
        $fee = 0;
        if (property_exists($this->shipping_method, 'fee') && !empty($this->shipping_method->fee)) {
            if (substr($this->shipping_method->fee, -1) == '%') {
                $porcentagem = preg_replace('/[^0-9]/', '', $this->shipping_method->fee);
                $price = ($price/100)*(100+$porcentagem);
                $fee = $porcentagem.'%';
            } else {
                $price = $price + $this->shipping_method->fee;
                $fee = $this->shipping_method->fee;
            }
        }
        return array(
            'price' => $price,
            'fee' => $fee
        );
    }

    /**
     * Check if the WebService response is valid
     */
    private function checkIsValidWebServiceResponse($response)
    {
        if (isset($response->Erro) && $response->Erro == 0) {
            return array(
                'success' => true
            );
        } else {
            return array(
                'success' => false,
                'message' => $response->MsgErro
            );
        }
    }

    /**
     * Check wether we have login and password for corporate users
     */
    private function checkLoginPassword()
    {
        return $this->shipping_method->service_type == 'corporate';
    }
}