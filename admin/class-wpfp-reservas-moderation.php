<?php
if (!defined('ABSPATH')) exit;

class WPFPPoolReservasModeration {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_post_wpfp_aprovar_reserva', [__CLASS__, 'aprovar_reserva']);
        add_action('admin_post_wpfp_rejeitar_reserva', [__CLASS__, 'rejeitar_reserva']);
        add_action('admin_post_wpfp_aprovar_enviar_recibo', [__CLASS__, 'aprovar_enviar_recibo']);
        add_action('admin_post_wpfp_export_csv', [__CLASS__, 'export_csv']);
    }

    public static function menu() {
        add_menu_page('Reservas de Cotas','Reservas de Cotas','manage_options','wpfp-reservas',[__CLASS__,'render_page'],'dashicons-groups',57);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) return;

        global $wpdb;
        $table = $wpdb->prefix . 'pool_apostas';

        $status_ui = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'pending';
        $valid_ui  = ['pending','approved','rejected','todos'];
        if (!in_array($status_ui, $valid_ui, true)) $status_ui = 'pending';

        $pool_filter = isset($_GET['pool_id']) ? intval($_GET['pool_id']) : 0;

        $where = "1=1";
        if ($status_ui !== 'todos') {
            $in = wpfp_status_in_clause([$status_ui]); // mapeia para variantes do DB
            $where .= " AND r.status IN {$in}";
        }
        if ($pool_filter > 0) {
            $where .= $wpdb->prepare(" AND r.pool_id = %d", $pool_filter);
        }

        $sql = "
            SELECT r.*, u.user_email, u.display_name, p.post_title
              FROM {$table} r
              LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
              LEFT JOIN {$wpdb->posts} p ON p.ID = r.pool_id
             WHERE {$where}
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT 500
        ";
        $rows = $wpdb->get_results($sql);

        $nonce_ap  = wp_create_nonce('wpfp_aprovar_reserva');
        $nonce_rj  = wp_create_nonce('wpfp_rejeitar_reserva');
        $nonce_apr = wp_create_nonce('wpfp_aprovar_enviar_recibo');
        $nonce_csv = wp_create_nonce('wpfp_export_csv');

        $pools = get_posts(['post_type'=>'wpfp_pool','posts_per_page'=>500,'post_status'=>'publish','orderby'=>'title','order'=>'ASC','fields'=>'ids']);
        ?>
        <div class="wrap">
            <h1>Moderação de Reservas de Cotas</h1>

            <form method="get" action="">
                <input type="hidden" name="page" value="wpfp-reservas">
                <label>Status:
                    <select name="status">
                        <option value="pending"   <?= selected($status_ui,'pending',false); ?>>Pendentes</option>
                        <option value="approved"  <?= selected($status_ui,'approved',false); ?>>Aprovadas</option>
                        <option value="rejected"  <?= selected($status_ui,'rejected',false); ?>>Rejeitadas</option>
                        <option value="todos"     <?= selected($status_ui,'todos',false); ?>>Todas</option>
                    </select>
                </label>
                <label style="margin-left:12px;">Pool:
                    <select name="pool_id">
                        <option value="0">Todos</option>
                        <?php foreach ($pools as $pid): ?>
                            <option value="<?= esc_attr($pid); ?>" <?= selected($pool_filter,$pid,false); ?>>
                                <?= esc_html(get_the_title($pid)); ?> (ID: <?= $pid; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="button">Filtrar</button>
                &nbsp;&nbsp;
                <form method="post" action="<?= esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                    <input type="hidden" name="action" value="wpfp_export_csv">
                    <input type="hidden" name="_wpnonce" value="<?= esc_attr($nonce_csv); ?>">
                    <input type="hidden" name="status" value="<?= esc_attr($status_ui); ?>">
                    <input type="hidden" name="pool_id" value="<?= esc_attr($pool_filter); ?>">
                    <button class="button button-secondary">Exportar CSV</button>
                </form>
            </form>

            <table class="widefat fixed striped" style="margin-top:14px;">
                <thead>
                    <tr>
                        <th>ID (Número da cota)</th>
                        <th>TXID</th>
                        <th>Usuário</th>
                        <th>Pool</th>
                        <th>Qtd</th>
                        <th>Valor unit.</th>
                        <th>Total</th>
                        <th>Faixa de cotas</th>
                        <th>Status</th>
                        <th>Criado em</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="11">Nenhuma reserva encontrada.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r):
                        $valor_unit = floatval(get_post_meta($r->pool_id, '_wpfp_pontos_por_cota', true) ?: 0);
                        $total = $valor_unit * intval($r->qtd_cotas);
                        $txid = self::compute_txid($r);
                        $range = self::get_cota_range($r->id);
                        $range_str = ($range ? ($range['start'].'–'.$range['end']) : '—');

                        $canon = wpfp_status_canonical($r->status);
                        $badge_color = ($canon === 'pending') ? '#f0ad4e' : (($canon === 'approved') ? '#5cb85c' : '#d9534f');
                        $badge_text  = ($canon === 'pending') ? 'pending' : (($canon === 'approved') ? 'aprovado' : 'rejeitado');
                        ?>
                        <tr>
                            <td>#<?= intval($r->id); ?></td>
                            <td><?= esc_html($txid); ?></td>
                            <td>
                                <?= esc_html($r->display_name ?: 'Usuário #'.$r->user_id); ?><br>
                                <small><?= esc_html($r->user_email); ?></small>
                            </td>
                            <td><?= esc_html($r->post_title ?: 'Pool #'.$r->pool_id); ?> (<?= intval($r->pool_id); ?>)</td>
                            <td><?= intval($r->qtd_cotas); ?></td>
                            <td><?= 'R$ ' . number_format($valor_unit, 2, ',', '.'); ?></td>
                            <td><strong><?= 'R$ ' . number_format($total, 2, ',', '.'); ?></strong></td>
                            <td><?= esc_html($range_str); ?></td>
                            <td><span style="background:<?= $badge_color ?>;color:#fff;padding:2px 6px;border-radius:4px;"><?= esc_html($badge_text) ?></span></td>
                            <td><?= esc_html( date_i18n('d/m/Y H:i', strtotime($r->created_at)) ); ?></td>
                            <td>
                                <?php if ($canon === 'pending'): ?>
                                    <form method="post" action="<?= esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:6px;">
                                        <input type="hidden" name="action" value="wpfp_aprovar_reserva">
                                        <input type="hidden" name="reserva_id" value="<?= intval($r->id); ?>">
                                        <input type="hidden" name="_wpnonce" value="<?= esc_attr($nonce_ap); ?>">
                                        <button class="button button-primary" type="submit">Aprovar</button>
                                    </form>
                                    <form method="post" action="<?= esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:6px;">
                                        <input type="hidden" name="action" value="wpfp_aprovar_enviar_recibo">
                                        <input type="hidden" name="reserva_id" value="<?= intval($r->id); ?>">
                                        <input type="hidden" name="_wpnonce" value="<?= esc_attr($nonce_apr); ?>">
                                        <button class="button button-primary" type="submit">Aprovar &amp; Enviar recibo</button>
                                    </form>
                                    <form method="post" action="<?= esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                                        <input type="hidden" name="action" value="wpfp_rejeitar_reserva">
                                        <input type="hidden" name="reserva_id" value="<?= intval($r->id); ?>">
                                        <input type="hidden" name="_wpnonce" value="<?= esc_attr($nonce_rj); ?>">
                                        <button class="button" type="submit">Rejeitar</button>
                                    </form>
                                <?php else: ?>
                                    <em>—</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function aprovar_reserva() {
        self::guard('wpfp_aprovar_reserva');
        $r = self::get_reserva_row();
        self::set_status($r->id, wpfp_status_db('approved'));
        self::ensure_cota_range($r);
        self::enviar_email_status($r, 'approved', false);
        self::redirect_back('aprovada');
    }

    public static function rejeitar_reserva() {
        self::guard('wpfp_rejeitar_reserva');
        $r = self::get_reserva_row();
        self::set_status($r->id, wpfp_status_db('rejected'));
        self::enviar_email_status($r, 'rejected', false);
        self::redirect_back('rejeitada');
    }

    public static function aprovar_enviar_recibo() {
        self::guard('wpfp_aprovar_enviar_recibo');
        $r = self::get_reserva_row();
        self::set_status($r->id, wpfp_status_db('approved'));
        self::ensure_cota_range($r);
        self::enviar_email_status($r, 'approved', true);
        self::redirect_back('aprovada_recibo');
    }

    public static function export_csv() {
        if (!current_user_can('manage_options')) wp_die('Sem permissão.');
        check_admin_referer('wpfp_export_csv');

        global $wpdb;
        $table = $wpdb->prefix . 'pool_apostas';

        $status_ui = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'todos';
        $pool_filter = isset($_POST['pool_id']) ? intval($_POST['pool_id']) : 0;
        $valid = ['pending','approved','rejected','todos'];
        if (!in_array($status_ui, $valid, true)) $status_ui = 'todos';

        $where = "1=1";
        if ($status_ui !== 'todos') {
            $where .= " AND r.status IN " . wpfp_status_in_clause([$status_ui]);
        }
        if ($pool_filter > 0) {
            $where .= $wpdb->prepare(" AND r.pool_id = %d", $pool_filter);
        }

        $sql = "
            SELECT r.*, u.user_email, u.display_name, p.post_title
              FROM {$table} r
              LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
              LEFT JOIN {$wpdb->posts} p ON p.ID = r.pool_id
             WHERE {$where}
             ORDER BY r.pool_id ASC, r.id ASC
             LIMIT 5000
        ";
        $rows = $wpdb->get_results($sql);

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="reservas-'.date('Ymd-His').'.csv"');
        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'id','txid','user_id','user_email','display_name',
            'pool_id','pool_title','qtd_cotas','valor_unit','total','status','status_canonical','created_at',
            'cota_start','cota_end'
        ], ';');

        foreach ($rows as $r) {
            $valor_unit = floatval(get_post_meta($r->pool_id, '_wpfp_pontos_por_cota', true) ?: 0);
            $total = $valor_unit * intval($r->qtd_cotas);
            $txid = self::compute_txid($r);
            $canon = wpfp_status_canonical($r->status);
            $range = self::get_cota_range($r->id);
            $start = $range ? $range['start'] : '';
            $end   = $range ? $range['end']   : '';

            fputcsv($out, [
                $r->id,
                $txid,
                $r->user_id,
                $r->user_email,
                $r->display_name,
                $r->pool_id,
                ($r->post_title ?: ''),
                $r->qtd_cotas,
                number_format($valor_unit, 2, ',', '.'),
                number_format($total, 2, ',', '.'),
                $r->status,
                $canon,
                $r->created_at,
                $start,
                $end,
            ], ';');
        }
        fclose($out);
        exit;
    }

    /* Helpers (mantidos, com pequenas adaptações) */

    private static function guard($nonce_action) {
        if (!current_user_can('manage_options')) wp_die('Sem permissão.');
        check_admin_referer($nonce_action);
    }

    private static function get_reserva_row() {
        global $wpdb;
        $table = $wpdb->prefix . 'pool_apostas';
        $reserva_id = isset($_POST['reserva_id']) ? intval($_POST['reserva_id']) : 0;
        if (!$reserva_id) wp_die('ID inválido.');
        $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $reserva_id));
        if (!$r) wp_die('Reserva não encontrada.');
        return $r;
    }

    private static function set_status($id, $status_db_value) {
        global $wpdb;
        $table = $wpdb->prefix . 'pool_apostas';
        $wpdb->update($table, ['status' => $status_db_value], ['id' => $id], ['%s'], ['%d']);
    }

    private static function redirect_back($msg) {
        $url = add_query_arg(['page'=>'wpfp-reservas','status'=>'pending','msg'=>$msg], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    private static function compute_txid($row) {
        $base = get_option('wpfp_pix_txid_pf', '');
        if ($base !== '') return substr(preg_replace('/[^A-Za-z0-9\-]/', '', $base), 0, 35);
        $raw = 'RES-' . intval($row->pool_id) . '-' . intval($row->id);
        return substr(preg_replace('/[^A-Za-z0-9\-]/', '', $raw), 0, 35);
    }

    private static function get_cota_range($reserva_id) {
        $opt = get_option('wpfp_cota_range_' . intval($reserva_id), []);
        if (is_array($opt) && isset($opt['start'], $opt['end'])) return $opt;
        return [];
    }

    private static function ensure_cota_range($row) {
        $exists = self::get_cota_range($row->id);
        if ($exists) return $exists;

        global $wpdb;
        $table = $wpdb->prefix . 'pool_apostas';
        $qtd = intval($row->qtd_cotas);

        // soma de cotas APROVADAS (todas variantes)
        $in = wpfp_status_in_clause(['approved']);
        $sum_prev = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(qtd_cotas),0)
               FROM {$table}
              WHERE pool_id = %d
                AND status IN {$in}
                AND id < %d",
            $row->pool_id, $row->id
        ));
        $start = $sum_prev + 1;
        $end   = $start + $qtd - 1;

        $data = ['start' => $start, 'end' => $end];
        add_option('wpfp_cota_range_' . intval($row->id), $data, '', false);
        return $data;
    }

    protected static function enviar_email_status($reserva_row, $novo_status_canon, $anexar_recibo = false) {
        $user = get_user_by('id', $reserva_row->user_id);
        if (!$user) return;

        $pool = get_post($reserva_row->pool_id);
        $pool_title = $pool ? $pool->post_title : ('Pool #'.$reserva_row->pool_id);

        $valor_unit = floatval(get_post_meta($reserva_row->pool_id, '_wpfp_pontos_por_cota', true) ?: 0);
        $qtd        = intval($reserva_row->qtd_cotas);
        $total      = $valor_unit * $qtd;

        $data_hora  = date_i18n('d/m/Y H:i', current_time('timestamp'));
        $status_label = ($novo_status_canon === 'approved') ? 'APROVADA' : (($novo_status_canon === 'rejected') ? 'REJEITADA' : 'PENDENTE');
        $txid = self::compute_txid($reserva_row);

        $range = ($novo_status_canon === 'approved') ? self::ensure_cota_range($reserva_row) : [];
        $range_str = ($range ? ($range['start'].'–'.$range['end']) : '—');

        $assunto = sprintf('Sua reserva de cotas foi %s', $status_label);

        $chave  = get_option('wpfp_pix_key_pf', '');
        $nome   = get_option('wpfp_pix_nome_pf', '');
        $cidade = get_option('wpfp_pix_cidade_pf', '');

        $emv_payload = '';
        if ($novo_status_canon === 'approved' && $chave && $total > 0) {
            if (!class_exists('WPFPPixEMV')) {
                require_once plugin_dir_path(__FILE__) . '../includes/class-wpfp-pix-emv.php';
            }
            $emv_payload = WPFPPixEMV::buildPayload($chave, (float)$total, $nome ?: 'PESSOA FISICA', $cidade ?: 'CIDADE', $txid);
        }

        ob_start(); ?>
        <p>Olá, <?= esc_html($user->display_name); ?>,</p>
        <p>Sua reserva de cotas foi <strong><?= esc_html($status_label); ?></strong>.</p>
        <p><strong>Dados da reserva</strong></p>
        <ul>
            <li><strong>Número da cota (ID da reserva):</strong> #<?= intval($reserva_row->id); ?></li>
            <li><strong>TXID:</strong> <?= esc_html($txid); ?></li>
            <li><strong>Pool:</strong> <?= esc_html($pool_title); ?> (ID <?= intval($reserva_row->pool_id); ?>)</li>
            <li><strong>Faixa de cotas:</strong> <?= esc_html($range_str); ?></li>
            <li><strong>Quantidade de cotas:</strong> <?= intval($qtd); ?></li>
            <li><strong>Valor unitário:</strong> R$ <?= number_format($valor_unit, 2, ',', '.'); ?></li>
            <li><strong>Total:</strong> R$ <?= number_format($total, 2, ',', '.'); ?></li>
            <li><strong>Data/Hora:</strong> <?= esc_html($data_hora); ?></li>
        </ul>
        <?php if ($novo_status_canon === 'approved' && $chave): ?>
            <p><strong>Pagamento PIX</strong><br>
                Chave: <?= esc_html($chave); ?><br>
                <?php if ($nome): ?>Nome: <?= esc_html($nome); ?><br><?php endif; ?>
                <?php if ($cidade): ?>Cidade: <?= esc_html($cidade); ?><br><?php endif; ?>
                Valor: R$ <?= number_format($total,2,',','.'); ?></p>
            <?php if ($emv_payload): ?>
                <p><strong>PIX copia e cola:</strong><br>
                    <textarea style="width:100%;min-height:90px" readonly><?= esc_html($emv_payload); ?></textarea>
                </p>
            <?php endif; ?>
        <?php else: ?>
            <p>Motivo: a reserva foi rejeitada pelo administrador. Se necessário, entre em contato para mais detalhes.</p>
        <?php endif; ?>
        <?php
        $html = ob_get_clean();

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $attachments = [];

        if ($anexar_recibo && $novo_status_canon === 'approved') {
            $file = self::gerar_recibo_html($reserva_row, $pool_title, $txid, $valor_unit, $qtd, $total, $data_hora, $range_str);
            if ($file && file_exists($file)) { $attachments[] = $file; }
        }

        wp_mail($user->user_email, $assunto, $html, $headers, $attachments);

        if (!empty($attachments)) {
            foreach ($attachments as $path) { if ($path && file_exists($path)) @unlink($path); }
        }
    }

    private static function gerar_recibo_html($r, $pool_title, $txid, $valor_unit, $qtd, $total, $data_hora, $range_str) {
        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) return '';
        $dir  = trailingslashit($upload_dir['basedir']) . 'wpfp-receipts/';
        if (!is_dir($dir)) wp_mkdir_p($dir);
        $filename = sprintf('recibo-reserva-%d-%s.html', intval($r->id), preg_replace('/[^A-Za-z0-9\-]/', '', $txid));
        $path = $dir . $filename;

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Recibo de Reserva</title>';
        $html.= '<style>body{font-family:Arial,Helvetica,sans-serif} .row{margin:6px 0} .muted{color:#555}</style>';
        $html.= '</head><body>';
        $html.= '<h2>Recibo de Reserva de Cotas</h2>';
        $html.= '<div class="row"><strong>Reserva:</strong> #'.intval($r->id).'</div>';
        $html.= '<div class="row"><strong>TXID:</strong> '.esc_html($txid).'</div>';
        $html.= '<div class="row"><strong>Pool:</strong> '.esc_html($pool_title).'</div>';
        $html.= '<div class="row"><strong>Faixa de cotas:</strong> '.esc_html($range_str).'</div>';
        $html.= '<div class="row"><strong>Quantidade de cotas:</strong> '.intval($qtd).'</div>';
        $html.= '<div class="row"><strong>Valor unitário:</strong> R$ '.number_format($valor_unit, 2, ',', '.').'</div>';
        $html.= '<div class="row"><strong>Total:</strong> R$ '.number_format($total, 2, ',', '.').'</div>';
        $html.= '<div class="row"><strong>Data/Hora:</strong> '.esc_html($data_hora).'</div>';
        $html.= '<hr><div class="muted">Documento gerado automaticamente pelo sistema.</div>';
        $html.= '</body></html>';

        file_put_contents($path, $html);
        return $path;
    }
}

WPFPPoolReservasModeration::init();
