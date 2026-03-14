/**
 * MEBE — Metis Email Block Editor  v2
 * Rich toolbar · Media browser · Nested column blocks
 */
(function (global) {
'use strict';

/* ====================================================================
   GIPHY key — free public beta (60 req/min, watermark not required
   for internal tools). Replace with your own key if desired.
   ==================================================================== */
const GIPHY_KEY = 'sXpGFDGZs0Dv1mmNFvYaGUvYwKX0PWIh';

/* ====================================================================
   Block definitions
   ==================================================================== */
const BLOCK_DEFS = {
    header:      { label:'Header',           icon:'⬆',  defaults:{ logo_url:'', logo_alt:'Mobilize Waco', logo_width:'180', bgcolor:'#ffffff', logo_link:'' } },
    heading:     { label:'Heading',          icon:'H',   defaults:{ content:'<h2>Your Heading</h2>', min_height:'' } },
    text:        { label:'Text',             icon:'T',   defaults:{ content:'<p>Edit this text…</p>', min_height:'' } },
    button:      { label:'Button',           icon:'▶',   defaults:{ label:'Click Here', url:'#', bgcolor:'#0d6efd', color:'#ffffff' } },
    image:       { label:'Image',            icon:'🖼',  defaults:{ src:'', alt:'', width:'100%', link:'', display_width:'' } },
    'two-col':   { label:'2 Column',         icon:'⬜⬜', defaults:{ left:[], right:[], left_valign:'top', right_valign:'top' } },
    'three-col': { label:'3 Column',         icon:'⬜⬜⬜',defaults:{ col1:[], col2:[], col3:[], col1_valign:'top', col2_valign:'top', col3_valign:'top' } },
    divider:     { label:'Divider',          icon:'—',   defaults:{ color:'#e2e6ea', height:1 } },
    spacer:      { label:'Spacer',           icon:'↕',   defaults:{ height:24 } },
    social:      { label:'Social Links',     icon:'🔗',  defaults:{ facebook:'', twitter:'', instagram:'', linkedin:'' } },
    footer:      { label:'Footer',           icon:'⬇',  defaults:{ address:'', phone:'', website:'', bgcolor:'#f7f8fa', text_color:'#888888' } },
    unsubscribe: { label:'Unsubscribe Footer',icon:'✉',  defaults:{ text:'You are receiving this because you opted in.', link_text:'Unsubscribe', link_url:'{{unsubscribe_url}}' } },
};
const BLOCK_ORDER = ['header','heading','text','button','image','two-col','three-col','divider','spacer','social','footer','unsubscribe'];

/* ====================================================================
   Email-safe HTML renderer (table-based output)
   ==================================================================== */
function renderBlockHtml(block) {
    const d = block.data;
    switch (block.type) {
        case 'text': case 'heading':
            return `<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding:8px 24px;font-family:Arial,sans-serif;font-size:15px;color:#222;line-height:1.6;">${d.content||''}</td></tr></table>`;
        case 'button':
            return `<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding:12px 24px;"><a href="${ea(d.url||'#')}" target="_blank" style="display:inline-block;padding:12px 28px;background-color:${ea(d.bgcolor||'#0d6efd')};color:${ea(d.color||'#fff')};font-family:Arial,sans-serif;font-size:15px;font-weight:bold;text-decoration:none;border-radius:4px;">${eh(d.label||'Click Here')}</a></td></tr></table>`;
        case 'image': {
            const img = `<img src="${ea(d.src||'')}" alt="${ea(d.alt||'')}" width="${ea(d.width||'100%')}" style="display:block;max-width:100%;border:0;">`;
            const wrap = d.link ? `<a href="${ea(d.link)}" target="_blank">${img}</a>` : img;
            return `<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding:8px 24px;">${wrap}</td></tr></table>`;
        }
        case 'divider':
            return `<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding:4px 24px;"><hr style="border:none;border-top:${parseInt(d.height||1,10)}px solid ${ea(d.color||'#e2e6ea')};margin:0;"></td></tr></table>`;
        case 'spacer':
            return `<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td height="${parseInt(d.height||24,10)}" style="font-size:0;line-height:0;">&nbsp;</td></tr></table>`;
        case 'two-col':
            return `<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td width="50%" valign="top" style="padding:8px 12px 8px 24px;font-family:Arial,sans-serif;font-size:14px;color:#222;">${renderColBlocks(d.left||[])}</td><td width="50%" valign="top" style="padding:8px 24px 8px 12px;font-family:Arial,sans-serif;font-size:14px;color:#222;">${renderColBlocks(d.right||[])}</td></tr></table>`;
        case 'three-col':
            return `<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td width="33%" valign="top" style="padding:8px 8px 8px 24px;font-family:Arial,sans-serif;font-size:13px;color:#222;">${renderColBlocks(d.col1||[])}</td><td width="34%" valign="top" style="padding:8px 8px;font-family:Arial,sans-serif;font-size:13px;color:#222;">${renderColBlocks(d.col2||[])}</td><td width="33%" valign="top" style="padding:8px 24px 8px 8px;font-family:Arial,sans-serif;font-size:13px;color:#222;">${renderColBlocks(d.col3||[])}</td></tr></table>`;
        case 'social': {
            const nets = ['facebook','twitter','instagram','linkedin'];
            const colors = {facebook:'#1877f2',twitter:'#000',instagram:'#e1306c',linkedin:'#0a66c2'};
            const icons  = {facebook:'f',twitter:'𝕏',instagram:'📷',linkedin:'in'};
            const links  = nets.filter(n=>d[n]).map(n=>`<a href="${ea(d[n])}" target="_blank" style="display:inline-block;margin:0 4px;padding:8px 12px;background:${colors[n]};color:#fff;font-family:Arial,sans-serif;font-size:13px;font-weight:bold;text-decoration:none;border-radius:3px;">${icons[n]}</a>`).join('');
            return `<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding:8px 24px;">${links}</td></tr></table>`;
        }
        case 'header': {
            const logo = d.logo_url
                ? `<a href="${ea(d.logo_link||'#')}" target="_blank"><img src="${ea(d.logo_url)}" alt="${ea(d.logo_alt||'')}" width="${ea(d.logo_width||'180')}" style="display:block;border:0;max-width:100%;"></a>`
                : `<span style="font-family:Arial,sans-serif;font-size:22px;font-weight:bold;color:#333;">${eh(d.logo_alt||'Your Organization')}</span>`;
            return `<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:${ea(d.bgcolor||'#ffffff')}"><tr><td align="center" style="padding:20px 24px;">${logo}</td></tr></table>`;
        }
        case 'footer':
            return `<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:${ea(d.bgcolor||'#f7f8fa')}"><tr><td align="center" style="padding:20px 24px;font-family:Arial,sans-serif;font-size:12px;color:${ea(d.text_color||'#888888')};line-height:1.7;">${d.address?eh(d.address)+'<br>':''}${d.phone?eh(d.phone)+'<br>':''}${d.website?`<a href="${ea(d.website)}" style="color:${ea(d.text_color||'#888888')};">${eh(d.website)}</a>`:''}</td></tr></table>`;
        case 'unsubscribe':
            return `<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="padding:16px 24px;font-family:Arial,sans-serif;font-size:12px;color:#888;">${eh(d.text||'')} <br><a href="${ea(d.link_url||'{{unsubscribe_url}}')}" style="color:#888;text-decoration:underline;">${eh(d.link_text||'Unsubscribe')}</a></td></tr></table>`;
        default: return `<!-- unknown block: ${eh(block.type)} -->`;
    }
}

function renderColBlocks(blocks) {
    if (!Array.isArray(blocks) || !blocks.length) return '';
    return blocks.map(b => renderBlockHtml(b)).join('');
}
function buildFullHtml(blocks) {
    return `<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title></title></head><body style="margin:0;padding:0;background-color:#f4f4f4;"><table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f4f4;"><tr><td align="center" style="padding:24px 0;"><table width="600" cellpadding="0" cellspacing="0" border="0" style="background-color:#fff;border-radius:4px;overflow:hidden;max-width:600px;"><tr><td>${blocks.map(b=>renderBlockHtml(b)).join('\n')}</td></tr></table></td></tr></table></body></html>`;
}

/* ====================================================================
   Canvas preview renderer
   ==================================================================== */
function previewBlock(block, colCtx) {
    const d = block.data;
    switch (block.type) {
        case 'text': case 'heading': {
            const mhStyle = d.min_height ? `min-height:${parseInt(d.min_height,10)}px;` : '';
            return `<div class="mebe-block-inner mebe-block-rich" data-field="content" contenteditable="true" style="${mhStyle}">${d.content||''}</div><div class="mebe-vresize-handle" title="Drag to set min-height"></div>`;
        }
        case 'button':
            return `<div class="mebe-block-inner mebe-block-button-wrap"><a class="mebe-button-preview" style="background-color:${ea(d.bgcolor||'#0d6efd')};color:${ea(d.color||'#fff')};" href="#">${eh(d.label||'Click Here')}</a></div>`;
        case 'image': {
            const imgW = d.display_width ? ea(d.display_width) + 'px' : '100%';
            return d.src
                ? `<div class="mebe-block-image-wrap"><div class="mebe-img-resizable" style="width:${imgW};"><img src="${ea(d.src)}" alt="${ea(d.alt||'')}" style="width:100%;display:block;border:0;"><div class="mebe-resize-handle" title="Drag to resize"></div></div></div>`
                : `<div class="mebe-block-inner mebe-block-image-placeholder"><span>🖼 Click to add image</span></div>`;
        }
        case 'divider':
            return `<div class="mebe-block-inner"><hr style="border:none;border-top:${parseInt(d.height||1,10)}px solid ${ea(d.color||'#e2e6ea')};margin:4px 0;"></div>`;
        case 'spacer':
            return `<div class="mebe-block-inner mebe-block-spacer" style="height:${parseInt(d.height||24,10)}px;"><span class="mebe-spacer-label">Spacer — ${d.height||24}px</span></div><div class="mebe-vresize-handle" title="Drag to resize spacer"></div>`;
        case 'two-col':
            return `<div class="mebe-block-inner mebe-block-two-col">
<div class="mebe-nested-col" data-col-field="left" style="justify-content:${d.left_valign==='middle'?'center':d.left_valign==='bottom'?'flex-end':'flex-start'}">${buildNestedColHtml(d.left||[], block.id, 'left')}</div>
<div class="mebe-nested-col" data-col-field="right" style="justify-content:${d.right_valign==='middle'?'center':d.right_valign==='bottom'?'flex-end':'flex-start'}">${buildNestedColHtml(d.right||[], block.id, 'right')}</div>
</div>`;
        case 'three-col':
            return `<div class="mebe-block-inner mebe-block-three-col">
<div class="mebe-nested-col" data-col-field="col1" style="justify-content:${d.col1_valign==='middle'?'center':d.col1_valign==='bottom'?'flex-end':'flex-start'}">${buildNestedColHtml(d.col1||[], block.id, 'col1')}</div>
<div class="mebe-nested-col" data-col-field="col2" style="justify-content:${d.col2_valign==='middle'?'center':d.col2_valign==='bottom'?'flex-end':'flex-start'}">${buildNestedColHtml(d.col2||[], block.id, 'col2')}</div>
<div class="mebe-nested-col" data-col-field="col3" style="justify-content:${d.col3_valign==='middle'?'center':d.col3_valign==='bottom'?'flex-end':'flex-start'}">${buildNestedColHtml(d.col3||[], block.id, 'col3')}</div>
</div>`;
        case 'social': {
            const nets = ['facebook','twitter','instagram','linkedin'];
            const icons = {facebook:'Facebook',twitter:'X / Twitter',instagram:'Instagram',linkedin:'LinkedIn'};
            const badges = nets.filter(n=>d[n]).map(n=>`<span class="mebe-social-badge">${icons[n]}</span>`).join('');
            return `<div class="mebe-block-inner mebe-block-social">${badges||'<span class="mebe-muted">No links set</span>'}</div>`;
        }
        case 'header': {
            const logo = d.logo_url
                ? `<img src="${ea(d.logo_url)}" alt="${ea(d.logo_alt||'')}" style="max-width:${ea(d.logo_width||'180')}px;display:block;margin:0 auto;">`
                : `<span style="font-size:20px;font-weight:bold;color:#333;font-family:Arial,sans-serif;">${eh(d.logo_alt||'Your Organization')}</span>`;
            return `<div class="mebe-block-inner mebe-block-header" style="background:${ea(d.bgcolor||'#ffffff')};text-align:center;padding:16px 20px;">${logo}</div>`;
        }
        case 'footer':
            return `<div class="mebe-block-inner mebe-block-footer" style="background:${ea(d.bgcolor||'#f7f8fa')};text-align:center;color:${ea(d.text_color||'#888')};font-size:12px;padding:14px 20px;line-height:1.7;">${d.address?eh(d.address)+'<br>':''}${d.phone?eh(d.phone)+'<br>':''}${d.website?`<a href="#" style="color:inherit;">${eh(d.website)}</a>`:''}<span class="mebe-muted">${(!d.address&&!d.phone&&!d.website)?'Footer — add address, phone, website in properties':''}</span></div>`;
        case 'unsubscribe':
            return `<div class="mebe-block-inner mebe-block-unsub"><span>${eh(d.text||'')}</span> <a href="#">${eh(d.link_text||'Unsubscribe')}</a></div>`;
        default:
            return `<div class="mebe-block-inner mebe-muted">[${eh(block.type)}]</div>`;
    }
}

function buildNestedColHtml(blocks, parentId, colField) {
    let html = `<div class="mebe-nested-drop" data-parent-id="${ea(parentId)}" data-col-field="${ea(colField)}">`;
    if (!blocks.length) {
        html += `<div class="mebe-nested-empty">Drop blocks here</div>`;
    } else {
        blocks.forEach(b => {
            html += `<div class="mebe-nested-block" data-block-id="${ea(b.id)}" data-parent-id="${ea(parentId)}" data-col-field="${ea(colField)}" draggable="true">
<div class="mebe-nested-handle">⠿</div>
<div class="mebe-nested-body">${previewBlock(b, true)}</div>
<button type="button" class="mebe-nested-delete" title="Delete">✕</button>
</div>`;
        });
    }
    html += '</div>';
    return html;
}

/* ====================================================================
   Props panel
   ==================================================================== */
function buildPropPanel(block) {
    const d = block.data, def = BLOCK_DEFS[block.type]||{};
    let h = `<div class="mebe-props"><div class="mebe-props-title">${eh(def.label||block.type)}</div>`;
    switch (block.type) {
        case 'button':
            h += pf('Label','text','label',d.label||'') + pf('URL','text','url',d.url||'') + pf('BG Color','color','bgcolor',d.bgcolor||'#0d6efd') + pf('Text Color','color','color',d.color||'#ffffff');
            break;
        case 'header':
            h += `<div class="mebe-prop-row"><button type="button" class="mebe-btn-media mw-btn mw-btn-xs mw-btn-ghost" style="width:100%;margin-bottom:6px;">📂 Browse Logo</button></div>`
               + pf('Organization Name','text','logo_alt',d.logo_alt||'') + pf('Logo Width (px)','number','logo_width',d.logo_width||'180') + pf('Logo Link','text','logo_link',d.logo_link||'') + pf('BG Color','color','bgcolor',d.bgcolor||'#ffffff');
            break;
        case 'footer':
            h += pf('Address','text','address',d.address||'') + pf('Phone','text','phone',d.phone||'') + pf('Website','text','website',d.website||'') + pf('BG Color','color','bgcolor',d.bgcolor||'#f7f8fa') + pf('Text Color','color','text_color',d.text_color||'#888888');
            break;
        case 'image':
            h += `<div class="mebe-prop-row"><button type="button" class="mebe-btn-media mw-btn mw-btn-xs mw-btn-ghost" style="width:100%;margin-bottom:6px;">📂 Browse / Search Media</button></div>`
               + pf('Alt text','text','alt',d.alt||'') + pf('Width (email)','text','width',d.width||'100%') + pf('Link URL','text','link',d.link||'');
            break;
        case 'divider':
            h += pf('Color','color','color',d.color||'#e2e6ea') + pf('Height (px)','number','height',d.height||1);
            break;
        case 'social':
            h += pf('Facebook','text','facebook',d.facebook||'') + pf('X/Twitter','text','twitter',d.twitter||'') + pf('Instagram','text','instagram',d.instagram||'') + pf('LinkedIn','text','linkedin',d.linkedin||'');
            break;
        case 'unsubscribe':
            h += pf('Footer Text','text','text',d.text||'') + pf('Link Text','text','link_text',d.link_text||'Unsubscribe') + pf('Link URL','text','link_url',d.link_url||'{{unsubscribe_url}}');
            break;
        case 'text': case 'heading':
            h += `<p class="mebe-props-hint">Use the toolbar above the block to format text.</p>`
               + pf('Min Height (px)', 'number', 'min_height', d.min_height || '');
            break;
        case 'spacer':
            h += pf('Height (px)', 'number', 'height', d.height || 24);
            break;
        case 'two-col':
            h += ps('Left Align', 'left_valign', d.left_valign || 'top')
               + ps('Right Align', 'right_valign', d.right_valign || 'top')
               + `<p class="mebe-props-hint">Drag blocks from the left panel into each column.</p>`;
            break;
        case 'three-col':
            h += ps('Col 1 Align', 'col1_valign', d.col1_valign || 'top')
               + ps('Col 2 Align', 'col2_valign', d.col2_valign || 'top')
               + ps('Col 3 Align', 'col3_valign', d.col3_valign || 'top')
               + `<p class="mebe-props-hint">Drag blocks from the left panel into each column.</p>`;
            break;
    }
    return h + '</div>';
}
function pf(label, type, key, val) {
    return `<div class="mebe-prop-row"><label>${eh(label)}</label><input type="${type}" data-key="${ea(key)}" value="${ea(String(val))}" class="mebe-prop-input"></div>`;
}
function ps(label, key, val) {
    const opts = [['top','Top'],['middle','Middle'],['bottom','Bottom']];
    const options = opts.map(([v,t]) => `<option value="${v}"${val===v?' selected':''}>${t}</option>`).join('');
    return `<div class="mebe-prop-row"><label>${eh(label)}</label><select data-key="${ea(key)}" class="mebe-prop-input mebe-prop-select">${options}</select></div>`;
}

/* ====================================================================
   Rich Text Toolbar
   ==================================================================== */
const TOOLBAR_HTML = `<div class="mebe-toolbar" id="mebe-toolbar" style="display:none;">
  <div class="mebe-toolbar-group">
    <button type="button" data-cmd="bold"          title="Bold"><b>B</b></button>
    <button type="button" data-cmd="italic"        title="Italic"><i>I</i></button>
    <button type="button" data-cmd="underline"     title="Underline"><u>U</u></button>
    <button type="button" data-cmd="strikeThrough" title="Strikethrough"><s>S</s></button>
  </div>
  <div class="mebe-toolbar-sep"></div>
  <div class="mebe-toolbar-group">
    <button type="button" data-cmd="justifyLeft"   title="Align left"   class="mebe-tb-icon"><svg width="14" height="12" viewBox="0 0 14 12"><rect x="0" y="0" width="14" height="2" rx="1"/><rect x="0" y="5" width="9" height="2" rx="1"/><rect x="0" y="10" width="14" height="2" rx="1"/></svg></button>
    <button type="button" data-cmd="justifyCenter" title="Center"        class="mebe-tb-icon"><svg width="14" height="12" viewBox="0 0 14 12"><rect x="0" y="0" width="14" height="2" rx="1"/><rect x="2.5" y="5" width="9" height="2" rx="1"/><rect x="0" y="10" width="14" height="2" rx="1"/></svg></button>
    <button type="button" data-cmd="justifyRight"  title="Align right"  class="mebe-tb-icon"><svg width="14" height="12" viewBox="0 0 14 12"><rect x="0" y="0" width="14" height="2" rx="1"/><rect x="5" y="5" width="9" height="2" rx="1"/><rect x="0" y="10" width="14" height="2" rx="1"/></svg></button>
    <button type="button" data-cmd="justifyFull"   title="Justify"      class="mebe-tb-icon"><svg width="14" height="12" viewBox="0 0 14 12"><rect x="0" y="0" width="14" height="2" rx="1"/><rect x="0" y="5" width="14" height="2" rx="1"/><rect x="0" y="10" width="14" height="2" rx="1"/></svg></button>
  </div>
  <div class="mebe-toolbar-sep"></div>
  <div class="mebe-toolbar-group">
    <button type="button" data-cmd="insertUnorderedList" title="Bullet list"   class="mebe-tb-icon"><svg width="14" height="12" viewBox="0 0 14 12"><circle cx="1.5" cy="1" r="1.5"/><rect x="4" y="0" width="10" height="2" rx="1"/><circle cx="1.5" cy="6" r="1.5"/><rect x="4" y="5" width="10" height="2" rx="1"/><circle cx="1.5" cy="11" r="1.5"/><rect x="4" y="10" width="10" height="2" rx="1"/></svg></button>
    <button type="button" data-cmd="insertOrderedList"   title="Numbered list" class="mebe-tb-icon"><svg width="14" height="12" viewBox="0 0 14 12"><text x="0" y="4" font-size="5" font-family="Arial" fill="currentColor">1.</text><rect x="5" y="2" width="9" height="2" rx="1"/><text x="0" y="9" font-size="5" font-family="Arial" fill="currentColor">2.</text><rect x="5" y="7" width="9" height="2" rx="1"/></svg></button>
  </div>
  <div class="mebe-toolbar-sep"></div>
  <div class="mebe-toolbar-group">
    <select class="mebe-toolbar-select" id="mebe-tb-fontsize" title="Font size">
      <option value="">Size</option>
      <option value="10">10</option><option value="11">11</option><option value="12">12</option>
      <option value="13">13</option><option value="14">14</option><option value="15">15</option>
      <option value="16">16</option><option value="18">18</option><option value="20">20</option>
      <option value="24">24</option><option value="28">28</option><option value="32">32</option>
      <option value="36">36</option><option value="48">48</option>
    </select>
    <select class="mebe-toolbar-select" id="mebe-tb-format" title="Paragraph style">
      <option value="">Style</option>
      <option value="p">Paragraph</option>
      <option value="h1">H1</option><option value="h2">H2</option>
      <option value="h3">H3</option><option value="h4">H4</option>
      <option value="blockquote">Quote</option><option value="pre">Code</option>
    </select>
  </div>
  <div class="mebe-toolbar-sep"></div>
  <div class="mebe-toolbar-group">
    <label title="Text color" class="mebe-color-wrap">A<input type="color" data-cmd="foreColor" value="#222222" class="mebe-color-input"></label>
    <label title="Highlight" class="mebe-color-wrap">&#9638;<input type="color" data-cmd="hiliteColor" value="#ffff00" class="mebe-color-input"></label>
  </div>
  <div class="mebe-toolbar-sep"></div>
  <div class="mebe-toolbar-group">
    <button type="button" data-cmd="createLink" title="Insert link" class="mebe-tb-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></button>
    <button type="button" data-cmd="unlink"     title="Remove link" class="mebe-tb-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/><line x1="4" y1="4" x2="20" y2="20"/></svg></button>
    <button type="button" data-cmd="mebe-image" title="Insert image" class="mebe-tb-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></button>
  </div>
  <div class="mebe-toolbar-sep"></div>
  <div class="mebe-toolbar-group">
    <select class="mebe-toolbar-select" id="mebe-tb-merge" title="Insert merge tag">
      <option value="">&#123;&#123;tag&#125;&#125;</option>
    </select>
  </div>
  <div class="mebe-toolbar-sep"></div>
  <div class="mebe-toolbar-group">
    <button type="button" data-cmd="removeFormat" title="Clear formatting" style="font-size:10px;">CLR</button>
  </div>
</div>`;

/* ====================================================================
   Keyboard shortcuts
   ==================================================================== */
MebeEditor.prototype._bindKeyboard = function () {
    const self = this;
    const isMac = /Mac|iPod|iPhone|iPad/.test(navigator.platform);
    document.addEventListener('keydown', function (e) {
        if (!document.activeElement || !document.activeElement.closest('.mebe-root')) return;
        const mod  = isMac ? e.metaKey : e.ctrlKey;
        const inCE = !!document.activeElement.closest('[contenteditable="true"]');

        /* Text shortcuts — inside contenteditable */
        if (inCE && mod) {
            if (e.key === 'b') { e.preventDefault(); if (self._toolbarRange) self._restoreRange(self._toolbarRange); document.execCommand('bold',false,null); self._syncContentEditableToBlock(); return; }
            if (e.key === 'i') { e.preventDefault(); if (self._toolbarRange) self._restoreRange(self._toolbarRange); document.execCommand('italic',false,null); self._syncContentEditableToBlock(); return; }
            if (e.key === 'u') { e.preventDefault(); if (self._toolbarRange) self._restoreRange(self._toolbarRange); document.execCommand('underline',false,null); self._syncContentEditableToBlock(); return; }
            if (e.key === 'z' && !e.shiftKey) { e.preventDefault(); document.execCommand('undo',false,null); self._syncContentEditableToBlock(); return; }
            if ((e.key === 'z' && e.shiftKey) || e.key === 'y') { e.preventDefault(); document.execCommand('redo',false,null); self._syncContentEditableToBlock(); return; }
        }

        /* Block shortcuts — when not typing */
        if (!inCE && self._selected) {
            if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); self._deleteBlock(self._selected); return; }
            if (e.key === 'Escape') { self._selectBlock(null); return; }
            if (mod && e.key === 'ArrowUp') {
                e.preventDefault();
                const idx = self.blocks.findIndex(b => b.id === self._selected);
                if (idx > 0) { self._moveBlock(self._selected, idx - 1); self._render(); self._onChange(); }
                return;
            }
            if (mod && e.key === 'ArrowDown') {
                e.preventDefault();
                const idx = self.blocks.findIndex(b => b.id === self._selected);
                if (idx < self.blocks.length - 1) { self._moveBlock(self._selected, idx + 2); self._render(); self._onChange(); }
                return;
            }
            if (mod && e.key === 'd') {
                e.preventDefault();
                const src = self._blockById(self._selected);
                if (src) {
                    const copy = JSON.parse(JSON.stringify(src));
                    copy.id = self._uid_next();
                    const idx = self.blocks.findIndex(b => b.id === self._selected);
                    self.blocks.splice(idx + 1, 0, copy);
                    self._render(); self._selectBlock(copy.id); self._onChange();
                }
                return;
            }
        }

        /* Save — Cmd/Ctrl+S anywhere in editor */
        if (mod && e.key === 's') {
            e.preventDefault();
            const saveBtn = document.querySelector('.mebe-save-trigger');
            if (saveBtn) { saveBtn.click(); return; }
            if (self._autosaveFn) {
                self._showAutosaveToast('Saving\u2026', 'saving');
                try {
                    const r = self._autosaveFn(self.export());
                    if (r && typeof r.then === 'function') {
                        r.then(function(){ self._showAutosaveToast('Saved','saved'); })
                         .catch(function(){ self._showAutosaveToast('Save failed','error'); });
                    } else { self._showAutosaveToast('Saved','saved'); }
                } catch(ex){ self._showAutosaveToast('Save failed','error'); }
            }
        }
    });
};

