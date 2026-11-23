// Comportamentos leves das páginas (sem dependências).
(function () {
  var script = document.currentScript;
  var base = (script && script.dataset.base) || '';

  // Município: carrega a lista do estado escolhido.
  document.querySelectorAll('select[data-municipios]').forEach(function (selUf) {
    var selMun = document.getElementById(selUf.dataset.municipios);
    if (!selMun) return;
    function carregar() {
      var valor = selMun.dataset.valor || '';
      selMun.length = 1;
      if (!selUf.value) return;
      fetch(base + '/api/municipios?uf=' + encodeURIComponent(selUf.value))
        .then(function (r) { return r.json(); })
        .then(function (lista) {
          lista.forEach(function (m) {
            var op = new Option(m.nome, m.id, false, String(m.id) === valor);
            selMun.add(op);
          });
        });
    }
    selUf.addEventListener('change', function () { selMun.dataset.valor = ''; carregar(); });
    carregar();
  });

  // Confirmação antes de ações que apagam dados.
  document.querySelectorAll('form[data-confirmar]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (!window.confirm(f.dataset.confirmar)) ev.preventDefault();
    });
  });

  // PWA: service worker só para os arquivos estáticos.
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register(base + '/sw.js').catch(function () {});
  }
})();
