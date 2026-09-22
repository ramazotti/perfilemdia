<?php

declare(strict_types=1);

namespace PerfilEmDia\Site;

use PerfilEmDia\Billing\CouponRepository;
use PDO;
use PDOException;

final class CouponAdmin
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function html(): string
    {
        $repo = new CouponRepository($this->pdo);
        $error = '';
        $editId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && Layout::checkCsrf()) {
            $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
            $percent = (int) ($_POST['percent'] ?? 0);
            $qty = trim((string) ($_POST['max_uses'] ?? ''));
            $maxUses = $qty === '' ? null : max(0, (int) $qty);
            $date = trim((string) ($_POST['valid_until'] ?? ''));
            $until = $date === '' ? null : $date . ' 23:59:59';
            $id = (int) ($_POST['id'] ?? 0);
            if (!preg_match('/^[A-Z0-9]{3,20}$/', $code)) {
                $error = 'O código usa de 3 a 20 letras ou números.';
            } else {
                try {
                    $repo->save(
                        $id > 0 ? $id : null,
                        $code,
                        $percent,
                        $maxUses,
                        $until,
                        isset($_POST['monthly_only']),
                        isset($_POST['active']),
                    );
                    header('Location: ' . Layout::url('admin/cupons'));
                    exit;
                } catch (PDOException) {
                    $error = 'Já existe um cupom com esse código.';
                }
            }
        }

        $editing = $editId > 0 ? $repo->find($editId) : null;
        $rows = '';
        foreach ($repo->all() as $coupon) {
            $limit = $coupon['max_uses'] === null ? 'sem limite' : ((int) $coupon['used_count'] . ' de ' . (int) $coupon['max_uses']);
            $until = $coupon['valid_until'] === null ? 'sem validade' : substr((string) $coupon['valid_until'], 0, 10);
            $rows .= '<tr><td>' . Layout::e((string) $coupon['code']) . '</td>'
                . '<td>' . (int) $coupon['percent'] . '%</td>'
                . '<td>' . Layout::e($limit) . '</td>'
                . '<td>' . Layout::e($until) . '</td>'
                . '<td>' . ((int) $coupon['monthly_only'] ? 'mensal' : 'mensal e anual') . '</td>'
                . '<td>' . ((int) $coupon['active'] ? 'ativo' : 'inativo') . '</td>'
                . '<td><a href="' . Layout::e(Layout::url('admin/cupons?id=' . $coupon['id'])) . '">Editar</a></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="7" class="empty">Nenhum cupom.</td></tr>';
        }

        $code = (string) ($editing['code'] ?? '');
        $percent = (string) ($editing['percent'] ?? '100');
        $max = $editing !== null && $editing['max_uses'] !== null ? (string) $editing['max_uses'] : '';
        $date = $editing !== null && $editing['valid_until'] !== null ? substr((string) $editing['valid_until'], 0, 10) : '';
        $monthly = $editing === null || (int) $editing['monthly_only'] === 1;
        $active = $editing === null || (int) $editing['active'] === 1;

        $html = '<div class="tbl-wrap"><table><thead><tr><th>Código</th><th>Desconto</th><th>Usos</th><th>Validade</th><th>Onde vale</th><th>Situação</th><th></th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>';
        $html .= '<form method="post" class="box" style="max-width:640px;margin-top:16px">';
        $html .= '<input type="hidden" name="csrf" value="' . Layout::e(Layout::csrf()) . '">';
        $html .= '<input type="hidden" name="id" value="' . (int) ($editing['id'] ?? 0) . '">';
        $html .= '<h2 style="font-size:22px">' . ($editing ? 'Editar cupom' : 'Novo cupom') . '</h2>';
        if ($error !== '') {
            $html .= '<p class="notice">' . Layout::e($error) . '</p>';
        }
        $html .= $this->input('code', 'Código', $code);
        $html .= $this->input('percent', 'Desconto (%)', $percent);
        $html .= $this->input('max_uses', 'Quantidade de usos', $max, 'Vazio significa sem limite. Cada checkout que aplica o cupom conta um uso.');
        $html .= '<div class="field"><label for="valid_until">Validade</label><input class="in" id="valid_until" name="valid_until" type="date" value="' . Layout::e($date) . '"><span class="hint">Vazio significa que não vence.</span></div>';
        $html .= '<label class="check"><input type="checkbox" name="monthly_only"' . ($monthly ? ' checked' : '') . '> Vale só na mensalidade</label>';
        $html .= '<label class="check"><input type="checkbox" name="active"' . ($active ? ' checked' : '') . '> Ativo</label>';
        $html .= '<button class="btn btn-primary" type="submit">Salvar cupom</button></form>';

        return $html;
    }

    private function input(string $name, string $label, string $value, string $hint = ''): string
    {
        return '<div class="field"><label for="' . Layout::e($name) . '">' . Layout::e($label) . '</label>'
            . '<input class="in" id="' . Layout::e($name) . '" name="' . Layout::e($name) . '" value="' . Layout::e($value) . '">'
            . ($hint !== '' ? '<span class="hint">' . Layout::e($hint) . '</span>' : '')
            . '</div>';
    }
}
