'use strict';

const navigation = document.querySelector('.navigation');
if (window.matchMedia('(max-width: 760px)').matches) navigation.open = false;

const input = document.querySelector('#doc-search');
const results = document.querySelector('#search-results');
const resultList = results.querySelector('ol');
const resultStatus = results.querySelector('.status');
document.querySelector('.search-control').hidden = false;

input.addEventListener('input', () => {
  const terms = input.value.trim().toLocaleLowerCase().split(/\s+/u).filter(Boolean);
  resultList.replaceChildren();
  results.hidden = terms.length === 0;
  if (results.hidden) return;
  const matches = (window.docSearch || []).filter(entry => {
    const value = `${entry.pageTitle} ${entry.title} ${entry.text}`.toLocaleLowerCase();
    return terms.every(term => value.includes(term));
  }).sort((a, b) => {
    const score = entry => terms.filter(term => entry.title.toLocaleLowerCase().includes(term)).length;
    return score(b) - score(a);
  });
  resultStatus.textContent = matches.length ? `${matches.length}件が一致しました。先頭12件を表示します。` : '一致する項目がありません。別のAPI名・設定キー・単語を試してください。';
  for (const entry of matches.slice(0, 12)) {
    const item = document.createElement('li');
    const link = document.createElement('a');
    link.href = entry.url;
    link.textContent = `${entry.pageTitle} / ${entry.title}`;
    const excerpt = document.createElement('p');
    const position = entry.text.toLocaleLowerCase().indexOf(terms[0]);
    const start = Math.max(0, position - 25);
    excerpt.textContent = `${start ? '…' : ''}${entry.text.slice(start, start + 150)}${entry.text.length > start + 150 ? '…' : ''}`;
    item.append(link, excerpt);
    resultList.append(item);
  }
});
input.addEventListener('keydown', event => {
  if (event.key === 'Escape') {
    input.value = '';
    input.dispatchEvent(new Event('input'));
  }
  if (event.key === 'Enter') {
    const first = resultList.querySelector('a');
    if (first) {
      if (window.matchMedia('(max-width: 760px)').matches) navigation.open = false;
      first.focus();
      results.scrollIntoView({ block: 'start' });
    }
  }
});

for (const block of document.querySelectorAll('.code-block')) {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'copy-button';
  button.textContent = 'コピー';
  button.setAttribute('aria-label', 'コードをコピー');
  button.addEventListener('click', async () => {
    const code = block.querySelector('code');
    try {
      if (!navigator.clipboard) throw new Error('Clipboard unavailable');
      await navigator.clipboard.writeText(code.textContent);
      button.textContent = 'コピーしました';
    } catch {
      const range = document.createRange();
      range.selectNodeContents(code);
      const selection = window.getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
      button.textContent = '選択済み：手動でコピー';
    }
    window.setTimeout(() => { button.textContent = 'コピー'; }, 2500);
  });
  block.querySelector('.code-toolbar').append(button);
}
