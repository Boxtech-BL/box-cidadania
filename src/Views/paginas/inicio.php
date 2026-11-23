<section class="destaque">
  <h1>Acompanhe o que o Congresso vai votar e dê a sua opinião</h1>
  <p>Veja as proposições na pauta da Câmara dos Deputados e do Senado Federal, registre se você é a favor, contra ou se abstém, e compare depois com a votação real e com os parlamentares do seu estado.</p>
  <p class="nota">É uma consulta informal entre usuários do app, sem valor estatístico ou oficial.</p>
  <?php if (!$usuario): ?>
    <p><a class="botao" href="<?= e($url('/cadastro')) ?>">Criar conta</a> <a href="<?= e($url('/entrar')) ?>">Já tenho conta</a></p>
  <?php elseif (!\BoxCidadania\Auth\AuthService::podeVotar($usuario)): ?>
    <p>Para votar, falta:
      <?= $usuario['email_verificado_em'] === null ? 'confirmar o seu e-mail' : '' ?>
      <?= ($usuario['email_verificado_em'] === null && $usuario['consentimento_politico_em'] === null) ? 'e' : '' ?>
      <?php if ($usuario['consentimento_politico_em'] === null): ?><a href="<?= e($url('/consentimento')) ?>">dar o consentimento para o tratamento da sua opinião política</a><?php endif; ?>.
    </p>
  <?php endif; ?>
</section>
<section>
  <h2>Pauta da semana</h2>
  <p class="vazio">A pauta da Câmara e do Senado aparece aqui assim que a coleta estiver ligada.</p>
</section>