/* ====================================================================
   Media Browser Modal
   ==================================================================== */
const MEDIA_MODAL_HTML = `<div class="mebe-modal-overlay" id="mebe-media-modal" style="display:none;" aria-modal="true">
  <div class="mebe-modal">
    <div class="mebe-modal-header">
      <span class="mebe-modal-title">Media Browser</span>
      <button type="button" class="mebe-modal-close" id="mebe-media-close">✕</button>
    </div>
    <div class="mebe-modal-tabs">
      <button type="button" class="mebe-modal-tab is-active" data-media-tab="upload">Upload</button>
      <button type="button" class="mebe-modal-tab" data-media-tab="url">By URL</button>
      <button type="button" class="mebe-modal-tab" data-media-tab="gif">GIF Search</button>
    </div>
    <div class="mebe-media-tab-panel" data-panel="upload">
      <div class="mebe-upload-zone" id="mebe-upload-zone">
        <div class="mebe-upload-prompt">
          <span>📁 Drop an image here or <label for="mebe-file-input" class="mebe-upload-link">browse</label></span>
          <input type="file" id="mebe-file-input" accept="image/*" style="display:none;">
        </div>
        <div id="mebe-upload-preview" style="display:none;text-align:center;padding:12px;">
          <img id="mebe-upload-preview-img" style="max-width:100%;max-height:200px;border-radius:4px;">
          <div style="margin-top:8px;"><button type="button" class="mebe-btn-insert-upload mw-btn mw-btn-xs">Insert This Image</button></div>
        </div>
        <div id="mebe-upload-status" style="text-align:center;padding:8px;font-size:12px;color:#6c757d;"></div>
      </div>
    </div>
    <div class="mebe-media-tab-panel" data-panel="url" style="display:none;">
      <div style="padding:16px;">
        <label style="font-size:12px;font-weight:600;display:block;margin-bottom:6px;">Image URL</label>
        <input type="text" id="mebe-url-input" class="mw-input" placeholder="https://…" style="width:100%;box-sizing:border-box;">
        <div style="margin-top:8px;text-align:center;"><img id="mebe-url-preview" style="max-width:100%;max-height:160px;display:none;border-radius:4px;margin-bottom:8px;"></div>
        <button type="button" class="mebe-btn-insert-url mw-btn mw-btn-xs" style="margin-top:8px;">Insert Image</button>
      </div>
    </div>
    <div class="mebe-media-tab-panel" data-panel="gif" style="display:none;">
      <div style="padding:10px 16px 6px;">
        <div style="display:flex;gap:8px;align-items:center;">
          <input type="text" id="mebe-gif-search" class="mw-input" placeholder="Search GIFs… (powered by Giphy)" style="flex:1;">
          <button type="button" id="mebe-gif-search-btn" class="mw-btn mw-btn-xs">Search</button>
        </div>
        <div style="font-size:10px;color:#adb5bd;margin-top:4px;">Powered by Giphy</div>
      </div>
      <div id="mebe-gif-results" class="mebe-gif-grid"></div>
    </div>
    <div class="mebe-modal-footer">
      <button type="button" class="mebe-modal-close mw-btn mw-btn-ghost mw-btn-xs">Cancel</button>
    </div>
  </div>
</div>`;

