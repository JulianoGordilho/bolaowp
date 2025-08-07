<?php
/**
 * Template Name: Página de Palpites
 */

if (!is_user_logged_in()) {
    wp_redirect(wp_login_url());
    exit;
}

global $wpdb;

$pool_id = intval(get_query_var('pool_id'));
$user_id = get_current_user_id();

// Validação do pool
$pool_post = get_post($pool_id);
if (!$pool_post || $pool_post->post_type !== 'wpfp_pool') {
    echo '<div class="alert alert-danger">❌ Pool não encontrado.</div>';
    return;
}

// Metadados do pool
$titulo = $pool_post->post_title;
$liga = get_post_meta($pool_id, '_wpfp_liga', true);
$pais = get_post_meta($pool_id, '_wpfp_pais', true);
$data_limite = get_post_meta($pool_id, '_wpfp_data_limite_cache', true) ?: date('Y-m-d H:i:s');

$games = get_post_meta($pool_id, '_wpfp_selected_games', true);
$cancelados = get_post_meta($pool_id, '_wpfp_jogos_cancelados', true) ?: [];

if (!is_array($games) || empty($games)) {
    echo '<div class="alert alert-warning">⚠️ Nenhum jogo foi selecionado para este pool.</div>';
    return;
}


////////////Cálculo e exibição dos prêmios

$valor_cota = floatval(get_post_meta($pool_id, '_wpfp_valor_cota', true));
$qtd_cotas = $wpdb->get_var($wpdb->prepare("
    SELECT SUM(qtd_cotas) FROM {$wpdb->prefix}pool_apostas
    WHERE pool_id = %d AND status = 'aprovado'
", $pool_id)) ?: 0;

$total_arrecadado = $valor_cota * $qtd_cotas;

$premio_primeiro = $total_arrecadado * 0.70;
$premio_segundo  = $total_arrecadado * 0.30;

// 🧠 Super prêmio acumulado = 3% do total + valor acumulado (placeholder)
$premio_extra_acumulado = get_post_meta($pool_id, '_wpfp_valor_acumulado', true) ?: 0;
$super_premio = ($total_arrecadado * 0.03) + floatval($premio_extra_acumulado);



/////////////////


?>




<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Palpitar Jogos</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?php wp_head(); ?>
</head>
<body>

  <div class="header">
    <h4>Palpite da Rodada - <?= esc_html($titulo) ?></h4>
    <small>Liga: <?= esc_html($liga) ?> | País: <?= esc_html($pais) ?> | Limite: <?= $data_limite ? date('d/m/Y H:i', strtotime($data_limite)) : 'Não definido' ?></small>
 
    <small>
      Id: <?= esc_html($pool_id) ?> |
      Liga: <?= esc_html($liga) ?> |
      Data limite da aposta: <?= $data_limite ? date('d/m/Y H:i', strtotime($data_limite)) : 'Não definido' ?>
    </small>
  </div>

  <div class="container"> 

     <div class="premiacao-box">
      <h5 class="text-white">🏆 Premiação</h5>
      <ul class="list-unstyled text-white">
        <li>💸 Valor da Aposta: R$ <?= number_format($valor_cota, 2, ',', '.') ?></li>
        <li>🥇 1º Prêmio (70%): R$ <?= number_format($premio_primeiro, 2, ',', '.') ?></li>
        <li>🥈 2º Prêmio (30%): R$ <?= number_format($premio_segundo, 2, ',', '.') ?></li>
        <li>💰 Super prêmio acumulado (3% + acumulado): R$ <?= number_format($super_premio, 2, ',', '.') ?></li>
      </ul>
    </div>




   
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

              if ($encerrado && !WPFPOOL_USAR_MOCK_EM_FALHA_API) {
                  echo "<td colspan='2'>" . esc_html($palpite->palpite_home ?? '-') . " x " . esc_html($palpite->palpite_away ?? '-') . "</td>";
              } else {
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


              }

              echo "<td class='team-cell'><img src='{$logo_away}' alt=''><span>{$away}</span></td>";
              echo "<td>" . esc_html($fx['league']['name'] ?? '-') . "</td>";
              echo "</tr>";

              $index++;
          }
          ?>
        </tbody>
      </table>
    </div>

    <div class="d-flex justify-content-between mt-3">
      <button class="btn btn-secondary" onclick="limparPalpites()">Limpar Palpites</button>
      <button class="btn btn-success" onclick="salvarPalpites()">Salvar Palpites</button>
    </div>
  </div>

      <secttion class="info-palpites">
      <h2 class="sub-title">Palpites para a Rodada</h2>
      <p>Você pode palpitar nos jogos abaixo. Lembre-se de que os palpites devem ser feitos até 📅 Data limite para palpites: <?= $data_limite ? date('d/m/Y H:i', strtotime($data_limite)) : 'Não definido' ?>.</p>
      <p>Para cada jogo, insira o número de gols que você acha que o time mandante e o time visitante irão marcar.</p>
      <p>Após inserir seus palpites, clique em "Salvar Palpites" para registrar suas escolhas.</p>
      <p class="premiacao">💰 Valor da cota: <?= get_post_meta($pool_id, '_wpfp_valor_cota', true) ?: 'R$ 0,00' ?></p>
    </secttion>

  <script>
    function limparPalpites() {
      document.querySelectorAll('.palpite-input').forEach(input => input.value = '');
    }

    function salvarPalpites() {
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

      fetch('<?= admin_url('admin-ajax.php') ?>', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        alert(data.message || '✅ Palpites salvos com sucesso!');
      })
      .catch(err => {
        console.error(err);
        alert('Erro ao salvar palpites.');
      });
    }
  </script>
</body>
</html>
