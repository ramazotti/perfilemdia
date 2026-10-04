(function () {
  var root = document.getElementById('portal-studio');
  if (!root) {
    return;
  }

  var chat = document.getElementById('portal-chat');
  var pollUrl = root.getAttribute('data-poll') || '';
  var actionUrl = root.getAttribute('data-action') || '';
  var csrf = root.getAttribute('data-csrf') || '';
  var statusEl = document.getElementById('portal-studio-status');
  var statusText = document.getElementById('portal-status-text');
  var alertEl = document.getElementById('portal-studio-alert');
  var formText = document.getElementById('portal-form-text');
  var formMedia = document.getElementById('portal-form-media');
  var resetBtn = document.getElementById('portal-studio-reset');
  var messageInput = document.getElementById('studio-message');

  var pollBusy = false;
  var actionBusy = false;
  var lastSig = '';
  var alertTimer = null;

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function nl2br(text) {
    return escapeHtml(text).replace(/\n/g, '<br>');
  }

  function scrollChat(force) {
    if (!chat) {
      return;
    }
    requestAnimationFrame(function () {
      chat.scrollTop = chat.scrollHeight;
      if (force && chat.scrollIntoView) {
        var nearBottom = chat.scrollHeight - chat.scrollTop - chat.clientHeight < 120;
        if (nearBottom) {
          chat.lastElementChild && chat.lastElementChild.scrollIntoView({ block: 'end', behavior: 'smooth' });
        }
      }
    });
  }

  function messageSignature(messages) {
    if (!messages || !messages.length) {
      return '0';
    }
    var last = messages[messages.length - 1];
    return messages.length + ':' + (last.type || '') + ':' + (last.text || last.caption || '') + ':' + JSON.stringify(last.buttons || []);
  }

  function renderButtons(buttons) {
    if (!buttons || !buttons.length) {
      return '';
    }
    var html = '<div class="portal-actions">';
    buttons.forEach(function (row) {
      if (!row || !row.length) {
        return;
      }
      row.forEach(function (button) {
        if (!button) {
          return;
        }
        var text = button.text || '';
        if (!text) {
          return;
        }
        if (button.url) {
          html += '<a class="btn btn-ghost btn-sm" href="' + escapeHtml(button.url) + '">' + escapeHtml(text) + '</a>';
          return;
        }
        var data = button.data || '';
        if (!data) {
          return;
        }
        html += '<button class="btn btn-ghost btn-sm" type="button" data-studio-callback="' + escapeHtml(data) + '">' + escapeHtml(text) + '</button>';
      });
    });
    html += '</div>';
    return html;
  }

  function renderMessage(message) {
    var role = message.role === 'user' ? 'portal-msg portal-msg-user' : 'portal-msg portal-msg-bot';
    var inner = '';
    var type = message.type || 'text';
    if (type === 'photo' || type === 'video') {
      var url = message.url || '';
      if (url) {
        if (type === 'video') {
          inner += '<video controls src="' + escapeHtml(url) + '"></video>';
        } else {
          inner += '<img src="' + escapeHtml(url) + '" alt="">';
        }
      }
      var cap = message.caption || '';
      if (cap) {
        inner += '<p>' + nl2br(cap) + '</p>';
      }
    } else if (type === 'album') {
      (message.urls || []).forEach(function (url) {
        if (url) {
          inner += '<img src="' + escapeHtml(url) + '" alt="">';
        }
      });
    } else {
      inner += '<p>' + nl2br(message.text || '') + '</p>';
    }
    inner += renderButtons(message.buttons);
    return '<article class="' + role + '">' + inner + '</article>';
  }

  function renderMessages(messages) {
    if (!chat) {
      return;
    }
    var sig = messageSignature(messages);
    if (sig === lastSig) {
      return;
    }
    lastSig = sig;
    chat.innerHTML = (messages || []).map(renderMessage).join('');
    scrollChat(true);
  }

  function setExpecting(state) {
    var expect = (state && state.expecting) || 'text';
    root.setAttribute('data-expecting', expect);
    root.querySelectorAll('.portal-compose-card').forEach(function (card) {
      var forKind = card.getAttribute('data-for');
      card.classList.toggle('portal-compose-card--active', forKind === 'media' ? expect === 'media' : expect !== 'media');
    });
    if (statusText && state && state.expecting_label) {
      statusText.textContent = state.expecting_label;
    }
    if (statusEl && state) {
      statusEl.setAttribute('data-busy', state.busy ? '1' : '0');
    }
  }

  function showAlert(text, isError) {
    if (!alertEl || !text) {
      return;
    }
    alertEl.textContent = text;
    alertEl.hidden = false;
    alertEl.classList.toggle('portal-studio-alert--err', !!isError);
    if (alertTimer) {
      clearTimeout(alertTimer);
    }
    alertTimer = setTimeout(function () {
      alertEl.hidden = true;
    }, isError ? 8000 : 5000);
  }

  function applyState(payload) {
    var state = payload && payload.state ? payload.state : payload;
    if (!state) {
      return;
    }
    renderMessages(state.messages || []);
    setExpecting(state);
  }

  function setLoading(loading) {
    actionBusy = loading;
    root.classList.toggle('portal-studio--loading', loading);
    root.querySelectorAll('[data-studio-submit]').forEach(function (btn) {
      btn.disabled = loading;
    });
    if (messageInput) {
      messageInput.disabled = loading;
    }
  }

  function postAction(body) {
    if (!actionUrl || actionBusy) {
      return Promise.resolve();
    }
    setLoading(true);
    return fetch(actionUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: body,
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (data && data.state) {
          applyState(data);
        }
        if (data && data.notice) {
          showAlert(data.notice, false);
        }
        if (data && data.error) {
          showAlert(data.error, true);
        }
        if (data && data.ok === false && !data.error) {
          showAlert('Não foi possível concluir.', true);
        }
      })
      .catch(function () {
        showAlert('Falha de conexão. Tente de novo.', true);
      })
      .finally(function () {
        setLoading(false);
        scrollChat(true);
      });
  }

  function poll() {
    if (!pollUrl || pollBusy || document.hidden) {
      return;
    }
    pollBusy = true;
    fetch(pollUrl, { credentials: 'same-origin' })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (data && data.state) {
          applyState(data);
        } else if (data && data.messages) {
          applyState(data);
        }
      })
      .catch(function () {})
      .finally(function () {
        pollBusy = false;
      });
  }

  if (formText) {
    formText.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var fd = new FormData(formText);
      if (!fd.get('csrf')) {
        fd.set('csrf', csrf);
      }
      var msg = String(fd.get('message') || '').trim();
      if (!msg) {
        return;
      }
      postAction(fd).then(function () {
        formText.reset();
        if (messageInput) {
          messageInput.focus();
        }
      });
    });
  }

  if (formMedia) {
    formMedia.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var fd = new FormData(formMedia);
      if (!fd.get('csrf')) {
        fd.set('csrf', csrf);
      }
      var fileInput = formMedia.querySelector('input[type="file"]');
      if (!fileInput || !fileInput.files || !fileInput.files[0]) {
        showAlert('Escolha uma foto ou vídeo antes de enviar.', true);
        return;
      }
      postAction(fd).then(function () {
        formMedia.reset();
        var nameEl = formMedia.querySelector('.portal-file-name');
        if (nameEl) {
          nameEl.textContent = 'Nenhum arquivo escolhido';
        }
      });
    });
  }

  if (resetBtn) {
    resetBtn.addEventListener('click', function () {
      var msg = resetBtn.getAttribute('data-confirm') || 'Limpar conversa?';
      if (!window.confirm(msg)) {
        return;
      }
      var fd = new FormData();
      fd.set('csrf', csrf);
      fd.set('action', 'studio_reset');
      postAction(fd);
    });
  }

  root.addEventListener('click', function (ev) {
    var target = ev.target;
    if (!target || !target.getAttribute) {
      return;
    }
    var cb = target.getAttribute('data-studio-callback');
    if (!cb) {
      return;
    }
    ev.preventDefault();
    var fd = new FormData();
    fd.set('csrf', csrf);
    fd.set('action', 'studio_cb');
    fd.set('callback', cb);
    postAction(fd);
  });

  scrollChat(true);
  poll();
  setInterval(function () {
    var busy = statusEl && statusEl.getAttribute('data-busy') === '1';
    if (busy || actionBusy || !document.hidden) {
      poll();
    }
  }, 2000);
})();