/* ====================================================================
   Link Modal
   ==================================================================== */
const LINK_MODAL_HTML = `<div class="mebe-modal-overlay" id="mebe-link-modal" style="display:none;" aria-modal="true">
  <div class="mebe-modal" style="max-width:400px;">
    <div class="mebe-modal-header">
      <span class="mebe-modal-title">Insert Link</span>
      <button type="button" class="mebe-modal-close" id="mebe-link-close">✕</button>
    </div>
    <div style="padding:16px;">
      <label style="font-size:12px;font-weight:600;display:block;margin-bottom:6px;">URL</label>
      <input type="text" id="mebe-link-url-input" class="mw-input" placeholder="https://…" style="width:100%;box-sizing:border-box;">
      <label style="font-size:12px;font-weight:600;display:block;margin:10px 0 6px;">Open in</label>
      <select id="mebe-link-target" class="mw-select" style="width:100%;">
        <option value="_blank">New tab</option>
        <option value="_self">Same tab</option>
      </select>
    </div>
    <div class="mebe-modal-footer">
      <button type="button" id="mebe-link-confirm" class="mw-btn mw-btn-xs">Insert Link</button>
      <button type="button" class="mebe-modal-close mw-btn mw-btn-ghost mw-btn-xs">Cancel</button>
    </div>
  </div>
</div>`;

