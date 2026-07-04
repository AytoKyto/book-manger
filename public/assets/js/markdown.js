// Small bidirectional converter for the subset of Markdown a novel chapter
// actually uses: headings, bold/italic, blockquotes, bullet lists, paragraphs.
// No dependency — keeps the whole frontend framework-free.

function escapeHtml(str) {
  return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function inlineMdToHtml(text) {
  let html = escapeHtml(text);
  html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
  html = html.replace(/(?<!\*)\*(?!\*)(.+?)\*(?!\*)/g, '<em>$1</em>');
  return html;
}

function inlineHtmlToMd(node) {
  let out = '';
  node.childNodes.forEach((child) => {
    if (child.nodeType === Node.TEXT_NODE) {
      out += child.textContent;
    } else if (child.nodeType === Node.ELEMENT_NODE) {
      const tag = child.tagName.toLowerCase();
      const inner = inlineHtmlToMd(child);
      if (tag === 'strong' || tag === 'b') out += `**${inner}**`;
      else if (tag === 'em' || tag === 'i') out += `*${inner}*`;
      else if (tag === 'br') out += '\n';
      else out += inner;
    }
  });
  return out;
}

function mdToHtml(markdown) {
  const blocks = markdown.replace(/\r\n/g, '\n').split(/\n{2,}/);
  const html = [];

  for (const block of blocks) {
    const trimmed = block.trim();
    if (trimmed === '') continue;

    if (trimmed.startsWith('### ')) {
      html.push(`<h3>${inlineMdToHtml(trimmed.slice(4))}</h3>`);
    } else if (trimmed.startsWith('## ')) {
      html.push(`<h2>${inlineMdToHtml(trimmed.slice(3))}</h2>`);
    } else if (trimmed.startsWith('# ')) {
      html.push(`<h1>${inlineMdToHtml(trimmed.slice(2))}</h1>`);
    } else if (trimmed.startsWith('> ')) {
      const lines = trimmed.split('\n').map((l) => l.replace(/^>\s?/, ''));
      html.push(`<blockquote>${inlineMdToHtml(lines.join(' '))}</blockquote>`);
    } else if (/^[-*]\s/.test(trimmed)) {
      const items = trimmed.split('\n').map((l) => l.replace(/^[-*]\s/, ''));
      html.push(`<ul>${items.map((i) => `<li>${inlineMdToHtml(i)}</li>`).join('')}</ul>`);
    } else {
      const withBreaks = trimmed.split('\n').map(inlineMdToHtml).join('<br>');
      html.push(`<p>${withBreaks}</p>`);
    }
  }

  return html.join('') || '<p><br></p>';
}

function htmlToMd(rootEl) {
  const blocks = [];

  rootEl.childNodes.forEach((node) => {
    if (node.nodeType === Node.TEXT_NODE) {
      const text = node.textContent.trim();
      if (text) blocks.push(text);
      return;
    }
    if (node.nodeType !== Node.ELEMENT_NODE) return;

    const tag = node.tagName.toLowerCase();
    if (tag === 'h1') blocks.push('# ' + inlineHtmlToMd(node).trim());
    else if (tag === 'h2') blocks.push('## ' + inlineHtmlToMd(node).trim());
    else if (tag === 'h3') blocks.push('### ' + inlineHtmlToMd(node).trim());
    else if (tag === 'blockquote') blocks.push('> ' + inlineHtmlToMd(node).trim());
    else if (tag === 'ul') {
      const items = Array.from(node.querySelectorAll('li')).map((li) => '- ' + inlineHtmlToMd(li).trim());
      blocks.push(items.join('\n'));
    } else if (tag === 'p' || tag === 'div') {
      const text = inlineHtmlToMd(node).trim();
      if (text) blocks.push(text);
    } else if (tag === 'br') {
      // ignore stray line breaks between blocks
    }
  });

  return blocks.join('\n\n') + '\n';
}
