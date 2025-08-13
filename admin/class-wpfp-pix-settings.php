<?php
if (!defined('ABSPATH')) exit;

class WPFPPixSettings {
    const OPT_KEY    = 'wpfp_pix_key_pf';
    const OPT_NOME   = 'wpfp_pix_nome_pf';
    const OPT_CIDADE = 'wpfp_pix_cidade_pf';
    const OPT_TXID   = 'wpfp_pix_txid_pf'; // novo

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register']);
    }

    public static function menu() {
        add_menu_page(
            'Configurações PIX (PF)',
            'PIX (PF)',
            'manage_options',
            'wpfp-pix-pf',
            [__CLASS__, 'render_page'],
            'dashicons-money-alt',
            56
        );
    }

    public static function register() {
        register_setting('wpfp_pix_pf_group', self::OPT_KEY);
        register_setting('wpfp_pix_pf_group', self::OPT_NOME);
        register_setting('wpfp_pix_pf_group', self::OPT_CIDADE);
        register_setting('wpfp_pix_pf_group', self::OPT_TXID);

        add_settings_section(
            'wpfp_pix_pf_section',
            'Chave PIX de Pessoa Física',
            function(){
                echo '<p>Configure a chave PIX que será exibida no QR Code após a reserva de cotas.</p>';
            },
            'wpfp-pix-pf'
        );

        add_settings_field(
            self::OPT_KEY,
            'Chave PIX (PF)',
            [__CLASS__, 'field_input'],
            'wpfp-pix-pf',
            'wpfp_pix_pf_section',
            ['key' => self::OPT_KEY, 'placeholder' => 'CPF / E-mail / Telefone / Aleatória']
        );

        add_settings_field(
            self::OPT_NOME,
            'Nome do Recebedor',
            [__CLASS__, 'field_input'],
            'wpfp-pix-pf',
            'wpfp_pix_pf_section',
            ['key' => self::OPT_NOME, 'placeholder' => 'Seu nome completo']
        );

        add_settings_field(
            self::OPT_CIDADE,
            'Cidade (BACEN)*',
            [__CLASS__, 'field_input'],
            'wpfp-pix-pf',
            'wpfp_pix_pf_section',
            ['key' => self::OPT_CIDADE, 'placeholder' => 'SUA-CIDADE']
        );

        add_settings_field(
            self::OPT_TXID,
            'TXID (opcional)',
            [__CLASS__, 'field_input'],
            'wpfp-pix-pf',
            'wpfp_pix_pf_section',
            ['key' => self::OPT_TXID, 'placeholder' => 'Identificador do pagamento (até 35 chars)']
        );
    }

    public static function field_input($args) {
        $key = $args['key'];
        $val = get_option($key, '');
        $placeholder = isset($args['placeholder']) ? $args['placeholder'] : '';
        printf(
            '<input type="text" name="%s" value="%s" class="regular-text" placeholder="%s" />',
            esc_attr($key),
            esc_attr($val),
            esc_attr($placeholder)
        );
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) return; ?>
        <div class="wrap">
            <h1>Configurações PIX (Pessoa Física)</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('wpfp_pix_pf_group');
                do_settings_sections('wpfp-pix-pf');
                submit_button('Salvar Configurações');
                ?>
            </form>
            <p>
                <em>
                    * A cidade é um campo obrigatório no padrão EMV do PIX, mas não precisa ser a cidade real. 
                <br />
               Por exemplo, se você tem conta no Banco do Brasil em São Bernardo do Campo, o campo "Cidade" deve ser preenchido com "São Bernardo do Campo", segundo o Banco Central. 
            </em>
        </p>
        </div>
    <?php }
}
WPFPPixSettings::init();