/* ====================================================================
   MebeEditor constructor + DOM build
   ==================================================================== */
function MebeEditor(containerId, opts) {
    this.containerId  = containerId;
    this.opts         = opts || {};
    this.blocks       = [];
    this._selected    = null;
    this._dragSrc     = null;
    this._panelDrag   = null;
    this._savedRange  = null;
    this._mediaTarget = null; // { block, field } or null
    this._uid         = 0;
    this._build();
}
MebeEditor.prototype._uid_next = function () { return 'b' + (++this._uid) + '_' + Date.now(); };

MebeEditor.prototype._build = function () {
    const container = document.getElementById(this.containerId);
    if (!container) { console.warn('[MEBE] container not found:', this.containerId); return; }
    container.innerHTML = '';
    container.classList.add('mebe-root');

    /* Toolbar (injected once into body) */
    if (!document.getElementById('mebe-toolbar')) {
        document.body.insertAdjacentHTML('beforeend', TOOLBAR_HTML);
    }
    if (!document.getElementById('mebe-media-modal')) {
        document.body.insertAdjacentHTML('beforeend', MEDIA_MODAL_HTML);
    }
    if (!document.getElementById('mebe-link-modal')) {
        document.body.insertAdjacentHTML('beforeend', LINK_MODAL_HTML);
    }

    /* Left panel */
    const panel = document.createElement('div');
    panel.className = 'mebe-panel';
    panel.innerHTML = '<div class="mebe-panel-title">Blocks</div>';
    BLOCK_ORDER.forEach(type => {
        const def  = BLOCK_DEFS[type];
        const item = document.createElement('div');
        item.className    = 'mebe-panel-block';
        item.draggable    = true;
        item.dataset.type = type;
        item.innerHTML    = `<span class="mebe-panel-icon">${def.icon}</span><span>${def.label}</span>`;
        panel.appendChild(item);
    });

    /* Canvas area */
    const canvasWrap = document.createElement('div');
    canvasWrap.className = 'mebe-canvas-wrap';
    const canvasOuter = document.createElement('div');
    canvasOuter.className = 'mebe-canvas-outer';
    this._canvas = document.createElement('div');
    this._canvas.className = 'mebe-canvas';
    this._emptyMsg = document.createElement('div');
    this._emptyMsg.className = 'mebe-canvas-empty';
    this._emptyMsg.innerHTML = '<span>← Drag a block here to start building</span>';
    this._canvas.appendChild(this._emptyMsg);
    canvasOuter.appendChild(this._canvas);
    canvasWrap.appendChild(canvasOuter);

    /* Props panel */
    this._propsEl = document.createElement('div');
    this._propsEl.className = 'mebe-props-panel';
    this._propsEl.innerHTML = '<div class="mebe-props-empty">Select a block<br>to edit its properties</div>';

    container.appendChild(panel);
    container.appendChild(canvasWrap);
    container.appendChild(this._propsEl);

    this._bindEvents(panel);
    this._bindToolbar();
    this._bindKeyboard();
    this._bindMediaModal();
    this._bindLinkModal();
};

