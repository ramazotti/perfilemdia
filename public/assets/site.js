(function () {
  var btn = document.querySelector('[data-act="theme"]');
  function label() {
    if (!btn) return;
    var t = localStorage.getItem('pd_theme');
    btn.textContent = t === 'dark' ? 'Tema: escuro' : t === 'light' ? 'Tema: claro' : 'Tema: automático';
  }
  label();
  if (btn) {
    btn.addEventListener('click', function () {
      var t = localStorage.getItem('pd_theme');
      var next = t === 'light' ? 'dark' : t === 'dark' ? '' : 'light';
      if (next) {
        localStorage.setItem('pd_theme', next);
        document.documentElement.setAttribute('data-theme', next);
      } else {
        localStorage.removeItem('pd_theme');
        document.documentElement.removeAttribute('data-theme');
      }
      label();
    });
  }

  var toggle = document.querySelector('.nav-toggle');
  var nav = document.getElementById('site-nav');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  document.querySelectorAll('[data-mask="phone"]').forEach(function (input) {
    input.addEventListener('input', function () {
      var d = input.value.replace(/\D/g, '');
      if ((d.length === 12 || d.length === 13) && d.slice(0, 2) === '55') d = d.slice(2);
      d = d.slice(0, 11);
      if (d.length > 10) input.value = '(' + d.slice(0, 2) + ') ' + d.slice(2, 7) + '-' + d.slice(7);
      else if (d.length > 6) input.value = '(' + d.slice(0, 2) + ') ' + d.slice(2, 6) + '-' + d.slice(6);
      else if (d.length > 2) input.value = '(' + d.slice(0, 2) + ') ' + d.slice(2);
      else input.value = d;
    });
  });

  document.querySelectorAll('[data-mask="document"]').forEach(function (input) {
    input.addEventListener('input', function () {
      var raw = input.value.toUpperCase().replace(/[^0-9A-Z]/g, '').slice(0, 14);
      if (/[A-Z]/.test(raw) || raw.length > 11) {
        var a = raw.slice(0, 2), b = raw.slice(2, 5), c = raw.slice(5, 8), d = raw.slice(8, 12), e = raw.slice(12, 14);
        input.value = a + (b ? '.' + b : '') + (c ? '.' + c : '') + (d ? '/' + d : '') + (e ? '-' + e : '');
      } else {
        var x = raw.slice(0, 3), y = raw.slice(3, 6), z = raw.slice(6, 9), w = raw.slice(9, 11);
        input.value = x + (y ? '.' + y : '') + (z ? '.' + z : '') + (w ? '-' + w : '');
      }
    });
  });

  document.querySelectorAll('[data-mask="card"]').forEach(function (input) {
    input.addEventListener('input', function () {
      var d = input.value.replace(/\D/g, '').slice(0, 19);
      input.value = d.replace(/(\d{4})(?=\d)/g, '$1 ').trim();
      var brand = document.getElementById('brand');
      if (!brand) return;
      var name = 'Cartão';
      if (d.charAt(0) === '4') name = 'Visa';
      else if (/^3[47]/.test(d)) name = 'American Express';
      else if (/^(5[1-5]|2[2-7])/.test(d)) name = 'Mastercard';
      brand.textContent = d.length >= 2 ? name : '';
    });
  });

  document.querySelectorAll('[data-mask="expiry"]').forEach(function (input) {
    input.addEventListener('input', function () {
      var d = input.value.replace(/\D/g, '').slice(0, 4);
      input.value = d.length > 2 ? d.slice(0, 2) + '/' + d.slice(2) : d;
    });
  });

  document.querySelectorAll('[data-act="copy"]').forEach(function (button) {
    button.addEventListener('click', function () {
      var el = document.getElementById(button.getAttribute('data-target'));
      if (!el) return;
      navigator.clipboard.writeText(el.value).then(function () {
        button.textContent = 'Copiado';
      });
    });
  });

  var poll = document.querySelector('[data-act="poll"]');
  if (poll) {
    poll.addEventListener('click', function () {
      fetch(poll.getAttribute('data-status'), { headers: { 'Accept': 'application/json' } })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.status === 'pago') location.href = poll.getAttribute('data-next');
          else poll.textContent = 'Ainda não confirmado';
        });
    });
  }

  var timer = document.querySelector('[data-expires]');
  if (timer) {
    var end = new Date(String(timer.getAttribute('data-expires')).replace(' ', 'T')).getTime();
    setInterval(function () {
      var left = Math.max(0, Math.floor((end - Date.now()) / 1000));
      var m = String(Math.floor(left / 60)).padStart(2, '0');
      var s = String(left % 60).padStart(2, '0');
      timer.textContent = left === 0 ? 'Pix expirado' : 'Expira em ' + m + ':' + s;
    }, 1000);
  }

  document.querySelectorAll('.field input, .field textarea').forEach(function (input) {
    input.addEventListener('input', function () {
      var field = input.closest('.field');
      if (field) field.classList.remove('bad');
    });
  });

  var phoneEl = document.querySelector('.phone');
  var shots = phoneEl ? (phoneEl.getAttribute('data-shots') || '').split('|').filter(Boolean) : [];
  var scenes = [
    {
      shot: 0,
      theme: 'Fechando a vers\u00e3o nova do guia, na mesa, caneta na m\u00e3o.',
      caption: 'A vers\u00e3o nova do guia est\u00e1 aberta na mesa. Caneta na m\u00e3o, p\u00e1gina por p\u00e1gina, para quem come\u00e7a hoje achar o caminho sem voltar atr\u00e1s.',
      tags: '#guia #bastidores'
    },
    {
      shot: 1,
      theme: 'Montando o kit na bancada: as caixas pequenas entram na caixa grande.',
      caption: 'O kit est\u00e1 sendo montado agora. Na bancada de madeira, cada caixa pequena entra na caixa grande, e este estoque sai hoje.',
      tags: '#kit #estoque',
      revise: {
        caption: 'M\u00e3os na caixa, pe\u00e7a por pe\u00e7a. O kit novo fecha na bancada e j\u00e1 pode sair para quem est\u00e1 esperando.',
        tags: '#kit #bastidores'
      }
    },
    {
      shot: 2,
      theme: 'Instalando o arm\u00e1rio da cozinha, furadeira na m\u00e3o e a caixa de ferramentas aberta no ch\u00e3o.',
      caption: 'Arm\u00e1rio da cozinha em instala\u00e7\u00e3o. Furadeira na m\u00e3o, caixa de ferramentas aberta no ch\u00e3o, e o furo vai exatamente onde o arm\u00e1rio pede.',
      tags: '#cozinha #instalacao'
    },
    {
      shot: 3,
      theme: 'Subindo as canecas novas na estante da loja, uma por uma.',
      caption: 'As canecas novas est\u00e3o subindo para a estante. Uma a uma, na madeira, do cinza ao bege, antes de abrir a loja.',
      tags: '#ceramica #loja',
      revise: {
        caption: 'M\u00e3os na estante, caneca por caneca. A fornada nova j\u00e1 ficou exposta e pronta para quem entrar na loja.',
        tags: '#ceramica #novidade'
      }
    }
  ];
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var start = scenes.length && !reduceMotion ? Math.floor(Math.random() * scenes.length) : 0;

  function shotSrc(scene) {
    if (!shots.length) return '';
    return shots[scene.shot] || shots[0];
  }

  function setPhoto(holder, src) {
    if (!holder || !src) return;
    var img = holder.querySelector('img');
    if (!img) {
      img = document.createElement('img');
      img.alt = '';
      holder.textContent = '';
      holder.appendChild(img);
    }
    img.src = src;
  }

  function fillCaption(node, text, tags) {
    node.textContent = '';
    node.appendChild(document.createTextNode(text));
    var tag = document.createElement('div');
    tag.className = 'tag';
    tag.textContent = tags;
    node.appendChild(tag);
  }

  var demo = document.querySelector('.phone .chat');
  if (demo && scenes.length) {
    var opening = scenes[start];
    setPhoto(demo.querySelector('.ph'), shotSrc(opening));
    var themeEl = demo.querySelector('.bub.me .t');
    if (themeEl) themeEl.textContent = opening.theme;
    var bots = demo.querySelectorAll('.bub.bot');
    if (bots[1]) fillCaption(bots[1], opening.caption, opening.tags);
  }

  if (demo && !reduceMotion) {
    var ask = 'Recebi a foto. Em uma frase, o que \u00e9 isso?';

    function wait(ms) {
      return new Promise(function (resolve) { window.setTimeout(resolve, ms); });
    }

    function scroll() {
      demo.scrollTop = demo.scrollHeight;
    }

    function reveal(node) {
      demo.appendChild(node);
      window.requestAnimationFrame(function () {
        node.classList.add('on');
        scroll();
      });
      return node;
    }

    function bot(text) {
      var node = document.createElement('div');
      node.className = 'bub bot';
      node.textContent = text;
      return node;
    }

    function me(text) {
      var node = document.createElement('div');
      node.className = 'bub me';
      var inner = document.createElement('div');
      inner.className = 't';
      inner.textContent = text;
      node.appendChild(inner);
      return node;
    }

    function photo(scene) {
      var node = document.createElement('div');
      node.className = 'bub me';
      var inner = document.createElement('div');
      inner.className = 'ph';
      setPhoto(inner, shotSrc(scene));
      node.appendChild(inner);
      return node;
    }

    function caption(text, tags) {
      var node = document.createElement('div');
      node.className = 'bub bot';
      node.appendChild(document.createTextNode(text));
      var tag = document.createElement('div');
      tag.className = 'tag';
      tag.textContent = tags;
      node.appendChild(tag);
      return node;
    }

    function keys() {
      var node = document.createElement('div');
      node.className = 'keys';
      ['Ajustar', 'Outra vers\u00e3o', 'Publicar'].forEach(function (label, index) {
        var span = document.createElement('span');
        span.textContent = label;
        if (index === 1) span.setAttribute('data-k', 'revise');
        if (index === 2) {
          span.className = 'p';
          span.setAttribute('data-k', 'go');
        }
        node.appendChild(span);
      });
      return node;
    }

    function press(span) {
      if (span) span.classList.add('is-down');
    }

    async function finishPublish(keyRow) {
      await wait(2400);
      press(keyRow.querySelector('[data-k="go"]'));
      await wait(700);
      reveal(bot('Publicando...'));
      await wait(1700);
      reveal(bot('T\u00e1 postado!'));
      await wait(5600);
    }

    async function play(scene) {
      demo.innerHTML = '';
      await wait(420);
      demo.classList.remove('is-out');
      await wait(280);
      reveal(photo(scene));
      await wait(1200);
      reveal(bot(ask));
      await wait(1600);
      reveal(me(scene.theme));
      await wait(1800);
      var cap = reveal(caption(scene.caption, scene.tags));
      await wait(1100);
      var keyRow = reveal(keys());
      await wait(2400);
      if (scene.revise) {
        var revise = keyRow.querySelector('[data-k="revise"]');
        press(revise);
        await wait(650);
        if (revise) revise.classList.remove('is-down');
        cap.textContent = '';
        cap.appendChild(document.createTextNode(scene.revise.caption));
        var tag = document.createElement('div');
        tag.className = 'tag';
        tag.textContent = scene.revise.tags;
        cap.appendChild(tag);
        cap.classList.remove('on');
        void cap.offsetWidth;
        cap.classList.add('on');
        await wait(2000);
      }
      await finishPublish(keyRow);
      demo.classList.add('is-out');
      await wait(500);
    }

    async function run() {
      await wait(5600);
      if (!demo.isConnected) return;
      demo.classList.add('live');
      demo.classList.remove('seq');
      Array.prototype.forEach.call(demo.children, function (node) {
        node.classList.add('on');
      });
      scroll();
      var keyRow = demo.querySelector('.keys');
      if (keyRow) {
        var go = keyRow.querySelector('.p');
        if (go) go.setAttribute('data-k', 'go');
        await finishPublish(keyRow);
      }
      demo.classList.add('is-out');
      await wait(500);
      var n = start + 1;
      while (demo.isConnected) {
        await play(scenes[n % scenes.length]);
        n += 1;
      }
    }

    run();
  }
})();

