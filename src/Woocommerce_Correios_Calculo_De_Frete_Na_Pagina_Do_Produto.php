<?php

/**
 * Classe principal do plugin
 */
class Woocommerce_Correios_Calculo_De_Frete_Na_Pagina_Do_Produto {

    // True se for página de produto
    protected $is_product;

    // Variáveis que são preenchidas com o valor do $_GET ao solicitar o cálculo do frete
    protected $cep_destino;
    protected $produto_altura_final;
    protected $produto_largura_final;
    protected $produto_comprimento_final;
    protected $produto_peso_final;

    // Variáveis que são preenchidas temporariamente na memória durante a visita à página do produto
    protected $height;
    protected $width;
    protected $length;
    protected $weight;

    // SVG Inline
    protected $caminhao_svg;

    // Mensagem de erros
    protected $mensagem_erro;
    protected $mensagem_aviso;

    // CEP da loja
    protected $cep_remetente;

    // Base path do plugin
    protected $base_path;

    // Base URL do plugin
    protected $base_url;

    public function __construct() {

        // Preenche o CEP do remetente
        if (defined('WOO_CORREIOS_CALCULO_CEP')) {
            $woo_correios_calculo_cep = preg_replace('/[^0-9]/', '', WOO_CORREIOS_CALCULO_CEP);
            if (strlen($woo_correios_calculo_cep) !== 8) {
                $this->do_fatal_error('O WOO_CORREIOS_CALCULO_CEP está num formato inválido, por favor preencha exatamente neste formato: XXXXX-XXX, substituindo os X pelo número do seu CEP.');
            }
            $this->cep_remetente = WOO_CORREIOS_CALCULO_CEP;
        } else {
            $this->cep_remetente = get_option( 'woocommerce_store_postcode' );
        }

        // Hooks
        add_action( 'plugins_loaded', array($this, 'escutar_solicitacoes_de_frete') );
        add_action( 'admin_init', array($this, 'check_woocommerce') );
        add_action( 'wp_enqueue_scripts', array($this, 'enqueue_css_js_frontend') );

        // Outros
        $this->base_path = WOO_CORREIOS_CALCULO_CEP_BASE_PATH;
        $this->base_url = WOO_CORREIOS_CALCULO_CEP_BASE_URL;
        $this->caminhao_svg = file_get_contents($this->base_path.'/assets/img/caminhao.svg');

    }

    /**
     * Registra os CSS e JS que devem aparecer no frontend
     */
    public function enqueue_css_js_frontend() {
        // CSS
        wp_enqueue_style( 'woocommerce-correios-calculo-de-frete-na-pagina-do-produto-css', $this->base_url . '/assets/css/woocommerce-correios-calculo-de-frete-na-pagina-do-produto-public.css', array(), filemtime($this->base_path.'/assets/css/woocommerce-correios-calculo-de-frete-na-pagina-do-produto-public.css'), 'all' );
        // Javascript
        wp_enqueue_script( 'woocommerce-correios-calculo-de-frete-na-pagina-do-produto-js', $this->base_url . '/assets/js/woocommerce-correios-calculo-de-frete-na-pagina-do-produto-public.js', array('jquery'), filemtime($this->base_path.'/assets/js/woocommerce-correios-calculo-de-frete-na-pagina-do-produto-public.js'), false );
    }

    /**
     * Exibe uma mensagem de erro no painel do WordPress
     */
    public function exibe_mensagem_de_erro() {
        ?>
            <div class="error notice">
                <p style="font-weight: bold;">Ops!</p>
                <p>O plugin Cálculo de Frete na Página do Produto foi desativado: <strong><?php echo $this->mensagem_erro ?></strong></p>
            </div>
        <?php
    }

    /**
     * Registra o hook da mensagem de erro e desativa o plugin
     */
    public function do_fatal_error($mensagem_erro) {
        $this->mensagem_erro = $mensagem_erro;
        add_action( 'admin_notices', array($this, 'exibe_mensagem_de_erro'), 10 );
        deactivate_plugins( '/woo-correios-calculo-de-frete-na-pagina-do-produto/woocorreios-calculo-de-frete-na-pagina-do-produto.php' );
    }

    /**
     * Exibe uma mensagem de aviso no painel do WordPress
     */
    public function exibe_mensagem_de_aviso() {
        ?>
            <div class="notice-warning notice">
                <p style="font-weight: bold;">Atenção!!</p>
                <p><?php echo $this->mensagem_aviso ?></p>
            </div>
        <?php
    }

    /**
     * Registra o hook da mensagem de aviso
     */
    public function do_warning($mensagem_aviso) {
        $this->mensagem_aviso = $mensagem_aviso;
        add_action( 'admin_notices', array($this, 'exibe_mensagem_de_aviso'), 10 );
    }

