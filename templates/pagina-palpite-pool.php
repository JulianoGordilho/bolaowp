<?php
/**
 * Template Name: Página de Palpites (Pool)
 */
if (!defined('ABSPATH')) exit;

if (!is_user_logged_in()) { wp_redirect(wp_login_url()); exit; }

global $wpdb;

$pool_id = intval(get_query_var('pool_id')) ?: (isset($_GET['pool_id']) ? intval($_GET['pool_id']) : 0);
$user_id = get_current_user_id();

// Validação do pool
$pool_post = get_post($pool_id);
if (!$pool_post || $pool_post->post_type !== 'wpfp_pool') {
    echo '<div class="alert alert-danger">❌ Pool não encontrado.</div>';
    return;
}

// Metadados do pool
$titulo      = $pool_post->post_title;
$liga        = get_post_meta($pool_id, '_wpfp_liga', true);
$pais        = get_post_meta($pool_id, '_wpfp_pais', true);
$data_limite = get_post_meta($pool_id, '_wpfp_data_limite_cache', true) ?: date('Y-m-d H:i:s');

$games      = get_post_meta($pool_id, '_wpfp_selected_games', true);
$cancelados = get_post_meta($pool_id, '_wpfp_jogos_cancelados', true) ?: [];
if (!is_array($games) || empty($games)) {
    echo '<div class="alert alert-warning">⚠️ Nenhum jogo foi selecionado para este pool.</div>';
    return;
}

/** ==========================
 *  RESERVA DE COTAS
 * ========================== */
$valor_cota_reserva = floatval(get_post_meta($pool_id, '_wpfp_pontos_por_cota', true) ?: 0);
$cotas_max          = intval(get_post_meta($pool_id, '_wpfp_cotas_max', true) ?: 0);

$table_apostas = $wpdb->prefix . 'pool_apostas';

// Descobre qual valor de "pending" seu ENUM aceita (pendente vs pending)
$wpfp_status_pending = 'pending';
$col = $wpdb->get_row("SHOW COLUMNS FROM {$table_apostas} LIKE 'status'");
if ($col && isset($col->Type) && preg_match_all("/'([^']+)'/",$col->Type,$m)) {
    $enum_vals = $m[1];
    if (in_array('pendente', $enum_vals, true)) {
        $wpfp_status_pending = 'pendente';
    } elseif (in_array('pending', $enum_vals, true)) {
        $wpfp_status_pending = 'pending';
    }
}

// Compat: somar pendentes + aprovadas, independente do ENUM
$in_pend_aprov = function_exists('wpfp_status_in_clause') ? wpfp_status_in_clause(['pending','approved']) : "('pending','aprovado','aprovada','pendente')";
$reservadas_pend_aprov = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(qtd_cotas),0) FROM {$table_apostas} WHERE pool_id = %d AND status IN {$in_pend_aprov}",
    $pool_id
));
$cotas_disponiveis = max(0, $cotas_max - $reservadas_pend_aprov);

// PIX (admin)
$pix_chave_pf  = get_option('wpfp_pix_key_pf', '');
$pix_nome_pf   = get_option('wpfp_pix_nome_pf', 'PESSOA FISICA');
$pix_cidade_pf = get_option('wpfp_pix_cidade_pf', 'CIDADE');
$pix_txid_base = get_option('wpfp_pix_txid_pf', '');