(function () {
  var bar = document.querySelector('[data-cookie]');
  if (!bar) return;
  var key = 'pd_cookies';
  var choice = '';
  try { choice = localStorage.getItem(key) || ''; } catch (e) {}

  function loadAnalytics() {
    var id = bar.getAttribute('data-ga') || '';
    if (!/^G-[A-Z0-9]+$/.test(id) || window.__pdGa) return;
    window.__pdGa = true;
    window.dataLayer = window.dataLayer || [];
    window.gtag = function () { window.dataLayer.push(arguments); };
    window.gtag('js', new Date());
    window.gtag('config', id);
    var script = document.createElement('script');
    script.async = true;
    script.src = 'https://www.googletagmanager.com/gtag/js?id=' + id;
    document.head.appendChild(script);
  }

  function placeHelp() {
    if (bar.hidden) {
      document.documentElement.style.removeProperty('--tg-lift');
      return;
    }
    document.documentElement.style.setProperty('--tg-lift', (bar.offsetHeight + 28) + 'px');
  }

  if (choice === 'aceito') {
    loadAnalytics();
    return;
  }
  if (choice === 'recusado') return;
  bar.hidden = false;
  document.body.classList.add('cookie-open');
  placeHelp();
  window.addEventListener('resize', placeHelp);
  bar.addEventListener('click', function (event) {
    var button = event.target.closest('[data-choice]');
    if (!button) return;
    var value = button.getAttribute('data-choice') || '';
    if (value !== 'aceito' && value !== 'recusado') return;
    try { localStorage.setItem(key, value); } catch (e) {}
    bar.hidden = true;
    document.body.classList.remove('cookie-open');
    placeHelp();
    if (value === 'aceito') loadAnalytics();
  });
})();

