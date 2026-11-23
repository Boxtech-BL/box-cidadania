<?php $titulo = 'Criar conta'; $erro = function (string $campo) use ($erros) { if (!empty($erros[$campo])) { echo '<p class="erro" id="erro-' . e($campo) . '">' . e($erros[$campo]) . '</p>'; } }; ?>
<h1>Criar conta</h1>
<?php $erro('geral'); ?>
<form method="post" action="<?= e($url('/cadastro')) ?>" class="formulario" novalidate>
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

  <label for="email">E-mail</label>
  <input id="email" name="email" type="email" autocomplete="email" required value="<?= e($dados['email'] ?? '') ?>" <?= !empty($erros['email']) ? 'aria-invalid="true" aria-describedby="erro-email"' : '' ?>>
  <?php $erro('email'); ?>

  <label for="senha">Senha <span class="dica">(mínimo de 8 caracteres)</span></label>
  <input id="senha" name="senha" type="password" autocomplete="new-password" minlength="8" required <?= !empty($erros['senha']) ? 'aria-invalid="true" aria-describedby="erro-senha"' : '' ?>>
  <?php $erro('senha'); ?>

  <p class="dica">Pedimos só o estado e, se quiser, o município, para mostrar os resultados da sua região. Não pedimos CPF, endereço nem localização.</p>
  <label for="uf">Estado</label>
  <select id="uf" name="uf" required data-municipios="municipio">
    <option value="">Escolha</option>
    <?php foreach ($ufs as $uf): ?>
      <option value="<?= e($uf['sigla']) ?>" <?= ($dados['uf'] ?? '') === $uf['sigla'] ? 'selected' : '' ?>><?= e($uf['nome']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php $erro('uf'); ?>

  <label for="municipio">Município <span class="dica">(opcional)</span></label>
  <select id="municipio" name="municipio" data-valor="<?= e((string) ($dados['municipio'] ?? '')) ?>">
    <option value="">Prefiro não informar</option>
  </select>
  <?php $erro('municipio'); ?>

  <div class="caixa">
    <label class="marcar"><input type="checkbox" name="aceita_termos" value="1" <?= !empty($dados['aceita_termos']) ? 'checked' : '' ?>>
      Li e aceito os <a href="<?= e($url('/termos')) ?>" target="_blank">termos de uso</a> e a <a href="<?= e($url('/privacidade')) ?>" target="_blank">política de privacidade</a>.</label>
    <?php $erro('aceita_termos'); ?>
  </div>

  <?php require __DIR__ . '/_texto_consentimento.php'; ?>
  <div class="caixa destaque-consentimento">
    <label class="marcar"><input type="checkbox" name="consentimento_politico" value="1" <?= !empty($dados['consentimento_politico']) ? 'checked' : '' ?>>
      Consinto, de forma específica e destacada, com o tratamento da minha opinião política nos termos acima. <span class="dica">(opcional agora; obrigatório antes do primeiro voto)</span></label>
  </div>

  <button type="submit" class="botao">Criar conta</button>
</form>
<p>Já tem conta? <a href="<?= e($url('/entrar')) ?>">Entrar</a></p>