// Estado do POST (reserva)
$reserva_msg = '';
$reserva_erro = '';
$reserva_ok = false;
$qtd_cotas_solic = 0;
$valor_total_reserva = 0.0;
$wpfp_emv_payload = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wpfp_reservar_cotas'])) {
    check_admin_referer('wpfp_reservar_cotas_' . $pool_id, 'wpfp_nonce');

    $qtd_cotas_solic = isset($_POST['qtd_cotas']) ? intval($_POST['qtd_cotas']) : 0;

    if ($qtd_cotas_solic <= 0) {
        $reserva_erro = 'Informe uma quantidade de cotas válida.';
    } elseif ($qtd_cotas_solic > $cotas_disponiveis) {
        $reserva_erro = 'Quantidade solicitada maior do que as cotas disponíveis.';
    } elseif ($valor_cota_reserva <= 0) {
        $reserva_erro = 'Valor da cota não configurado para este pool.';
    } else {
        $status_db = $wpfp_status_pending; // compatível com o ENUM atual
        $ok = $wpdb->insert(
            $table_apostas,
            [
                'user_id'    => $user_id,
                'pool_id'    => $pool_id,
                'qtd_cotas'  => $qtd_cotas_solic,
                'status'     => $status_db,
                'created_at' => current_time('mysql'),
            ],
            ['%d','%d','%d','%s','%s']
        );

        if (!$ok) {
            // Mensagem amigável
            $reserva_erro = 'Não foi possível salvar sua reserva. Tente novamente.';

            // Log pra debug
            error_log('[WPFPOOL][RESERVA] last_error=' . $wpdb->last_error . ' | last_query=' . $wpdb->last_query);

            // Mostra erro detalhado só para administradores na tela
            if (current_user_can('manage_options')) {
                $reserva_erro .= ' <small style="color:#a00">[DB]: ' . esc_html($wpdb->last_error) . '</small>';
            }
        } else {
            $reserva_id = (int) $wpdb->insert_id;
            $valor_total_reserva = $valor_cota_reserva * $qtd_cotas_solic;
            $reserva_msg = 'Reserva criada com sucesso! Seu pedido ficará <strong>pendente</strong> até aprovação do administrador.';
            $reserva_ok = true;

            // TXID determinístico, sem DDL
            $txid = $pix_txid_base !== '' ? $pix_txid_base : ('RES-' . $pool_id . '-' . $reserva_id);
            $txid = substr(preg_replace('/[^A-Za-z0-9\-]/', '', $txid), 0, 35);

            // EMV PIX pagável
            if (!class_exists('WPFPPixEMV')) {
                require_once plugin_dir_path(__FILE__) . '../includes/class-wpfp-pix-emv.php';
            }
            if ($pix_chave_pf && $valor_total_reserva > 0) {
                $wpfp_emv_payload = WPFPPixEMV::buildPayload(
                    $pix_chave_pf,
                    (float) $valor_total_reserva,
                    $pix_nome_pf ?: 'PESSOA FISICA',
                    $pix_cidade_pf ?: 'CIDADE',
                    $txid
                );
            }

            // Recalcula disponíveis pós-reserva
            $reservadas_pend_aprov = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(qtd_cotas),0) FROM {$table_apostas} WHERE pool_id = %d AND status IN {$in_pend_aprov}",
                $pool_id
            ));
            $cotas_disponiveis = max(0, $cotas_max - $reservadas_pend_aprov);
        }
    }
}

$valor_cota_reserva_fmt  = 'R$ ' . number_format($valor_cota_reserva, 2, ',', '.');
$valor_total_reserva_fmt = 'R$ ' . number_format($valor_total_reserva, 2, ',', '.');

/** ==========================
 *  PREMIAÇÃO (mantendo seu cálculo)
 * ========================== */
$valor_cota = floatval(get_post_meta($pool_id, '_wpfp_valor_cota', true));
$in_aprov = function_exists('wpfp_status_in_clause') ? wpfp_status_in_clause(['approved']) : "('aprovado','aprovada')";
$qtd_cotas_aprovadas = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COALESCE(SUM(qtd_cotas),0) FROM {$wpdb->prefix}pool_apostas WHERE pool_id = %d AND status IN {$in_aprov}",
    $pool_id
));