    /**
     * Verifica se o WooCommerce está devidamente instalado.
     */
    public function check_woocommerce() {
        // Verifica se o WooCommerce está ativado
        if (!is_plugin_active('woocommerce/woocommerce.php') && is_admin()) {
            $this->do_fatal_error('O plugin WooCommerce deve estar ativo para usar este plugin.');
        }
        // Verifica se a versão do WooCommerce instalada é suportada
        if ( class_exists( 'WooCommerce' ) ) {
            global $woocommerce;
            if ( !version_compare( $woocommerce->version, '3.2.0', ">=" ) ) {
                if (!defined('WOO_CORREIOS_CALCULO_CEP')) {
                    $this->do_warning('O plugin Cálculo de Frete na Página requer WooCommerce 3.2.0 ou superior. Como você está usando uma versão inferior, é necessário adicionar este código no seu wp-config.php: <strong>define("WOO_CORREIOS_CALCULO_CEP", "XXXXX-XXX");</strong> (coloque logo abaixo do WP_DEBUG)');
                }
            }
        }
        // Verifica se o WooCommerce Correios está ativado
        if (!is_plugin_active('woocommerce-correios/woocommerce-correios.php') && is_admin()) {
            $this->do_fatal_error('O plugin WooCommerce Correios deve estar ativo para usar este plugin.');
        }
        $cep_origem = get_option( 'woocommerce_store_postcode' );
        $cep_origem = preg_replace('/[^0-9]/', '', $cep_origem);
        if (strlen($cep_origem) !== 8) {
            $this->do_fatal_error('Antes de usar este plugin, configure o CEP da sua loja em WooCommerce -> Configurações.');
        }
    }

    /**
     * Executa quando inicia o plugin
     */
    public function run() {
        add_action( 'woocommerce_before_add_to_cart_button', array($this, 'is_produto_single'));
    }

    /**
    *   Verifica se estamos na página de produto
    */
    public function is_produto_single() {
        global $product;
        if (is_product()) {
            $this->prepara_produto($product);
            if ($this->verifica_produto()) {
                add_action('woocommerce_before_add_to_cart_button', array($this, 'add_calculo_de_frete'), 11);
            }
        }
    }

    /**
     * Listener de $_POSTs para ver se estamos solicitando um cálculo de frete...
     */
    public function escutar_solicitacoes_de_frete() {
        // Verifica se estamos solicitando um cálculo de frete...
        if (
            isset($_POST['cep_origem']) && !empty($_POST['cep_origem'])
            &&
            isset($_POST['produto_altura']) && !empty($_POST['produto_altura'])
            &&
            isset($_POST['produto_largura']) && !empty($_POST['produto_largura'])
            &&
            isset($_POST['produto_comprimento']) && !empty($_POST['produto_comprimento'])
            &&
            isset($_POST['produto_peso']) && !empty($_POST['produto_peso'])
            &&
            isset($_POST['produto_peso']) && !empty($_POST['produto_preco'])
            &&
            isset($_POST['solicita_calculo_frete']) && wp_verify_nonce($_POST['solicita_calculo_frete'], 'solicita_calculo_frete')
        ) {
            $this->prepara_calculo_de_frete($_POST['cep_origem'], $_POST['produto_altura'], $_POST['produto_largura'], $_POST['produto_comprimento'], $_POST['produto_peso'], $_POST['produto_preco']);
        }
    }

    /**
     * Salva os dados do produto na memória
     */
    public function prepara_produto($product) {
        $this->product = $product;
        $this->height = $product->get_height();
        $this->width = $product->get_width();
        $this->length = $product->get_length();
        $this->weight = $product->get_weight();
        $this->price = number_format($product->get_price(), 2, '.', ',');
        $this->id = $product->get_id();
    }

    /**
     * Verifica se o produto têm os dados necessários para cálculo de frete
     */
    public function verifica_produto() {
        return is_numeric($this->height) && is_numeric($this->width) && is_numeric($this->length) && is_numeric($this->weight) && is_numeric($this->price);
    }

