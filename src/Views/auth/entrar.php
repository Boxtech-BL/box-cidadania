<?php $titulo = 'Entrar'; ?>
<h1>Entrar</h1>
<?php foreach (['geral', 'email'] as $c): if (!empty($erros[$c])): ?><p class="erro" role="alert"><?= e($erros[$c]) ?></p><?php endif; endforeach; ?>
<form method="post" action="<?= e($url('/entrar')) ?>" class="formulario">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <label for="email">E-mail</label>
  <input id="email" name="email" type="email" autocomplete="email" required value="<?= e($email) ?>">
  <label for="senha">Senha</label>
  <input id="senha" name="senha" type="password" autocomplete="current-password" required>
  <button type="submit" class="botao">Entrar</button>
</form>
<p><a href="<?= e($url('/recuperar-senha')) ?>">Esqueci a senha</a> · <a href="<?= e($url('/cadastro')) ?>">Criar conta</a></p>