$total_arrecadado = $valor_cota * $qtd_cotas_aprovadas;
$premio_primeiro  = $total_arrecadado * 0.70;
$premio_segundo   = $total_arrecadado * 0.30;
$premio_extra_acumulado = get_post_meta($pool_id, '_wpfp_valor_acumulado', true) ?: 0;
$super_premio     = ($total_arrecadado * 0.03) + floatval($premio_extra_acumulado);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Palpitar Jogos</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <?php wp_head(); ?>
  <style>
    .wpfp-reserva{margin:20px 0;padding:16px;border:1px solid #ddd;border-radius:8px;background:#fafafa}
    .wpfp-row{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:8px 0;color:#333}
    .wpfp-row strong{min-width:190px}
    .wpfp-msg{margin-top:12px;padding:12px;border-radius:8px}
    .wpfp-msg.success{background:#e8f7ee;border:1px solid #9ed7b5;color:#333}
    .wpfp-msg.error{background:#fde8e8;border:1px solid #f5b5b5}
    .team-cell img{height:18px;margin-right:6px}
    
    textarea[readonly]{background:#f9f9f9}
  </style>
</head>
<body>

  <div class="header">
    <h4>Palpite da Rodada - <?= esc_html($titulo) ?></h4>
    <small>Liga: <?= esc_html($liga) ?> | País: <?= esc_html($pais) ?> | Limite: <?= $data_limite ? date('d/m/Y H:i', strtotime($data_limite)) : 'Não definido' ?></small><br>
    <small> Id: <?= esc_html($pool_id) ?> | Liga: <?= esc_html($liga) ?> | Data limite: <?= $data_limite ? date('d/m/Y H:i', strtotime($data_limite)) : 'Não definido' ?> </small>
  </div>

  <div class="container">

    <!-- PREMIAÇÃO (mantido) -->
    <div class="premiacao-box">
      <h5 class="text-white">🏆 Premiação</h5>
      <ul class="list-unstyled text-white">
        <li>💸 Valor da Aposta: R$ <?= number_format($valor_cota, 2, ',', '.') ?></li>
        <li>🥇 1º Prêmio (70%): R$ <?= number_format($premio_primeiro, 2, ',', '.') ?></li>
        <li>🥈 2º Prêmio (30%): R$ <?= number_format($premio_segundo, 2, ',', '.') ?></li>
        <li>💰 Super prêmio acumulado (3% + acumulado): R$ <?= number_format($super_premio, 2, ',', '.') ?></li>
      </ul>
    </div>

    <!-- =======================
         TABELA DE PALPITES
         ======================= -->
    <div class="table-responsive">
      <table class="table table-bordered align-middle text-center text-white">
        <thead>
          <tr>
            <th>#</th>
            <th>Data</th>
            <th>Mandante</th>
            <th>M</th>
            <th>V</th>
            <th>Visitante</th>
            <th>Campeonato</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $index = 1;
          foreach ($games as $fixture_id) {
              if (in_array($fixture_id, $cancelados)) continue;

              $fx = wpfp_obter_fixture_com_fallback($fixture_id);
              if (!$fx && defined('WPFPOOL_USAR_MOCK_EM_FALHA_API') && WPFPOOL_USAR_MOCK_EM_FALHA_API) {
                  $mock_path = WPFPOOL_PLUGIN_DIR . "mock/fixtures/{$fixture_id}.json";
                  if (file_exists($mock_path)) {
                      $fx = json_decode(file_get_contents($mock_path), true);
                  }
              }

              if (!$fx) {
                  echo "<tr><td colspan='7'>❌ Fixture {$fixture_id} não disponível.</td></tr>";
                  continue;
              }

              $home = $fx['teams']['home']['name'] ?? 'Time A';
              $away = $fx['teams']['away']['name'] ?? 'Time B';
              $logo_home = $fx['teams']['home']['logo'] ?? '';
              $logo_away = $fx['teams']['away']['logo'] ?? '';
              $data_jogo = $fx['fixture']['date'] ?? '';
              $timestamp_jogo = strtotime($data_jogo);
              $agora = current_time('timestamp');
              $encerrado = $timestamp_jogo < $agora;

              $palpite = $wpdb->get_row($wpdb->prepare(
                  "SELECT palpite_home, palpite_away FROM {$wpdb->prefix}pool_palpites 
                   WHERE user_id = %d AND pool_id = %d AND fixture_id = %d",
                  $user_id, $pool_id, $fixture_id
              ));

              echo "<tr>";
              echo "<td>{$index}</td>";
              echo "<td>" . date('d/m/Y H:i', $timestamp_jogo) . "</td>";

              echo "<td class='team-cell'><img src='{$logo_home}' alt=''><span>{$home}</span></td>";

              // Liberado para edição sempre (se quiser travar após o jogo, reative o if abaixo)
              // if ($encerrado && (!defined('WPFPOOL_USAR_MOCK_EM_FALHA_API') || !WPFPOOL_USAR_MOCK_EM_FALHA_API)) {
              //     echo "<td colspan='2'>" . esc_html($palpite->palpite_home ?? '-') . " x " . esc_html($palpite->palpite_away ?? '-') . "</td>";
              // } else {
                  $v1 = esc_attr($palpite->palpite_home ?? '');
                  $v2 = esc_attr($palpite->palpite_away ?? '');
                  $input_home = esc_attr($v1);
                  $input_away = esc_attr($v2);

                  echo <<<HTML
                    <td>
                      <input 
                        type="number" 
                        class="form-control palpite-input" 
                        name="palpites[{$fixture_id}][home]" 
                        value="{$input_home}" 
                        inputmode="numeric" 
                        pattern="[0-9]*" 
                        oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                    </td>
                    <td>
                      <input 
                        type="number" 
                        class="form-control palpite-input" 
                        name="palpites[{$fixture_id}][away]" 
                        value="{$input_away}" 
                        inputmode="numeric" 
                        pattern="[0-9]*" 
                        oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                    </td>
                  HTML;
              // }

              echo "<td class='team-cell'><img src='{$logo_away}' alt=''><span>{$away}</span></td>";
              echo "<td>" . esc_html($fx['league']['name'] ?? '-') . "</td>";
              echo "</tr>";

              $index++;
          }
          ?>
        </tbody>
      </table>
    </div>

    <!-- =======================
         RESERVA DE COTAS (reposicionada AQUI)
         ======================= -->
    <div class="wpfp-reserva">
      <form method="post" action="" id="wpfp_form_reserva">
        <?php wp_nonce_field('wpfp_reservar_cotas_' . $pool_id, 'wpfp_nonce'); ?>
        <input type="hidden" name="wpfp_reservar_cotas" value="1" />

        <div class="wpfp-row"><strong>Valor da Cota (reserva):</strong> <span><?= esc_html($valor_cota_reserva_fmt) ?></span></div>
        <div class="wpfp-row"><strong>Cotas Máximas:</strong> <span><?= esc_html($cotas_max) ?></span></div>
        <div class="wpfp-row"><strong>Reservadas (pendentes + aprovadas):</strong> <span><?= esc_html($reservadas_pend_aprov) ?></span></div>
        <div class="wpfp-row"><strong>Disponíveis:</strong> <span id="wpfp_disp"><?= esc_html($cotas_disponiveis) ?></span></div>

        <div class="wpfp-row">
          <label for="qtd_cotas"><strong>Quantidade de cotas:</strong></label>
          <input type="number"
                 id="qtd_cotas"
                 name="qtd_cotas"
                 min="1"
                 max="<?= esc_attr($cotas_disponiveis) ?>"
                 value="<?= esc_attr(min(max(1, $qtd_cotas_solic ?: 1), $cotas_disponiveis)) ?>"
                 <?= $cotas_disponiveis <= 0 ? 'disabled' : '' ?>
                 required
                 class="form-control"
                 style="width:130px;">
          <span class="text-muted">máx: <?= esc_html($cotas_disponiveis) ?></span>
        </div>

        <div class="wpfp-row">
          <strong>Total:</strong>
          <span id="wpfp_total_js"><?php
            $init_total = ($qtd_cotas_solic > 0 ? $qtd_cotas_solic : 1) * $valor_cota_reserva;
            echo 'R$ ' . number_format($init_total, 2, ',', '.');
          ?></span>
        </div>
      </form>

      <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wpfp_reservar_cotas']) && $reserva_erro): ?>
        <div class="wpfp-msg error"><?= wp_kses_post($reserva_erro) ?></div>
      <?php endif; ?>

      <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wpfp_reservar_cotas']) && $reserva_ok): ?>
        <div class="wpfp-msg success">
          <p><?= wp_kses_post($reserva_msg) ?></p>
          <p><strong>Valor total:</strong> <?= esc_html($valor_total_reserva_fmt) ?></p>

          <?php if ($wpfp_emv_payload): ?>
            <div id="wpfp_qrcode" class="mt-2"></div>
            <p class="mt-2">
              <strong>PIX copia e cola:</strong><br>
              <textarea id="wpfp_emv_text" readonly style="width:100%;min-height:90px"><?= esc_html($wpfp_emv_payload) ?></textarea>
              <button type="button" class="btn btn-secondary btn-sm" id="wpfp_copy_btn" style="margin-top:6px;">Copiar</button>
              <span id="wpfp_copy_done" class="text-success" style="display:none;margin-left:8px;">Copiado! ✔️</span>
            </p>
            <small class="text-muted">Escaneie o QR ou use o “copia e cola”.</small>
          <?php else: ?>
            <p class="text-muted">Configurações de PIX não definidas. Cadastre a chave/nome/cidade no admin.</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- BOTÕES -->
    <div class="d-flex justify-content-between mt-3">
      <button class="btn btn-secondary" onclick="limparPalpites()">Limpar Palpites</button>
      <button class="btn btn-success" id="btnSalvarTudo" onclick="salvarPalpitesEReservar()" <?= $cotas_disponiveis <= 0 ? 'disabled title="Sem cotas disponíveis no momento"' : '' ?>>Salvar Palpites e Reservar Cotas</button>
    </div>

  </div>

  <section class="info-palpites">
    <h2 class="sub-title">Palpites para a Rodada</h2>
    <p>Você pode palpitar nos jogos acima. Lembre-se: prazo até 📅 <?= $data_limite ? date('d/m/Y H:i', strtotime($data_limite)) : 'Não definido' ?>.</p>
  </section>

  <script>
    function limparPalpites() {
      document.querySelectorAll('.palpite-input').forEach(input => input.value = '');
    }

    function salvarPalpitesEReservar() {
      const btn = document.getElementById('btnSalvarTudo');
      if (!btn) return;

      // bloqueia se não há disponibilidade
      const disp = parseInt(document.getElementById('wpfp_disp')?.textContent || '0', 10);
      const qtdEl = document.getElementById('qtd_cotas');
      const qtd = parseInt(qtdEl?.value || '0', 10);
      if (isNaN(disp) || disp <= 0) {
        alert('Não há cotas disponíveis no momento.');
        return;
      }
      if (!qtdEl || !qtd || qtd <= 0) {
        alert('Informe a quantidade de cotas antes de reservar.');
        return;
      }
      if (qtd > disp) {
        alert('A quantidade desejada excede as cotas disponíveis.');
        return;
      }

      btn.disabled = true; btn.textContent = 'Salvando...';

      // 1) Salvar palpites via AJAX
      const palpites = {};
      document.querySelectorAll('.palpite-input').forEach(input => {
        const match = input.name.match(/\[(\d+)\]\[(home|away)\]/);
        if (match) {
          const fixture_id = match[1];
          const tipo = match[2];
          if (!palpites[fixture_id]) palpites[fixture_id] = {};
          palpites[fixture_id][tipo] = input.value;
        }
      });

      const formData = new FormData();
      formData.append('action', 'wpfp_salvar_palpites');
      formData.append('pool_id', '<?= $pool_id ?>');
      formData.append('palpites', JSON.stringify(palpites));

      fetch('<?= admin_url('admin-ajax.php') ?>', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
          // 2) Após salvar palpites, envia o form de reserva (POST clássico)
          document.getElementById('wpfp_form_reserva').submit();
        })
        .catch(err => {
          console.error(err);
          alert('Erro ao salvar palpites. Tente novamente.');
          btn.disabled = false; btn.textContent = 'Salvar Palpites e Reservar Cotas';
        });
    }
  </script>

  <!-- QR EMV PIX (pagável) -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" defer></script>
  <script>
    window.addEventListener('load', function(){
      const el = document.getElementById('wpfp_qrcode');
      if (!el) return;
      const payload = <?= json_encode($wpfp_emv_payload ?: ''); ?>;
      if (!payload) return;
      try { new QRCode(el, { text: payload, width: 220, height: 220 }); }
      catch(e){ console.error('QR fail', e); }
    });

    // Atualiza total & botão copiar
    (function(){
      const valorCota = <?= json_encode((float)$valor_cota_reserva); ?>;
      const totalEl = document.getElementById('wpfp_total_js');
      const inputQtd = document.getElementById('qtd_cotas');
      function formatBR(n){ return 'R$ ' + n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
      function updateTotal(){
        if (!inputQtd || !totalEl) return;
        const qtd = Math.max(0, parseInt(inputQtd.value || '0', 10));
        const total = (qtd > 0 ? qtd : 0) * valorCota;
        totalEl.textContent = formatBR(total);
      }
      document.addEventListener('input', e => { if (e.target && e.target.id === 'qtd_cotas') updateTotal(); });
      document.addEventListener('DOMContentLoaded', updateTotal);

      // Copiar payload
      document.addEventListener('click', function(e){
        if (e.target && e.target.id === 'wpfp_copy_btn') {
          const ta = document.getElementById('wpfp_emv_text');
          if (!ta) return;
          const text = ta.value;
          if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(()=>{
              document.getElementById('wpfp_copy_done').style.display='inline';
              setTimeout(()=>{ document.getElementById('wpfp_copy_done').style.display='none'; }, 1500);
            });
          } else {
            ta.select(); ta.setSelectionRange(0, 99999);
            try {
              document.execCommand('copy');
              document.getElementById('wpfp_copy_done').style.display='inline';
              setTimeout(()=>{ document.getElementById('wpfp_copy_done').style.display='none'; }, 1500);
            } catch(e){}
          }
        }
      });
    })();
  </script>

  <?php wp_footer(); ?>
</body>
</html>
