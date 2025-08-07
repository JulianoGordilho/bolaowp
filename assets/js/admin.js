jQuery(document).ready(function ($) {
    $('#wpfp-test-api').on('click', function (e) {
        e.preventDefault();

        $('#wpfp-api-status').html('🔄 Testando...');

        $.post(wpfp_ajax.ajax_url, {
            action: 'wpfp_test_api',
            nonce: wpfp_ajax.nonce
        }, function (response) {
            if (response.success) {
                $('#wpfp-api-status').html('✅ Conexão bem-sucedida com a API-Football!');
            } else {
                $('#wpfp-api-status').html('❌ Erro: ' + response.data);
            }
        });
    });
});
