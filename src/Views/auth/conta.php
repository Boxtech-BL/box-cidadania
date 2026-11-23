<?php $titulo = 'Minha conta'; $ufAtual = $usuario['uf']; ?>
<h1>Minha conta</h1>
<dl class="ficha">
  <dt>E-mail</dt><dd><?= e($usuario['email']) ?> <?= $usuario['email_verificado_em'] ? '<span class="selo">confirmado</span>' : '<span class="selo pendente">não confirmado</span>' ?></dd>
  <dt>Região</dt><dd><?= e($municipioUsuario ? $municipioUsuario . ' - ' . $ufAtual : $ufAtual) ?></dd>
  <dt>Opinião política</dt><dd><?= $usuario['consentimento_politico_em'] ? 'consentimento dado' : '<a href="' . e($url('/consentimento')) . '">consentimento ainda não dado</a>' ?></dd>
</dl>

<section>
  <h2>Estado e município</h2>
  <?php foreach (['uf', 'municipio'] as $c): if (!empty($erros[$c])): ?><p class="erro" role="alert"><?= e($erros[$c]) ?></p><?php endif; endforeach; ?>
  <form method="post" action="<?= e($url('/conta/localidade')) ?>" class="formulario">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <label for="uf">Estado</label>
    <select id="uf" name="uf" required data-municipios="municipio">
      <?php foreach ($ufs as $uf): ?>
        <option value="<?= e($uf['sigla']) ?>" <?= $ufAtual === $uf['sigla'] ? 'selected' : '' ?>><?= e($uf['nome']) ?></option>
      <?php endforeach; ?>
    </select>
    <label for="municipio">Município <span class="dica">(opcional)</span></label>
    <select id="municipio" name="municipio" data-valor="<?= e((string) $usuario['municipio']) ?>">
      <option value="">Prefiro não informar</option>
    </select>
    <button type="submit" class="botao">Salvar</button>
  </form>
</section>

<section>
  <h2>Meus dados</h2>
  <form method="post" action="<?= e($url('/conta/apagar-votos')) ?>" class="inline" data-confirmar="Apagar todos os seus votos? Isso não pode ser desfeito.">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <button type="submit" class="botao secundario">Apagar meus votos</button>
  </form>
  <?php if ($usuario['consentimento_politico_em']): ?>
    <form method="post" action="<?= e($url('/conta/revogar-consentimento')) ?>" class="inline" data-confirmar="Retirar o consentimento apaga os seus votos. Continuar?">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <button type="submit" class="botao secundario">Retirar consentimento</button>
    </form>
  <?php endif; ?>
</section>

<section class="perigo">
  <h2>Excluir conta</h2>
  <p>Apaga a conta e todos os seus votos, de forma definitiva.</p>
  <?php if (!empty($erros['excluir'])): ?><p class="erro" role="alert"><?= e($erros['excluir']) ?></p><?php endif; ?>
  <form method="post" action="<?= e($url('/conta/excluir')) ?>" class="formulario" data-confirmar="Excluir a conta de forma definitiva?">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <label for="senha-excluir">Confirme com a sua senha</label>
    <input id="senha-excluir" name="senha" type="password" autocomplete="current-password" required>
    <button type="submit" class="botao perigo">Excluir minha conta</button>
  </form>
</section>
