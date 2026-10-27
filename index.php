<?php
declare(strict_types=1);
session_start();

$dbFile = __DIR__ . '/despesas.sqlite';
try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("CREATE TABLE IF NOT EXISTS despesas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        descricao TEXT NOT NULL,
        categoria TEXT NOT NULL,
        valor INTEGER NOT NULL,
        data_despesa TEXT NOT NULL,
        criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS salarios (
        mes TEXT PRIMARY KEY,
        valor INTEGER NOT NULL DEFAULT 0,
        atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {
    http_response_code(500);
    exit('Não foi possível iniciar o banco de dados. Verifique se o PHP tem PDO_SQLite habilitado e se a pasta permite gravação.');
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function dinheiro(int $c): string { return 'R$ ' . number_format($c / 100, 2, ',', '.'); }
function validarMes(string $m): bool { return (bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m); }
function voltar(string $pagina, string $mes): never { header('Location: ?pagina=' . urlencode($pagina) . '&mes=' . urlencode($mes)); exit; }
$categorias = ['Alimentação', 'Transporte', 'Moradia', 'Saúde', 'Educação', 'Lazer', 'Contas', 'Outros'];
$pagina = (string)($_GET['pagina'] ?? 'dashboard');
$paginasValidas = ['dashboard', 'despesas', 'relatorios', 'salario'];
if (!in_array($pagina, $paginasValidas, true)) $pagina = 'dashboard';
$mesAtual = date('Y-m');
$mes = (string)($_GET['mes'] ?? $mesAtual);
if (!validarMes($mes)) $mes = $mesAtual;
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    $acao = (string)($_POST['acao'] ?? '');
    $mesPost = (string)($_POST['mes'] ?? $mesAtual);
    if (!validarMes($mesPost)) $mesPost = $mesAtual;
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $erro = 'Sua sessão expirou. Atualize a página e tente novamente.';
    } elseif ($acao === 'adicionar') {
        $descricao = trim((string)($_POST['descricao'] ?? ''));
        $categoria = (string)($_POST['categoria'] ?? 'Outros');
        $valorTexto = str_replace(',', '.', trim((string)($_POST['valor'] ?? '')));
        $data = (string)($_POST['data_despesa'] ?? date('Y-m-d'));
        $valorFloat = is_numeric($valorTexto) ? (float)$valorTexto : 0;
        $dataValida = DateTime::createFromFormat('!Y-m-d', $data);
        if ($descricao === '' || strlen($descricao) > 360) $erro = 'Informe uma descrição com até 120 caracteres.';
        elseif (!in_array($categoria, $categorias, true)) $erro = 'Selecione uma categoria válida.';
        elseif ($valorFloat <= 0 || $valorFloat > 99999999) $erro = 'Informe um valor maior que zero.';
        elseif (!$dataValida || $dataValida->format('Y-m-d') !== $data) $erro = 'Informe uma data válida.';
        else {
            $stmt = $pdo->prepare('INSERT INTO despesas (descricao, categoria, valor, data_despesa) VALUES (:descricao, :categoria, :valor, :data)');
            $stmt->execute([':descricao'=>$descricao, ':categoria'=>$categoria, ':valor'=>(int)round($valorFloat * 100), ':data'=>$data]);
            voltar('despesas', substr($data, 0, 7));
        }
        $pagina = 'despesas'; $mes = $mesPost;
    } elseif ($acao === 'excluir') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) { $stmt = $pdo->prepare('DELETE FROM despesas WHERE id = :id'); $stmt->execute([':id'=>$id]); }
        voltar('despesas', $mesPost);
    } elseif ($acao === 'salvar_salario') {
        $valorTexto = str_replace(',', '.', trim((string)($_POST['salario'] ?? '')));
        $valorFloat = is_numeric($valorTexto) ? (float)$valorTexto : -1;
        if ($valorFloat < 0 || $valorFloat > 99999999) { $erro = 'Informe um salário válido (zero ou maior).'; $pagina = 'salario'; $mes = $mesPost; }
        else {
            $stmt = $pdo->prepare('INSERT INTO salarios (mes, valor, atualizado_em) VALUES (:mes, :valor, CURRENT_TIMESTAMP) ON CONFLICT(mes) DO UPDATE SET valor = excluded.valor, atualizado_em = CURRENT_TIMESTAMP');
            $stmt->execute([':mes'=>$mesPost, ':valor'=>(int)round($valorFloat * 100)]);
            voltar('salario', $mesPost);
        }
    }
}