/* ====================================================================
   Toolbar binding
   ==================================================================== */
MebeEditor.prototype._bindToolbar = function () {
    const self    = this;
    const toolbar = document.getElementById('mebe-toolbar');
    if (!toolbar) return;

    /* Show toolbar on selection inside any contenteditable in the canvas */
    document.addEventListener('selectionchange', function () {
        const sel = window.getSelection();
        if (!sel || !sel.rangeCount || sel.isCollapsed) {
            setTimeout(function () {
                if (!toolbar.matches(':hover')) toolbar.style.display = 'none';
            }, 180);
            return;
        }
        const anchor = sel.anchorNode;
        if (!anchor) return;
        const ce = anchor.nodeType === 1 ? anchor : anchor.parentElement;
        if (!ce || !ce.closest('[contenteditable="true"]')) { toolbar.style.display = 'none'; return; }
        const within = ce.closest('.mebe-root');
        if (!within) { toolbar.style.display = 'none'; return; }

        /* Keep _toolbarRange fresh for button/dropdown use */
        self._toolbarRange = self._captureRange();

        const range   = sel.getRangeAt(0);
        const rect    = range.getBoundingClientRect();
        const scrollY = window.scrollY || document.documentElement.scrollTop;
        const scrollX = window.scrollX || document.documentElement.scrollLeft;
        toolbar.style.display = 'flex';
        toolbar.style.left    = Math.max(4, rect.left  + scrollX) + 'px';
        toolbar.style.top     = (rect.top + scrollY - toolbar.offsetHeight - 6) + 'px';
    });

    /* Capture selection on mousedown BEFORE focus shifts, then restore before execCommand */
    toolbar.addEventListener('mousedown', function (e) {
        self._toolbarRange = self._captureRange(); /* snapshot selection */
        e.preventDefault(); /* keep focus in contenteditable */
    });

    toolbar.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-cmd]');
        if (!btn) return;
        const cmd = btn.dataset.cmd;
        /* Restore selection so execCommand operates on it */
        if (self._toolbarRange) self._restoreRange(self._toolbarRange);
        if (cmd === 'createLink') {
            self._savedRange = self._captureRange();
            self._openLinkModal();
            return;
        }
        if (cmd === 'mebe-image') {
            self._savedRange = self._captureRange();
            self._openMediaModal(null, 'inline');
            return;
        }
        document.execCommand(cmd, false, null);
        self._syncContentEditableToBlock();
    });

    toolbar.addEventListener('change', function (e) {
        const el = e.target;
        /* Restore selection before any execCommand */
        if (self._toolbarRange) self._restoreRange(self._toolbarRange);

        /* Font size — use inline span style */
        if (el.id === 'mebe-tb-fontsize') {
            if (!el.value) return;
            document.execCommand('fontSize', false, '7');
            const root = document.activeElement && document.activeElement.closest('[contenteditable]');
            if (root) {
                root.querySelectorAll('font[size="7"]').forEach(function (f) {
                    const span = document.createElement('span');
                    span.style.fontSize = el.value + 'px';
                    span.innerHTML = f.innerHTML;
                    f.parentNode.replaceChild(span, f);
                });
            }
            el.value = '';
            self._syncContentEditableToBlock();
            return;
        }
        /* Format block */
        if (el.id === 'mebe-tb-format') {
            if (!el.value) return;
            document.execCommand('formatBlock', false, '<' + el.value + '>');
            el.value = '';
            self._syncContentEditableToBlock();
            return;
        }
        /* Merge tag */
        if (el.id === 'mebe-tb-merge') {
            if (!el.value) return;
            const tag = el.value;
            el.value = '';
            document.execCommand('insertText', false, tag);
            self._syncContentEditableToBlock();
            return;
        }
        const sel2 = el.closest('[data-cmd]');
        if (!sel2 || !sel2.value) return;
        document.execCommand(sel2.dataset.cmd, false, sel2.value);
        sel2.value = '';
        self._syncContentEditableToBlock();
    });

    /* Color inputs */
    toolbar.addEventListener('input', function (e) {
        const inp = e.target;
        if (inp.type !== 'color' || !inp.dataset.cmd) return;
        if (self._toolbarRange) self._restoreRange(self._toolbarRange);
        document.execCommand(inp.dataset.cmd, false, inp.value);
        self._syncContentEditableToBlock();
    });
};

/* Sync contenteditable innerHTML back to block data */
MebeEditor.prototype._syncContentEditableToBlock = function () {
    const sel  = window.getSelection();
    if (!sel || !sel.rangeCount) return;
    const node = sel.anchorNode;
    const ce   = node ? (node.nodeType===1 ? node : node.parentElement) : null;
    if (!ce) return;
    const editEl = ce.closest('[contenteditable="true"]');
    if (!editEl) return;
    const row = editEl.closest('.mebe-block-row');
    if (row && editEl.dataset.field) {
        const block = this._blockById(row.dataset.blockId);
        if (block) { block.data[editEl.dataset.field] = editEl.innerHTML; this._onChange(); }
    }
    /* Nested block */
    const nRow = editEl.closest('.mebe-nested-block');
    if (nRow && editEl.dataset.field) {
        const pId     = nRow.dataset.parentId;
        const colField = nRow.dataset.colField;
        const bId     = nRow.dataset.blockId;
        const parent  = this._blockById(pId);
        if (parent) {
            const colBlocks = parent.data[colField] || [];
            const b = colBlocks.find(x => x.id === bId);
            if (b) { b.data[editEl.dataset.field] = editEl.innerHTML; this._onChange(); }
        }
    }
};

MebeEditor.prototype._captureRange = function () {
    const sel = window.getSelection();
    return (sel && sel.rangeCount) ? sel.getRangeAt(0).cloneRange() : null;
};

MebeEditor.prototype._restoreRange = function () {
    if (!this._savedRange) return;
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(this._savedRange);
    this._savedRange = null;
};

/* ====================================================================
   Link Modal binding
   ==================================================================== */
MebeEditor.prototype._bindLinkModal = function () {
    const self = this;
    const modal = document.getElementById('mebe-link-modal');
    if (!modal) return;

    document.getElementById('mebe-link-confirm').addEventListener('click', function () {
        const url    = document.getElementById('mebe-link-url-input').value.trim();
        const target = document.getElementById('mebe-link-target').value;
        if (!url) return;
        self._restoreRange();
        document.execCommand('createLink', false, url);
        /* set target attribute on the newly created <a> */
        const sel = window.getSelection();
        if (sel && sel.anchorNode) {
            const a = sel.anchorNode.parentElement && sel.anchorNode.parentElement.closest('a');
            if (a) a.target = target;
        }
        self._syncContentEditableToBlock();
        modal.style.display = 'none';
    });

    modal.addEventListener('click', function (e) {
        if (e.target.classList.contains('mebe-modal-close') || e.target.closest('.mebe-modal-close')) {
            modal.style.display = 'none';
        }
        if (e.target === modal) modal.style.display = 'none';
    });
};

MebeEditor.prototype._openLinkModal = function () {
    const modal = document.getElementById('mebe-link-modal');
    document.getElementById('mebe-link-url-input').value = '';
    modal.style.display = 'flex';
    document.getElementById('mebe-link-url-input').focus();
};

/* ====================================================================
   Media Modal binding
   ==================================================================== */
