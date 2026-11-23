<?php $titulo = 'Nova senha'; ?>
<h1>Criar nova senha</h1>
<?php foreach (['token', 'senha'] as $c): if (!empty($erros[$c])): ?><p class="erro" role="alert"><?= e($erros[$c]) ?></p><?php endif; endforeach; ?>
<form method="post" action="<?= e($url('/redefinir-senha')) ?>" class="formulario">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <label for="senha">Nova senha <span class="dica">(mínimo de 8 caracteres)</span></label>
  <input id="senha" name="senha" type="password" autocomplete="new-password" minlength="8" required>
  <button type="submit" class="botao">Salvar senha</button>
</form>