$stmt = $pdo->prepare('SELECT COALESCE(SUM(valor),0) FROM despesas WHERE substr(data_despesa,1,7)=:mes');
$stmt->execute([':mes'=>$mes]); $totalMes = (int)$stmt->fetchColumn();
$stmt = $pdo->prepare('SELECT COALESCE(valor,0) FROM salarios WHERE mes=:mes'); $stmt->execute([':mes'=>$mes]); $salario = (int)($stmt->fetchColumn() ?: 0);
$saldo = $salario - $totalMes;
$stmt = $pdo->prepare('SELECT COUNT(*) FROM despesas WHERE substr(data_despesa,1,7)=:mes'); $stmt->execute([':mes'=>$mes]); $qtdMes = (int)$stmt->fetchColumn();
$totalGeral = (int)$pdo->query('SELECT COALESCE(SUM(valor),0) FROM despesas')->fetchColumn();
$stmt = $pdo->prepare('SELECT categoria, SUM(valor) AS total FROM despesas WHERE substr(data_despesa,1,7)=:mes GROUP BY categoria ORDER BY total DESC'); $stmt->execute([':mes'=>$mes]); $porCategoria = $stmt->fetchAll();
$maxCategoria = 0; foreach ($porCategoria as $item) $maxCategoria = max($maxCategoria, (int)$item['total']);
$stmt = $pdo->prepare('SELECT substr(data_despesa,1,7) AS mes, SUM(valor) AS total, COUNT(*) AS quantidade FROM despesas WHERE substr(data_despesa,1,7) <= :mes GROUP BY substr(data_despesa,1,7) ORDER BY mes DESC LIMIT 24'); $stmt->execute([':mes'=>$mes]); $historico = $stmt->fetchAll();
$where = ['substr(data_despesa,1,7)=:mes']; $params = [':mes'=>$mes];
$filtroCategoria = (string)($_GET['categoria'] ?? '');
if ($filtroCategoria !== '' && in_array($filtroCategoria, $categorias, true)) { $where[]='categoria=:categoria'; $params[':categoria']=$filtroCategoria; }
$stmt = $pdo->prepare('SELECT * FROM despesas WHERE ' . implode(' AND ', $where) . ' ORDER BY data_despesa DESC, id DESC LIMIT 300'); $stmt->execute($params); $despesas = $stmt->fetchAll();
$mesesNomes = ['01'=>'Janeiro','02'=>'Fevereiro','03'=>'Março','04'=>'Abril','05'=>'Maio','06'=>'Junho','07'=>'Julho','08'=>'Agosto','09'=>'Setembro','10'=>'Outubro','11'=>'Novembro','12'=>'Dezembro'];
function nomeMes(string $m, array $nomes): string { return ($nomes[substr($m,5,2)] ?? $m) . ' ' . substr($m,0,4); }
$inicioGrafico = [];
for ($i=5; $i>=0; $i--) { $key = date('Y-m', strtotime($mes . '-01 -' . $i . ' months')); $stmt=$pdo->prepare('SELECT COALESCE(SUM(valor),0) FROM despesas WHERE substr(data_despesa,1,7)=:mes'); $stmt->execute([':mes'=>$key]); $inicioGrafico[]=['mes'=>$key,'total'=>(int)$stmt->fetchColumn()]; }
$maxGrafico=0; foreach($inicioGrafico as $g) $maxGrafico=max($maxGrafico,$g['total']);
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Minhas Despesas | <?= e(ucfirst($pagina)) ?></title>
<style>
:root{--bg:#f5f7fb;--panel:#fff;--ink:#172033;--muted:#7b8498;--line:#e8ebf2;--primary:#5b5ce2;--soft:#eeeeff;--green:#16866a;--red:#c74b58;--shadow:0 8px 30px rgba(27,39,74,.05)}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;font-size:14px}.layout{display:flex;min-height:100vh}.sidebar{width:235px;background:#fff;border-right:1px solid var(--line);padding:26px 17px;flex-shrink:0}.brand{display:flex;align-items:center;gap:10px;font-size:17px;font-weight:800;margin:0 0 35px 6px}.brand-icon{width:35px;height:35px;border-radius:11px;background:var(--primary);color:white;display:grid;place-items:center;font-size:20px}.nav-label{color:#a0a7b7;text-transform:uppercase;font-size:10px;letter-spacing:1.2px;font-weight:800;padding:0 12px;margin-bottom:11px}.nav-item{display:flex;align-items:center;gap:12px;padding:12px;border-radius:10px;color:#7b8498;margin:4px 0;font-weight:650;text-decoration:none}.nav-item span:first-child{font-size:17px;width:20px;text-align:center}.nav-item:hover,.nav-item.active{color:var(--primary);background:var(--soft)}.side-note{margin:38px 5px 0;background:#f7f7ff;border:1px solid #e9e9ff;padding:14px;border-radius:13px;color:#777e9a;font-size:12px;line-height:1.6}.main{flex:1;min-width:0;padding:29px 34px 40px;max-width:1600px;margin:0 auto}.topbar{display:flex;justify-content:space-between;align-items:center;gap:18px;margin-bottom:25px}.eyebrow{color:var(--muted);font-size:11px;margin-bottom:5px;letter-spacing:.6px}.topbar h1{font-size:26px;letter-spacing:-.7px;margin:0}.month-form{display:flex;gap:8px;align-items:center}.month-form input,.field input,.field select{height:40px;border:1px solid #e2e6ef;border-radius:8px;padding:0 10px;background:#fff;color:var(--ink);font:inherit}.btn{border:0;background:var(--primary);color:#fff;font-weight:700;padding:11px 15px;border-radius:9px;cursor:pointer;font-size:12px;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.btn:hover{background:#494acb}.btn-light{background:#f0f1f8;color:#626b80}.btn-light:hover{background:#e6e8f2}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:15px;margin-bottom:20px}.stat,.panel{background:var(--panel);border:1px solid var(--line);border-radius:15px;padding:21px;box-shadow:var(--shadow);min-width:0}.stat-top{display:flex;justify-content:space-between;align-items:center;color:var(--muted);font-size:12px;font-weight:600;gap:8px}.stat-icon{width:34px;height:34px;border-radius:10px;background:var(--soft);color:var(--primary);display:grid;place-items:center}.stat-value{font-size:24px;font-weight:800;letter-spacing:-.7px;margin:17px 0 7px;overflow-wrap:anywhere}.stat-foot{font-size:11px;color:var(--muted)}.positive{color:var(--green)!important}.negative{color:var(--red)!important}.grid{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(280px,1fr);gap:18px;margin-bottom:22px}.panel-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:22px}.panel h2{font-size:15px;margin:0 0 5px}.sub{color:var(--muted);font-size:11px}.chart{height:170px;display:flex;align-items:flex-end;justify-content:space-around;gap:12px;padding:8px 4px 0;border-bottom:1px solid var(--line);background:repeating-linear-gradient(to bottom,transparent 0,transparent 41px,#f0f2f7 42px)}.bar-wrap{height:100%;flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:8px;min-width:0}.bar{width:min(32px,70%);min-height:3px;background:#dedefe;border-radius:6px 6px 0 0}.bar.current{background:var(--primary)}.bar-label{font-size:10px;color:var(--muted);padding-bottom:9px}.category-row{margin-bottom:17px}.category-info{display:flex;justify-content:space-between;gap:12px;margin-bottom:8px;font-size:12px}.category-info span:first-child{color:#687187}.track{height:7px;background:#f0f1f7;border-radius:8px;overflow:hidden}.fill{height:100%;border-radius:8px;background:var(--primary)}.section-title{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:25px 0 15px}.section-title h2{font-size:17px;margin:0}.field label{display:block;font-size:11px;color:#707991;font-weight:700;margin:0 0 7px}.form-grid{display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:10px;align-items:end}.field input,.field select{width:100%;outline:none}.field input:focus,.field select:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--soft)}.filters{display:flex;gap:9px;align-items:end;margin-bottom:15px;flex-wrap:wrap}.filters .field{min-width:155px}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;white-space:nowrap}th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.7px;color:#9aa2b3;font-weight:800;padding:12px 10px;border-bottom:1px solid var(--line)}td{padding:14px 10px;border-bottom:1px solid #f0f2f6;font-size:12px}tbody tr:last-child td{border-bottom:0}.expense-name{font-weight:700}.pill{display:inline-block;background:#f0f1ff;color:#6869c7;border-radius:6px;padding:5px 8px;font-size:10px;font-weight:700}.amount{font-weight:800}.delete{border:0;background:#fff0f1;color:var(--red);border-radius:7px;padding:7px 9px;cursor:pointer;font-size:11px}.empty{padding:30px;text-align:center;color:var(--muted);line-height:1.7}.empty b{display:block;color:#4f5870;margin-bottom:5px}.error{background:#fff0f1;border:1px solid #ffd8dc;color:#a92e3b;border-radius:9px;padding:11px 13px;margin-bottom:15px}.month-list{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.month-card{border:1px solid var(--line);border-radius:12px;padding:15px;text-decoration:none;color:inherit;background:#fff}.month-card:hover{border-color:var(--primary);background:#fbfbff}.month-card strong{display:block;font-size:13px;margin-bottom:10px}.month-card .money{font-size:18px;font-weight:800}.month-card small{display:block;color:var(--muted);margin-top:7px}.footer{text-align:center;color:#a0a7b7;font-size:10px;padding-top:25px}
@media(max-width:1100px){.sidebar{width:205px}.main{padding:24px}.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.form-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.form-grid .btn{height:40px}.grid{grid-template-columns:1fr 1fr}.month-list{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:760px){.layout{display:block}.sidebar{width:100%;padding:14px 15px;border-right:0;border-bottom:1px solid var(--line)}.brand{margin:0 0 12px}.sidebar nav{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:3px}.sidebar .nav-label,.side-note{display:none}.nav-item{padding:10px 8px;font-size:12px}.main{padding:20px 13px}.topbar{align-items:flex-start;flex-direction:column}.topbar h1{font-size:23px}.month-form{width:100%}.month-form input{flex:1;min-width:0}.cards{grid-template-columns:1fr 1fr;gap:9px}.stat{padding:14px}.stat-value{font-size:19px;margin:12px 0 6px}.grid{grid-template-columns:1fr}.panel{padding:16px}.form-grid{grid-template-columns:1fr 1fr}.form-grid .field:first-child{grid-column:1/-1}.form-grid .btn{grid-column:1/-1}.section-title{align-items:flex-start}.month-list{grid-template-columns:1fr 1fr}.filters .field{flex:1;min-width:130px}}
</style></head><body><div class="layout">
<aside class="sidebar"><div class="brand"><div class="brand-icon">↗</div> Minhas Despesas</div><div class="nav-label">Menu principal</div><nav>
<a class="nav-item <?= $pagina==='dashboard'?'active':'' ?>" href="?pagina=dashboard&mes=<?= e($mes) ?>"><span>▦</span><span>Visão geral</span></a>
<a class="nav-item <?= $pagina==='despesas'?'active':'' ?>" href="?pagina=despesas&mes=<?= e($mes) ?>"><span>↘</span><span>Despesas</span></a>
<a class="nav-item <?= $pagina==='relatorios'?'active':'' ?>" href="?pagina=relatorios&mes=<?= e($mes) ?>"><span>▤</span><span>Relatórios mensais</span></a>
<a class="nav-item <?= $pagina==='salario'?'active':'' ?>" href="?pagina=salario&mes=<?= e($mes) ?>"><span>＋</span><span>Salário mensal</span></a>
</nav><div class="side-note"><strong>Controle simples, vida leve.</strong><br>Registre seus gastos e acompanhe seu dinheiro mês a mês.</div></aside>
<main class="main"><header class="topbar"><div><div class="eyebrow">PAINEL DE CONTROLE</div><h1><?= ['dashboard'=>'Visão geral','despesas'=>'Despesas','relatorios'=>'Relatórios mensais','salario'=>'Salário mensal'][$pagina] ?></h1></div><form class="month-form" method="get"><input type="hidden" name="pagina" value="<?= e($pagina) ?>"><label for="mes_top" class="sub">Mês:</label><input id="mes_top" type="month" name="mes" value="<?= e($mes) ?>"><button class="btn" type="submit">Ver mês</button></form></header>
<?php if($erro!==''): ?><div class="error"><?= e($erro) ?></div><?php endif; ?>
<?php if($pagina==='dashboard'): ?>
<p class="sub" style="margin:0 0 18px">Resumo financeiro de <?= e(nomeMes($mes,$mesesNomes)) ?>. Use o seletor de mês acima para consultar períodos anteriores.</p>
<section class="cards"><div class="stat"><div class="stat-top">Salário do mês <span class="stat-icon">＋</span></div><div class="stat-value"><?= dinheiro($salario) ?></div><div class="stat-foot"><a href="?pagina=salario&mes=<?= e($mes) ?>">Editar salário deste mês</a></div></div><div class="stat"><div class="stat-top">Gastos do mês <span class="stat-icon">↘</span></div><div class="stat-value"><?= dinheiro($totalMes) ?></div><div class="stat-foot"><?= $qtdMes ?> despesa(s) registrada(s)</div></div><div class="stat"><div class="stat-top">Saldo do mês <span class="stat-icon">◎</span></div><div class="stat-value <?= $saldo<0?'negative':'positive' ?>"><?= dinheiro($saldo) ?></div><div class="stat-foot">Salário menos despesas registradas</div></div><div class="stat"><div class="stat-top">Gasto acumulado <span class="stat-icon">▤</span></div><div class="stat-value"><?= dinheiro($totalGeral) ?></div><div class="stat-foot">Todos os meses cadastrados</div></div></section>
<section class="grid"><div class="panel"><div class="panel-heading"><div><h2>Histórico de gastos</h2><div class="sub">Seis meses até o período selecionado</div></div></div><div class="chart"><?php foreach($inicioGrafico as $g): $h=$maxGrafico>0?max(4,(int)round($g['total']/$maxGrafico*115)):4; ?><div class="bar-wrap" title="<?= e(nomeMes($g['mes'],$mesesNomes)) ?>: <?= e(dinheiro($g['total'])) ?>"><div class="bar <?= $g['mes']===$mes?'current':'' ?>" style="height:<?= $h ?>px"></div><div class="bar-label"><?= e(substr($mesesNomes[substr($g['mes'],5,2)],0,3)) ?></div></div><?php endforeach; ?></div></div>
<div class="panel"><div class="panel-heading"><div><h2>Gastos por categoria</h2><div class="sub"><?= e(nomeMes($mes,$mesesNomes)) ?></div></div></div><?php if(!$porCategoria): ?><div class="empty">Nenhuma despesa neste mês.</div><?php else: foreach($porCategoria as $it): $pct=$maxCategoria>0?$it['total']/$maxCategoria*100:0; ?><div class="category-row"><div class="category-info"><span><?= e($it['categoria']) ?></span><strong><?= dinheiro((int)$it['total']) ?></strong></div><div class="track"><div class="fill" style="width:<?= number_format($pct,2,'.','') ?>%"></div></div></div><?php endforeach; endif; ?></div></section>
<div class="section-title"><h2>Atalhos</h2></div><div style="display:flex;gap:10px;flex-wrap:wrap"><a class="btn" href="?pagina=despesas&mes=<?= e($mes) ?>">+ Registrar despesa</a><a class="btn btn-light" href="?pagina=salario&mes=<?= e($mes) ?>">Informar salário</a><a class="btn btn-light" href="?pagina=relatorios&mes=<?= e($mes) ?>">Ver meses anteriores</a></div>
<?php elseif($pagina==='despesas'): ?>
<div class="section-title"><h2>Adicionar despesa</h2><span class="sub">Data da despesa determina o mês do relatório</span></div><section class="panel"><?php if($erro!==''): ?><div class="error"><?= e($erro) ?></div><?php endif; ?><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="acao" value="adicionar"><input type="hidden" name="mes" value="<?= e($mes) ?>"><div class="field"><label for="descricao">Descrição</label><input id="descricao" name="descricao" maxlength="120" placeholder="Ex.: Compra no supermercado" required></div><div class="field"><label for="categoria">Categoria</label><select id="categoria" name="categoria" required><?php foreach($categorias as $cat): ?><option><?= e($cat) ?></option><?php endforeach; ?></select></div><div class="field"><label for="valor">Valor (R$)</label><input id="valor" name="valor" type="number" min="0.01" step="0.01" placeholder="0,00" required></div><div class="field"><label for="data_despesa">Data</label><input id="data_despesa" name="data_despesa" type="date" value="<?= e($mes===date('Y-m')?date('Y-m-d'):$mes.'-01') ?>" required></div><button class="btn" type="submit">Salvar despesa</button></form></section>
<div class="section-title"><h2>Despesas de <?= e(nomeMes($mes,$mesesNomes)) ?></h2><span class="sub"><?= count($despesas) ?> lançamento(s)</span></div><section class="panel"><form class="filters" method="get"><input type="hidden" name="pagina" value="despesas"><div class="field"><label for="mes_filter">Mês</label><input id="mes_filter" type="month" name="mes" value="<?= e($mes) ?>"></div><div class="field"><label for="categoria_filter">Categoria</label><select id="categoria_filter" name="categoria"><option value="">Todas</option><?php foreach($categorias as $cat): ?><option value="<?= e($cat) ?>" <?= $filtroCategoria===$cat?'selected':'' ?>><?= e($cat) ?></option><?php endforeach; ?></select></div><button class="btn">Filtrar</button></form><div class="table-wrap"><table><thead><tr><th>Descrição</th><th>Categoria</th><th>Data</th><th>Valor</th><th>Ação</th></tr></thead><tbody><?php if(!$despesas): ?><tr><td colspan="5"><div class="empty"><b>Nenhuma despesa encontrada</b>Adicione uma despesa ou selecione outro mês.</div></td></tr><?php else: foreach($despesas as $d): ?><tr><td class="expense-name"><?= e($d['descricao']) ?></td><td><span class="pill"><?= e($d['categoria']) ?></span></td><td><?= e(date('d/m/Y',strtotime($d['data_despesa']))) ?></td><td class="amount"><?= dinheiro((int)$d['valor']) ?></td><td><form method="post" onsubmit="return confirm('Deseja realmente excluir esta despesa?');"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="mes" value="<?= e($mes) ?>"><button class="delete">Excluir</button></form></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
<?php elseif($pagina==='relatorios'): ?>
<p class="sub" style="margin:0 0 18px">Consulte os totais mensais anteriores. Clique em um mês para abrir o dashboard ou a lista de despesas daquele período.</p><section class="cards"><div class="stat"><div class="stat-top">Salário de <?= e(substr($mes,5,2).'/'.substr($mes,0,4)) ?></div><div class="stat-value"><?= dinheiro($salario) ?></div></div><div class="stat"><div class="stat-top">Gastos do mês</div><div class="stat-value"><?= dinheiro($totalMes) ?></div></div><div class="stat"><div class="stat-top">Saldo do mês</div><div class="stat-value <?= $saldo<0?'negative':'positive' ?>"><?= dinheiro($saldo) ?></div></div><div class="stat"><div class="stat-top">Despesas no mês</div><div class="stat-value"><?= $qtdMes ?></div></div></section><div class="section-title"><h2>Meses anteriores</h2><span class="sub">Até 24 meses com despesas registradas</span></div><section class="panel"><div class="month-list"><?php if(!$historico): ?><div class="empty">Ainda não há despesas para exibir.</div><?php else: foreach($historico as $h): $sm=$pdo->prepare('SELECT COALESCE(valor,0) FROM salarios WHERE mes=:mes'); $sm->execute([':mes'=>$h['mes']]); $sal=(int)($sm->fetchColumn()?:0); ?><a class="month-card" href="?pagina=dashboard&mes=<?= e($h['mes']) ?>"><strong><?= e(nomeMes($h['mes'],$mesesNomes)) ?></strong><div class="money"><?= dinheiro((int)$h['total']) ?></div><small><?= (int)$h['quantidade'] ?> despesa(s) · Salário: <?= dinheiro($sal) ?></small><small>Saldo: <b class="<?= $sal-(int)$h['total']<0?'negative':'positive' ?>"><?= dinheiro($sal-(int)$h['total']) ?></b></small><span class="sub">Abrir mês →</span></a><?php endforeach; endif; ?></div></section>
<?php else: ?>
<p class="sub" style="margin:0 0 18px">Cadastre o salário recebido em cada mês. O valor é salvo separadamente para permitir consultar seu saldo nos meses anteriores.</p><section class="panel" style="max-width:700px"><div class="panel-heading"><div><h2>Salário de <?= e(nomeMes($mes,$mesesNomes)) ?></h2><div class="sub">Você pode informar um valor diferente para cada mês.</div></div></div><form method="post" style="display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:end"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="acao" value="salvar_salario"><input type="hidden" name="mes" value="<?= e($mes) ?>"><div class="field"><label for="salario">Salário mensal (R$)</label><input id="salario" name="salario" type="number" min="0" step="0.01" value="<?= e(number_format($salario/100,2,'.','')) ?>" placeholder="Ex.: 2500.00" required></div><button class="btn" type="submit">Salvar salário</button></form><div style="margin-top:20px;padding:15px;background:#f7f7ff;border-radius:10px"><div class="sub">Resumo de <?= e(nomeMes($mes,$mesesNomes)) ?></div><p style="margin:9px 0">Salário: <strong><?= dinheiro($salario) ?></strong></p><p style="margin:9px 0">Despesas: <strong><?= dinheiro($totalMes) ?></strong></p><p style="margin:9px 0">Saldo estimado: <strong class="<?= $saldo<0?'negative':'positive' ?>"><?= dinheiro($saldo) ?></strong></p></div></section><div class="section-title"><h2>Consultar outro mês</h2></div><a class="btn btn-light" href="?pagina=relatorios&mes=<?= e($mes) ?>">Abrir relatórios mensais</a>
<?php endif; ?><div class="footer">Minhas Despesas · Dados salvos localmente em SQLite</div></main></div></body></html>