MebeEditor.prototype._bindMediaModal = function () {
    const self  = this;
    const modal = document.getElementById('mebe-media-modal');
    if (!modal) return;

    /* Tab switching */
    modal.addEventListener('click', function (e) {
        const tab = e.target.closest('[data-media-tab]');
        if (tab) {
            modal.querySelectorAll('.mebe-modal-tab').forEach(t => t.classList.remove('is-active'));
            tab.classList.add('is-active');
            modal.querySelectorAll('.mebe-media-tab-panel').forEach(p => p.style.display = 'none');
            modal.querySelector(`.mebe-media-tab-panel[data-panel="${tab.dataset.mediaTab}"]`).style.display = '';
            return;
        }
        if (e.target.classList.contains('mebe-modal-close') || e.target.closest('.mebe-modal-close')) {
            modal.style.display = 'none'; return;
        }
        if (e.target === modal) { modal.style.display = 'none'; return; }

        /* Insert buttons */
        if (e.target.classList.contains('mebe-btn-insert-upload')) {
            const img = document.getElementById('mebe-upload-preview-img');
            if (img && img.src) { self._insertMedia(img.src); modal.style.display = 'none'; }
        }
        if (e.target.classList.contains('mebe-btn-insert-url')) {
            const url = document.getElementById('mebe-url-input').value.trim();
            if (url) { self._insertMedia(url); modal.style.display = 'none'; }
        }
    });

    /* URL preview */
    let urlTimer;
    modal.querySelector('#mebe-url-input').addEventListener('input', function () {
        clearTimeout(urlTimer);
        const val = this.value.trim();
        const prev = document.getElementById('mebe-url-preview');
        if (!val) { prev.style.display = 'none'; return; }
        urlTimer = setTimeout(function () { prev.src = val; prev.style.display = 'block'; prev.onerror = function(){ prev.style.display='none'; }; }, 400);
    });

    /* File input */
    document.getElementById('mebe-file-input').addEventListener('change', function () {
        const file = this.files && this.files[0];
        if (!file) return;
        self._handleFileUpload(file);
    });

    /* Upload zone drag-drop */
    const zone = document.getElementById('mebe-upload-zone');
    zone.addEventListener('dragover', function (e) { e.preventDefault(); zone.classList.add('is-over'); });
    zone.addEventListener('dragleave', function () { zone.classList.remove('is-over'); });
    zone.addEventListener('drop', function (e) {
        e.preventDefault(); zone.classList.remove('is-over');
        const file = e.dataTransfer.files && e.dataTransfer.files[0];
        if (file && file.type.startsWith('image/')) self._handleFileUpload(file);
    });

    /* GIF search */
    document.getElementById('mebe-gif-search-btn').addEventListener('click', function () {
        self._searchGiphy(document.getElementById('mebe-gif-search').value.trim());
    });
    document.getElementById('mebe-gif-search').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') self._searchGiphy(this.value.trim());
    });

    /* GIF result click */
    document.getElementById('mebe-gif-results').addEventListener('click', function (e) {
        const item = e.target.closest('.mebe-gif-item');
        if (!item) return;
        self._insertMedia(item.dataset.url);
        modal.style.display = 'none';
    });
};

MebeEditor.prototype._openMediaModal = function (blockOrNull, mode) {
    this._mediaTarget = { block: blockOrNull, mode: mode || 'block' };
    const modal = document.getElementById('mebe-media-modal');
    document.getElementById('mebe-upload-preview').style.display = 'none';
    document.getElementById('mebe-upload-status').textContent = '';
    document.getElementById('mebe-url-input').value = '';
    document.getElementById('mebe-url-preview').style.display = 'none';
    document.getElementById('mebe-gif-results').innerHTML = '';
    document.getElementById('mebe-gif-search').value = '';
    /* reset to upload tab */
    modal.querySelectorAll('.mebe-modal-tab').forEach(t => t.classList.toggle('is-active', t.dataset.mediaTab === 'upload'));
    modal.querySelectorAll('.mebe-media-tab-panel').forEach(p => p.style.display = p.dataset.panel === 'upload' ? '' : 'none');
    modal.style.display = 'flex';
};

MebeEditor.prototype._insertMedia = function (url) {
    if (!url) return;
    const mt = this._mediaTarget;
    if (!mt) return;

    if (mt.mode === 'inline') {
        /* Insert <img> at cursor in contenteditable */
        this._restoreRange();
        document.execCommand('insertImage', false, url);
        this._syncContentEditableToBlock();
        this._mediaTarget = null;
        return;
    }

    /* block mode — update image block src */
    const block = mt.block || (this._selected ? this._blockById(this._selected) : null);
    if (block && block.type === 'image') {
        block.data.src = url;
        this._refreshBlockPreview(block);
        if (this._selected === block.id) {
            /* refresh props panel url display */
            const inp = this._propsEl.querySelector('[data-key="src"]');
            if (inp) inp.value = url;
        }
        this._onChange();
    }
    this._mediaTarget = null;
};

MebeEditor.prototype._handleFileUpload = function (file) {
    const self    = this;
    const status  = document.getElementById('mebe-upload-status');
    const preview = document.getElementById('mebe-upload-preview');
    const prevImg = document.getElementById('mebe-upload-preview-img');
    status.textContent = 'Uploading…';

    const action  = 'metis_nl_upload_image';
    const ajaxUrl = (window.metisNewsletterAjax && window.metisNewsletterAjax.ajax_url) || '/api/ajax';
    const nonce   = Metis.ajax.nonceFor(action, (window.metisNewsletterAjax && window.metisNewsletterAjax.nonce) || '');
    const fd      = new FormData();
    fd.append('action', action);
    fd.append('metis_action_nonce', nonce);
    fd.append('nonce',  (window.metisNewsletterAjax && window.metisNewsletterAjax.nonce) || '');
    fd.append('file',   file);

    fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: fd })
        .then(r => Metis.ajax.parseJson(r))
        .then(r => {
            if (r.success && r.data && r.data.url) {
                status.textContent = '';
                prevImg.src        = r.data.url;
                preview.style.display = 'block';
                /* stash URL for insert button */
                prevImg.dataset.uploadedUrl = r.data.url;
            } else {
                /* Fallback: preview locally via FileReader */
                const reader = new FileReader();
                reader.onload = function (ev) {
                    prevImg.src = ev.target.result;
                    preview.style.display = 'block';
                    status.textContent = 'Preview only — upload endpoint not configured.';
                };
                reader.readAsDataURL(file);
            }
        })
        .catch(function () {
            const reader = new FileReader();
            reader.onload = function (ev) {
                prevImg.src = ev.target.result;
                preview.style.display = 'block';
                status.textContent = 'Could not reach upload endpoint. Preview only.';
            };
            reader.readAsDataURL(file);
        });
};

MebeEditor.prototype._searchGiphy = function (q) {
    if (!q) return;
    const grid = document.getElementById('mebe-gif-results');
    grid.innerHTML = '<div class="mebe-gif-loading">Searching…</div>';
    const url = `https://api.giphy.com/v1/gifs/search?api_key=${GIPHY_KEY}&q=${encodeURIComponent(q)}&limit=24&rating=g`;
    fetch(url)
        .then(r => r.json())
        .then(function (data) {
            if (!data.data || !data.data.length) { grid.innerHTML = '<div class="mebe-gif-loading">No results.</div>'; return; }
            grid.innerHTML = data.data.map(g => {
                const preview = g.images.fixed_width_small.url;
                const full    = g.images.fixed_width.url;
                return `<div class="mebe-gif-item" data-url="${ea(full)}" title="${ea(g.title)}">
<img src="${ea(preview)}" loading="lazy" alt="${ea(g.title)}">
</div>`;
            }).join('');
        })
        .catch(function () { grid.innerHTML = '<div class="mebe-gif-loading">Error reaching Giphy.</div>'; });
};

/* ====================================================================
   Main canvas + nested column events
   ==================================================================== */
