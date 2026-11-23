<?php /** @var callable $url */ ?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(($titulo ?? null) ? $titulo . ' - ' . $appNome : $appNome) ?></title>
  <meta name="theme-color" content="#1f4e79">
  <link rel="manifest" href="<?= e($url('/manifest.webmanifest')) ?>">
  <link rel="icon" href="<?= e($url('/assets/icone.svg')) ?>" type="image/svg+xml">
  <link rel="stylesheet" href="<?= e($url('/assets/estilo.css')) ?>">
  <script src="<?= e($url('/assets/app.js')) ?>" defer data-base="<?= e($url('')) ?>"></script>
</head>
<body>
  <a class="pular" href="#conteudo">Pular para o conteúdo</a>
  <header class="topo">
    <a class="marca" href="<?= e($url('/')) ?>"><?= e($appNome) ?></a>
    <nav aria-label="Principal">
      <?php if ($usuario): ?>
        <a href="<?= e($url('/conta')) ?>">Minha conta</a>
        <form method="post" action="<?= e($url('/sair')) ?>" class="inline">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <button type="submit" class="link">Sair</button>
        </form>
      <?php else: ?>
        <a href="<?= e($url('/entrar')) ?>">Entrar</a>
        <a href="<?= e($url('/cadastro')) ?>" class="botao">Criar conta</a>
      <?php endif; ?>
    </nav>
  </header>
  <main id="conteudo">
    <?php if ($aviso): ?><p class="aviso" role="status"><?= e($aviso) ?></p><?php endif; ?>
    <?php if ($usuario && $usuario['email_verificado_em'] === null): ?>
      <div class="alerta">
        Confirme o seu e-mail para poder votar. Não recebeu?
        <form method="post" action="<?= e($url('/reenviar-verificacao')) ?>" class="inline">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
          <button type="submit" class="link">Enviar de novo</button>
        </form>
      </div>
    <?php endif; ?>
    <?= $conteudo ?>
  </main>
  <footer class="rodape">
    <p>Consulta informal entre usuários do app, sem valor estatístico ou oficial.</p>
    <p><a href="<?= e($url('/termos')) ?>">Termos de uso</a> · <a href="<?= e($url('/privacidade')) ?>">Política de privacidade</a></p>
  </footer>
</body>
</html>
