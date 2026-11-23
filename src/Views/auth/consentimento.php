<?php $titulo = 'Consentimento'; ?>
<?php require __DIR__ . '/_texto_consentimento.php'; ?>
<?php if ($usuario['consentimento_politico_em'] !== null): ?>
  <p class="aviso">Você já deu este consentimento. Para retirá-lo, vá em <a href="<?= e($url('/conta')) ?>">Minha conta</a>.</p>
<?php else: ?>
  <?php if (!empty($erros['consentimento_politico'])): ?><p class="erro" role="alert"><?= e($erros['consentimento_politico']) ?></p><?php endif; ?>
  <form method="post" action="<?= e($url('/consentimento')) ?>" class="formulario">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div class="caixa destaque-consentimento">
      <label class="marcar"><input type="checkbox" name="consentimento_politico" value="1">
        Consinto com o tratamento da minha opinião política nos termos acima.</label>
    </div>
    <button type="submit" class="botao">Registrar consentimento</button>
  </form>
<?php endif; ?>