MebeEditor.prototype._bindEvents = function (panel) {
    const self = this;

    /* Panel drag */
    panel.addEventListener('dragstart', function (e) {
        const item = e.target.closest('.mebe-panel-block');
        if (!item) return;
        self._panelDrag = item.dataset.type;
        self._dragSrc   = null;
        e.dataTransfer.effectAllowed = 'copy';
    });
    panel.addEventListener('dragend', function () { self._panelDrag = null; self._clearDropIndicators(); });

    /* Canvas dragover/drop (top-level blocks) */
    this._canvas.addEventListener('dragover', function (e) {
        /* Don't handle if nested col will handle it */
        if (e.target.closest('.mebe-nested-col')) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = self._panelDrag ? 'copy' : 'move';
        self._showDropIndicator(e);
    });
    this._canvas.addEventListener('dragleave', function (e) {
        if (!self._canvas.contains(e.relatedTarget)) self._clearDropIndicators();
    });
    this._canvas.addEventListener('drop', function (e) {
        if (e.target.closest('.mebe-nested-col')) return;
        e.preventDefault();
        const idx = self._dropIndexFromEvent(e);
        if (self._panelDrag) { self._insertBlock(self._panelDrag, idx); self._panelDrag = null; }
        else if (self._dragSrc) { self._moveBlock(self._dragSrc, idx); self._dragSrc = null; }
        self._clearDropIndicators();
        self._render();
        self._onChange();
    });
    this._canvas.addEventListener('dragstart', function (e) {
        const row = e.target.closest('.mebe-block-row');
        if (!row) return;
        self._dragSrc   = row.dataset.blockId;
        self._panelDrag = null;
        e.dataTransfer.effectAllowed = 'move';
    });

    /* Nested column drag/drop */
    this._canvas.addEventListener('dragover', function (e) {
        const col = e.target.closest('.mebe-nested-drop');
        if (!col) return;
        e.preventDefault(); e.stopPropagation();
        e.dataTransfer.dropEffect = 'copy';
        self._showNestedDropIndicator(col, e);
    });
    this._canvas.addEventListener('drop', function (e) {
        const col = e.target.closest('.mebe-nested-drop');
        if (!col) return;
        e.preventDefault(); e.stopPropagation();
        self._clearNestedDropIndicators(col);
        const pId      = col.dataset.parentId;
        const colField = col.dataset.colField;
        const parent   = self._blockById(pId);
        if (!parent) return;
        if (!Array.isArray(parent.data[colField])) parent.data[colField] = [];
        const idx = self._nestedDropIndexFromEvent(col, e);

        if (self._panelDrag) {
            /* New block from panel */
            const def = BLOCK_DEFS[self._panelDrag];
            if (!def) return;
            const nb = { id: self._uid_next(), type: self._panelDrag, data: JSON.parse(JSON.stringify(def.defaults)) };
            parent.data[colField].splice(idx, 0, nb);
            self._panelDrag = null;
        } else if (self._dragSrc) {
            /* Existing top-level block moved into a column */
            const srcBlock = self._blockById(self._dragSrc);
            if (!srcBlock || srcBlock.id === pId) { self._dragSrc = null; return; }
            /* Remove from top-level */
            self.blocks = self.blocks.filter(b => b.id !== self._dragSrc);
            /* Insert into column */
            parent.data[colField].splice(idx, 0, srcBlock);
            self._dragSrc = null;
            if (self._selected === srcBlock.id) self._selectBlock(null);
        } else { return; }

        self._render();
        self._onChange();
    });

    /* Click delegation */
    this._canvas.addEventListener('click', function (e) {
        /* Nested delete */
        const nd = e.target.closest('.mebe-nested-delete');
        if (nd) {
            e.stopPropagation();
            const nRow  = nd.closest('.mebe-nested-block');
            const pId   = nRow.dataset.parentId;
            const cField= nRow.dataset.colField;
            const bId   = nRow.dataset.blockId;
            const p     = self._blockById(pId);
            if (p && Array.isArray(p.data[cField])) {
                p.data[cField] = p.data[cField].filter(x => x.id !== bId);
                self._render(); self._onChange();
            }
            return;
        }
        /* Top-level delete */
        const del = e.target.closest('.mebe-block-delete');
        if (del) { e.stopPropagation(); self._deleteBlock(del.closest('.mebe-block-row').dataset.blockId); return; }
        /* Image placeholder click */
        const imgPh = e.target.closest('.mebe-block-image-placeholder');
        if (imgPh) {
            const row = imgPh.closest('.mebe-block-row');
            if (row) { self._openMediaModal(self._blockById(row.dataset.blockId), 'block'); return; }
        }
        /* Resize handle — handled on mousedown below, stop click bubbling */
        if (e.target.closest('.mebe-resize-handle')) return;
        /* Row select */
        const row = e.target.closest('.mebe-block-row');
        row ? self._selectBlock(row.dataset.blockId) : self._selectBlock(null);
    });

    /* ContentEditable changes */
    this._canvas.addEventListener('input', function (e) {
        const el  = e.target;
        const row = el.closest('.mebe-block-row');
        if (row && el.dataset.field) {
            const block = self._blockById(row.dataset.blockId);
            if (block) { block.data[el.dataset.field] = el.innerHTML; self._onChange(); }
        }
        /* nested */
        const nRow = el.closest('.mebe-nested-block');
        if (nRow && el.dataset.field) {
            const p = self._blockById(nRow.dataset.parentId);
            if (p) {
                const col = p.data[nRow.dataset.colField] || [];
                const b   = col.find(x => x.id === nRow.dataset.blockId);
                if (b) { b.data[el.dataset.field] = el.innerHTML; self._onChange(); }
            }
        }
    });

    /* Props panel changes */
    function handlePropChange(e) {
        const inp = e.target.closest('.mebe-prop-input');
        if (!inp || !self._selected) return;
        const block = self._blockById(self._selected);
        if (!block) return;
        block.data[inp.dataset.key] = inp.value;
        self._refreshBlockPreview(block);
        self._onChange();
    }
    this._propsEl.addEventListener('input', handlePropChange);
    this._propsEl.addEventListener('change', handlePropChange); /* handles <select> */
    this._propsEl.addEventListener('click', function (e) {
        if (!e.target.closest('.mebe-btn-media')) return;
        const block = self._selected ? self._blockById(self._selected) : null;
        self._openMediaModal(block, 'block');
    });

    /* Vertical resize (text, heading, spacer) — drag bottom handle */
    this._canvas.addEventListener('mousedown', function (e) {
        const vh = e.target.closest('.mebe-vresize-handle');
        if (!vh) return;
        e.preventDefault();
        const row    = vh.closest('.mebe-block-row');
        if (!row) return;
        const block  = self._blockById(row.dataset.blockId);
        if (!block) return;
        const isSpacer = block.type === 'spacer';
        const target = isSpacer
            ? row.querySelector('.mebe-block-spacer')
            : row.querySelector('.mebe-block-rich');
        if (!target) return;
        const startY = e.clientY;
        const startH = target.offsetHeight;
        function onVMove(ev) {
            const newH = Math.max(isSpacer ? 4 : 20, startH + (ev.clientY - startY));
            target.style.minHeight = newH + 'px';
            if (isSpacer) target.style.height = newH + 'px';
        }
        function onVUp() {
            document.removeEventListener('mousemove', onVMove);
            document.removeEventListener('mouseup', onVUp);
            const newH = Math.round(isSpacer ? target.offsetHeight : target.offsetHeight);
            if (isSpacer) { block.data.height = newH; }
            else { block.data.min_height = newH; }
            if (self._selected === block.id) self._selectBlock(block.id);
            self._onChange();
        }
        document.addEventListener('mousemove', onVMove);
        document.addEventListener('mouseup', onVUp);
    });

    /* Image resize — mousedown on handle, mousemove/up on document */
    this._canvas.addEventListener('mousedown', function (e) {
        const handle = e.target.closest('.mebe-resize-handle');
        if (!handle) return;
        e.preventDefault();
        const wrap  = handle.closest('.mebe-img-resizable');
        const row   = handle.closest('.mebe-block-row');
        if (!wrap || !row) return;
        const block = self._blockById(row.dataset.blockId);
        if (!block) return;
        const startX = e.clientX;
        const startW = wrap.offsetWidth;
        function onMove(ev) {
            const newW = Math.max(40, startW + (ev.clientX - startX));
            wrap.style.width = newW + 'px';
        }
        function onUp() {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            /* Save display_width back to block data */
            block.data.display_width = Math.round(wrap.offsetWidth);
            /* Update props panel if this block is selected */
            if (self._selected === block.id) self._selectBlock(block.id);
            self._onChange();
        }
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    });
};

/* ====================================================================
   Drop indicator helpers
   ==================================================================== */
MebeEditor.prototype._showDropIndicator = function (e) {
    this._clearDropIndicators();
    const rows = Array.from(this._canvas.querySelectorAll('.mebe-block-row'));
    if (!rows.length) return;
    let insertBefore = null;
    for (const row of rows) {
        if (e.clientY < row.getBoundingClientRect().top + row.getBoundingClientRect().height / 2) { insertBefore = row; break; }
    }
    const line = document.createElement('div');
    line.className = 'mebe-drop-line';
    insertBefore ? this._canvas.insertBefore(line, insertBefore) : this._canvas.appendChild(line);
};
MebeEditor.prototype._clearDropIndicators = function () {
    this._canvas.querySelectorAll('.mebe-drop-line').forEach(el => el.remove());
};
MebeEditor.prototype._dropIndexFromEvent = function (e) {
    const rows = Array.from(this._canvas.querySelectorAll('.mebe-block-row'));
    for (let i = 0; i < rows.length; i++) {
        const r = rows[i].getBoundingClientRect();
        if (e.clientY < r.top + r.height / 2) return i;
    }
    return rows.length;
};

MebeEditor.prototype._showNestedDropIndicator = function (col, e) {
    this._clearNestedDropIndicators(col);
    const rows = Array.from(col.querySelectorAll('.mebe-nested-block'));
    if (!rows.length) return;
    let ins = null;
    for (const r of rows) { if (e.clientY < r.getBoundingClientRect().top + r.getBoundingClientRect().height/2) { ins=r; break; } }
    const line = document.createElement('div');
    line.className = 'mebe-drop-line mebe-nested-drop-line';
    ins ? col.insertBefore(line, ins) : col.appendChild(line);
};
MebeEditor.prototype._clearNestedDropIndicators = function (col) {
    col.querySelectorAll('.mebe-nested-drop-line').forEach(el => el.remove());
};
MebeEditor.prototype._nestedDropIndexFromEvent = function (col, e) {
    const rows = Array.from(col.querySelectorAll('.mebe-nested-block'));
    for (let i=0;i<rows.length;i++) {
        const r = rows[i].getBoundingClientRect();
        if (e.clientY < r.top + r.height/2) return i;
    }
    return rows.length;
};

