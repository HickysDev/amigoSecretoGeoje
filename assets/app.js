// Amigo Secreto Geoje: revelação do envelope, confete e microinterações.
(function () {
  var calmo = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var ESTRELA = 'M12 0C13 8 16 11 24 12 16 13 13 16 12 24 11 16 8 13 0 12 8 11 11 8 12 0Z';
  var CORES = ['#004aad', '#7aa7ee', '#ffffff', '#d8b98d'];

  // ---------------------------------------------------------- entradas em cascata
  function indices(itens, max) {
    Array.prototype.forEach.call(itens, function (el, i) { el.style.setProperty('--i', Math.min(i, max)); });
  }
  // Capas na revelação e jogos ao abrir a lista de alguém
  document.querySelectorAll('.segredo .jogos').forEach(function (l) { indices(l.children, 14); });
  document.querySelectorAll('.pessoa-corpo').forEach(function (l) { indices(l.querySelectorAll('.jogo-linha'), 10); });

  // ---------------------------------------------------------- ordenar as listas de jogos
  // O servidor já entrega por maior desconto; aqui só reordena se a pessoa escolher outra ordem.
  var num = function (el, k) { return Number(el.dataset[k]); };
  var semPreco = function (el) { return num(el, 'centavos') < 0; };   // sem preço vai para o fim
  var ORDENS = {
    desconto: function (a, b) { return num(b, 'desconto') - num(a, 'desconto') || num(a, 'ordem') - num(b, 'ordem'); },
    menor: function (a, b) { return semPreco(a) - semPreco(b) || num(a, 'centavos') - num(b, 'centavos') || num(a, 'ordem') - num(b, 'ordem'); },
    maior: function (a, b) { return semPreco(a) - semPreco(b) || num(b, 'centavos') - num(a, 'centavos') || num(a, 'ordem') - num(b, 'ordem'); },
    nome: function (a, b) { return a.dataset.nome.localeCompare(b.dataset.nome, 'pt-BR'); },
    preferencia: function (a, b) { return num(a, 'ordem') - num(b, 'ordem'); }
  };
  var seletores = document.querySelectorAll('[data-ordenar]');
  function ordenar(modo, salvar) {
    if (!ORDENS[modo]) modo = 'desconto';
    document.querySelectorAll('[data-lista-jogos]').forEach(function (lista) {
      var itens = Array.prototype.slice.call(lista.children).sort(ORDENS[modo]);
      itens.forEach(function (el, i) { lista.appendChild(el); el.style.setProperty('--i', Math.min(i, 14)); });
      if (salvar && !calmo) {
        itens.slice(0, 15).forEach(function (el) { el.classList.remove('reordenado'); void el.offsetWidth; el.classList.add('reordenado'); });
      }
    });
    seletores.forEach(function (s) { s.value = modo; });
    if (salvar) localStorage.setItem('ordem-jogos', modo);
    aplicarRecolher();
  }
  if (seletores.length) {
    var guardada = localStorage.getItem('ordem-jogos');
    if (guardada && guardada !== 'desconto') ordenar(guardada, false);
    seletores.forEach(function (s) { s.addEventListener('change', function () { ordenar(s.value, true); }); });
  }

  // ---------------------------------------------------------- esconder jogos da própria lista
  function postar(corpo) {
    var csrf = document.querySelector('input[name=csrf]');
    if (!csrf) return;
    fetch(location.pathname, {
      method: 'POST', credentials: 'same-origin', keepalive: true,
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: corpo + '&csrf=' + encodeURIComponent(csrf.value)
    });
  }
  // Aba Minha lista: olho em cada capa, busca, filtro e esconder/mostrar em massa
  var minha = document.querySelector('[data-minha]');
  var busca = document.querySelector('[data-busca]');
  var filtro = 'todos';
  function marcarOculto(caixa, oculto) {
    var b = caixa.querySelector('[data-ocultar]');
    caixa.classList.toggle('oculto', oculto);
    b.setAttribute('aria-pressed', oculto);
    b.title = oculto ? 'Mostrar para o grupo' : 'Esconder do grupo';
    b.setAttribute('aria-label', b.title);
  }
  function aplicarFiltro() {
    if (!minha) return;
    var termo = busca ? busca.value.trim().toLowerCase() : '';
    var itens = Array.prototype.slice.call(minha.children);
    var escondidos = 0, naTela = 0;
    itens.forEach(function (el) {
      var oculto = el.classList.contains('oculto');
      if (oculto) escondidos++;
      var passa = (!termo || el.dataset.nome.indexOf(termo) >= 0)
        && (filtro === 'todos' || (filtro === 'escondidos') === oculto);
      el.hidden = !passa;
      if (passa) naTela++;
    });
    var put = function (sel, v) { document.querySelectorAll(sel).forEach(function (x) { x.textContent = v; }); };
    put('[data-cont-total]', itens.length);
    put('[data-cont-escondidos]', escondidos);
    put('[data-cont-aparecem]', itens.length - escondidos);
    put('[data-cont-tela]', naTela + (naTela === 1 ? ' jogo na tela' : ' jogos na tela'));
    var vazio = document.querySelector('[data-sem-resultado]');
    if (vazio) vazio.hidden = naTela > 0;
  }
  if (busca) busca.addEventListener('input', aplicarFiltro);
  document.querySelectorAll('[data-filtro]').forEach(function (bt) {
    bt.addEventListener('click', function () {
      filtro = bt.dataset.filtro;
      document.querySelectorAll('[data-filtro]').forEach(function (x) { x.setAttribute('aria-pressed', x === bt); });
      aplicarFiltro();
    });
  });
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-ocultar]');
    if (b) {
      var caixa = b.closest('.jogo-caixa');
      var oculto = !caixa.classList.contains('oculto');
      marcarOculto(caixa, oculto);
      postar('acao=ocultar&appid=' + encodeURIComponent(b.dataset.ocultar) + '&oculto=' + (oculto ? '1' : '0'));
      aplicarFiltro();
      return;
    }
    var m = e.target.closest && e.target.closest('[data-massa]');
    if (m && minha) {
      var esconder = m.dataset.massa === '1';
      var ids = [];
      Array.prototype.forEach.call(minha.children, function (el) {
        if (!el.hidden && el.classList.contains('oculto') !== esconder) {
          marcarOculto(el, esconder);
          ids.push(el.querySelector('[data-ocultar]').dataset.ocultar);
        }
      });
      if (ids.length) postar('acao=ocultar_varios&oculto=' + (esconder ? '1' : '0') + '&appids=' + ids.join(','));
      aplicarFiltro();
    }
  });

  // ---------------------------------------------------------- "talvez eu compre": soma até o valor mínimo
  var cesta = document.querySelector('[data-cesta]');
  var brl = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
  function atualizarCesta() {
    if (!cesta) return;
    var itens = document.querySelectorAll('.jogo-caixa.escolhido');
    var soma = 0;
    itens.forEach(function (el) { soma += Math.max(0, Number(el.dataset.centavos)); });
    var qtd = itens.length;
    var minimo = cesta.dataset.minimo === '' ? null : Number(cesta.dataset.minimo);
    var ok = minimo !== null && qtd > 0 && soma >= minimo;
    var status = !qtd ? 'Clique no + das capas para separar o que talvez você compre'
      : minimo === null ? ''
      : ok ? (soma > minimo ? 'Mínimo atingido · ' + brl.format((soma - minimo) / 100) + ' acima' : 'Mínimo atingido')
      : 'Faltam ' + brl.format((minimo - soma) / 100);
    cesta.querySelector('[data-cesta-soma]').textContent = brl.format(soma / 100);
    cesta.querySelector('[data-cesta-status]').textContent = status;
    cesta.querySelector('[data-cesta-qtd]').textContent = qtd + (qtd === 1 ? ' jogo escolhido' : ' jogos escolhidos');
    var barra = cesta.querySelector('[data-cesta-barra]');
    if (barra) barra.style.transform = 'translateX(' + (Math.min(100, minimo ? Math.round(soma * 100 / minimo) : 0) - 100) + '%)';
    cesta.querySelector('[data-cesta-limpar]').hidden = !qtd;
    cesta.classList.toggle('cesta-ok', ok);
    cesta.classList.toggle('cesta-vazia', !qtd);
  }
  function escolher(botao, sim) {
    var caixa = botao.closest('.jogo-caixa');
    caixa.classList.toggle('escolhido', sim);
    botao.setAttribute('aria-pressed', sim);
    botao.title = sim ? 'Tirar da seleção' : 'Talvez eu compre';
    postar('acao=carrinho&appid=' + encodeURIComponent(botao.dataset.escolher) + '&escolhido=' + (sim ? '1' : '0'));
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-escolher]');
    if (b && !b.disabled) {
      escolher(b, !b.closest('.jogo-caixa').classList.contains('escolhido'));
      atualizarCesta();
      return;
    }
    if (e.target.closest && e.target.closest('[data-cesta-limpar]')) {
      document.querySelectorAll('.jogo-caixa.escolhido [data-escolher]').forEach(function (x) { escolher(x, false); });
      document.querySelectorAll('[data-surpresa]').forEach(tirarSurpresa);
      atualizarCesta();
    }
  });

  // ---------------------------------------------------------- jogos fora da lista de desejos (surpresas)
  // Busca na loja da Steam; o que a pessoa adiciona entra na soma junto com os jogos da lista.
  var buscaSteam = document.querySelector('[data-busca-steam]');
  var resultados = document.querySelector('[data-resultados]');
  var gradeSurpresas = document.querySelector('[data-surpresas-grade]');
  function pedir(corpo) {
    var csrf = document.querySelector('input[name=csrf]');
    return fetch(location.pathname, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: corpo + '&csrf=' + encodeURIComponent(csrf ? csrf.value : '')
    }).then(function (r) { return r.status === 204 ? {} : r.json(); })
      .catch(function () { return { erro: 'Não deu para consultar agora. Recarregue a página e tente de novo' }; });
  }
  function avisoResultados(texto) {
    var p = document.createElement('p');
    p.className = 'vazio';
    p.textContent = texto;
    resultados.textContent = '';
    resultados.appendChild(p);
    resultados.hidden = false;
  }
  function rotuloResultado(appid, texto, travado) {
    var b = resultados && resultados.querySelector('[data-surpresa-add="' + appid + '"]');
    if (!b) return;
    b.disabled = travado;
    b.querySelector('span').textContent = texto;
  }
  function tirarSurpresa(botao) {
    var appid = botao.dataset.surpresa;
    botao.closest('.jogo-caixa').remove();
    postar('acao=surpresa&escolhido=0&appid=' + encodeURIComponent(appid));
    rotuloResultado(appid, 'Adicionar', false);
  }
  if (buscaSteam && resultados && gradeSurpresas) {
    var espera, pedido = 0;
    buscaSteam.addEventListener('input', function () {
      clearTimeout(espera);
      var termo = buscaSteam.value.trim();
      var meu = ++pedido;
      if (termo.length < 2) { resultados.hidden = true; resultados.textContent = ''; return; }
      espera = setTimeout(function () {
        avisoResultados('Buscando na Steam');
        pedir('acao=buscar_jogo&termo=' + encodeURIComponent(termo)).then(function (r) {
          if (meu !== pedido) return;
          if (r.erro) return avisoResultados(r.erro);
          if (!r.html) return avisoResultados('Nenhum jogo com esse nome na Steam');
          resultados.innerHTML = r.html;
          resultados.hidden = false;
          // O que já está na lista de desejos se escolhe na própria lista
          resultados.querySelectorAll('[data-surpresa-add]').forEach(function (b) {
            if (document.querySelector('[data-escolher="' + b.dataset.surpresaAdd + '"]')) rotuloResultado(b.dataset.surpresaAdd, 'Já está na lista', true);
          });
        });
      }, 400);
    });
    document.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('[data-surpresa-add]');
      if (a && !a.disabled) {
        var appid = a.dataset.surpresaAdd;
        rotuloResultado(appid, 'Adicionando', true);
        pedir('acao=surpresa&escolhido=1&appid=' + encodeURIComponent(appid)).then(function (r) {
          if (r.erro || !r.html) {
            rotuloResultado(appid, 'Tente de novo', false);
            a.title = r.erro || '';
            return;
          }
          if (!gradeSurpresas.querySelector('[data-surpresa="' + appid + '"]')) gradeSurpresas.insertAdjacentHTML('beforeend', r.html);
          rotuloResultado(appid, 'Adicionado', true);
          atualizarCesta();
        });
        return;
      }
      var t = e.target.closest && e.target.closest('[data-surpresa]');
      if (t) {
        tirarSurpresa(t);
        atualizarCesta();
      }
    });
  }

  // ---------------------------------------------------------- lista grande de quem a pessoa tirou: recolhida
  // Mostra os primeiros N na ordem atual; "Ver todos" abre o resto; a busca mostra tudo que bate.
  var recolher = document.querySelector('[data-recolher]');
  var verTodos = document.querySelector('[data-ver-todos]');
  var buscaSeg = document.querySelector('[data-busca-segredo]');
  var listaAberta = false;
  function aplicarRecolher() {
    if (!recolher) return;
    var n = Number(recolher.dataset.recolher);
    var termo = buscaSeg ? buscaSeg.value.trim().toLowerCase() : '';
    var vistos = 0;
    Array.prototype.forEach.call(recolher.children, function (el, i) {
      var passa = termo ? el.dataset.nome.indexOf(termo) >= 0 : (listaAberta || i < n);
      el.hidden = !passa;
      if (passa) vistos++;
    });
    if (verTodos) {
      verTodos.parentNode.hidden = !!termo;
      verTodos.classList.toggle('aberto', listaAberta);
      verTodos.querySelector('span').textContent = listaAberta ? 'Mostrar menos' : 'Ver todos os ' + recolher.children.length + ' jogos';
    }
    var vazio = document.querySelector('[data-segredo-vazio]');
    if (vazio) vazio.hidden = vistos > 0;
  }
  if (verTodos) {
    verTodos.addEventListener('click', function () {
      listaAberta = !listaAberta;
      aplicarRecolher();
      // Ao recolher, volta para o começo da lista (descontando a barra do topo)
      if (!listaAberta) window.scrollTo({ top: recolher.getBoundingClientRect().top + window.scrollY - 160, behavior: calmo ? 'auto' : 'smooth' });
    });
  }
  if (buscaSeg) buscaSeg.addEventListener('input', aplicarRecolher);
  aplicarRecolher();

  // ---------------------------------------------------------- lista de alguém do grupo em tela cheia
  // Monta as capas a partir das linhas que já estão na página (nada de novo é buscado no servidor)
  var ampliada = document.querySelector('[data-ampliada]');
  if (ampliada && ampliada.showModal) {
    var gradeAmp = ampliada.querySelector('[data-ampliada-grade]');
    var buscaAmp = ampliada.querySelector('[data-busca-ampliada]');
    var filtrarAmpliada = function () {
      var t = buscaAmp.value.trim().toLowerCase(), n = 0;
      Array.prototype.forEach.call(gradeAmp.children, function (el) {
        var passa = !t || el.dataset.nome.indexOf(t) >= 0;
        el.hidden = !passa;
        if (passa) n++;
      });
      ampliada.querySelector('[data-ampliada-vazio]').hidden = n > 0;
    };
    document.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('[data-ampliar]');
      if (!b) return;
      var linhas = b.closest('.pessoa-corpo').querySelectorAll('.jogo-linha');
      gradeAmp.textContent = '';
      Array.prototype.forEach.call(linhas, function (l, i) {
        var caixa = document.createElement('div');
        caixa.className = 'jogo-caixa';
        ['desconto', 'centavos', 'ordem', 'nome'].forEach(function (k) { caixa.dataset[k] = l.dataset[k]; });
        caixa.style.setProperty('--i', Math.min(i, 14));
        var a = document.createElement('a');
        a.className = 'jogo';
        a.href = l.href; a.target = '_blank'; a.rel = 'noopener';
        var nome = l.querySelector('.jogo-nome').textContent;
        a.title = nome;
        var img = l.querySelector('img');
        a.innerHTML = '<span class="jogo-img"></span><span class="jogo-info"><span class="jogo-nome"><span class="jogo-titulo"></span></span></span>';
        if (img) { var im = document.createElement('img'); im.src = img.getAttribute('src'); im.alt = ''; im.loading = 'lazy'; a.firstChild.appendChild(im); }
        a.querySelector('.jogo-titulo').textContent = nome;
        a.querySelector('.jogo-info').appendChild(l.querySelector('.preco').cloneNode(true));
        caixa.appendChild(a);
        gradeAmp.appendChild(caixa);
      });
      ampliada.querySelector('[data-ampliada-nome]').textContent = b.dataset.nome;
      ampliada.querySelector('[data-ampliada-resumo]').textContent = b.dataset.resumo;
      buscaAmp.value = '';
      filtrarAmpliada();
      ampliada.showModal();
      ampliada.querySelector('.ampliada-corpo').scrollTop = 0;
    });
    buscaAmp.addEventListener('input', filtrarAmpliada);
    ampliada.querySelector('[data-fechar-ampliada]').addEventListener('click', function () { ampliada.close(); });
    // Clique fora da janela (no fundo escurecido) fecha
    ampliada.addEventListener('click', function (e) { if (e.target === ampliada) ampliada.close(); });
  }

  // ---------------------------------------------------------- abas sem recarregar a página
  // As três abas já vêm na página; o clique só troca o painel e atualiza o endereço (F5 e Voltar continuam funcionando)
  var paineis = document.querySelectorAll('[data-painel]');
  function mostrarAba(aba, empurrar) {
    if (!document.querySelector('[data-painel="' + aba + '"]')) aba = 'amigo';
    paineis.forEach(function (p) {
      var ativo = p.dataset.painel === aba;
      p.hidden = !ativo;
      if (ativo && !calmo) { p.classList.remove('aba-entra'); void p.offsetWidth; p.classList.add('aba-entra'); }
    });
    document.querySelectorAll('.abas-topo .aba').forEach(function (a) {
      var ativa = a.dataset.aba === aba;
      a.classList.toggle('ativa', ativa);
      if (ativa) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
    if (empurrar) history.pushState({ aba: aba }, '', aba === 'amigo' ? './' : './?aba=' + aba);
    window.scrollTo(0, 0);
  }
  if (paineis.length) {
    document.querySelectorAll('.abas-topo .aba').forEach(function (a) {
      a.addEventListener('click', function (e) {
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.button) return;   // Ctrl+clique abre em nova guia
        e.preventDefault();
        if (!a.classList.contains('ativa')) mostrarAba(a.dataset.aba, true);
      });
    });
    window.addEventListener('popstate', function () {
      mostrarAba(new URLSearchParams(location.search).get('aba') || 'amigo', false);
    });
  }

  // ---------------------------------------------------------- ajuda: o "?" de cada funcionalidade
  // Passar o mouse mostra; clicar deixa aberto até clicar fora, no próprio "?" ou apertar Esc.
  function fecharAjuda(a) {
    a.classList.remove('aberta');
    delete a.dataset.fixa;
    a.querySelector('.ajuda-botao').setAttribute('aria-expanded', 'false');
  }
  function abrirAjuda(a) {
    document.querySelectorAll('.ajuda.aberta').forEach(function (x) { if (x !== a) fecharAjuda(x); });
    var balao = a.querySelector('.ajuda-balao');
    a.classList.remove('cima');
    balao.style.setProperty('--dx', '0px');
    a.classList.add('aberta');
    a.querySelector('.ajuda-botao').setAttribute('aria-expanded', 'true');
    // Não deixa o balão sair da tela: desloca para o lado e, sem espaço embaixo, abre para cima
    var r = balao.getBoundingClientRect();
    var dx = 0;
    if (r.right > window.innerWidth - 16) dx = window.innerWidth - 16 - r.right;
    if (r.left + dx < 16) dx = 16 - r.left;
    balao.style.setProperty('--dx', dx + 'px');
    if (r.bottom > window.innerHeight - 8 && a.getBoundingClientRect().top > r.height + 16) a.classList.add('cima');
  }
  document.querySelectorAll('.ajuda').forEach(function (a) {
    a.addEventListener('mouseenter', function () { if (!a.classList.contains('aberta')) abrirAjuda(a); });
    a.addEventListener('mouseleave', function () { if (!a.dataset.fixa) fecharAjuda(a); });
  });
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('.ajuda .ajuda-botao');
    var a = b && b.closest('.ajuda');
    document.querySelectorAll('.ajuda.aberta').forEach(function (x) { if (x !== a && !x.contains(e.target)) fecharAjuda(x); });
    if (!a) return;
    e.preventDefault();
    if (a.dataset.fixa) { fecharAjuda(a); return; }
    abrirAjuda(a);
    a.dataset.fixa = '1';
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') document.querySelectorAll('.ajuda.aberta').forEach(fecharAjuda);
  });

  // ---------------------------------------------------------- tour de boas-vindas
  // Abre sozinho na primeira vez (o servidor guarda que a pessoa já viu); o "?" da barra repete.
  // Passo sem "alvo", ou com o alvo fora da tela, vira um cartão no meio.
  var tour = document.querySelector('[data-tour]');
  var PASSOS = [
    { titulo: 'Boas-vindas ao Amigo Secreto Geoje', texto: 'Em menos de um minuto você vê onde fica cada coisa. Dá para pular agora e rever depois' },
    { alvo: '.envelope-cena', titulo: 'Quem você tirou', texto: 'O nome está neste envelope: clique nele para abrir. Só você vê esse nome, então guarde no envelope quando tiver alguém por perto' },
    { alvo: '#segredo .bloco-topo', titulo: 'Monte o presente', texto: 'Com o envelope aberto, aparece a lista de desejos da pessoa com os preços de hoje. Clique no + das capas para somar até o valor mínimo. Em "Fora da lista" você busca qualquer jogo para surpreender' },
    { alvo: '[data-tour-grupo]', titulo: 'Listas do grupo', texto: 'Aqui ficam as listas de desejos de todo mundo. Nada nesta parte mostra quem tirou quem' },
    { alvo: '[data-aba="lista"]', titulo: 'Sua lista de desejos', texto: 'Nesta aba você informa o seu perfil da Steam e escolhe quais jogos o grupo pode ver' },
    { alvo: '[data-tour-abrir]', titulo: 'Ficou com dúvida?', texto: 'Cada parte do site tem um ? com uma explicação curta. Este botão aqui em cima repete o tour' }
  ];
  if (tour) {
    var foco = tour.querySelector('[data-tour-foco]');
    var cartaoTour = tour.querySelector('.tour-cartao');
    var btProximo = tour.querySelector('[data-tour-proximo]');
    var btVoltar = tour.querySelector('[data-tour-voltar]');
    var passo = 0;
    var voltarFoco = null;
    function alvoDoPasso() {
      var sel = PASSOS[passo].alvo;
      var el = sel && document.querySelector(sel);
      return el && el.getClientRects().length ? el : null;
    }
    function posicionarTour() {
      var el = alvoDoPasso();
      var vw = window.innerWidth, vh = window.innerHeight;
      var cw = cartaoTour.offsetWidth, ch = cartaoTour.offsetHeight;
      foco.classList.toggle('sem-alvo', !el);
      if (!el) {
        foco.style.left = vw / 2 + 'px'; foco.style.top = vh / 2 + 'px'; foco.style.width = '0px'; foco.style.height = '0px';
        cartaoTour.style.left = (vw - cw) / 2 + 'px'; cartaoTour.style.top = Math.max(16, (vh - ch) / 2) + 'px';
        return;
      }
      var r = el.getBoundingClientRect();
      var m = 8;   // folga em volta do trecho destacado
      var topo = Math.max(4, r.top - m), base = Math.min(vh - 4, r.bottom + m);
      foco.style.left = Math.max(4, r.left - m) + 'px'; foco.style.top = topo + 'px';
      foco.style.width = Math.min(vw - 8, r.width + m * 2) + 'px'; foco.style.height = Math.max(0, base - topo) + 'px';
      // Cartão embaixo do destaque; sem espaço, em cima; sem espaço nos dois, no pé da tela
      var y = base + 12 + ch <= vh - 8 ? base + 12 : topo - 12 - ch >= 8 ? topo - 12 - ch : vh - ch - 16;
      // Trecho alto demais: o destaque termina onde o cartão começa
      if (y > topo && y < base + 12) foco.style.height = Math.max(0, y - 12 - topo) + 'px';
      cartaoTour.style.left = Math.min(Math.max(16, r.left - m), vw - cw - 16) + 'px';
      cartaoTour.style.top = y + 'px';
    }
    function mostrarPasso(n) {
      passo = n;
      var p = PASSOS[n];
      tour.querySelector('[data-tour-passo]').textContent = (n + 1) + ' de ' + PASSOS.length;
      tour.querySelector('[data-tour-titulo]').textContent = p.titulo;
      tour.querySelector('[data-tour-texto]').textContent = p.texto;
      btVoltar.hidden = n === 0;
      btProximo.textContent = n === 0 ? 'Começar' : n === PASSOS.length - 1 ? 'Entendi' : 'Próximo';
      cartaoTour.classList.remove('tour-troca'); void cartaoTour.offsetWidth; cartaoTour.classList.add('tour-troca');
      // Rola para o trecho e o cartão caberem juntos na tela (o que está na barra do topo não rola)
      var el = alvoDoPasso();
      if (el && !el.closest('.barra')) {
        var r = el.getBoundingClientRect();
        var junto = r.height + 28 + cartaoTour.offsetHeight;
        window.scrollBy({ top: r.top - 8 - Math.max(document.querySelector('.barra').offsetHeight + 8, (window.innerHeight - junto) / 2), behavior: 'instant' });
      }
      posicionarTour();
      btProximo.focus({ preventScroll: true });
    }
    function abrirTour() {
      document.querySelectorAll('.ajuda.aberta').forEach(fecharAjuda);
      if (document.querySelector('[data-painel="amigo"]').hidden) mostrarAba('amigo', true);
      voltarFoco = document.activeElement;
      document.documentElement.classList.add('tour-aberto');
      tour.hidden = false;
      mostrarPasso(0);
    }
    function fecharTour() {
      tour.hidden = true;
      document.documentElement.classList.remove('tour-aberto');
      if (tour.hasAttribute('data-auto')) {
        tour.removeAttribute('data-auto');
        postar('acao=tour_visto');
      }
      window.scrollTo(0, 0);
      if (voltarFoco && voltarFoco.focus) voltarFoco.focus({ preventScroll: true });
    }
    btProximo.addEventListener('click', function () { if (passo === PASSOS.length - 1) fecharTour(); else mostrarPasso(passo + 1); });
    btVoltar.addEventListener('click', function () { if (passo > 0) mostrarPasso(passo - 1); });
    tour.querySelector('[data-tour-pular]').addEventListener('click', fecharTour);
    document.addEventListener('keydown', function (e) {
      if (tour.hidden) return;
      if (e.key === 'Escape') fecharTour();
      else if (e.key === 'ArrowRight' && passo < PASSOS.length - 1) mostrarPasso(passo + 1);
      else if (e.key === 'ArrowLeft' && passo > 0) mostrarPasso(passo - 1);
      else if (e.key === 'Tab') {
        // O foco fica dentro do cartão enquanto o tour está aberto
        var bts = Array.prototype.filter.call(cartaoTour.querySelectorAll('button'), function (b) { return !b.hidden; });
        var i = bts.indexOf(document.activeElement);
        e.preventDefault();
        bts[(i + (e.shiftKey ? bts.length - 1 : 1)) % bts.length].focus();
      }
    });
    window.addEventListener('resize', function () { if (!tour.hidden) posicionarTour(); });
    var abrirBt = document.querySelector('[data-tour-abrir]');
    if (abrirBt) abrirBt.addEventListener('click', abrirTour);
    if (tour.hasAttribute('data-auto')) setTimeout(abrirTour, 500);
  }

  // Login (ou outra tela de acesso) com erro: o formulário treme
  var formAcesso = document.querySelector('.acesso-form .form');
  if (formAcesso && document.querySelector('.aviso-erro')) {
    formAcesso.classList.add('tremer');
    var campo = formAcesso.querySelector('input[type=password], input[type=email]');
    if (campo) campo.select();
  }

  // ---------------------------------------------------------- brilho que segue o mouse nas capas
  document.addEventListener('pointermove', function (e) {
    var card = e.target.closest && e.target.closest('.jogo');
    if (!card) return;
    var r = card.getBoundingClientRect();
    card.style.setProperty('--mx', (e.clientX - r.left) + 'px');
    card.style.setProperty('--my', (e.clientY - r.top) + 'px');
  });

  // ---------------------------------------------------------- botão mostra que está enviando (e não aceita 2º clique)
  document.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return;   // confirm() cancelado
    var b = e.submitter;
    if (!b || !b.classList.contains('botao')) return;
    if (b.classList.contains('carregando')) { e.preventDefault(); return; }
    b.classList.add('carregando');
    b.setAttribute('aria-busy', 'true');
  });
  // Ao voltar pelo histórico, o botão não pode ficar travado
  window.addEventListener('pageshow', function () {
    document.querySelectorAll('.carregando').forEach(function (b) { b.classList.remove('carregando'); b.removeAttribute('aria-busy'); });
  });

  // ---------------------------------------------------------- revelação
  var botao = document.getElementById('revelar');
  if (!botao) return;
  var cena = document.querySelector('[data-revelar]');
  var nome = cena.querySelector('[data-nome]');
  var lacre = cena.querySelector('.lacre');
  var segredo = document.getElementById('segredo');
  var nomeFinal = nome.textContent;

  function estado(aberto) {
    if (segredo) segredo.hidden = !aberto;
    botao.textContent = aberto ? botao.dataset.fechar : botao.dataset.abrir;
    botao.setAttribute('aria-expanded', aberto);
    botao.classList.toggle('primario', !aberto);
    botao.classList.toggle('secundario', aberto);
    // Guarda na conta: da próxima vez a página já abre assim
    var csrf = document.querySelector('input[name=csrf]');
    if (csrf) {
      fetch(location.pathname, {
        method: 'POST', credentials: 'same-origin', keepalive: true,
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'acao=revelar&revelado=' + (aberto ? '1' : '0') + '&csrf=' + encodeURIComponent(csrf.value)
      });
    }
  }

  // Letras giram como num painel de aeroporto e travam da esquerda para a direita
  function embaralhar() {
    var letras = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    var inicio = performance.now();
    function quadro(agora) {
      var travadas = Math.floor((agora - inicio - 200) / 65);
      var s = '';
      for (var i = 0; i < nomeFinal.length; i++) {
        var c = nomeFinal[i];
        // Sorteia na mesma caixa da letra original, para o nome não mudar de largura e quebrar a linha
        var l = letras[Math.floor(Math.random() * letras.length)];
        s += (i < travadas || c === ' ') ? c : (c === c.toLowerCase() ? l.toLowerCase() : l);
      }
      nome.textContent = s;
      if (travadas < nomeFinal.length) requestAnimationFrame(quadro);
      else nome.textContent = nomeFinal;
    }
    requestAnimationFrame(quadro);
  }

  function confete(x, y) {
    var tela = document.createElement('canvas');
    tela.className = 'confete';
    var dpr = window.devicePixelRatio || 1;
    tela.width = innerWidth * dpr;
    tela.height = innerHeight * dpr;
    document.body.appendChild(tela);
    var ctx = tela.getContext('2d');
    ctx.scale(dpr, dpr);
    var estrela = new Path2D(ESTRELA);
    var pecas = [];
    for (var i = 0; i < 90; i++) {
      var ang = -Math.PI / 2 + (Math.random() - .5) * Math.PI * 1.3;
      var vel = 5 + Math.random() * 8;
      pecas.push({
        x: x, y: y, vx: Math.cos(ang) * vel, vy: Math.sin(ang) * vel,
        giro: Math.random() * 6, vg: (Math.random() - .5) * .35,
        t: 5 + Math.random() * 6, cor: CORES[i % CORES.length], estrela: i % 4 === 0
      });
    }
    var inicio = performance.now();
    (function quadro(agora) {
      ctx.clearRect(0, 0, innerWidth, innerHeight);
      var vivas = 0;
      pecas.forEach(function (p) {
        p.vx *= .985; p.vy = p.vy * .985 + .32; p.x += p.vx; p.y += p.vy; p.giro += p.vg;
        if (p.y > innerHeight + 20) return;
        vivas++;
        ctx.save();
        ctx.translate(p.x, p.y);
        ctx.rotate(p.giro);
        ctx.fillStyle = p.cor;
        if (p.estrela) { ctx.scale(p.t / 14, p.t / 14); ctx.translate(-12, -12); ctx.fill(estrela); }
        else { ctx.scale(1, Math.cos(p.giro * 2)); ctx.fillRect(-p.t / 2, -p.t / 4, p.t, p.t / 2); }
        ctx.restore();
      });
      if (vivas && agora - inicio < 4000) requestAnimationFrame(quadro);
      else tela.remove();
    })(inicio);
  }

  function abrir(animar) {
    document.body.classList.toggle('revelando', !!animar && !calmo);
    if (!animar || calmo) {
      cena.classList.remove('lacrada');
      estado(true);
      return;
    }
    // O lacre estala, a aba vira e a carta sobe (a sequência está no CSS do envelope)
    var r = lacre.getBoundingClientRect();
    cena.classList.add('abrindo');
    cena.classList.remove('lacrada');
    setTimeout(function () { confete(r.left + r.width / 2, r.top + r.height / 2); }, 380);
    setTimeout(function () { embaralhar(); estado(true); }, 420);
    setTimeout(function () { cena.classList.remove('abrindo'); }, 1400);
  }

  function fechar() {
    cena.classList.add('lacrada');
    estado(false);
  }

  botao.addEventListener('click', function () {
    if (cena.classList.contains('lacrada')) abrir(true); else fechar();
  });
  cena.addEventListener('click', function () {
    if (cena.classList.contains('lacrada')) abrir(true);
  });
})();
