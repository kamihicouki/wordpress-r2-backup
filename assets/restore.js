(() => {
  const root = document.getElementById('wrb-restore');
  if (!root) { return; }
  const form = root.querySelector('#wrb-r-form');
  const q = selector => root.querySelector(selector);
  const message = text => { q('[data-message]').textContent = text || ''; };
  let plan = null, job = null, poll = null, context = null, current = null, r2Connection = '', sourceRevision = 0;
  let formRevision = 0;
  const urlLabels = { home: 'サイトアドレス (URL)', siteurl: 'WordPress アドレス (URL)' };

  const element = (tag, className, text) => { const node = document.createElement(tag); if (className) { node.className = className; } if (text !== undefined) { node.textContent = text; } return node; };

  async function call(command, fields, signal) {
    const body = new FormData();
    if (fields) { for (const [name, value] of fields) { body.append(name, value); } }
    if (r2Connection && ['snapshots', 'preflight', 'start'].includes(command)) { body.append('r2Connection', r2Connection); }
    if (root.dataset.secureTransport === '0') {
      const entered = [...form.querySelectorAll('[data-secret][name]')].find(input => body.get(input.name));
      const field = body.has('r2Connection') ? 'r2Connection' : entered && entered.name;
      if (field) {
        const failure = new Error('秘密情報を送るにはHTTPSが必要です。');
        failure.field = field;
        throw failure;
      }
    }
    body.append('command', command);
    body.append(root.dataset.tokenName, root.dataset.token);
    for (const [name, value] of Object.entries(root.dataset)) { if (name.startsWith('extra')) { body.append(name.slice(5).toLowerCase(), value); } }
    const response = await fetch(root.dataset.endpoint, { method: 'POST', credentials: 'same-origin', body, cache: 'no-store', signal });
    let data = null;
    try { data = await response.json(); } catch (error) { throw new Error('サーバーの応答を読み取れません。画面を開き直してください。'); }
    const payload = data.data !== undefined ? data.data : data;
    if (!response.ok || data.success === false) { const failure = new Error(payload.error || '処理を完了できませんでした。'); failure.field = payload.field; throw failure; }
    return payload;
  }

  function clearErrors() {
    root.querySelectorAll('[data-error-for]').forEach(node => { node.textContent = ''; });
    root.querySelectorAll('.wrb-r-invalid').forEach(node => { node.classList.remove('wrb-r-invalid'); node.removeAttribute('aria-invalid'); });
  }

  function focusField(field) {
    const input = form.elements[field];
    if (!input || !input.classList) { return; }
    const details = input.closest('details'); if (details) { details.open = true; }
    input.focus();
  }

  function showError(field, text, focus = true) {
    if (!field) { return; }
    const slot = root.querySelector('[data-error-for="' + field + '"]');
    if (slot && text) { slot.textContent = text; }
    const input = form.elements[field];
    if (input && input.classList) {
      input.classList.add('wrb-r-invalid'); input.setAttribute('aria-invalid', 'true');
      const details = input.closest('details'); if (details) { details.open = true; }
      if (focus) { input.focus(); }
    }
  }

  function applyVisibility() {
    root.querySelectorAll('[data-show-when]').forEach(block => {
      const [name, value] = block.dataset.showWhen.split('=');
      const checked = form.querySelector('input[name="' + name + '"]:checked');
      const visible = checked && checked.value === value;
      block.hidden = !visible;
      block.querySelectorAll('input,select,textarea').forEach(input => { input.disabled = !visible; });
    });
    const publication = q('[data-publication-urls]');
    if (publication) {
      const mode = form.querySelector('input[name="urlMode"]:checked');
      publication.hidden = !mode || mode.value === 'keep';
      publication.querySelectorAll('input').forEach(input => { input.disabled = publication.hidden; });
    }
    const set = context && context.connections.find(item => item.id === form.elements.connection.value);
    const ready = !!(set && set.accountsRegistered && set.database);
    q('[data-connection-detail]').textContent = set ? (ready ? 'データベース ' + set.database + ' へ復元します。アカウントは準備済みです。' : '下でデータベース名とアカウントを入力してください。') : '';
    q('[data-connection-empty]').hidden = !context || context.connections.length > 0;
    const manual = q('[data-manual-accounts]');
    if (manual) { manual.hidden = ready || !set; manual.open = !!set && !ready; }
    const admin = q('[data-preview-admin]');
    if (admin) {
      const enabled = form.elements.createPreviewAdmin.checked;
      admin.hidden = !enabled;
      admin.querySelectorAll('input,button').forEach(control => { control.disabled = !enabled; });
      if (!enabled) { form.elements.adminPassword.value = ''; }
    }
    const address = q('[data-preview-address]');
    const unavailable = q('[data-preview-unavailable]');
    if (address && unavailable && context && context.startup) {
      address.hidden = !context.startup.available;
      unavailable.hidden = context.startup.available;
    }
    const port = form.elements.previewPort.value;
    q('[data-preview-url]').textContent = 'http://127.0.0.1:' + (port || '…');
    updateNext();
  }

  function invalidate() {
    if (plan === null) { return; }
    plan = null; q('[data-confirm]').hidden = true; q('[data-checks]').replaceChildren();
    form.elements.confirmName.value = '';
    message('内容を変更しました。もう一度「内容を検査」を押してください。');
  }

  function fields() { const data = new FormData(form); data.delete('confirmName'); data.delete('reauthPassword'); data.delete('r2SourceMode'); return data; }

  function clearSecrets() {
    root.querySelectorAll('[data-secret]').forEach(input => { input.value = ''; });
    if (r2Connection) { resetSnapshots('一覧を取得してください'); sourceStatus.textContent = ''; }
    r2Connection = ''; r2File.value = '';
    if (form.elements.reauthPassword) { form.elements.reauthPassword.value = ''; }
  }

  const stateText = { ok: '問題なし', failed: '要修正', warning: '注意', verified: '完了', pending: '待機中', running: '実行中' };

  function keyHint() {
    const value = form.elements.identity.value.trim();
    const slot = root.querySelector('[data-error-for="identity"]');
    if (value === '') { slot.textContent = ''; return; }
    if (value.startsWith('age1')) { slot.textContent = 'これは公開鍵です。AGE-SECRET-KEY-1 で始まる秘密鍵を入れてください。'; return; }
    slot.textContent = value.includes('AGE-SECRET-KEY-1') ? '' : 'AGE-SECRET-KEY-1 で始まる行が見つかりません。';
  }

  function updateNext() {
    const next = q('[data-next]');
    const set = text => { next.replaceChildren(element('strong', '', '次にすること'), element('span', '', text)); };
    const state = current ? current.state : 'none';
    if (['queued', 'running'].includes(state)) { set('完了までお待ちください。この画面を閉じても処理は続きます。'); return; }
    const choosingAnother = !!root.dataset.formRequested && !form.hidden;
    if (state === 'complete' && !choosingAnother) {
      const site = current.site || {};
      if (site.state === 'unavailable') { set('この環境では確認用サイトを起動できません。下の案内を確認してください。'); return; }
      if (site.openable) { set(site.adminLogin ? '確認用サイトを開き、' + site.adminLogin + ' でログインして確認してください。' : '確認用サイトを開いて動作を確認してください。'); return; }
      if (['running', 'starting'].includes(site.state)) { set(site.state === 'starting' ? '確認用サイトの起動をお待ちください。' : '下の案内に沿って確認用サイトを開いてください。'); return; }
      if (site.state === 'failed') { set('確認用サイトをもう一度起動してください。'); return; }
      set('「確認用サイトを起動」を押してください。'); return;
    }
    if (q('[data-form-closed]') && !choosingAnother) { set('結果を確認してください。別の復元は「別の復元を始める」から行えます。'); return; }
    if (state === 'startup-failed' && !choosingAnother) { set('下の案内を確認し、確認用サイトの準備だけをやり直してください。'); return; }
    if (form.elements.snapshot.disabled) { set('R2接続情報の入力方法を選び、バックアップ一覧を取得してください。'); return; }
    if (form.elements.snapshot.value === '') { set('復元するバックアップを選んでください。'); return; }
    if (form.elements.identity.value.trim() === '') { set('秘密鍵を入れてください。'); return; }
    if (!form.elements.adminPassword.disabled && form.elements.adminPassword.value.length < 12) { set('確認用ログインのパスワードを決めてください。'); return; }
    if (plan === null) { set('「内容を検査」を押してください。'); return; }
    set('内容を確かめ、復元の名前を入力して開始してください。');
  }

  async function preflight(button) {
    if (snapshot.disabled) { showSourceError('先にバックアップ一覧を取得してください。', false); loadButton.focus(); return; }
    const revision = sourceRevision, inputs = formRevision;
    clearErrors(); keyHint(); message('検査しています…'); button.disabled = true; plan = null; q('[data-confirm]').hidden = true;
    try {
      const result = await call('preflight', fields());
      if (revision !== sourceRevision) { return; }
      if (inputs !== formRevision) { message('検査中に内容を変更しました。もう一度「内容を検査」を押してください。'); return; }
      const area = q('[data-checks]'); area.replaceChildren();
      const failed = result.checks.filter(check => check.state === 'failed');
      const attention = result.checks.filter(check => check.state !== 'ok');
      const list = element('ul', 'wrb-r-checks');
      for (const check of attention) { list.append(checkRow(check)); }
      if (attention.length) { area.append(list); }
      const passed = result.checks.filter(check => check.state === 'ok');
      if (passed.length) {
        const details = element('details'); details.append(element('summary', '', passed.length + '項目は問題ありません'));
        const okList = element('ul', 'wrb-r-checks'); for (const check of passed) { okList.append(checkRow(check)); }
        details.append(okList); area.append(details);
      }
      failed.forEach((check, index) => { if (check.field) { showError(check.field, check.message, index === 0); } });
      if (result.ok) {
        const mode = form.querySelector('input[name="urlMode"]:checked');
        if (!mode || result.summary?.urlMode !== mode.value) {
          message('復元画面とサーバーの設定形式が一致しません。ページを開き直して、もう一度検査してください。'); return;
        }
        showSummary(result.summary, result.startup); plan = result.planHash; message('確認の期限は15分です。');
      }
      else { message('「要修正」の項目を直して、もう一度検査してください。'); }
    } catch (error) { if (revision === sourceRevision && inputs === formRevision) { message(error.message); showError(error.field, error.message); } }
    finally { button.disabled = false; updateNext(); }
  }

  function checkRow(check) {
    const row = element('li');
    row.append(element('span', 'wrb-r-state wrb-r-state-' + check.state, stateText[check.state] || check.state));
    const detail = element('span'); detail.append(element('strong', '', check.label));
    if (check.message) { detail.append(element('span', 'wrb-r-help', check.message)); }
    if (check.state === 'failed' && check.field && form.elements[check.field]) { detail.append(actionButton('この項目を直す', 'wrb-r-link', () => focusField(check.field))); }
    row.append(detail);
    return row;
  }

  function showSummary(summary, startup) {
    const list = q('[data-summary]'); list.replaceChildren();
    const add = (term, value) => { list.append(element('dt', '', term)); list.append(element('dd', '', value)); };
    add('バックアップに記録されたサイトのURL', summary.sourceUrl);
    const captured = summary.capturedAt ? new Date(summary.capturedAt) : null;
    add('バックアップ', (captured && !Number.isNaN(captured.getTime()) ? captured.toLocaleString('ja-JP') + 'に取得' : '取得日時は不明') + '（' + summary.snapshot.slice(0, 8) + '…）');
    if (summary.consistency && summary.consistency.mode === 'online') {
      add('取得時の注意', '保守なしで取得しています。データベースとファイルは異なる時点の状態である可能性があります。');
      add('データベースの固定時刻', new Date(summary.consistency.snapshotAt).toLocaleString('ja-JP'));
      add('ファイルの最終確認時刻', new Date(summary.consistency.filesVerifiedAt).toLocaleString('ja-JP'));
    }
    add('件数', summary.counts.files + 'ファイル／' + summary.counts.tables + '表');
    add('メディア', summary.purpose === 'full' ? 'すべて配置する' : '中身を検証するだけ（配置しない）');
    add('保存先（新規）', summary.destination);
    add('データベース', summary.database.mode);
    add('元データ', summary.database.original);
    add('確認用コピー', summary.database.running);
    add('変更前の設定', '元のデータベースの2つの値は、原本の照合後に確認します。wp-config.php がバックアップの対象外の場合、元の設定ファイルの値は確認できません。');
    addPublicationUrls(add, summary.urlMode, summary.publicationUrls);
    if (summary.urlMode === 'database') {
      if (summary.aliases && summary.aliases.length) { add('置換対象に加える旧URL', summary.aliases.join('、')); }
      add('自動変更の条件', '原本の設定がバックアップの記録と一致しない場合や、設定ファイルの追加読み込みがある場合は、書き換えずに停止します。');
    }
    if (startup.available) { add('動作確認用のURL', summary.previewUrl + '（確認用WordPressの起動中のみ）'); }
    add('確認用管理者', summary.adminLogin || '作成しない');
    if (!startup.available) { add('確認用サイト', startup.reason); }
    const name = summary.name;
    const label = form.querySelector('label[for="wrb-r-confirmName"]');
    label.textContent = '確認のため「' + name + '」と入力';
    q('[data-confirm]').hidden = false;
    form.elements.confirmName.value = '';
    form.elements.confirmName.focus();
  }

  function addPublicationUrls(add, mode, urls) {
    if (!urls || mode === 'keep') { add('運用用のURL', 'バックアップの設定を引き継ぐ'); return; }
    for (const [key, label] of Object.entries(urlLabels)) {
      const effect = mode === 'config' ? '（移行先の設定値。データベースは維持）' : '（運用向けデータベースに保存）';
      add(label + '：変更後', urls[key] + effect);
    }
    add('本文などのURL', mode === 'config' ? '変更しない' : '対象の旧URLを新しいサイトアドレスに置換');
    if (mode === 'config' && urls.home !== urls.siteurl) { add('本体が別のパスにある場合', 'URL設定だけでは動作しません。移行先でWordPress本体を指定の場所に配置し、サイト入口の index.php からその本体を読み込むように設定してください。'); }
    add('WordPressで優先される設定', 'wp-config.php に WP_HOME / WP_SITEURL が定義されていると、データベースより優先されます。');
  }

  function appendConfigGuidance(panel, urls) {
    const details = element('details', 'wrb-r-url-help');
    const quote = value => "'" + value.replace(/['\\]/g, '\\$&') + "'";
    details.append(element('summary', '', '移行先の wp-config.php に設定する値'));
    details.append(element('p', 'wrb-r-help', '移行先で既存の定義があれば置き換え、なければ WordPress を読み込む行より前に追加します。公開するパスに WordPress 本体を配置し、HTTPS とサーバーの経路を確認してください。本文の旧URLは別途確認してください。'));
    if (urls.home !== urls.siteurl) {
      const link = element('a', '', 'WordPress公式：本体をサブディレクトリに置く手順');
      link.href = 'https://developer.wordpress.org/advanced-administration/server/wordpress-in-directory/';
      link.target = '_blank'; link.rel = 'noopener noreferrer'; details.append(link);
    }
    const code = element('pre');
    code.append(element('code', '', "define('WP_HOME', " + quote(urls.home) + ");\ndefine('WP_SITEURL', " + quote(urls.siteurl) + ');'));
    details.append(code); panel.append(details);
  }

  async function start(button) {
    if (plan === null) { message('先に「内容を検査」を押してください。'); return; }
    clearErrors(); message('開始しています…'); button.disabled = true;
    const data = fields(); data.append('planHash', plan); data.append('confirmName', form.elements.confirmName.value);
    if (form.elements.reauthPassword) { data.append('reauthPassword', form.elements.reauthPassword.value); }
    try {
      const status = await call('start', data);
      clearSecrets();
      delete root.dataset.formRequested;
      plan = null; q('[data-confirm]').hidden = true; q('[data-checks]').replaceChildren();
      message(''); renderStatus(status); schedule();
      q('[data-status]').scrollIntoView({ block: 'nearest' });
    } catch (error) { message(error.message); showError(error.field, error.message); }
    finally { button.disabled = false; }
  }

  function setFormOpen(open) {
    form.hidden = !open;
    root.classList.toggle('wrb-r-running', !open);
    const old = q('[data-form-closed]');
    if (old) { old.remove(); }
  }

  function renderProgress(panel, status, active) {
    const end = active ? Math.floor(Date.now() / 1000) : status.updatedAt;
    const elapsed = Number.isFinite(end) && Number.isFinite(status.startedAt)
      ? Math.max(0, end - status.startedAt) : null;
    const phase = element('p', 'wrb-r-progress-phase', (status.phaseLabel || '処理状況') +
      (elapsed === null ? '' : '（経過 ' + Math.floor(elapsed / 60) + '分' + (elapsed % 60) + '秒）'));
    phase.id = 'wrb-r-current-phase';
    panel.append(phase);
    if (!active) { return; }

    const progress = status.progress || {};
    const labels = { packs: ['取得して照合したパック', '個'], files: ['内容を照合したファイル', '件'], tables: ['照合済みのテーブル', '表'] };
    const metric = labels[progress.unit];
    const bar = element('progress', 'wrb-r-progress');
    bar.setAttribute('aria-labelledby', phase.id);
    bar.setAttribute('aria-describedby', 'wrb-r-progress-detail');
    let text = status.state === 'queued' ? '開始を待っています。' : '処理中です。';
    if (metric && Number.isSafeInteger(progress.done) && Number.isSafeInteger(progress.total) && progress.total > 0 && progress.done >= 0 && progress.done <= progress.total) {
      bar.max = progress.total;
      bar.value = progress.done;
      text = metric[0] + ' ' + progress.done.toLocaleString() + ' / ' + progress.total.toLocaleString() + ' ' + metric[1];
    } else if (progress.unit === 'statements' && Number.isSafeInteger(progress.done) && progress.done >= 0) {
      text = 'SQL文を ' + progress.done.toLocaleString() + ' 件処理';
    }
    const detail = element('p', 'wrb-r-help wrb-r-progress-detail', text);
    detail.id = 'wrb-r-progress-detail';
    panel.append(bar, detail);
  }

  function previewLink(url, label, className) {
    const link = element('a', className, label);
    link.href = url;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    return link;
  }

  function renderAddresses(panel, status, site, active) {
    const publication = site.publication || status.startup?.publicationUrls || {};
    const home = publication.home || site.targetUrl;
    const prepared = status.startup?.state === 'prepared';
    const address = site.previewUrl || status.startup?.previewUrl;
    const canOpen = site.openable === true && site.state === 'running' && site.response === 'responding'
      && typeof address === 'string' && /^http:\/\/127\.0\.0\.1:[0-9]{4,5}$/.test(address);
    const section = element('section', 'wrb-r-access');
    section.append(element('h3', 'wrb-r-subhead', '復元先と確認用のURL'));
    const list = element('dl', 'wrb-r-summary');
    const add = (label, value, detail) => {
      const cell = element('dd');
      cell.append(typeof value === 'string' ? element('span', '', value) : value, element('span', 'wrb-r-help', detail));
      list.append(element('dt', '', label), cell);
    };
    const addressState = publication.mode === 'config' ? '設定案 · 移行先で適用してください'
      : (prepared ? '復元先の設定に反映済み · 公開状況は未確認' : (active ? '予定 · 復元後に適用' : '適用を確認できません'));
    if (home) {
      add('サイトアドレス (URL)', home, addressState);
      if (publication.siteurl && publication.siteurl !== home) { add('WordPress アドレス (URL)', publication.siteurl, addressState); }
    } else {
      add('サイトアドレス (URL)', publication.recordedHome || 'バックアップの設定を引き継ぐ', publication.recordedHome ? 'バックアップの記録値 · 元の設定を維持' : 'バックアップのURL設定を引き継ぎます');
    }
    if (address) {
      let check = '予定 · 復元後に起動';
      if (site.state === 'unavailable' || site.state === 'failed' || (!active && !prepared)) { check = '利用不可'; }
      else if (canOpen) { check = '利用可能 · 確認用サイトの応答を確認'; }
      else if (site.response === 'responding') { check = '利用不可 · この端末からの接続は未確認'; }
      else if (site.state === 'starting' || site.state === 'running') { check = '起動中または接続待ち'; }
      else if (prepared) { check = '未起動 · 下のボタンから起動'; }
      add('確認用URL', canOpen ? previewLink(address, address, 'wrb-r-preview-link') : address, check);
    }
    section.append(list); panel.append(section);
    return canOpen;
  }

  function renderStatus(status) {
    current = status && status.state !== 'none' ? status : null;
    const panel = q('[data-status]');
    // Progress redraws every few seconds; a keyboard user must stay on the control they reached.
    const focused = panel.contains(document.activeElement) ? document.activeElement.textContent : null;
    panel.replaceChildren();
    if (!current) { job = null; setFormOpen(true); updateNext(); return; }
    job = status.name;
    const active = ['queued', 'running'].includes(status.state);
    const site = status.site || {};
    const labels = { queued: '開始を待っています', running: '復元しています', complete: '復元と照合が完了しました', failed: '復元できませんでした', 'startup-failed': '元データの復元は完了しましたが、確認用サイトを準備できませんでした', interrupted: '途中で中断しました', stopped: '依頼により停止しました' };
    panel.append(element('p', 'wrb-r-headline' + (status.state === 'complete' ? ' wrb-r-complete' : '') + (['failed', 'startup-failed', 'interrupted'].includes(status.state) ? ' wrb-r-bad' : ''), labels[status.state] || status.state));
    panel.append(element('p', 'wrb-r-help', '復元の名前：' + status.name));
    const captured = status.capturedAt ? new Date(status.capturedAt) : null;
    if (captured && !Number.isNaN(captured.getTime())) {
      panel.append(element('p', 'wrb-r-help', 'バックアップ取得日時：' + captured.toLocaleString('ja-JP')));
    }
    renderProgress(panel, status, active);
    const canOpen = renderAddresses(panel, status, site, active);
    if (status.message) { panel.append(element('p', 'wrb-r-note', status.message)); }
    const results = element('ul', 'wrb-r-results');
    for (const item of Object.values(status.results || {})) {
      const row = element('li');
      row.append(element('span', 'wrb-r-state wrb-r-state-' + item.state, stateText[item.state] || item.state));
      let detail = item.label;
      if (typeof item.files === 'number') { detail += '（' + item.files.toLocaleString() + '件' + (item.placed === 0 ? '・配置0件' : '') + '）'; }
      row.append(element('span', '', detail)); results.append(row);
    }
    panel.append(results);
    const actions = element('div', 'wrb-r-actions');
    if (active) { actions.append(actionButton('処理を停止', 'wrb-r-danger', () => act('stop'))); }
    if (status.restoredUrls) {
      const original = element('details', 'wrb-r-url-help');
      original.append(element('summary', '', '復元原本で確認したURL設定'));
      const list = element('dl', 'wrb-r-summary');
      for (const [key, label] of Object.entries(urlLabels)) {
        list.append(element('dt', '', label + '：元のデータベース'));
        list.append(element('dd', '', status.restoredUrls.database[key] || '確認できませんでした'));
        list.append(element('dt', '', key === 'home' ? 'WP_HOME' : 'WP_SITEURL'));
        const configNote = status.restoredUrls.configuration[key] ? '元の設定ファイルに記述あり。有効な定義ならデータベースの値より優先されます。実際に使われた値は未確認です。'
          : (status.restoredUrls.configurationPresent === false ? 'バックアップに設定ファイルがないため、未確認です。'
            : (status.restoredUrls.configurationIncomplete ? '設定ファイルの一部を確認できないため、未確認です。' : '復元した設定ファイルには記述を確認できませんでした。'));
        list.append(element('dd', '', configNote));
      }
      original.append(list); panel.append(original);
    }
    if (status.startup && status.startup.state === 'prepared') {
      const urls = element('dl', 'wrb-r-summary');
      const add = (term, value) => { urls.append(element('dt', '', term)); urls.append(element('dd', '', value)); };
      if (status.startup.migrationDatabase) { add('運用へ移すDB（確認用管理者を含まない）', status.startup.migrationDatabase); }
      const siteLabels = { 'not-started': '未起動', starting: '起動中', running: site.response === 'responding' ? '動作中（応答あり）' : '動作中（応答を確認中）', stopped: '停止中', failed: '起動できませんでした', unavailable: 'この環境では起動できません' };
      add('確認用サイト', siteLabels[site.state] || site.state);
      if (site.adminLogin && site.state !== 'unavailable') { add('確認用ログイン', site.adminLogin); }
      panel.append(urls);
      if (status.startup.urlMode === 'config' && status.startup.publicationUrls) { appendConfigGuidance(panel, status.startup.publicationUrls); }
      if (status.startup.previewLinksMayPointToSource && site.state !== 'unavailable') { panel.append(element('p', 'wrb-r-note wrb-r-warn', '元のURLが複数あるか確認できない設定があります。確認用ページに元のサイトへのリンクが残る場合があります。動作確認では外部リンクを開かないでください。')); }
      if (status.startup.hasBackedUpUsers === false) { panel.append(element('p', 'wrb-r-note wrb-r-warn', 'この世代にWordPressユーザーが含まれていません。運用を始める前に移行先で管理者を用意してください。')); }
      if (status.startup.notCarriedOver && status.startup.notCarriedOver.length) { panel.append(element('p', 'wrb-r-note wrb-r-warn', '元の設定の ' + status.startup.notCarriedOver.join('、') + ' は引き継いでいません。独自の配置先は自動で対応しません。')); }
      if (site.state !== 'unavailable') {
        if (['not-started', 'stopped', 'failed'].includes(site.state)) { actions.append(actionButton('確認用サイトを起動（外部通信なし）', 'wrb-r-primary', () => act('site_start'))); }
        if (['running', 'starting'].includes(site.state)) { actions.append(actionButton('確認用サイトを停止', 'wrb-r-danger', () => act('site_stop'))); }
      }
      if (canOpen) {
        const links = [previewLink(site.previewUrl + '/', '確認用サイトを開く', 'wrb-r-button wrb-r-primary')];
        if (site.adminOpenable) { links.push(previewLink(site.previewUrl + '/wp-admin/', '管理画面を開く', 'wrb-r-button')); }
        actions.prepend(...links);
      }
    }
    if (site.reason) { panel.append(element('p', 'wrb-r-note wrb-r-warn', site.reason)); }
    if (site.guidance) { panel.append(element('p', 'wrb-r-help', site.guidance)); }
    if (status.state === 'startup-failed') { panel.append(element('p', 'wrb-r-help', '表の接頭辞を確認し、前回確認用管理者を指定した場合は同じ名前の確認用パスワードを入力して、準備をやり直せます。データベースは取り込み直しません。')); actions.append(actionButton('確認用サイトの準備をやり直す', '', retry)); }
    if (status.state === 'complete') {
      const lines = ['復元結果', ...Array.from(panel.querySelectorAll('p, li'), node => node.textContent)];
      const terms = panel.querySelectorAll('dl > *');
      for (let i = 0; i < terms.length; i += 2) { lines.push(terms[i].textContent + '：' + terms[i + 1].textContent); }
      actions.append(actionButton('復元結果を保存', '', () => {
        const url = URL.createObjectURL(new Blob([lines.join('\n') + '\n'], {type: 'text/plain;charset=utf-8'}));
        const link = element('a'); link.href = url; link.download = status.name + '-result.txt'; link.click();
        setTimeout(() => URL.revokeObjectURL(url), 0);
      }));
    }
    panel.append(actions);
    if (focused !== null) { const again = [...panel.querySelectorAll('button, a')].find(node => node.textContent === focused); if (again) { again.focus(); } }
    const formNeeded = status.state === 'startup-failed';
    if (formNeeded && form.elements.createPreviewAdmin) {
      form.elements.createPreviewAdmin.checked = !!site.adminLogin;
      if (site.adminLogin) { form.elements.adminLogin.value = site.adminLogin; }
      applyVisibility();
    }
    if (active || !formNeeded) {
      if (!root.dataset.formRequested || active) {
        setFormOpen(false);
        if (!active && !['running', 'starting'].includes(site.state)) {
          const again = element('p', '', ''); again.dataset.formClosed = '1';
          again.append(actionButton('別の復元を始める', '', newRestore));
          q('[data-status]').after(again);
        }
      }
    } else { setFormOpen(true); }
    updateNext();
    if (!active && !['running', 'starting'].includes(site.state) && poll) { clearInterval(poll); poll = null; }
  }

  async function newRestore() {
    root.dataset.formRequested = '1';
    setFormOpen(true);
    const latest = await call('context'); context = latest; fillSuggestions();
    updateNext(); form.elements.snapshot.focus();
  }

  function actionButton(label, className, handler) {
    const node = element('button', 'wrb-r-button ' + className, label); node.type = 'button';
    node.addEventListener('click', async () => { node.disabled = true; try { await handler(); } finally { node.disabled = false; } });
    return node;
  }

  async function act(command) {
    try { const data = new FormData(); data.append('job', job); renderStatus(await call(command, data)); schedule(); }
    catch (error) { message(error.message); }
  }

  async function retry() {
    const data = new FormData(); data.append('job', job); data.append('tablePrefix', form.elements.tablePrefix.value); data.append('adminPassword', form.elements.adminPassword.value);
    try { renderStatus(await call('startup_retry', data)); form.elements.adminPassword.value = ''; schedule(); }
    catch (error) { message(error.message); showError(error.field, error.message); }
  }

  function schedule() {
    if (poll) { return; }
    let pending = false;
    const timer = setInterval(async () => {
      if (pending) { return; }
      pending = true;
      const observedJob = job;
      try {
        const status = await call('status');
        if (poll === timer && job === observedJob) { renderStatus(status); }
      }
      catch (error) {
        if (poll !== timer || job !== observedJob) { return; }
        if (current?.site?.openable) {
          renderStatus({ ...current, site: { ...current.site, openable: false, adminOpenable: false, response: 'not-checked' } });
        }
        message(error.message); clearInterval(timer); poll = null;
      } finally { pending = false; }
    }, 3000);
    poll = timer;
  }

  function fillSuggestions() {
    if (context.suggestedName) { form.elements.name.value = context.suggestedName; }
    if (context.suggestedPort) { form.elements.previewPort.value = String(context.suggestedPort); }
    applyVisibility();
  }

  const r2File = q('[data-r2-file]');
  const r2Paste = q('[data-r2-paste]');
  const r2Configured = q('[data-r2-configured]');
  const r2Server = r2Configured.querySelector('input');
  const loadButton = q('[data-load-snapshots]');
  const sourceStatus = q('[data-source-status]');
  const sourceError = q('[data-error-for="r2Connection"]');
  const snapshot = form.elements.snapshot;
  let listing = null;

  function resetSnapshots(label) {
    const option = element('option', '', label); option.value = '';
    snapshot.replaceChildren(option); snapshot.disabled = true; updateNext();
  }

  function showSourceError(text, invalidInput = false) {
    sourceError.textContent = text;
    if (invalidInput) {
      const input = form.elements.r2SourceMode.value === 'paste' ? r2Paste : r2File;
      if (!input.disabled) { input.setAttribute('aria-invalid', 'true'); input.focus(); }
    }
  }

  async function loadSnapshots() {
    if (listing) { return; }
    const revision = ++sourceRevision;
    const mode = form.elements.r2SourceMode.value;
    const file = mode === 'file' ? r2File.files[0] : null;
    const pasted = mode === 'paste' ? r2Paste.value : '';
    r2Connection = ''; invalidate();
    sourceError.textContent = ''; r2File.removeAttribute('aria-invalid'); r2Paste.removeAttribute('aria-invalid');
    if ((mode === 'file' && !file) || (mode === 'paste' && pasted.trim() === '')) {
      showSourceError(mode === 'paste' ? 'R2接続情報のJSON全文を貼り付けてください。' : 'R2接続ファイル（JSON）を選んでください。', true); return;
    }
    if ((file && (file.size === 0 || file.size > 8192)) || (mode === 'paste' && new Blob([pasted]).size > 8192)) {
      showSourceError('R2接続情報が空か大きすぎます。WordPressから取得した復旧用JSONを確認してください。', true); return;
    }
    if (mode !== 'server' && location.protocol !== 'https:' && !['127.0.0.1', 'localhost', '[::1]'].includes(location.hostname)) {
      showSourceError('R2接続情報を送るにはHTTPSで開いてください。'); return;
    }
    const controller = new AbortController();
    listing = controller; loadButton.disabled = true; snapshot.setAttribute('aria-busy', 'true');
    sourceStatus.textContent = 'バックアップ一覧を読み込んでいます…';
    resetSnapshots('読み込み中…');
    let timedOut = false;
    const timeout = setTimeout(() => { timedOut = true; controller.abort(); }, 60000);
    try {
      if (mode !== 'server') {
        const contents = file ? await file.text() : pasted;
        if (revision !== sourceRevision) { return; }
        try { JSON.parse(contents); }
        catch (error) { showSourceError('JSONを読み取れません。R2接続情報を確認してください。', true); resetSnapshots('一覧を取得してください'); return; }
        r2Connection = contents;
      }
      const { snapshots } = await call('snapshots', null, controller.signal);
      if (revision !== sourceRevision) { return; }
      const usable = snapshots.filter(item => item.valid);
      if (usable.length === 0) {
        r2Connection = '';
        resetSnapshots('復元できるバックアップがありません');
        showSourceError(snapshots.length ? '保存記録を確認できるバックアップがありません。' : 'この保存先にバックアップがありません。', false);
        return;
      }
      snapshot.replaceChildren();
      if (usable.length !== 1) {
        const placeholder = element('option', '', 'バックアップを選んでください'); placeholder.value = ''; snapshot.append(placeholder);
      }
      for (const item of snapshots) {
        const saved = item.savedAt ? new Date(item.savedAt) : null;
        const date = saved && !Number.isNaN(saved.getTime()) ? saved.toLocaleString('ja-JP') + ' 保存' : '保存日時不明';
        const label = date + ' · ' + item.snapshotId.slice(0, 8) + '…';
        const option = element('option', '', label + (item.valid ? (item.verified ? '' : '（保存物は未照合）') : '（保存記録に問題）'));
        option.value = item.snapshotId; option.disabled = !item.valid; snapshot.append(option);
      }
      snapshot.disabled = false;
      if (usable.length === 1) { snapshot.value = usable[0].snapshotId; }
      sourceStatus.textContent = usable.length + '件のバックアップを読み込みました。' + (usable.length === 1 ? 'このバックアップを選択しました。' : '');
      updateNext();
    } catch (error) {
      if (revision !== sourceRevision) { return; }
      r2Connection = '';
      resetSnapshots('一覧を取得できませんでした');
      showSourceError(timedOut ? '一覧の取得に時間がかかっています。少し待って「バックアップ一覧を取得」を押し直してください。' : error.message, error.field === 'r2Connection');
    } finally {
      clearTimeout(timeout);
      if (listing === controller) {
        listing = null; loadButton.disabled = false; snapshot.removeAttribute('aria-busy');
        if (snapshot.disabled) { sourceStatus.textContent = ''; }
      }
    }
  }

  async function init() {
    try {
      context = await call('context');
      r2Configured.hidden = !context.sourceConfigured;
      r2Server.disabled = !context.sourceConfigured;
      if (!sourceRevision && context.sourceConfigured) { r2Server.checked = true; }
      const select = form.elements.connection; select.replaceChildren();
      for (const set of context.connections) { const option = element('option', '', set.label); option.value = set.id; select.append(option); }
      fillSuggestions();
      renderStatus(context.latest);
      if (context.latest && (['queued', 'running'].includes(context.latest.state) || ['running', 'starting'].includes((context.latest.site || {}).state))) { schedule(); }
    } catch (error) { message(error.message); }
    if (!sourceRevision && context && context.sourceConfigured) { await loadSnapshots(); }
  }

  root.querySelectorAll('[data-reveal]').forEach(input => {
    const toggle = element('button', 'wrb-r-link', '表示'); toggle.type = 'button'; toggle.setAttribute('aria-pressed', 'false'); toggle.setAttribute('aria-label', 'パスワードを表示');
    toggle.addEventListener('click', () => { const show = input.type === 'password'; input.type = show ? 'text' : 'password'; toggle.textContent = show ? '隠す' : '表示'; toggle.setAttribute('aria-pressed', String(show)); });
    input.after(toggle);
  });
  const keyFile = q('[data-key-file]');
  function resetSource(readyText = '') {
    sourceRevision++; r2Connection = ''; invalidate(); q('[data-checks]').replaceChildren(); message('');
    if (listing) { listing.abort(); listing = null; }
    loadButton.disabled = false; snapshot.removeAttribute('aria-busy');
    sourceError.textContent = ''; r2File.removeAttribute('aria-invalid'); r2Paste.removeAttribute('aria-invalid');
    sourceStatus.textContent = readyText;
    resetSnapshots('一覧を取得してください');
  }
  r2File.addEventListener('change', () => resetSource(r2File.files[0] ? 'ファイルを選択しました。「バックアップ一覧を取得」を押してください。' : ''));
  r2Paste.addEventListener('input', () => resetSource());
  form.addEventListener('change', event => {
    if (event.target.name !== 'r2SourceMode') { return; }
    r2File.value = ''; r2Paste.value = '';
    resetSource(); applyVisibility();
  });
  loadButton.addEventListener('click', loadSnapshots);
  keyFile.addEventListener('change', async () => {
    const [file] = keyFile.files; keyFile.value = '';
    if (!file) { return; }
    if (file.size > 65536) { showError('identity', '鍵ファイルとしては大きすぎます。ファイルを確認してください。'); return; }
    form.elements.identity.value = await file.text();
    form.elements.identity.dispatchEvent(new Event('input', { bubbles: true }));
  });
  form.addEventListener('input', event => {
    if (event.target.name === 'identity') { keyHint(); }
    if (event.target.name && event.target.name !== 'confirmName' && event.target.name !== 'reauthPassword') { formRevision++; invalidate(); }
    applyVisibility();
  });
  form.addEventListener('submit', event => event.preventDefault());
  root.querySelector('[data-action="preflight"]').addEventListener('click', event => preflight(event.currentTarget));
  root.querySelector('[data-action="start"]').addEventListener('click', event => start(event.currentTarget));
  init();
})();