(function () {
  var opener = document.querySelector('.tg-help');
  var dialog = document.querySelector('[data-tg-dialog]');
  if (!opener || !dialog) return;

  function setOpen(open) {
    dialog.hidden = !open;
    opener.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
      var first = dialog.querySelector('a');
      if (first) first.focus();
      return;
    }
    opener.focus();
  }

  opener.addEventListener('click', function () {
    setOpen(dialog.hidden);
  });
  dialog.addEventListener('click', function (event) {
    if (event.target === dialog || event.target.closest('[data-tg-close]')) setOpen(false);
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !dialog.hidden) setOpen(false);
  });
})();

(function () {
  var root = document.getElementById('profissoes');
  if (!root) return;
  var track = root.querySelector('.jobs');
  var prev = root.querySelector('[data-job="prev"]');
  var next = root.querySelector('[data-job="next"]');
  if (!track || !prev || !next) return;
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var timer = 0;
  var paused = false;

  function stepSize() {
    var cards = track.children;
    if (cards.length < 2) return cards[0] ? cards[0].getBoundingClientRect().width : 0;
    return cards[1].offsetLeft - cards[0].offsetLeft;
  }

  function maxIndex() {
    var size = stepSize();
    var count = track.children.length;
    if (!size || count < 2) return 0;
    return Math.max(0, count - Math.max(1, Math.round(track.clientWidth / size)));
  }

  function index() {
    var size = stepSize();
    if (!size) return 0;
    return Math.round(track.scrollLeft / size);
  }

  function go(to) {
    var size = stepSize();
    var last = maxIndex();
    if (!size) return;
    if (to > last) to = 0;
    if (to < 0) to = last;
    track.scrollTo({ left: to * size, behavior: reduce ? 'auto' : 'smooth' });
  }

  function tick() {
    if (paused || document.hidden) return;
    go(index() + 1);
  }

  function arm() {
    window.clearInterval(timer);
    if (reduce || maxIndex() === 0) return;
    timer = window.setInterval(tick, 4500);
  }

  prev.addEventListener('click', function () { go(index() - 1); arm(); });
  next.addEventListener('click', function () { go(index() + 1); arm(); });
  root.addEventListener('mouseenter', function () { paused = true; });
  root.addEventListener('mouseleave', function () { paused = false; });
  root.addEventListener('focusin', function () { paused = true; });
  root.addEventListener('focusout', function (event) {
    if (!root.contains(event.relatedTarget)) paused = false;
  });
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) window.clearInterval(timer);
    else arm();
  });
  window.addEventListener('resize', arm);
  arm();
})();
