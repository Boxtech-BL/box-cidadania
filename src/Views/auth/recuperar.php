<?php $titulo = 'Recuperar senha'; ?>
<h1>Recuperar senha</h1>
<?php if (!empty($erros['geral'])): ?><p class="erro" role="alert"><?= e($erros['geral']) ?></p><?php endif; ?>
<?php if ($enviado): ?>
  <p class="aviso" role="status">Se houver uma conta com esse e-mail, enviamos um link para criar uma nova senha. O link vale por 1 hora.</p>
<?php else: ?>
  <form method="post" action="<?= e($url('/recuperar-senha')) ?>" class="formulario">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <label for="email">E-mail da conta</label>
    <input id="email" name="email" type="email" autocomplete="email" required>
    <button type="submit" class="botao">Enviar link</button>
  </form>
<?php endif; ?>