    /**
    * Adiciona o HTML do cálculo de frete na página do produto
    */
    public function add_calculo_de_frete() {
        ?>
            <div id="woocommerce-correios-calculo-de-frete-na-pagina-do-produto">
                <?php wp_nonce_field('solicita_calculo_frete', 'solicita_calculo_frete'); ?>
                <input type="hidden" id="calculo_frete_endpoint_url" value="<?php echo get_site_url();?>">
                <input type="hidden" id="calculo_frete_produto_altura" value="<?php echo $this->height;?>">
                <input type="hidden" id="calculo_frete_produto_largura" value="<?php echo $this->width;?>">
                <input type="hidden" id="calculo_frete_produto_comprimento" value="<?php echo $this->length;?>">
                <input type="hidden" id="calculo_frete_produto_peso" value="<?php echo $this->weight;?>">
                <input type="hidden" id="calculo_frete_produto_preco" value="<?php echo $this->price;?>">
                <input type="hidden" id="id_produto" value="<?php echo $this->id;?>">
                <div class="calculo-de-frete">
                    <input type="text" maxlength="9" onkeyup="return mascara(this, '#####-###');">
                    <div id="calcular-frete"><?php echo $this->caminhao_svg;?> Calcular Frete</div>
                    <div id="calcular-frete-loader"></div>
                </div>
                <div class="resultado-frete">
                    <table>
                        <thead>
                            <tr>
                                <td>Forma de envio</td>
                                <td>Custo estimado</td>
                                <td>Entrega estimada</td>
                            </tr>
                        </thead>
                        <tbody>
                            <tr data-formaenvio="pac">
                                <td>PAC</td>
                                <td>R$ <span data-custo></span></td>
                                <td>Em até <span data-entrega></span> dias</td>
                            </tr>
                            <tr data-formaenvio="sedex">
                                <td>SEDEX</td>
                                <td>R$ <span data-custo></span></td>
                                <td>Em até <span data-entrega></span> dias</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php
    }

    /**
     * Retorna um JSON com os preços do frete
     */
    public function prepara_calculo_de_frete($cep_destino, $altura, $largura, $comprimento, $peso, $preco) {
        $erro = false;
        $cep = preg_replace('/[^0-9]/', '', $cep_destino);
        if (strlen($cep) !== 8) {
            $erro = true;
            $result['status'] = 'erro';
            $result['mensagem'] = 'Por favor, informe um CEP válido.';
            $this->retornar_json($result);
        }
        if (!is_numeric($altura) || !is_numeric($largura) || !is_numeric($comprimento) || !is_numeric($peso) || !is_numeric($preco)) {
            $erro = true;
            $result['status'] = 'erro';
            $result['mensagem'] = 'Por favor, informe dimensões válidas.';
            $this->retornar_json($result);
        }
        if (!$erro) {
            // Temos dados válidos
            $this->cep_destino = $cep_destino;
            $this->produto_altura_final = $altura;
            $this->produto_largura_final = $largura;
            $this->produto_comprimento_final = $comprimento;
            $this->produto_peso_final = $peso;
            $this->produto_preco_final = $preco;
            $this->id_produto = $id_produto;
            add_action('plugins_loaded', array($this, 'calcula_frete'), 15);
        }
    }

    /**
     * Retorna o JSON
     */
    protected function retornar_json(array $output) {
        header("Content-type: application/json");
        die(json_encode($output, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Envia os dados para a API do WC_Correios e retorna o JSON
     */
    public function calcula_frete() {
        $output = array();
        if ( class_exists('WC_Correios') ) {
            // Carrega o WC_Correios
            $correios = new WC_Correios;
            $correios->init();

            // Pega os valores propriamente dito
            $pac = (array) $this->get_valor_frete_wc_correios('04510'); // PAC
            $sedex = (array) $this->get_valor_frete_wc_correios('04014'); // SEDEX

            // Faz algumas verificações de segurança pra garantir que está tudo certo
            $pac = $this->verifica_retorno_wc_correios($pac);
            $sedex = $this->verifica_retorno_wc_correios($sedex);

            // Preenche o Output
            $output['pac'] = $pac;
            $output['sedex'] = $sedex;
        }
        $this->retornar_json($output);
    }

    /**
     * Envia os dados para a API do WC_Correios e retorna o valor do frete
     */
    protected function get_valor_frete_wc_correios($code) {
        $correiosWebService = new WC_Correios_Webservice;

        $correiosWebService->set_height($this->produto_altura_final);
        $correiosWebService->set_width($this->produto_largura_final);
        $correiosWebService->set_length($this->produto_comprimento_final);
        $correiosWebService->set_weight($this->produto_peso_final);
        $correiosWebService->set_declared_value($this->produto_preco_final);
        $correiosWebService->set_destination_postcode($this->cep_destino);
        $correiosWebService->set_origin_postcode($this->cep_remetente);
        $correiosWebService->set_service($code);
        return $correiosWebService->get_shipping();
    }

    /**
     * Verifica se o retorno do WC_Correios é válido
     */
    public function verifica_retorno_wc_correios($array) {
        if (!is_array($array)) {
            return array('status' => 'erro', 'mensagem' => 'Erro desconhecido');
        }
        if (!array_key_exists('Valor', $array) || !array_key_exists('PrazoEntrega', $array)) {
            return array('status' => 'erro', 'mensagem' => 'Erro desconhecido.');
        }
        return $array;
    }

}