/* ====================================================================
   Block CRUD
   ==================================================================== */
MebeEditor.prototype._insertBlock = function (type, idx) {
    const def = BLOCK_DEFS[type];
    if (!def) return;
    const defaults = JSON.parse(JSON.stringify(def.defaults)); /* deep copy */
    const block = { id: this._uid_next(), type, data: defaults };
    this.blocks.splice(idx, 0, block);
    this._selectBlock(block.id);
};
MebeEditor.prototype._moveBlock = function (id, toIdx) {
    const fromIdx = this.blocks.findIndex(b => b.id === id);
    if (fromIdx === -1) return;
    const [block] = this.blocks.splice(fromIdx, 1);
    this.blocks.splice(toIdx > fromIdx ? toIdx - 1 : toIdx, 0, block);
};
MebeEditor.prototype._deleteBlock = function (id) {
    this.blocks = this.blocks.filter(b => b.id !== id);
    if (this._selected === id) this._selectBlock(null);
    this._render(); this._onChange();
};
MebeEditor.prototype._blockById = function (id) { return this.blocks.find(b => b.id === id) || null; };

/* ====================================================================
   Selection + Props
   ==================================================================== */
MebeEditor.prototype._selectBlock = function (id) {
    this._selected = id;
    this._canvas.querySelectorAll('.mebe-block-row').forEach(r => r.classList.toggle('is-selected', r.dataset.blockId === id));
    if (id) {
        const block = this._blockById(id);
        this._propsEl.innerHTML = block ? buildPropPanel(block) : '';
    } else {
        this._propsEl.innerHTML = '<div class="mebe-props-empty">Select a block<br>to edit its properties</div>';
    }
};

/* ====================================================================
   Render
   ==================================================================== */
MebeEditor.prototype._render = function () {
    this._canvas.querySelectorAll('.mebe-block-row').forEach(el => el.remove());
    const empty = this._canvas.querySelector('.mebe-canvas-empty');
    if (!this.blocks.length) {
        if (!empty) { const e=document.createElement('div'); e.className='mebe-canvas-empty'; e.innerHTML='<span>← Drag a block here to start building</span>'; this._canvas.appendChild(e); }
        return;
    }
    if (empty) empty.remove();
    const sel = this._selected;
    this.blocks.forEach(block => {
        const row = document.createElement('div');
        row.className = 'mebe-block-row' + (block.id === sel ? ' is-selected' : '');
        row.draggable = true;
        row.dataset.blockId = block.id;
        row.innerHTML = `<div class="mebe-block-handle" title="Drag to reorder">⠿</div><div class="mebe-block-body">${previewBlock(block)}</div><button type="button" class="mebe-block-delete" title="Delete block">✕</button>`;
        this._canvas.appendChild(row);
    });
};

MebeEditor.prototype._refreshBlockPreview = function (block) {
    const row = this._canvas.querySelector(`.mebe-block-row[data-block-id="${block.id}"]`);
    if (!row) return;
    const body = row.querySelector('.mebe-block-body');
    if (body) body.innerHTML = previewBlock(block);
};

MebeEditor.prototype._onChange = function () {
    if (typeof this.opts.onchange === 'function') this.opts.onchange(this);
};

/* ====================================================================
   Public API
   ==================================================================== */
MebeEditor.prototype.load = function (jsonStrOrObj) {
    if (!jsonStrOrObj) { this.blocks = []; this._render(); return; }
    try {
        const doc = typeof jsonStrOrObj === 'string' ? JSON.parse(jsonStrOrObj) : jsonStrOrObj;
        this.blocks = Array.isArray(doc.blocks) ? doc.blocks : [];
        this.blocks.forEach(b => {
            const def = BLOCK_DEFS[b.type];
            if (def) {
                const defaults = JSON.parse(JSON.stringify(def.defaults));
                b.data = Object.assign({}, defaults, b.data || {});
            }
            if (!b.id) b.id = this._uid_next();
            /* ensure column arrays are arrays */
            ['left','right','col1','col2','col3'].forEach(f => {
                if (b.data[f] && !Array.isArray(b.data[f])) b.data[f] = [];
            });
        });
    } catch (e) { console.warn('[MEBE] load error:', e); this.blocks = []; }
    this._selectBlock(null);
    this._render();
    this._autosaveReady = true; /* mark ready — any onChange after this is a real user edit */
};

MebeEditor.prototype.export = function () {
    return { json: JSON.stringify({ blocks: this.blocks }), html: buildFullHtml(this.blocks) };
};

/* Populate merge tag dropdown in the toolbar */
MebeEditor.prototype.setMergeTags = function (tags) {
    const sel = document.getElementById('mebe-tb-merge');
    if (!sel) return;
    sel.innerHTML = '<option value="">&#123;&#123;tag&#125;&#125;</option>';
    (tags || []).forEach(function (t) {
        const opt = document.createElement('option');
        opt.value       = t.value || t;
        opt.textContent = t.label || t.value || t;
        sel.appendChild(opt);
    });
};

/* Autosave — call enableAutosave(saveFn, delayMs) to activate */
MebeEditor.prototype.enableAutosave = function (saveFn, delayMs) {
    const self  = this;
    const delay = delayMs || 3000;
    this._autosaveFn    = saveFn;
    this._autosaveTimer = null;
    this._autosaveDirty = false;
    /* If load() already ran, _autosaveReady is already true; otherwise wait for it */
    if (typeof this._autosaveReady === 'undefined') this._autosaveReady = false;
    const prev = this.opts.onchange;
    this.opts.onchange = function (ed) {
        if (prev) prev(ed);
        if (!self._autosaveReady) return; /* skip onChange during load() */
        self._autosaveDirty = true;
        clearTimeout(self._autosaveTimer);
        self._showAutosaveToast('Unsaved changes\u2026', 'pending');
        self._autosaveTimer = setTimeout(function () {
            if (!self._autosaveDirty) return;
            self._autosaveDirty = false;
            self._showAutosaveToast('Saving\u2026', 'saving');
            try {
                const result = saveFn(ed.export());
                if (result && typeof result.then === 'function') {
                    result.then(function () { self._showAutosaveToast('Saved', 'saved'); })
                          .catch(function () { self._showAutosaveToast('Save failed', 'error'); });
                } else {
                    self._showAutosaveToast('Saved', 'saved');
                }
            } catch (e) { self._showAutosaveToast('Save failed', 'error'); }
        }, delay);
    };
};

MebeEditor.prototype._showAutosaveToast = function (msg, state) {
    let el = document.getElementById('mebe-autosave-toast');
    if (!el) {
        el = document.createElement('div');
        el.id = 'mebe-autosave-toast';
        document.body.appendChild(el);
    }
    el.textContent = msg;
    el.className = 'mebe-autosave-toast mebe-autosave-' + state;
    clearTimeout(el._hideTimer);
    if (state === 'saved' || state === 'error') {
        el._hideTimer = setTimeout(function () { el.classList.add('mebe-autosave-toast-hide'); }, state === 'error' ? 1000 : 1000);
    }
};
/* keep old name as alias for backward compat */
MebeEditor.prototype._showAutosaveStatus = MebeEditor.prototype._showAutosaveToast;

MebeEditor.prototype.injectMergeTag = function (tag) {
    if (!tag) return;
    const sel = this._selected ? this._blockById(this._selected) : null;
    if (sel && (sel.type === 'text' || sel.type === 'heading')) {
        sel.data.content = (sel.data.content || '') + tag;
        this._refreshBlockPreview(sel);
        const row = this._canvas.querySelector(`.mebe-block-row[data-block-id="${sel.id}"]`);
        if (row) { const ce = row.querySelector('[contenteditable]'); if (ce) ce.innerHTML = sel.data.content; }
        this._onChange();
    } else {
        this._insertBlock('text', this.blocks.length);
        const nb = this.blocks[this.blocks.length - 1];
        nb.data.content = `<p>${tag}</p>`;
        this._render(); this._selectBlock(nb.id); this._onChange();
    }
};

/* ====================================================================
   Utilities
   ==================================================================== */
function eh(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function ea(s) { return String(s).replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

/* ====================================================================
   Expose
   ==================================================================== */
global.MebeEditor = MebeEditor;

}(window));
