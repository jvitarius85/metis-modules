/**
 * Metis Page Builder Engine — Beaver Builder Parity
 * Version 2.0.0
 *
 * Data model: layout.sections[] → section.columns[] → column.modules[]
 * Interaction: hover = outline + floating controls only; no persistent chrome
 *
 * Phases:
 *   1. Core layout engine (Section / Column / Module hierarchy)
 *   2. Drag-and-drop with before/after/inside indicators
 *   3. Inline text editing + floating rich-text toolbar
 *   4. Right settings panel (General/Style/Advanced, live preview)
 *   5. Autosave + undo/redo (60-state history)
 *   6. Responsive preview controls
 *   7. Template (webpart) save/insert
 */
(function(global) {
'use strict';

/* =========================================================================
   UTILITIES
   ========================================================================= */
var _uid = 0;
function uid() { return 'mb_' + (++_uid) + '_' + Math.random().toString(36).slice(2, 6); }
function cloneDeep(v) { return JSON.parse(JSON.stringify(v)); }
function escA(s) { return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function escH(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

var DEFAULT_WIDTHS = { 1:[100], 2:[50,50], 3:[33.333,33.333,33.334], 4:[25,25,25,25] };

function ensureLayout(layout) {
  if (!layout || typeof layout !== 'object') layout = {};
  if (!Array.isArray(layout.sections)) layout.sections = [];
  layout.sections.forEach(ensureSection);
  return layout;
}
function ensureSection(s) {
  if (!s) return s;
  if (!s.id) s.id = uid();
  s.type = s.type || 'section';
  if (!Array.isArray(s.columns)) s.columns = [makeColumn(100)];
  if (!s.settings) s.settings = {};
  s.columns.forEach(ensureColumn);
  return s;
}
function ensureColumn(c) {
  if (!c) return c;
  if (!c.id) c.id = uid();
  if (typeof c.width !== 'number') c.width = 100;
  if (!Array.isArray(c.modules)) c.modules = [];
  if (!c.settings) c.settings = {};
  c.modules.forEach(ensureModule);
  return c;
}
function ensureModule(m) {
  if (!m) return m;
  if (!m.id) m.id = uid();
  m.type = m.type || 'text';
  if (!m.data) m.data = {};
  if (!m.style) m.style = {};
  return m;
}
function makeSection(cols) {
  var w = DEFAULT_WIDTHS[cols] || [100];
  return { id: uid(), type: 'section', columns: w.map(makeColumn), settings: {} };
}
function makeColumn(width) {
  return { id: uid(), width: width || 100, modules: [], settings: {} };
}
function makeModule(type, defaults) {
  var m = { id: uid(), type: type || 'text', data: {}, style: {} };
  if (defaults && typeof defaults === 'object') {
    Object.keys(defaults).forEach(function(k) { m.data[k] = defaults[k]; });
  }
  return m;
}

/* =========================================================================
   BLOCK CATALOGUE
   ========================================================================= */
var CATS = [
  { key:'content', label:'Content' }, { key:'media', label:'Media' },
  { key:'interactive', label:'Interactive' }, { key:'layout', label:'Layout' },
  { key:'navigation', label:'Navigation' }, { key:'advanced', label:'Advanced' },
  { key:'dynamic', label:'Dynamic' }, { key:'donations', label:'Donations' }
];

var BLOCKS = {
  /* content */
  text:          { label:'Text',            cat:'content',     defs:{ content:'<p>Add your text here...</p>' } },
  heading:       { label:'Heading',         cat:'content',     defs:{ content:'Your Heading', level:'h2', align:'left' } },
  icon:          { label:'Icon',            cat:'content',     defs:{ icon_svg:'', size:'48px', color:'#334155' } },
  cta:           { label:'CTA',             cat:'content',     defs:{ title:'Call to Action', content:'', button_label:'Get Started', button_url:'#' } },
  hero:          { label:'Hero',            cat:'content',     defs:{ title:'Hero Headline', content:'<p>Supporting copy.</p>', button_label:'Get Started', button_url:'#', background_image:'' } },
  card_grid:     { label:'Card Grid',       cat:'content',     defs:{ items:[] } },
  feature_grid:  { label:'Feature Grid',    cat:'content',     defs:{ items:[] } },
  image_content: { label:'Image + Content', cat:'content',     defs:{ image:'', title:'', content:'' } },
  testimonials:  { label:'Testimonials',    cat:'content',     defs:{ items:[] } },
  faq:           { label:'FAQ',             cat:'content',     defs:{ items:[] } },
  accordion:     { label:'Accordion',       cat:'content',     defs:{ items:[] } },
  pricing:       { label:'Pricing',         cat:'content',     defs:{ plans:[] } },
  countdown:     { label:'Countdown',       cat:'content',     defs:{ end_at:'', timezone:'America/Chicago' } },
  /* media */
  image:         { label:'Image',           cat:'media',       defs:{ src:'', alt:'', width:'100%' } },
  video:         { label:'Video',           cat:'media',       defs:{ url:'', provider:'youtube', aspect_ratio:'16:9' } },
  gallery:       { label:'Gallery',         cat:'media',       defs:{ images:[] } },
  /* interactive */
  button:        { label:'Button',          cat:'interactive', defs:{ label:'Click Here', url:'#', style:'primary' } },
  popup_trigger: { label:'Popup Trigger',   cat:'interactive', defs:{ popup_id:null, label:'Open' } },
  button_group:  { label:'Button Group',    cat:'interactive', defs:{ buttons:[], align:'left' } },
  form_embed:    { label:'Form Embed',      cat:'interactive', defs:{ form_id:'' } },
  modal_trigger: { label:'Modal Trigger',   cat:'interactive', defs:{ popup_id:null, label:'Open' } },
  /* layout */
  container:     { label:'Container',       cat:'layout',      defs:{ max_width:'1200px', align:'center' } },
  grid:          { label:'Grid',            cat:'layout',      defs:{ columns:2, gap:'24px' } },
  spacer:        { label:'Spacer',          cat:'layout',      defs:{ height:40 } },
  divider:       { label:'Divider',         cat:'layout',      defs:{ color:'#e2e6ea', style:'solid', height:1 } },
  columns:       { label:'Columns',         cat:'layout',      defs:{ columns:2, gap:'24px' } },
  tabs:          { label:'Tabs',            cat:'layout',      defs:{ items:[] } },
  anchor:        { label:'Anchor',          cat:'layout',      defs:{ anchor_id:'' } },
  /* navigation */
  menu:          { label:'Menu',            cat:'navigation',  defs:{ menu_id:null, orientation:'horizontal' } },
  breadcrumbs:   { label:'Breadcrumbs',     cat:'navigation',  defs:{ separator:'/', show_home:true } },
  /* advanced */
  html:          { label:'HTML',            cat:'advanced',    defs:{ content:'' } },
  /* dynamic */
  post_list:     { label:'Post List',       cat:'dynamic',     defs:{ count:5, layout:'list' } },
  page_title:    { label:'Page Title',      cat:'dynamic',     defs:{ tag:'h1', align:'left' } },
  posts_feed:    { label:'Posts Feed',      cat:'dynamic',     defs:{ count:5 } },
  events_list:   { label:'Events List',     cat:'dynamic',     defs:{ count:5 } },
  team:          { label:'Team',            cat:'dynamic',     defs:{ members:[] } },
  /* donations */
  donation_form:         { label:'Donation Form',         cat:'donations', defs:{ campaign_id:'' } },
  donation_progress:     { label:'Donation Progress',     cat:'donations', defs:{ campaign_id:'' } },
  donation_description:  { label:'Donation Description',  cat:'donations', defs:{ campaign_id:'' } },
  donation_goal_summary: { label:'Donation Goal Summary', cat:'donations', defs:{ campaign_id:'' } },
  donor_wall:            { label:'Donor Wall',            cat:'donations', defs:{ limit:20 } },
  impact_metrics:        { label:'Impact Metrics',        cat:'donations', defs:{ items:[] } }
};

/* canonical alias map (old flat-model types → new types) */
var TYPE_ALIAS = {
  responsive_spacer:'spacer', enhanced_divider:'divider', posts_block:'posts_feed',
  events_block:'events_list', team_block:'team', donation_form_block:'donation_form',
  progress_bar_block:'donation_progress', campaign_description_block:'donation_description',
  donation_goal_summary_block:'donation_goal_summary', donor_wall_block:'donor_wall',
  impact_metrics_block:'impact_metrics', countdown_block:'countdown',
  anchor_block:'anchor', modal_trigger_block:'modal_trigger', cta_banner_block:'cta',
  form:'form_embed', inline_form:'form_embed', form_multistep:'form_embed',
  multi_step_form:'form_embed', advanced_columns:'columns', html_embed:'html',
  section_block:'section'
};

function canonType(t) { return TYPE_ALIAS[t] || t; }

/* =========================================================================
   BLOCK ICONS (inline SVG)
   ========================================================================= */
var ICONS = {
  text:         '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7V5h16v2"/><path d="M9 5v14"/><line x1="4" y1="19" x2="14" y2="19"/></svg>',
  heading:      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 4v16M18 4v16M6 12h12"/></svg>',
  image:        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>',
  button:       '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="8" width="18" height="8" rx="4"/><line x1="8" y1="12" x2="16" y2="12"/></svg>',
  spacer:       '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><line x1="12" y1="5" x2="12" y2="19"/><path d="M5 5h14M5 19h14"/></svg>',
  divider:      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><line x1="3" y1="12" x2="21" y2="12"/></svg>',
  video:        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="15" height="14" rx="2"/><path d="M17 9l5-3v12l-5-3"/></svg>',
  html:         '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 3l-4 18M5 8L2 12l3 4M19 8l3 4-3 4"/></svg>',
  menu:         '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>',
  columns:      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="4" width="8" height="16" rx="1.5"/><rect x="14" y="4" width="8" height="16" rx="1.5"/></svg>',
  hero:         '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="4" width="20" height="16" rx="2"/><line x1="7" y1="10" x2="17" y2="10"/><line x1="9" y1="14" x2="15" y2="14"/></svg>',
  form_embed:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="7" y1="9" x2="17" y2="9"/><line x1="7" y1="13" x2="13" y2="13"/><rect x="7" y="16" width="4" height="2" rx="1"/></svg>',
  donation_form:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>',
  icon:         '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polygon points="12,2 15.09,8.26 22,9.27 17,14.14 18.18,21.02 12,17.77 5.82,21.02 7,14.14 2,9.27 8.91,8.26"/></svg>',
  gallery:      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="2" width="9" height="9" rx="1"/><rect x="13" y="2" width="9" height="9" rx="1"/><rect x="2" y="13" width="9" height="9" rx="1"/><rect x="13" y="13" width="9" height="9" rx="1"/></svg>',
  anchor:       '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="5" r="3"/><line x1="12" y1="8" x2="12" y2="22"/><path d="M5 15l7 7 7-7"/></svg>',
  tabs:         '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="8" width="20" height="14" rx="1.5"/><rect x="2" y="4" width="6" height="6" rx="1"/><rect x="10" y="4" width="6" height="6" rx="1"/></svg>',
  card_grid:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="4" width="9" height="16" rx="1.5"/><rect x="13" y="4" width="9" height="16" rx="1.5"/></svg>',
  cta:          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="6" width="20" height="12" rx="2"/><line x1="7" y1="10" x2="17" y2="10"/><rect x="8" y="13" width="8" height="2" rx="1"/></svg>',
  post_list:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="10" x2="14" y2="10"/><line x1="4" y1="14" x2="20" y2="14"/><line x1="4" y1="18" x2="12" y2="18"/></svg>',
  team:         '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="7" r="4"/><circle cx="16" cy="7" r="4"/><path d="M1 21c0-4 3-7 7-7s7 3 7 7"/><path d="M17 14c2 0 5 1.5 5 5"/></svg>',
  grid:         '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
  container:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="4" width="20" height="16" rx="2"/><rect x="6" y="8" width="12" height="8" rx="1" stroke-dasharray="3 2"/></svg>',
  breadcrumbs:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 12h4M17 12h4M10 12h4M7 9l3 3-3 3M14 9l3 3-3 3"/></svg>',
  popup_trigger:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="2"/><rect x="7" y="9" width="10" height="6" rx="1"/></svg>',
  button_group: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="9" width="8" height="6" rx="3"/><rect x="13" y="9" width="9" height="6" rx="3"/></svg>',
  modal_trigger:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M8 12h8M12 8v8"/></svg>',
  page_title:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 10h10M4 14h12M4 18h8"/></svg>',
  posts_feed:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="5" rx="1"/><rect x="3" y="12" width="18" height="5" rx="1"/></svg>',
  events_list:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><circle cx="8" cy="15" r="1" fill="currentColor"/><circle cx="12" cy="15" r="1" fill="currentColor"/></svg>',
  countdown:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
  feature_grid: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="2" width="9" height="6" rx="1"/><rect x="13" y="2" width="9" height="6" rx="1"/><rect x="2" y="11" width="9" height="6" rx="1"/><rect x="13" y="11" width="9" height="6" rx="1"/></svg>',
  testimonials: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 8h6M6 12h10M6 16h8"/><rect x="2" y="4" width="20" height="16" rx="2"/></svg>',
  faq:          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17" stroke-width="3" stroke-linecap="round"/></svg>',
  accordion:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="5" rx="1"/><rect x="3" y="12" width="18" height="5" rx="1"/><path d="M19 7l-2 2-2-2M19 15l-2 2-2-2"/></svg>',
  pricing:      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="4" width="6" height="16" rx="1"/><rect x="10" y="2" width="6" height="18" rx="1"/><rect x="18" y="5" width="5" height="15" rx="1"/></svg>',
  image_content:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="5" width="8" height="14" rx="1"/><line x1="14" y1="9" x2="22" y2="9"/><line x1="14" y1="13" x2="20" y2="13"/><line x1="14" y1="17" x2="18" y2="17"/></svg>',
  donation_progress:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="10" width="20" height="4" rx="2"/><rect x="2" y="10" width="13" height="4" rx="2" fill="currentColor" stroke="none"/></svg>',
  donation_description:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 5c-5 0-8 3.5-8 7s3 6 8 6 8-2.5 8-6-3-7-8-7z"/><path d="M12 10v4M10 12h4"/></svg>',
  donation_goal_summary: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>',
  donor_wall:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="6" cy="8" r="3"/><circle cx="18" cy="8" r="3"/><circle cx="12" cy="5" r="3"/><path d="M2 20c0-3 1.7-5 4-5h12c2.3 0 4 2 4 5"/></svg>',
  impact_metrics:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="14" width="4" height="7"/><rect x="10" y="9" width="4" height="12"/><rect x="18" y="4" width="4" height="17"/></svg>'
};

function blockIcon(type) {
  var t = canonType(type);
  return ICONS[t] || ICONS[BLOCKS[t] && BLOCKS[t].cat] || '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>';
}

/* =========================================================================
   CONSTRUCTOR
   ========================================================================= */
function MetisBlockEditor(containerId, config) {
  this.container = document.getElementById(containerId);
  if (!this.container) throw new Error('MUBE: mount not found: ' + containerId);

  this.config  = config || {};
  this.context = String(this.config.context || 'website');
  this.onChange = typeof this.config.onChange === 'function' ? this.config.onChange : function(){};

  /* Layout — always sections model */
  var rawLayout = this.config.layout || null;
  var rawBlocks = this.config.blocks || null;
  if (rawLayout && rawLayout.sections) {
    this.layout = ensureLayout(cloneDeep(rawLayout));
  } else if (rawBlocks && rawBlocks.length) {
    this.layout = this._migrateBlocks(rawBlocks);
  } else {
    this.layout = ensureLayout({});
  }

  /* State */
  this.sel          = null;   /* {kind:'section'|'column'|'module', sIdx, cIdx, mIdx} */
  this._drag        = null;
  this._history     = [];
  this._future      = [];
  this._hLimit      = 60;
  this._hmuting     = false;
  this._panelTab    = 'general'; /* general | style | advanced — block sub-tab */
  this._sidebarTab  = 'blocks';  /* blocks | properties | settings — main sidebar tab */
  this._device      = 'desktop';
  this._saveTimer   = null;
  this._webparts    = [];
  this._cpStyle     = null;  /* copied style */
  this._forms       = [];    /* list of available forms */
  this._contextProfile = this.config.contextProfile || null;

  /* Page-level metadata (Status, Slug, SEO, etc.) — populated by page-builder */
  this._pageMeta = Object.assign({
    status: 'draft', slug: '', is_homepage: false,
    seo_title: '', seo_desc: '', excerpt: '',
    created_by: '', context: this.context,
    schedule_at: ''
  }, this.config.pageMeta || {});
  this._onPageMetaChange = typeof this.config.onPageMetaChange === 'function'
    ? this.config.onPageMetaChange : function() {};

  /* Block defs: merge server registry */
  this._defs = this._buildDefs();

  /* Pending insert target (for palette click-to-insert) */
  this._pendingInsert = null; /* {sIdx, cIdx} */

  /* Init */
  this._render();
  this._bindEvents();

  /* Expose for integration */
  global.mubeEditorInstance = this;
}

/* Migrate flat blocks[] to sections layout */
MetisBlockEditor.prototype._migrateBlocks = function(blocks) {
  var layout = { sections: [] };
  (blocks || []).forEach(function(block) {
    var b = cloneDeep(block);
    if (!b.id) b.id = uid();
    var type = canonType(b.type || 'text');
    var m = { id: b.id, type: type, data: b.data || {}, style: b.style || {} };
    var col = makeColumn(100);
    col.modules.push(m);
    var sec = makeSection(1);
    sec.columns = [col];
    layout.sections.push(sec);
  });
  return ensureLayout(layout);
};

MetisBlockEditor.prototype._buildDefs = function() {
  var defs = {};
  var serverReg = this.config.blockRegistry || null;
  Object.keys(BLOCKS).forEach(function(type) {
    var base = BLOCKS[type];
    var srv = (serverReg && serverReg[type]) ? serverReg[type] : {};
    defs[type] = {
      type: type,
      label: srv.label || base.label || type,
      cat:   srv.category || base.cat || 'content',
      defs:  Object.assign({}, base.defs, srv.defaults || {})
    };
  });
  if (serverReg) {
    Object.keys(serverReg).forEach(function(type) {
      var canon = canonType(type);
      if (!defs[canon]) {
        var d = serverReg[type];
        defs[canon] = { type: canon, label: d.label || type, cat: d.category || 'content', defs: d.defaults || {} };
      }
    });
  }
  return defs;
};

/* =========================================================================
   HISTORY
   ========================================================================= */
MetisBlockEditor.prototype._push = function() {
  if (this._hmuting) return;
  this._history.push(cloneDeep(this.layout));
  if (this._history.length > this._hLimit) this._history.shift();
  this._future = [];
};
MetisBlockEditor.prototype.undo = function() {
  if (!this._history.length) return;
  this._future.push(cloneDeep(this.layout));
  this._hmuting = true;
  this.layout = ensureLayout(this._history.pop());
  this.sel = null; this._hmuting = false;
  this._renderCanvas(); this._renderPropsPane(); this.onChange(this.layout);
};
MetisBlockEditor.prototype.redo = function() {
  if (!this._future.length) return;
  this._history.push(cloneDeep(this.layout));
  this._hmuting = true;
  this.layout = ensureLayout(this._future.pop());
  this.sel = null; this._hmuting = false;
  this._renderCanvas(); this._renderPropsPane(); this.onChange(this.layout);
};

/* =========================================================================
   SHELL HTML
   ========================================================================= */
MetisBlockEditor.prototype._render = function() {
  this.container.innerHTML = this._shellHtml();
  this._renderPalette();
  this._renderCanvas();
  this._renderPropsPane();
  this._renderSettingsPane();
};

MetisBlockEditor.prototype._shellHtml = function() {
  return [
    '<div class="mube-root mube-ctx-' + escA(this.context) + '" id="mube-root">',

    /* ── UNIFIED LEFT SIDEBAR ── */
    '  <div class="mube-sidebar" id="mube-sidebar">',

    /* Sidebar tab bar */
    '    <div class="mube-stab-bar" role="tablist">',
    '      <button type="button" class="mube-stab is-active" data-stab="blocks"     role="tab" aria-selected="true">Blocks</button>',
    '      <button type="button" class="mube-stab"           data-stab="properties" role="tab" aria-selected="false">Properties</button>',
    '      <button type="button" class="mube-stab"           data-stab="settings"   role="tab" aria-selected="false">Settings</button>',
    '    </div>',

    /* Pane: Blocks */
    '    <div class="mube-spane is-active" id="mube-spane-blocks" data-spane="blocks">',
    '      <div class="mube-palette-search-wrap">',
    '        <input id="mube-palette-search" type="search" class="mube-palette-search" placeholder="Search blocks\u2026" autocomplete="off">',
    '      </div>',
    '      <div class="mube-palette-scroll">',
    '        <div class="mube-palette" id="mube-palette"></div>',
    '        <div class="mube-webparts-wrap" id="mube-webparts-wrap">',
    '          <div class="mube-webparts-head">',
    '            <span>Saved Sections</span>',
    '            <button type="button" class="mube-icon-btn" data-action="refresh-webparts" title="Refresh">',
    '              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>',
    '            </button>',
    '          </div>',
    '          <div class="mube-webparts-list" id="mube-webparts-list"></div>',
    '        </div>',
    '      </div>',
    '    </div>',

    /* Pane: Properties (block settings — General / Style / Advanced sub-tabs) */
    '    <div class="mube-spane" id="mube-spane-properties" data-spane="properties">',
    '      <div id="mube-props-body" class="mube-props-body"></div>',
    '    </div>',

    /* Pane: Settings (page-level meta) */
    '    <div class="mube-spane" id="mube-spane-settings" data-spane="settings">',
    '      <div id="mube-settings-body" class="mube-props-body"></div>',
    '    </div>',
    '  </div>',

    /* ── CANVAS ── */
    '  <div class="mube-canvas-wrap" id="mube-canvas-wrap">',
    '    <div class="mube-canvas" id="mube-canvas" data-device="desktop">',
    '    </div>',
    '  </div>',

    /* ── FLOATING RICH-TEXT TOOLBAR ── */
    '  <div class="mube-ft" id="mube-ft" hidden></div>',

    /* ── SHORTCUTS HELP ── */
    '  <div class="mube-shortcuts" id="mube-shortcuts" hidden>',
    '    <div class="mube-shortcuts-bg" data-action="close-shortcuts"></div>',
    '    <div class="mube-shortcuts-panel" role="dialog">',
    '      <div class="mube-shortcuts-head"><strong>Keyboard Shortcuts</strong>',
    '        <button type="button" data-action="close-shortcuts" aria-label="Close">\u2715</button></div>',
    '      <div class="mube-shortcuts-grid">',
    '        <div><kbd>Ctrl+Z</kbd><span>Undo</span></div><div><kbd>Ctrl+Y</kbd><span>Redo</span></div>',
    '        <div><kbd>Ctrl+D</kbd><span>Duplicate</span></div><div><kbd>Del</kbd><span>Delete</span></div>',
    '        <div><kbd>Esc</kbd><span>Deselect</span></div><div><kbd>?</kbd><span>Shortcuts</span></div>',
    '      </div>',
    '    </div>',
    '  </div>',
    '</div>'
  ].join('\n');
};

/* =========================================================================
   PALETTE
   ========================================================================= */
MetisBlockEditor.prototype._renderPalette = function(q) {
  var palette = document.getElementById('mube-palette');
  if (!palette) return;
  q = String(q || '').trim().toLowerCase();
  var defs = this._defs;
  var html = '';

  CATS.forEach(function(cat) {
    var blocks = Object.keys(defs).filter(function(type) {
      var d = defs[type];
      if (d.cat !== cat.key) return false;
      if (!q) return true;
      return d.label.toLowerCase().indexOf(q) !== -1 || type.indexOf(q) !== -1;
    });
    if (!blocks.length) return;
    html += '<div class="mube-cat">';
    html += '<div class="mube-cat-label">' + escH(cat.label) + '</div>';
    html += '<div class="mube-cat-grid">';
    blocks.forEach(function(type) {
      var d = defs[type];
      html += '<div class="mube-pblock" draggable="true" data-block-type="' + escA(type) + '" title="' + escA(d.label) + '">';
      html += '<span class="mube-pblock-icon">' + blockIcon(type) + '</span>';
      html += '<span class="mube-pblock-label">' + escH(d.label) + '</span>';
      html += '</div>';
    });
    html += '</div></div>';
  });

  if (!html) html = '<div class="mube-palette-empty">No blocks match.</div>';
  palette.innerHTML = html;
  this._renderWebparts();
};

MetisBlockEditor.prototype._renderWebparts = function() {
  var el = document.getElementById('mube-webparts-list');
  if (!el) return;
  var items = this._webparts || [];
  if (!items.length) { el.innerHTML = '<div class="mube-webparts-empty">No saved sections yet.</div>'; return; }
  var html = '';
  items.forEach(function(wp) {
    html += '<div class="mube-webpart-item">';
    html += '<div class="mube-webpart-name">' + escH(wp.name || 'Untitled') + '</div>';
    html += '<button type="button" class="mube-btn-ghost" data-action="insert-webpart" data-id="' + escA(String(wp.id||'')) + '">Insert</button>';
    html += '</div>';
  });
  el.innerHTML = html;
};

/* =========================================================================
   CANVAS
   ========================================================================= */
MetisBlockEditor.prototype._renderCanvas = function() {
  var canvas = document.getElementById('mube-canvas');
  if (!canvas) return;
  canvas.dataset.device = this._device;
  var sections = this.layout.sections || [];
  var self = this;
  var html = '';

  if (!sections.length) {
    html = this._emptyCanvasHtml();
  } else {
    sections.forEach(function(sec, sIdx) { html += self._sectionHtml(sec, sIdx); });
    html += '<div class="mube-add-row-strip"><button type="button" class="mube-add-row-btn" data-action="add-section">+ Add Row</button></div>';
  }

  canvas.innerHTML = html;
  this._applySelClasses();
};

MetisBlockEditor.prototype._emptyCanvasHtml = function() {
  return [
    '<div class="mube-empty-canvas">',
    '  <svg viewBox="0 0 80 60" fill="none" stroke="currentColor" stroke-width="1.5" opacity=".3" class="mube-empty-canvas-svg">',
    '    <rect x="4" y="4" width="72" height="52" rx="4"/>',
    '    <rect x="12" y="14" width="56" height="8" rx="2"/>',
    '    <rect x="12" y="28" width="32" height="6" rx="2"/>',
    '    <rect x="12" y="38" width="44" height="6" rx="2"/>',
    '  </svg>',
    '  <p class="mube-empty-canvas-text">Click <strong>+ Add Row</strong> to start building</p>',
    '  <button type="button" class="mube-add-row-btn mube-add-row-btn--hero" data-action="add-section">+ Add Row</button>',
    '</div>',
    '<div class="mube-add-row-strip"><button type="button" class="mube-add-row-btn" data-action="add-section">+ Add Row</button></div>'
  ].join('\n');
};

/* =========================================================================
   SECTION HTML RENDERER
   ========================================================================= */
MetisBlockEditor.prototype._sectionHtml = function(sec, sIdx) {
  var self = this;
  var s = sec.settings || {};
  var isSel = this.sel && this.sel.kind === 'section' && this.sel.sIdx === sIdx;

  var styleArr = [];
  if (s.bg_color)       styleArr.push('background-color:' + s.bg_color);
  if (s.bg_image)       styleArr.push('background-image:url(' + escA(s.bg_image) + ');background-size:cover;background-position:center');
  if (s.padding_top)    styleArr.push('padding-top:'    + s.padding_top);
  if (s.padding_bottom) styleArr.push('padding-bottom:' + s.padding_bottom);
  var styleAttr = styleArr.length ? ' style="' + escA(styleArr.join(';')) + '"' : '';

  var html = '<div class="mube-section' + (isSel ? ' is-selected' : '') + '"'
    + ' data-kind="section" data-sidx="' + sIdx + '"' + styleAttr + '>';

  /* Controls bar — appears on hover via CSS */
  html += '<div class="mube-section-bar">';
  html += '<span class="mube-bar-label">Row</span>';
  html += this._ctrlBtn('drag-section',            sIdx, -1, -1, 'Drag Row',             this._iconDrag());
  html += this._ctrlBtn('edit-section',            sIdx, -1, -1, 'Row Settings',         this._iconEdit());
  html += this._ctrlBtn('duplicate-section',       sIdx, -1, -1, 'Duplicate Row',        this._iconDupe());
  html += this._ctrlBtn('save-section-webpart',    sIdx, -1, -1, 'Save as Template',     this._iconSave());
  html += this._ctrlBtn('delete-section',          sIdx, -1, -1, 'Delete Row',           this._iconDel(), 'mube-ctrl-del');
  html += '</div>';

  /* Inner width wrapper */
  var innerW = (s.width_mode === 'fixed') ? (s.max_width || '1200px') : '100%';
  html += '<div class="mube-section-inner" style="max-width:' + escA(innerW) + ';margin:0 auto;">';

  /* Columns */
  (sec.columns || []).forEach(function(col, cIdx) {
    html += self._columnHtml(sec, sIdx, col, cIdx);
  });

  html += '</div>'; /* section-inner */

  /* Section-level drop slots (for section reordering) */
  html += '<div class="mube-sec-drop mube-sec-drop--before" data-drop-sidx="' + sIdx + '" data-drop-pos="before"></div>';
  html += '<div class="mube-sec-drop mube-sec-drop--after"  data-drop-sidx="' + sIdx + '" data-drop-pos="after"></div>';

  html += '</div>'; /* section */
  return html;
};

/* =========================================================================
   COLUMN HTML RENDERER
   ========================================================================= */
MetisBlockEditor.prototype._columnHtml = function(sec, sIdx, col, cIdx) {
  var self = this;
  var s = col.settings || {};
  var isSel = this.sel && this.sel.kind === 'column' && this.sel.sIdx === sIdx && this.sel.cIdx === cIdx;
  var w = (typeof col.width === 'number') ? col.width : 100;

  var styleArr = ['flex:0 0 ' + w.toFixed(4) + '%;width:' + w.toFixed(4) + '%;min-width:0'];
  if (s.bg_color) styleArr.push('background-color:' + s.bg_color);
  if (s.padding)  styleArr.push('padding:' + s.padding);
  var valignMap = { center:'center', bottom:'flex-end' };
  if (s.valign)   styleArr.push('align-items:' + (valignMap[s.valign] || 'flex-start'));

  var html = '<div class="mube-column' + (isSel ? ' is-selected' : '') + '"'
    + ' data-kind="column" data-sidx="' + sIdx + '" data-cidx="' + cIdx + '"'
    + ' style="' + escA(styleArr.join(';')) + '">';

  /* Column controls bar */
  html += '<div class="mube-col-bar">';
  html += this._ctrlBtn('drag-column',      sIdx, cIdx, -1, 'Drag Column',      this._iconDrag());
  html += this._ctrlBtn('edit-column',      sIdx, cIdx, -1, 'Column Settings',  this._iconEdit());
  html += this._ctrlBtn('duplicate-column', sIdx, cIdx, -1, 'Duplicate Column', this._iconDupe());
  if ((sec.columns || []).length > 1) {
    html += this._ctrlBtn('delete-column',  sIdx, cIdx, -1, 'Delete Column',    this._iconDel(), 'mube-ctrl-del');
  }
  html += '</div>';

  /* Column resize handle (right edge, not on last column) */
  if (cIdx < (sec.columns.length - 1)) {
    html += '<div class="mube-col-resize" data-action="resize-col-start" data-sidx="' + sIdx + '" data-cidx="' + cIdx + '" title="Drag to resize columns"></div>';
  }

  /* Module drop zone */
  html += '<div class="mube-col-dropzone" data-sidx="' + sIdx + '" data-cidx="' + cIdx + '">';

  var modules = col.modules || [];
  if (!modules.length) {
    html += '<div class="mube-col-empty" data-action="focus-palette" data-sidx="' + sIdx + '" data-cidx="' + cIdx + '">Drag a block here</div>';
  }

  modules.forEach(function(mod, mIdx) {
    html += self._moduleHtml(sIdx, cIdx, mod, mIdx);
  });

  /* Trailing drop slot */
  html += '<div class="mube-mod-drop mube-mod-drop--tail" data-sidx="' + sIdx + '" data-cidx="' + cIdx + '" data-midx="' + modules.length + '" data-pos="before"></div>';

  /* Add block button */
  html += '<button type="button" class="mube-add-mod-btn" data-action="focus-palette" data-sidx="' + sIdx + '" data-cidx="' + cIdx + '">+ Add Block</button>';

  html += '</div>'; /* col-dropzone */
  html += '</div>'; /* column */
  return html;
};

/* =========================================================================
   MODULE HTML RENDERER
   ========================================================================= */
MetisBlockEditor.prototype._moduleHtml = function(sIdx, cIdx, mod, mIdx) {
  var isSel = this.sel && this.sel.kind === 'module'
    && this.sel.sIdx === sIdx && this.sel.cIdx === cIdx && this.sel.mIdx === mIdx;
  var def = this._defs[canonType(mod.type)] || {};
  var label = def.label || mod.type;
  var style = mod.style || {};

  /* Module-level style */
  var styleArr = [];
  if (style.margin_top)    styleArr.push('margin-top:'    + style.margin_top);
  if (style.margin_bottom) styleArr.push('margin-bottom:' + style.margin_bottom);
  if (style.padding)       styleArr.push('padding:'       + style.padding);
  if (style.bg_color)      styleArr.push('background-color:' + style.bg_color);
  if (style.color)         styleArr.push('color:' + style.color);
  if (style.border_radius) styleArr.push('border-radius:' + style.border_radius);
  var styleAttr = styleArr.length ? ' style="' + escA(styleArr.join(';')) + '"' : '';

  var html = '';

  /* Drop slot BEFORE this module */
  html += '<div class="mube-mod-drop" data-sidx="' + sIdx + '" data-cidx="' + cIdx + '" data-midx="' + mIdx + '" data-pos="before"></div>';

  html += '<div class="mube-module' + (isSel ? ' is-selected' : '') + '"'
    + ' data-kind="module" data-sidx="' + sIdx + '" data-cidx="' + cIdx + '" data-midx="' + mIdx + '"'
    + ' data-mod-id="' + escA(mod.id || '') + '"' + styleAttr + '>';

  /* Module controls bar — visible on hover/select via CSS */
  html += '<div class="mube-mod-bar">';
  html += '<span class="mube-bar-label">' + escH(label) + '</span>';
  html += this._ctrlBtn('drag-module',        sIdx, cIdx, mIdx, 'Drag',           this._iconDrag());
  html += this._ctrlBtn('edit-module',        sIdx, cIdx, mIdx, 'Settings',       this._iconEdit());
  html += this._ctrlBtn('duplicate-module',   sIdx, cIdx, mIdx, 'Duplicate',      this._iconDupe());
  html += this._ctrlBtn('copy-module-style',  sIdx, cIdx, mIdx, 'Copy Style',     this._iconCopyStyle());
  html += this._ctrlBtn('paste-module-style', sIdx, cIdx, mIdx, 'Paste Style',    this._iconPasteStyle());
  html += this._ctrlBtn('delete-module',      sIdx, cIdx, mIdx, 'Delete',         this._iconDel(), 'mube-ctrl-del');
  html += '</div>';

  /* Preview area */
  html += '<div class="mube-mod-preview" data-sidx="' + sIdx + '" data-cidx="' + cIdx + '" data-midx="' + mIdx + '">';
  html += this._modulePreview(mod);
  html += '</div>';

  html += '</div>'; /* module */
  return html;
};

/* =========================================================================
   CONTROL BUTTON HELPER + ICONS
   ========================================================================= */
MetisBlockEditor.prototype._ctrlBtn = function(action, sIdx, cIdx, mIdx, title, iconHtml, extraClass) {
  var attrs = ' data-action="' + action + '"';
  if (sIdx >= 0) attrs += ' data-sidx="' + sIdx + '"';
  if (cIdx >= 0) attrs += ' data-cidx="' + cIdx + '"';
  if (mIdx >= 0) attrs += ' data-midx="' + mIdx + '"';
  var cls = 'mube-ctrl-btn' + (extraClass ? ' ' + extraClass : '');
  return '<button type="button" class="' + cls + '"' + attrs + ' title="' + escA(title) + '" draggable="' + (action.indexOf('drag') === 0 ? 'true' : 'false') + '">' + iconHtml + '</button>';
};
MetisBlockEditor.prototype._iconDrag       = function() { return '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="9"  cy="6"  r="1.4"/><circle cx="15" cy="6"  r="1.4"/><circle cx="9"  cy="12" r="1.4"/><circle cx="15" cy="12" r="1.4"/><circle cx="9"  cy="18" r="1.4"/><circle cx="15" cy="18" r="1.4"/></svg>'; };
MetisBlockEditor.prototype._iconEdit       = function() { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4z"/></svg>'; };
MetisBlockEditor.prototype._iconDupe       = function() { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>'; };
MetisBlockEditor.prototype._iconSave       = function() { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>'; };
MetisBlockEditor.prototype._iconDel        = function() { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>'; };
MetisBlockEditor.prototype._iconCopyStyle  = function() { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5z"/><path d="M2 17l10 5 10-5M2 12l10 5 10-5"/></svg>'; };
MetisBlockEditor.prototype._iconPasteStyle = function() { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1.5"/></svg>'; };

/* =========================================================================
   MODULE PREVIEW RENDERER (inline client-side preview)
   ========================================================================= */
MetisBlockEditor.prototype._modulePreview = function(mod) {
  var type = canonType(mod.type || 'text');
  var data = mod.data || {};

  switch (type) {
    case 'text':
      /* content is richtext HTML — render directly, safe because it came from contenteditable */
      var textHtml = String(data.content || '<p><em>Double-click to edit text</em></p>');
      return '<div class="mube-prev-richtext">' + textHtml + '</div>';

    case 'heading':
      var tag = /^h[1-6]$/.test(data.level || '') ? data.level : 'h2';
      var align = ['left','center','right'].indexOf(data.align) !== -1 ? data.align : 'left';
      /* content may be plain text or richtext — strip any outer tags and render clean */
      var headingText = String(data.content || 'Your Heading').replace(/<[^>]+>/g, '').trim() || 'Your Heading';
      return '<' + tag + ' class="mube-prev-heading" style="text-align:' + align + ';margin:0;font-size:inherit;">' + escH(headingText) + '</' + tag + '>';

    case 'image':
      if (!data.src) return this._placeholder('image', 'Image', 'No image selected');
      var imgStyle = data.width ? 'max-width:' + escA(data.width) + ';' : '';
      return '<img src="' + escA(data.src) + '" alt="' + escA(data.alt||'') + '" style="display:block;' + imgStyle + 'max-width:100%;">';

    case 'button':
      return '<div class="mube-prev-btn-wrap"><span class="mube-prev-btn">' + escH(data.label || 'Button') + '</span></div>';

    case 'spacer':
      var h = Math.max(8, parseInt(data.height, 10) || 40);
      return '<div class="mube-prev-spacer" style="height:' + h + 'px;" title="Spacer: ' + h + 'px"><span>' + h + 'px</span></div>';

    case 'divider':
      return '<hr class="mube-prev-divider" style="border:none;border-top:' + escA(String(data.height||1)) + 'px ' + escA(data.style||'solid') + ' ' + escA(data.color||'#e2e6ea') + ';margin:8px 0;">';

    case 'video':
      return this._placeholder('video', 'Video', data.url ? data.url.slice(0,40) : 'No URL set');

    case 'html':
      return '<div class="mube-prev-html"><pre>' + escH((data.content||'').slice(0,200)) + '</pre></div>';

    case 'hero':
      return '<div class="mube-prev-hero"><div class="mube-prev-hero-title">' + escH(data.title||'Hero') + '</div>'
        + '<div class="mube-prev-hero-body">' + escH((data.content||'').replace(/<[^>]*>/g,'').slice(0,80)) + '</div>'
        + (data.button_label ? '<span class="mube-prev-btn">' + escH(data.button_label) + '</span>' : '') + '</div>';

    case 'cta':
      return '<div class="mube-prev-cta"><strong>' + escH(data.title||'CTA') + '</strong>'
        + (data.button_label ? ' <span class="mube-prev-btn">' + escH(data.button_label) + '</span>' : '') + '</div>';

    case 'form_embed':
      return this._placeholder('form_embed', 'Form Embed', data.form_id ? 'Form ID: ' + data.form_id : 'No form selected');

    case 'donation_form':
      return this._placeholder('donation_form', 'Donation Form', data.campaign_id ? 'Campaign: ' + data.campaign_id : 'Select a campaign');

    case 'donation_progress':
      return this._placeholder('donation_progress', 'Donation Progress', data.campaign_id || 'Select a campaign');

    case 'menu':
      return this._placeholder('menu', 'Navigation Menu', data.menu_id ? 'Menu ID: ' + data.menu_id : 'No menu selected');

    case 'gallery':
      var imgs = Array.isArray(data.images) ? data.images : [];
      return this._placeholder('gallery', 'Gallery', imgs.length + ' image' + (imgs.length !== 1 ? 's' : ''));

    case 'tabs':
      var tabs = Array.isArray(data.items) ? data.items : [];
      return this._placeholder('tabs', 'Tabs', tabs.length + ' tab' + (tabs.length !== 1 ? 's' : ''));

    case 'accordion':
    case 'faq':
      var items = Array.isArray(data.items) ? data.items : [];
      return this._placeholder(type, type === 'faq' ? 'FAQ' : 'Accordion', items.length + ' item' + (items.length !== 1 ? 's' : ''));

    case 'countdown':
      return this._placeholder('countdown', 'Countdown', data.end_at || 'No end date set');

    case 'anchor':
      return this._placeholder('anchor', 'Anchor', data.anchor_id ? '#' + data.anchor_id : 'No anchor ID');

    default:
      var d = this._defs[type] || {};
      return this._placeholder(type, d.label || type, '');
  }
};

MetisBlockEditor.prototype._placeholder = function(type, label, sub) {
  return '<div class="mube-prev-placeholder">'
    + '<span class="mube-prev-placeholder-icon">' + blockIcon(type) + '</span>'
    + '<div class="mube-prev-placeholder-text">'
    + '<span class="mube-prev-placeholder-label">' + escH(label) + '</span>'
    + (sub ? '<span class="mube-prev-placeholder-sub">' + escH(sub) + '</span>' : '')
    + '</div></div>';
};

/* =========================================================================
   SELECTION STATE
   ========================================================================= */
MetisBlockEditor.prototype._applySelClasses = function() {
  var canvas = document.getElementById('mube-canvas');
  if (!canvas) return;
  canvas.querySelectorAll('.is-selected').forEach(function(el) { el.classList.remove('is-selected'); });
  if (!this.sel) return;
  var sel = this.sel;
  var q = '';
  if (sel.kind === 'section') {
    q = '[data-kind="section"][data-sidx="' + sel.sIdx + '"]';
  } else if (sel.kind === 'column') {
    q = '[data-kind="column"][data-sidx="' + sel.sIdx + '"][data-cidx="' + sel.cIdx + '"]';
  } else if (sel.kind === 'module') {
    q = '[data-kind="module"][data-sidx="' + sel.sIdx + '"][data-cidx="' + sel.cIdx + '"][data-midx="' + sel.mIdx + '"]';
  }
  if (q) { var el = canvas.querySelector(q); if (el) el.classList.add('is-selected'); }
};

MetisBlockEditor.prototype._select = function(sel) {
  this.sel = sel;
  this._applySelClasses();
  if (sel) this._switchSidebarTab('properties');
  this._renderPropsPane();
};

MetisBlockEditor.prototype._deselect = function() {
  this.sel = null;
  this._applySelClasses();
  this._renderPropsPane();
  /* Stay on whichever tab we're on — don't force back to blocks */
};

/* =========================================================================
   SIDEBAR TAB SWITCHING
   ========================================================================= */
MetisBlockEditor.prototype._switchSidebarTab = function(tab) {
  this._sidebarTab = tab;
  var root = document.getElementById('mube-root');
  if (!root) return;
  /* Update tab buttons */
  root.querySelectorAll('.mube-stab').forEach(function(btn) {
    var active = btn.dataset.stab === tab;
    btn.classList.toggle('is-active', active);
    btn.setAttribute('aria-selected', active ? 'true' : 'false');
  });
  /* Update panes */
  root.querySelectorAll('.mube-spane').forEach(function(pane) {
    pane.classList.toggle('is-active', pane.dataset.spane === tab);
  });
};

/* =========================================================================
   PROPERTIES PANE  (block General / Style / Advanced)
   ========================================================================= */
MetisBlockEditor.prototype._renderPropsPane = function() {
  var body = document.getElementById('mube-props-body');
  if (!body) return;
  body.innerHTML = this._propsPaneContent();
  var self = this;
  body.querySelectorAll('[data-bind]').forEach(function(el) { self._bindInput(el); });
};

MetisBlockEditor.prototype._propsPaneContent = function() {
  if (!this.sel) {
    return '<div class="mube-props-empty">'
      + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" opacity=".3"><path d="M3 6h18M3 12h18M3 18h18"/></svg>'
      + '<p>Click a block on the canvas<br>to edit its settings.</p>'
      + '</div>';
  }
  var sel = this.sel;
  var tab = this._panelTab;

  /* Sub-tab bar */
  var subTabs = ['general','style','advanced'].map(function(t) {
    return '<button type="button" class="mube-ptab' + (tab === t ? ' is-active' : '') + '" data-ptab="' + t + '">'
      + t.charAt(0).toUpperCase() + t.slice(1) + '</button>';
  }).join('');
  var html = '<div class="mube-ptab-bar">' + subTabs + '</div>';

  if (sel.kind === 'section') html += this._panelSection(sel, tab);
  else if (sel.kind === 'column')  html += this._panelColumn(sel, tab);
  else if (sel.kind === 'module')  html += this._panelModule(sel, tab);
  return html;
};

/* =========================================================================
   SETTINGS PANE  (page-level metadata)
   ========================================================================= */
MetisBlockEditor.prototype._renderSettingsPane = function() {
  var body = document.getElementById('mube-settings-body');
  if (!body) return;
  body.innerHTML = this._settingsPaneContent();
  var self = this;
  /* Bind all settings inputs */
  body.querySelectorAll('[data-page-bind]').forEach(function(el) {
    self._bindPageMetaInput(el);
  });
};

MetisBlockEditor.prototype._settingsPaneContent = function() {
  var m   = this._pageMeta || {};
  var ctx = String(m.context || this.context || 'website');
  var isPage     = ctx === 'website' || ctx === 'page';
  var isPost     = ctx === 'post';
  var isTemplate = ctx === 'template';
  var isNewsletter = ctx === 'newsletter' || ctx === 'newsletter_template';

  var statusOpts = isTemplate
    ? [{v:'draft',l:'Draft'},{v:'published',l:'Published'}]
    : [{v:'draft',l:'Draft'},{v:'scheduled',l:'Scheduled'},{v:'published',l:'Published'}];

  var html = '<div class="mube-settings-section">';
  html += '<div class="mube-settings-label">Page</div>';

  /* Status */
  html += '<div class="mube-pfield">';
  html += '<label class="mube-plabel">Status</label>';
  html += '<select class="mube-fi" id="mube-meta-status" data-page-bind="status">';
  statusOpts.forEach(function(o) {
    html += '<option value="' + escA(o.v) + '"' + (m.status === o.v ? ' selected' : '') + '>' + escH(o.l) + '</option>';
  });
  html += '</select></div>';

  /* Scheduled At — shown only when status=scheduled */
  var schedDisplay = m.status === 'scheduled' ? '' : ' style="display:none"';
  html += '<div class="mube-pfield" id="mube-meta-schedule-wrap"' + schedDisplay + '>';
  html += '<label class="mube-plabel">Publish Date</label>';
  html += '<input type="datetime-local" class="mube-fi" id="mube-meta-schedule-at" data-page-bind="schedule_at" value="' + escA(m.schedule_at || '') + '">';
  html += '</div>';

  /* Slug */
  if (!isNewsletter) {
    html += '<div class="mube-pfield">';
    html += '<label class="mube-plabel">Slug</label>';
    html += '<input type="text" class="mube-fi" id="mube-meta-slug" data-page-bind="slug" value="' + escA(m.slug || '') + '" placeholder="my-page-slug">';
    html += '</div>';
  }

  /* Set as Homepage — pages only */
  if (isPage) {
    html += '<div class="mube-pfield mube-pfield--check">';
    html += '<label class="mube-check-label">';
    html += '<input type="checkbox" id="mube-meta-homepage" data-page-bind="is_homepage"' + (m.is_homepage ? ' checked' : '') + '>';
    html += '<span>Set as Homepage</span></label>';
    html += '</div>';
  }

  /* Excerpt — posts only */
  if (isPost) {
    html += '<div class="mube-pfield">';
    html += '<label class="mube-plabel">Excerpt</label>';
    html += '<textarea class="mube-fi mube-fita" rows="3" id="mube-meta-excerpt" data-page-bind="excerpt" placeholder="Short summary\u2026">' + escH(m.excerpt || '') + '</textarea>';
    html += '</div>';
  }

  html += '</div>'; /* end mube-settings-section */

  /* SEO section — not for newsletter */
  if (!isNewsletter) {
    html += '<div class="mube-settings-section">';
    html += '<div class="mube-settings-label">SEO</div>';
    html += '<div class="mube-pfield">';
    html += '<label class="mube-plabel">SEO Title</label>';
    html += '<input type="text" class="mube-fi" id="mube-meta-seo-title" data-page-bind="seo_title" value="' + escA(m.seo_title || '') + '" placeholder="Overrides page title\u2026">';
    html += '</div>';
    html += '<div class="mube-pfield">';
    html += '<label class="mube-plabel">Meta Description</label>';
    html += '<textarea class="mube-fi mube-fita" rows="3" id="mube-meta-seo-desc" data-page-bind="seo_desc" placeholder="160 chars max\u2026">' + escH(m.seo_desc || '') + '</textarea>';
    html += '</div>';
    html += '</div>';
  }

  /* Info section */
  html += '<div class="mube-settings-section">';
  html += '<div class="mube-settings-label">Info</div>';
  html += '<div class="mube-pfield">';
  html += '<label class="mube-plabel">Created By</label>';
  html += '<div class="mube-meta-value">' + escH(m.created_by || '\u2014') + '</div>';
  html += '</div>';
  html += '</div>';

  return html;
};

/* Bind a page-meta input to _pageMeta and fire onPageMetaChange */
MetisBlockEditor.prototype._bindPageMetaInput = function(el) {
  var self = this;
  var key = el.dataset.pageBind;
  if (!key) return;
  var handler = function() {
    var val = el.type === 'checkbox' ? el.checked : el.value;
    self._pageMeta[key] = val;
    /* Show/hide scheduled date field */
    if (key === 'status') {
      var wrap = document.getElementById('mube-meta-schedule-wrap');
      if (wrap) wrap.style.display = val === 'scheduled' ? '' : 'none';
    }
    self._onPageMetaChange(Object.assign({}, self._pageMeta));
  };
  el.addEventListener('input',  handler);
  el.addEventListener('change', handler);
};

/* Public method for page-builder to push updated metadata into editor */
MetisBlockEditor.prototype.setPageMeta = function(meta) {
  if (!meta || typeof meta !== 'object') return;
  Object.assign(this._pageMeta, meta);
  this._renderSettingsPane();
};

/* helper shortcuts */
MetisBlockEditor.prototype._fi = function(label, bind, val, type, extra, sidx, cidx, midx) {
  type = type || 'text';
  var attrs = ' data-bind="' + escA(bind) + '"';
  if (sidx !== undefined) attrs += ' data-sidx="' + sidx + '"';
  if (cidx !== undefined) attrs += ' data-cidx="' + cidx + '"';
  if (midx !== undefined) attrs += ' data-midx="' + midx + '"';
  var cls = type === 'color' ? 'mube-fi-color' : 'mube-fi';
  return '<div class="mube-pfield"><label class="mube-plabel">' + escH(label) + '</label>'
    + '<input type="' + type + '" class="' + cls + '" value="' + escA(val||'') + '"' + attrs + (extra||'') + '></div>';
};
MetisBlockEditor.prototype._fsel = function(label, bind, val, options, sidx, cidx, midx) {
  var attrs = ' data-bind="' + escA(bind) + '"';
  if (sidx !== undefined) attrs += ' data-sidx="' + sidx + '"';
  if (cidx !== undefined) attrs += ' data-cidx="' + cidx + '"';
  if (midx !== undefined) attrs += ' data-midx="' + midx + '"';
  var opts = options.map(function(o) {
    var v = typeof o === 'object' ? o.v : o;
    var l = typeof o === 'object' ? o.l : o;
    return '<option value="' + escA(v) + '"' + (val === v ? ' selected' : '') + '>' + escH(l) + '</option>';
  }).join('');
  return '<div class="mube-pfield"><label class="mube-plabel">' + escH(label) + '</label>'
    + '<select class="mube-fi"' + attrs + '>' + opts + '</select></div>';
};
MetisBlockEditor.prototype._fta = function(label, bind, val, sidx, cidx, midx) {
  var attrs = ' data-bind="' + escA(bind) + '"';
  if (sidx !== undefined) attrs += ' data-sidx="' + sidx + '"';
  if (cidx !== undefined) attrs += ' data-cidx="' + cidx + '"';
  if (midx !== undefined) attrs += ' data-midx="' + midx + '"';
  return '<div class="mube-pfield"><label class="mube-plabel">' + escH(label) + '</label>'
    + '<textarea class="mube-fi mube-fita" rows="6"' + attrs + '>' + escH(val||'') + '</textarea></div>';
};

/* ---- Section panel ---- */
MetisBlockEditor.prototype._panelSection = function(sel, tab) {
  var sec = this._getSection(sel.sIdx); if (!sec) return '';
  var s = sec.settings || {};
  var si = sel.sIdx;
  var visOpts = [{v:'all',l:'All devices'},{v:'desktop_only',l:'Desktop only'},{v:'mobile_only',l:'Mobile only'}];
  var aniOpts = [{v:'',l:'None'},{v:'fade-in',l:'Fade In'},{v:'slide-up',l:'Slide Up'},{v:'slide-left',l:'Slide Left'}];

  if (tab === 'general') {
    var colCount = (sec.columns||[]).length;
    var btns = [1,2,3,4].map(function(n) {
      return '<button type="button" class="mube-layout-btn' + (colCount === n ? ' is-active' : '') + '"'
        + ' data-action="set-section-cols" data-sidx="' + si + '" data-cols="' + n + '" title="' + n + ' column' + (n>1?'s':'') + '">'
        + (['&#9646;','&#9646;&#9646;','&#9646;&#9646;&#9646;','&#9646;&#9646;&#9646;&#9646;'][n-1]) + '</button>';
    }).join('');
    return '<div class="mube-ptype-label">Row</div>'
      + '<div class="mube-pfield"><label class="mube-plabel">Column Layout</label><div class="mube-layout-btns">' + btns + '</div></div>'
      + this._fsel('Width', 'section.settings.width_mode', s.width_mode||'full', [{v:'full',l:'Full Width'},{v:'fixed',l:'Fixed Width'}], si)
      + (s.width_mode === 'fixed' ? this._fi('Max Width', 'section.settings.max_width', s.max_width||'1200px', 'text', ' placeholder="1200px"', si) : '');
  }
  if (tab === 'style') {
    return '<div class="mube-ptype-label">Row Style</div>'
      + this._fi('Background Color', 'section.settings.bg_color', s.bg_color||'#ffffff', 'color', '', si)
      + this._fi('Background Image', 'section.settings.bg_image', s.bg_image||'', 'text', ' placeholder="https://…"', si)
      + this._fi('Padding Top',    'section.settings.padding_top',    s.padding_top||'',    'text', ' placeholder="e.g. 60px"', si)
      + this._fi('Padding Bottom', 'section.settings.padding_bottom', s.padding_bottom||'', 'text', ' placeholder="e.g. 60px"', si)
      + this._fi('Padding Left',   'section.settings.padding_left',   s.padding_left||'',   'text', ' placeholder="e.g. 0"', si)
      + this._fi('Padding Right',  'section.settings.padding_right',  s.padding_right||'',  'text', ' placeholder="e.g. 0"', si);
  }
  if (tab === 'advanced') {
    return '<div class="mube-ptype-label">Row Advanced</div>'
      + this._fi('Anchor ID',  'section.settings.anchor_id',  s.anchor_id||'',  'text', ' placeholder="my-section"', si)
      + this._fi('CSS Class',  'section.settings.css_class',  s.css_class||'',  'text', '', si)
      + this._fsel('Visibility', 'section.settings.visibility', s.visibility||'all', visOpts, si)
      + this._fsel('Animation',  'section.settings.animation',  s.animation||'',   aniOpts, si);
  }
  return '';
};

/* ---- Column panel ---- */
MetisBlockEditor.prototype._panelColumn = function(sel, tab) {
  var col = this._getColumn(sel.sIdx, sel.cIdx); if (!col) return '';
  var s = col.settings || {};
  var si = sel.sIdx; var ci = sel.cIdx;
  var visOpts = [{v:'all',l:'All devices'},{v:'desktop_only',l:'Desktop only'},{v:'mobile_only',l:'Mobile only'}];

  if (tab === 'general') {
    return '<div class="mube-ptype-label">Column</div>'
      + '<div class="mube-pfield"><label class="mube-plabel">Width (%)</label>'
      + '<input type="range" class="mube-fi-range" min="10" max="90" step="1" value="' + Math.round(col.width||50) + '"'
      + ' data-bind="column.width" data-sidx="' + si + '" data-cidx="' + ci + '">'
      + '<input type="number" class="mube-fi mube-fi-narrow" min="10" max="90" value="' + Math.round(col.width||50) + '"'
      + ' data-bind="column.width" data-sidx="' + si + '" data-cidx="' + ci + '"><span class="mube-fi-unit">%</span></div>'
      + this._fsel('Vertical Align', 'column.settings.valign', s.valign||'top',
          [{v:'top',l:'Top'},{v:'center',l:'Center'},{v:'bottom',l:'Bottom'}], si, ci);
  }
  if (tab === 'style') {
    return '<div class="mube-ptype-label">Column Style</div>'
      + this._fi('Background Color', 'column.settings.bg_color', s.bg_color||'#ffffff', 'color', '', si, ci)
      + this._fi('Padding', 'column.settings.padding', s.padding||'', 'text', ' placeholder="e.g. 20px 16px"', si, ci);
  }
  if (tab === 'advanced') {
    return '<div class="mube-ptype-label">Column Advanced</div>'
      + this._fi('CSS Class', 'column.settings.css_class', s.css_class||'', 'text', '', si, ci)
      + this._fsel('Visibility', 'column.settings.visibility', s.visibility||'all', visOpts, si, ci);
  }
  return '';
};

/* ---- Module panel ---- */
MetisBlockEditor.prototype._panelModule = function(sel, tab) {
  var mod = this._getModule(sel.sIdx, sel.cIdx, sel.mIdx); if (!mod) return '';
  var type = canonType(mod.type || 'text');
  var def = this._defs[type] || {};
  var d = mod.data || {};
  var s = mod.style || {};
  var si = sel.sIdx; var ci = sel.cIdx; var mi = sel.mIdx;
  var visOpts = [{v:'all',l:'All devices'},{v:'desktop_only',l:'Desktop only'},{v:'mobile_only',l:'Mobile only'}];
  var aniOpts = [{v:'',l:'None'},{v:'fade-in',l:'Fade In'},{v:'slide-up',l:'Slide Up'}];

  if (tab === 'general') {
    var html = '<div class="mube-ptype-label">' + escH(def.label||type) + '</div>';
    switch (type) {
      case 'text':
        html += '<div class="mube-pfield mube-pfield--info"><p>Double-click the block on the canvas to edit text inline.</p></div>';
        break;
      case 'heading':
        html += '<div class="mube-pfield mube-pfield--info"><p>Double-click to edit inline.</p></div>';
        html += this._fsel('Level', 'module.data.level', d.level||'h2', ['h1','h2','h3','h4','h5','h6'].map(function(t){return{v:t,l:t.toUpperCase()};}), si, ci, mi);
        html += this._fsel('Align', 'module.data.align', d.align||'left', [{v:'left',l:'Left'},{v:'center',l:'Center'},{v:'right',l:'Right'}], si, ci, mi);
        break;
      case 'image':
        html += this._fi('Image URL', 'module.data.src',  d.src||'',  'text', ' placeholder="https://…"', si, ci, mi);
        html += this._fi('Alt Text',  'module.data.alt',  d.alt||'',  'text', ' placeholder="Describe the image"', si, ci, mi);
        html += this._fi('Link URL',  'module.data.link', d.link||'', 'text', ' placeholder="https://…"', si, ci, mi);
        html += this._fi('Width',     'module.data.width',d.width||'100%','text',' placeholder="100%"', si, ci, mi);
        break;
      case 'button':
        html += this._fi('Label', 'module.data.label', d.label||'Click Here', 'text', '', si, ci, mi);
        html += this._fi('URL',   'module.data.url',   d.url||'#',            'text', ' placeholder="https://…"', si, ci, mi);
        html += this._fsel('Style', 'module.data.style', d.style||'primary',
          [{v:'primary',l:'Primary'},{v:'secondary',l:'Secondary'},{v:'outline',l:'Outline'},{v:'ghost',l:'Ghost'}], si, ci, mi);
        break;
      case 'spacer':
        html += this._fi('Height (px)', 'module.data.height', String(d.height||40), 'number', ' min="0" max="600"', si, ci, mi);
        break;
      case 'divider':
        html += this._fi('Color',     'module.data.color',  d.color||'#e2e6ea', 'color', '', si, ci, mi);
        html += this._fsel('Style',   'module.data.style',  d.style||'solid',   ['solid','dashed','dotted'], si, ci, mi);
        html += this._fi('Height (px)','module.data.height',String(d.height||1),'number',' min="1" max="20"', si, ci, mi);
        break;
      case 'hero':
        html += this._fi('Title',        'module.data.title',        d.title||'',        'text', '', si, ci, mi);
        html += this._fi('Button Label', 'module.data.button_label', d.button_label||'', 'text', '', si, ci, mi);
        html += this._fi('Button URL',   'module.data.button_url',   d.button_url||'',   'text', ' placeholder="https://…"', si, ci, mi);
        html += this._fi('Background Image','module.data.background_image',d.background_image||'','text',' placeholder="https://…"', si, ci, mi);
        break;
      case 'cta':
        html += this._fi('Title',        'module.data.title',        d.title||'',        'text', '', si, ci, mi);
        html += this._fi('Button Label', 'module.data.button_label', d.button_label||'', 'text', '', si, ci, mi);
        html += this._fi('Button URL',   'module.data.button_url',   d.button_url||'',   'text', ' placeholder="https://…"', si, ci, mi);
        break;
      case 'video':
        html += this._fi('Video URL', 'module.data.url', d.url||'', 'text', ' placeholder="https://youtube.com/…"', si, ci, mi);
        html += this._fsel('Provider', 'module.data.provider', d.provider||'youtube',
          [{v:'youtube',l:'YouTube'},{v:'vimeo',l:'Vimeo'},{v:'local',l:'Self-hosted'}], si, ci, mi);
        html += this._fsel('Aspect Ratio', 'module.data.aspect_ratio', d.aspect_ratio||'16:9',
          ['16:9','4:3','1:1'].map(function(r){return{v:r,l:r};}), si, ci, mi);
        break;
      case 'html':
        html += this._fta('HTML Content', 'module.data.content', d.content||'', si, ci, mi);
        html += '<div class="mube-pfield mube-pfield--info"><p>JavaScript execution is not allowed.</p></div>';
        break;
      case 'form_embed':
      case 'modal_trigger':
      case 'popup_trigger':
        html += this._fi('ID', 'module.data.' + (type === 'form_embed' ? 'form_id' : 'popup_id'),
          String(d.form_id || d.popup_id || ''), 'text', ' placeholder="Select from list…"', si, ci, mi);
        html += '<div class="mube-pfield mube-pfield--info"><p>Only existing forms/popups can be selected.</p></div>';
        break;
      case 'countdown':
        html += this._fi('End Date/Time', 'module.data.end_at', d.end_at||'', 'datetime-local', '', si, ci, mi);
        break;
      case 'anchor':
        html += this._fi('Anchor ID', 'module.data.anchor_id', d.anchor_id||'', 'text', ' placeholder="my-section"', si, ci, mi);
        break;
      case 'menu':
        html += this._fsel('Orientation', 'module.data.orientation', d.orientation||'horizontal',
          [{v:'horizontal',l:'Horizontal'},{v:'vertical',l:'Vertical'}], si, ci, mi);
        break;
      default:
        html += '<div class="mube-pfield mube-pfield--info"><p>Use the Style and Advanced tabs to customize this block.</p></div>';
    }
    return html;
  }
  if (tab === 'style') {
    return '<div class="mube-ptype-label">' + escH(def.label||type) + ' Style</div>'
      + this._fi('Margin Top',     'module.style.margin_top',    s.margin_top||'',    'text', ' placeholder="e.g. 24px"', si, ci, mi)
      + this._fi('Margin Bottom',  'module.style.margin_bottom', s.margin_bottom||'', 'text', ' placeholder="e.g. 24px"', si, ci, mi)
      + this._fi('Padding',        'module.style.padding',       s.padding||'',       'text', ' placeholder="e.g. 16px 24px"', si, ci, mi)
      + this._fi('Background',     'module.style.bg_color',      s.bg_color||'#ffffff','color','', si, ci, mi)
      + this._fi('Text Color',     'module.style.color',         s.color||'#111827',  'color','', si, ci, mi)
      + this._fi('Border Radius',  'module.style.border_radius', s.border_radius||'', 'text', ' placeholder="e.g. 8px"', si, ci, mi);
  }
  if (tab === 'advanced') {
    return '<div class="mube-ptype-label">' + escH(def.label||type) + ' Advanced</div>'
      + this._fi('Anchor ID', 'module.style.anchor_id', s.anchor_id||'', 'text', ' placeholder="my-block"', si, ci, mi)
      + this._fi('CSS Class', 'module.style.css_class', s.css_class||'', 'text', '', si, ci, mi)
      + this._fsel('Visibility', 'module.style.visibility', s.visibility||'all', visOpts, si, ci, mi)
      + this._fsel('Animation',  'module.style.animation',  s.animation||'',   aniOpts, si, ci, mi);
  }
  return '';
};

/* =========================================================================
   DATA BINDING — live panel input → layout
   ========================================================================= */
MetisBlockEditor.prototype._bindInput = function(el) {
  var self = this;
  var key = el.dataset.bind;
  if (!key) return;

  var handler = function() {
    var val = el.type === 'checkbox' ? el.checked : el.value;
    var si = el.dataset.sidx !== undefined ? +el.dataset.sidx : -1;
    var ci = el.dataset.cidx !== undefined ? +el.dataset.cidx : -1;
    var mi = el.dataset.midx !== undefined ? +el.dataset.midx : -1;
    self._applyBind(key, val, si, ci, mi);
  };

  el.addEventListener('input',  handler);
  el.addEventListener('change', handler);
};

MetisBlockEditor.prototype._applyBind = function(key, val, si, ci, mi) {
  /* Segment the key */
  var parts = key.split('.');       /* e.g. section.settings.bg_color */
  var entity = parts[0];            /* section | column | module */
  var prop   = parts.slice(1).join('.');  /* settings.bg_color | data.src | width */

  this._push();

  if (entity === 'section') {
    var sec = this._getSection(si); if (!sec) return;
    this._setNestedKey(sec, prop, val);
  } else if (entity === 'column') {
    if (prop === 'width') {
      /* Width change: adjust sibling column to compensate */
      var sec = this._getSection(si); var col = this._getColumn(si, ci);
      if (sec && col) {
        var newW = Math.max(10, Math.min(90, parseFloat(val) || 50));
        var diff = newW - col.width;
        var others = (sec.columns || []).filter(function(c) { return c !== col; });
        if (others.length === 1) others[0].width = Math.max(10, others[0].width - diff);
        col.width = newW;
        /* Sync paired width input/range in panel */
        var panel = document.getElementById('mube-props-body');
        if (panel) {
          panel.querySelectorAll('[data-bind="column.width"]').forEach(function(el) {
            if (el !== document.activeElement) el.value = String(Math.round(newW));
          });
        }
      }
    } else {
      var col = this._getColumn(si, ci); if (!col) return;
      this._setNestedKey(col, prop, val);
    }
  } else if (entity === 'module') {
    var mod = this._getModule(si, ci, mi); if (!mod) return;
    this._setNestedKey(mod, prop, val);
  }

  this._renderCanvas();
  this._applySelClasses();
  this._scheduleAutosave();
};

MetisBlockEditor.prototype._setNestedKey = function(obj, dotKey, val) {
  var parts = dotKey.split('.');
  var cur = obj;
  for (var i = 0; i < parts.length - 1; i++) {
    if (cur[parts[i]] === undefined || cur[parts[i]] === null) cur[parts[i]] = {};
    cur = cur[parts[i]];
  }
  cur[parts[parts.length - 1]] = val;
};

/* =========================================================================
   DATA ACCESSORS
   ========================================================================= */
MetisBlockEditor.prototype._getSection = function(si) { return (this.layout.sections||[])[si]||null; };
MetisBlockEditor.prototype._getColumn  = function(si, ci) { var s=this._getSection(si); return s?(s.columns||[])[ci]||null:null; };
MetisBlockEditor.prototype._getModule  = function(si, ci, mi) { var c=this._getColumn(si,ci); return c?(c.modules||[])[mi]||null:null; };

/* =========================================================================
   SECTION CRUD
   ========================================================================= */
MetisBlockEditor.prototype._addSection = function(afterIdx) {
  this._push();
  var sec = makeSection(1);
  if (afterIdx >= 0 && afterIdx < this.layout.sections.length) {
    this.layout.sections.splice(afterIdx + 1, 0, sec);
    this._renderCanvas(); this._select({ kind:'section', sIdx: afterIdx + 1 });
  } else {
    this.layout.sections.push(sec);
    this._renderCanvas(); this._select({ kind:'section', sIdx: this.layout.sections.length - 1 });
  }
  this._scheduleAutosave();
};

MetisBlockEditor.prototype._deleteSection = function(si) {
  if (!this._getSection(si)) return;
  this._push(); this.layout.sections.splice(si, 1);
  this._deselect(); this._renderCanvas(); this._scheduleAutosave();
};

MetisBlockEditor.prototype._duplicateSection = function(si) {
  var sec = this._getSection(si); if (!sec) return;
  this._push();
  var clone = cloneDeep(sec);
  clone.id = uid();
  (clone.columns||[]).forEach(function(c){ c.id=uid(); (c.modules||[]).forEach(function(m){m.id=uid();}); });
  this.layout.sections.splice(si + 1, 0, clone);
  this._renderCanvas(); this._select({ kind:'section', sIdx: si+1 }); this._scheduleAutosave();
};

MetisBlockEditor.prototype._setSectionCols = function(si, n) {
  var sec = this._getSection(si); if (!sec) return;
  this._push();
  var widths = DEFAULT_WIDTHS[n] || [100];
  var old = sec.columns || [];
  sec.columns = widths.map(function(w, i) {
    var ex = old[i]; if (ex) { ex.width = w; return ex; } return makeColumn(w);
  });
  this._renderCanvas(); this._select({ kind:'section', sIdx: si }); this._scheduleAutosave();
};

MetisBlockEditor.prototype._moveSectionTo = function(fromSi, toSi) {
  if (fromSi === toSi) return;
  this._push();
  var sec = this.layout.sections.splice(fromSi, 1)[0];
  var adj = toSi > fromSi ? toSi - 1 : toSi;
  this.layout.sections.splice(adj, 0, sec);
  this._renderCanvas(); this._select({ kind:'section', sIdx: adj }); this._scheduleAutosave();
};

/* =========================================================================
   COLUMN CRUD
   ========================================================================= */
MetisBlockEditor.prototype._deleteColumn = function(si, ci) {
  var sec = this._getSection(si); if (!sec || (sec.columns||[]).length < 2) return;
  this._push(); sec.columns.splice(ci, 1);
  var per = 100 / sec.columns.length;
  sec.columns.forEach(function(c){ c.width = per; });
  this._deselect(); this._renderCanvas(); this._scheduleAutosave();
};

MetisBlockEditor.prototype._duplicateColumn = function(si, ci) {
  var sec = this._getSection(si); var col = this._getColumn(si, ci); if (!sec||!col) return;
  this._push();
  var clone = cloneDeep(col); clone.id=uid();
  (clone.modules||[]).forEach(function(m){m.id=uid();});
  sec.columns.splice(ci+1,0,clone);
  var per = 100/sec.columns.length; sec.columns.forEach(function(c){c.width=per;});
  this._renderCanvas(); this._select({kind:'column',sIdx:si,cIdx:ci+1}); this._scheduleAutosave();
};

/* =========================================================================
   MODULE CRUD
   ========================================================================= */
MetisBlockEditor.prototype._addModuleToCol = function(si, ci, type) {
  var col = this._getColumn(si, ci); if (!col) return;
  var def = this._defs[canonType(type)] || {};
  this._push();
  var m = makeModule(canonType(type), cloneDeep(def.defs||{}));
  col.modules.push(m);
  var mi = col.modules.length - 1;
  this._renderCanvas(); this._select({kind:'module',sIdx:si,cIdx:ci,mIdx:mi}); this._scheduleAutosave();
};

MetisBlockEditor.prototype._insertModuleAt = function(si, ci, mi, type) {
  var col = this._getColumn(si, ci); if (!col) return;
  var def = this._defs[canonType(type)] || {};
  this._push();
  var m = makeModule(canonType(type), cloneDeep(def.defs||{}));
  col.modules.splice(mi, 0, m);
  this._renderCanvas(); this._select({kind:'module',sIdx:si,cIdx:ci,mIdx:mi}); this._scheduleAutosave();
};

MetisBlockEditor.prototype._deleteModule = function(si, ci, mi) {
  var col = this._getColumn(si, ci); if (!col||!col.modules[mi]) return;
  this._push(); col.modules.splice(mi,1);
  this._deselect(); this._renderCanvas(); this._scheduleAutosave();
};

MetisBlockEditor.prototype._duplicateModule = function(si, ci, mi) {
  var col = this._getColumn(si, ci); var mod = this._getModule(si, ci, mi); if (!col||!mod) return;
  this._push();
  var clone = cloneDeep(mod); clone.id=uid();
  col.modules.splice(mi+1,0,clone);
  this._renderCanvas(); this._select({kind:'module',sIdx:si,cIdx:ci,mIdx:mi+1}); this._scheduleAutosave();
};

MetisBlockEditor.prototype._moveModuleInCol = function(si, ci, fromMi, toMi) {
  var col = this._getColumn(si, ci); if (!col||fromMi===toMi) return;
  this._push();
  var m = col.modules.splice(fromMi,1)[0];
  col.modules.splice(toMi > fromMi ? toMi-1 : toMi, 0, m);
  var newMi = toMi > fromMi ? toMi-1 : toMi;
  this._renderCanvas(); this._select({kind:'module',sIdx:si,cIdx:ci,mIdx:newMi}); this._scheduleAutosave();
};

MetisBlockEditor.prototype._moveModuleAcrossCols = function(fromSi, fromCi, fromMi, toSi, toCi, toMi) {
  var fromCol = this._getColumn(fromSi, fromCi); var toCol = this._getColumn(toSi, toCi); if (!fromCol||!toCol) return;
  this._push();
  var m = fromCol.modules.splice(fromMi,1)[0]; if (!m) return;
  toCol.modules.splice(toMi,0,m);
  this._renderCanvas(); this._select({kind:'module',sIdx:toSi,cIdx:toCi,mIdx:toMi}); this._scheduleAutosave();
};

/* =========================================================================
   AUTOSAVE
   ========================================================================= */
MetisBlockEditor.prototype._scheduleAutosave = function() {
  var self = this;
  clearTimeout(this._saveTimer);
  this._saveTimer = setTimeout(function() { self.onChange(self.layout); }, 1800);
};

/* =========================================================================
   EVENT BINDING
   ========================================================================= */
MetisBlockEditor.prototype._bindEvents = function() {
  var self = this;
  var root = document.getElementById('mube-root');
  if (!root) return;

  /* ---- Click: action buttons ---- */
  root.addEventListener('click', function(e) {
    var btn = e.target.closest('[data-action]');
    if (btn) { self._handleAction(btn.dataset.action, btn, e); e.stopPropagation(); return; }

    /* Select module → column → section by clicking preview area */
    var modEl = e.target.closest('[data-kind="module"]');
    if (modEl) { self._select({kind:'module',sIdx:+modEl.dataset.sidx,cIdx:+modEl.dataset.cidx,mIdx:+modEl.dataset.midx}); e.stopPropagation(); return; }
    var colEl = e.target.closest('[data-kind="column"]');
    if (colEl) { self._select({kind:'column',sIdx:+colEl.dataset.sidx,cIdx:+colEl.dataset.cidx}); e.stopPropagation(); return; }
    var secEl = e.target.closest('[data-kind="section"]');
    if (secEl) { self._select({kind:'section',sIdx:+secEl.dataset.sidx}); e.stopPropagation(); return; }

    /* Click on blank canvas → deselect */
    if (e.target.closest('#mube-canvas')) { self._deselect(); }
  });

  /* ---- Double-click: inline edit text/heading ---- */
  root.addEventListener('dblclick', function(e) {
    var preview = e.target.closest('.mube-mod-preview');
    if (!preview) return;
    var modEl = preview.closest('[data-kind="module"]');
    if (!modEl) return;
    var si=+modEl.dataset.sidx, ci=+modEl.dataset.cidx, mi=+modEl.dataset.midx;
    var mod = self._getModule(si, ci, mi);
    if (mod && (mod.type === 'text' || canonType(mod.type) === 'text' || mod.type === 'heading' || canonType(mod.type) === 'heading')) {
      self._startInlineEdit(si, ci, mi, preview);
    }
  });

  /* ---- Main sidebar tab switching ---- */
  root.addEventListener('click', function(e) {
    var stab = e.target.closest('.mube-stab');
    if (!stab) return;
    self._switchSidebarTab(stab.dataset.stab || 'blocks');
  });

  /* ---- Properties sub-tab switching (General/Style/Advanced) ---- */
  root.addEventListener('click', function(e) {
    var ptab = e.target.closest('[data-ptab]');
    if (!ptab) return;
    root.querySelectorAll('.mube-ptab').forEach(function(t) { t.classList.remove('is-active'); });
    ptab.classList.add('is-active');
    self._panelTab = ptab.dataset.ptab || 'general';
    self._renderPropsPane();
  });

  /* ---- Palette search ---- */
  var search = document.getElementById('mube-palette-search');
  if (search) search.addEventListener('input', function() { self._renderPalette(search.value); });

  /* ---- Keyboard shortcuts ---- */
  document.addEventListener('keydown', function(e) { self._handleKeydown(e); });

  /* ---- Drag from palette ---- */
  root.addEventListener('dragstart', function(e) {
    var pBlock = e.target.closest('[data-block-type]');
    if (pBlock) {
      self._drag = { mode:'new', type: pBlock.dataset.blockType };
      e.dataTransfer.effectAllowed = 'copy';
      e.dataTransfer.setData('text/plain', pBlock.dataset.blockType);
      pBlock.classList.add('is-dragging');
      return;
    }
    /* Drag existing module */
    var dBtn = e.target.closest('[data-action="drag-module"]');
    if (dBtn) {
      self._drag = { mode:'move-module', si:+dBtn.dataset.sidx, ci:+dBtn.dataset.cidx, mi:+dBtn.dataset.midx };
      e.dataTransfer.effectAllowed = 'move'; return;
    }
    /* Drag section */
    var sBtn = e.target.closest('[data-action="drag-section"]');
    if (sBtn) {
      self._drag = { mode:'move-section', si:+sBtn.dataset.sidx };
      e.dataTransfer.effectAllowed = 'move'; return;
    }
    /* Drag column */
    var cBtn = e.target.closest('[data-action="drag-column"]');
    if (cBtn) {
      self._drag = { mode:'move-column', si:+cBtn.dataset.sidx, ci:+cBtn.dataset.cidx };
      e.dataTransfer.effectAllowed = 'move'; return;
    }
  });

  root.addEventListener('dragend', function() {
    root.querySelectorAll('.is-dragging,.mube-drop-over').forEach(function(el){
      el.classList.remove('is-dragging','mube-drop-over');
    });
    self._drag = null;
  });

  /* ---- Dragover / drop on column drop zones ---- */
  root.addEventListener('dragover', function(e) {
    if (!self._drag) return;
    var dz = e.target.closest('.mube-col-dropzone');
    if (dz) { e.preventDefault(); dz.classList.add('mube-drop-over'); }
    var secDrop = e.target.closest('.mube-sec-drop');
    if (secDrop) { e.preventDefault(); secDrop.classList.add('mube-drop-over'); }
    var modDrop = e.target.closest('.mube-mod-drop');
    if (modDrop) { e.preventDefault(); modDrop.classList.add('mube-drop-over'); }
  });

  root.addEventListener('dragleave', function(e) {
    var el = e.target.closest('.mube-col-dropzone,.mube-sec-drop,.mube-mod-drop');
    if (el) el.classList.remove('mube-drop-over');
  });

  root.addEventListener('drop', function(e) {
    e.preventDefault();
    if (!self._drag) return;
    var drag = self._drag;
    root.querySelectorAll('.mube-drop-over').forEach(function(el){el.classList.remove('mube-drop-over');});
    self._drag = null;

    /* Drop module into column */
    var dz = e.target.closest('.mube-col-dropzone');
    if (dz && (drag.mode === 'new' || drag.mode === 'move-module')) {
      var tSi = +dz.dataset.sidx; var tCi = +dz.dataset.cidx;
      var slot = e.target.closest('.mube-mod-drop');
      var tMi = slot ? +slot.dataset.midx : (self._getColumn(tSi,tCi)||{modules:[]}).modules.length;
      if (drag.mode === 'new') { self._insertModuleAt(tSi, tCi, tMi, drag.type); }
      else { self._moveModuleAcrossCols(drag.si, drag.ci, drag.mi, tSi, tCi, tMi); }
      return;
    }

    /* Drop to reorder section */
    var secDrop = e.target.closest('.mube-sec-drop');
    if (secDrop && drag.mode === 'move-section') {
      var tSi = +secDrop.dataset['dropSidx'];
      var pos  = secDrop.dataset['dropPos'];
      self._moveSectionTo(drag.si, pos === 'before' ? tSi : tSi + 1); return;
    }
  });

  /* ---- Column resize ---- */
  this._bindColResize(root);
};

/* =========================================================================
   COLUMN RESIZE (drag handle)
   ========================================================================= */
MetisBlockEditor.prototype._bindColResize = function(root) {
  var self = this;
  var resizeState = null;

  root.addEventListener('mousedown', function(e) {
    var handle = e.target.closest('[data-action="resize-col-start"]');
    if (!handle) return;
    e.preventDefault();
    var si = +handle.dataset.sidx; var ci = +handle.dataset.cidx;
    var sec = self._getSection(si); if (!sec) return;
    resizeState = { si:si, ci:ci, startX: e.clientX, startWidths: (sec.columns||[]).map(function(c){return c.width;}) };
    document.body.style.cursor = 'col-resize';
    document.body.style.userSelect = 'none';
  });

  document.addEventListener('mousemove', function(e) {
    if (!resizeState) return;
    var sec = self._getSection(resizeState.si); if (!sec) return;
    var canvas = document.getElementById('mube-canvas');
    var canvasW = canvas ? canvas.offsetWidth : 800;
    var dx = e.clientX - resizeState.startX;
    var pctDelta = (dx / canvasW) * 100;
    var colA = sec.columns[resizeState.ci];
    var colB = sec.columns[resizeState.ci + 1];
    if (!colA || !colB) return;
    var newA = Math.max(10, resizeState.startWidths[resizeState.ci]     + pctDelta);
    var newB = Math.max(10, resizeState.startWidths[resizeState.ci + 1] - pctDelta);
    colA.width = newA; colB.width = newB;
    /* Partial re-render of just this section for performance */
    var secEl = (document.getElementById('mube-canvas')||{}).querySelector
      ? document.getElementById('mube-canvas').querySelector('[data-kind="section"][data-sidx="'+resizeState.si+'"]')
      : null;
    if (secEl) {
      var inner = secEl.querySelector('.mube-section-inner');
      if (inner) {
        (inner.querySelectorAll('.mube-column')||[]).forEach(function(colEl, idx) {
          var col = sec.columns[idx]; if (!col) return;
          colEl.style.flex = '0 0 ' + col.width.toFixed(4) + '%';
          colEl.style.width = col.width.toFixed(4) + '%';
        });
      }
    }
  });

  document.addEventListener('mouseup', function() {
    if (!resizeState) return;
    self._scheduleAutosave();
    resizeState = null;
    document.body.style.cursor = '';
    document.body.style.userSelect = '';
  });
};

/* =========================================================================
   ACTION DISPATCHER
   ========================================================================= */
MetisBlockEditor.prototype._handleAction = function(action, el) {
  var si = el.dataset.sidx !== undefined ? +el.dataset.sidx : -1;
  var ci = el.dataset.cidx !== undefined ? +el.dataset.cidx : -1;
  var mi = el.dataset.midx !== undefined ? +el.dataset.midx : -1;
  var cols = el.dataset.cols !== undefined ? +el.dataset.cols : -1;

  switch (action) {
    case 'add-section':          this._addSection(); break;
    case 'delete-section':       this._deleteSection(si); break;
    case 'duplicate-section':    this._duplicateSection(si); break;
    case 'edit-section':         this._select({kind:'section',sIdx:si}); break;
    case 'save-section-webpart': this._saveSectionWebpart(si); break;
    case 'set-section-cols':     if (cols > 0) this._setSectionCols(si, cols); break;
    case 'edit-column':          this._select({kind:'column',sIdx:si,cIdx:ci}); break;
    case 'duplicate-column':     this._duplicateColumn(si, ci); break;
    case 'delete-column':        this._deleteColumn(si, ci); break;
    case 'edit-module':          this._select({kind:'module',sIdx:si,cIdx:ci,mIdx:mi}); break;
    case 'duplicate-module':     this._duplicateModule(si, ci, mi); break;
    case 'delete-module':        this._deleteModule(si, ci, mi); break;
    case 'copy-module-style':
      var m = this._getModule(si, ci, mi); if (m) this._cpStyle = cloneDeep(m.style||{});
      break;
    case 'paste-module-style':
      if (this._cpStyle) { var m = this._getModule(si, ci, mi); if (m) { this._push(); m.style = cloneDeep(this._cpStyle); this._renderCanvas(); this._scheduleAutosave(); } }
      break;
    case 'focus-palette':
      this._pendingInsert = {si:si,ci:ci};
      var s = document.getElementById('mube-palette-search'); if (s) { s.focus(); s.select(); }
      break;
    case 'refresh-webparts':
      if (typeof this.config.onRequestReusableLibrary === 'function') this.config.onRequestReusableLibrary();
      break;
    case 'insert-webpart': this._insertWebpart(el.dataset.id); break;
    case 'close-shortcuts':
      var help = document.getElementById('mube-shortcuts'); if (help) help.hidden = true;
      break;
    case 'drag-module': case 'drag-section': case 'drag-column': break; /* handled by dragstart */
  }
};

/* =========================================================================
   INLINE TEXT EDITING
   ========================================================================= */
MetisBlockEditor.prototype._startInlineEdit = function(si, ci, mi, previewEl) {
  var mod = this._getModule(si, ci, mi); if (!mod) return;
  var self = this;
  previewEl.contentEditable = 'true';
  previewEl.classList.add('mube-inline-active');
  previewEl.focus();
  /* Position the floating toolbar */
  this._showFT(previewEl);
  previewEl.addEventListener('blur', function onBlur() {
    previewEl.removeEventListener('blur', onBlur);
    var content = previewEl.innerHTML;
    previewEl.contentEditable = 'false';
    previewEl.classList.remove('mube-inline-active');
    self._hideFT();
    self._push();
    if (!mod.data) mod.data = {};
    mod.data.content = content;
    self._scheduleAutosave(); self.onChange(self.layout);
  }, { once: true });
};

MetisBlockEditor.prototype._showFT = function(anchor) {
  var ft = document.getElementById('mube-ft'); if (!ft) return;
  ft.innerHTML = this._ftHtml();
  ft.hidden = false;
  this._bindFT(ft);
  /* Position relative to anchor */
  if (anchor) {
    var rect = anchor.getBoundingClientRect();
    ft.style.position = 'fixed';
    ft.style.top  = Math.max(4, rect.top - ft.offsetHeight - 6) + 'px';
    ft.style.left = rect.left + 'px';
  }
};

MetisBlockEditor.prototype._hideFT = function() {
  var ft = document.getElementById('mube-ft'); if (ft) ft.hidden = true;
};

MetisBlockEditor.prototype._ftHtml = function() {
  var btns = [
    ['bold',       'B',  'Bold'],
    ['italic',     'I',  'Italic'],
    ['underline',  'U',  'Underline'],
    ['sep'],
    ['justifyLeft','◁', 'Left'],
    ['justifyCenter','▣','Center'],
    ['justifyRight','▷','Right'],
    ['sep'],
    ['createLink', '🔗','Link'],
  ];
  var html = '';
  btns.forEach(function(b) {
    if (b[0] === 'sep') { html += '<span class="mube-ft-sep"></span>'; return; }
    html += '<button type="button" class="mube-ft-btn" data-cmd="' + b[0] + '" title="' + b[2] + '">' + b[1] + '</button>';
  });
  html += '<label class="mube-ft-color-wrap" title="Text color">'
    + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 20h14M8 16l4-12 4 12M9.2 12h5.6"/></svg>'
    + '<input type="color" data-cmd="foreColor" value="#111827"></label>';
  html += '<select class="mube-ft-size" data-cmd="fontSize">'
    + ['12px','14px','16px','18px','20px','24px','32px','48px'].map(function(s){
        return '<option value="' + s + '"' + (s==='16px'?' selected':'') + '>' + s + '</option>';
      }).join('') + '</select>';
  return html;
};

MetisBlockEditor.prototype._bindFT = function(ft) {
  ft.addEventListener('mousedown', function(e) {
    e.preventDefault();
    var btn = e.target.closest('[data-cmd]');
    if (!btn) return;
    var cmd = btn.dataset.cmd;
    if (cmd === 'createLink') {
      var url = window.prompt('Enter URL:');
      if (url) document.execCommand('createLink', false, url);
    } else if (btn.tagName !== 'INPUT' && btn.tagName !== 'SELECT') {
      document.execCommand(cmd, false, null);
    }
  });
  ft.addEventListener('change', function(e) {
    var el = e.target; if (!el.dataset.cmd) return;
    if (el.dataset.cmd === 'foreColor') { document.execCommand('foreColor', false, el.value); }
    else if (el.dataset.cmd === 'fontSize') {
      /* Apply inline span approach */
      document.execCommand('fontSize', false, '7');
      var items = document.querySelectorAll('font[size="7"]');
      items.forEach(function(f){ f.removeAttribute('size'); f.style.fontSize = el.value; });
    }
  });
};

/* =========================================================================
   WEBPARTS (saved section templates)
   ========================================================================= */
MetisBlockEditor.prototype._saveSectionWebpart = function(si) {
  var sec = this._getSection(si); if (!sec) return;
  var name = window.prompt('Name this section template:'); if (!name) return;
  if (typeof this.config.onSaveWebpart === 'function') this.config.onSaveWebpart({ name:name, section: cloneDeep(sec) });
};

MetisBlockEditor.prototype._insertWebpart = function(id) {
  var wp = (this._webparts||[]).filter(function(w){ return String(w.id)===String(id); })[0];
  if (!wp||!wp.section) return;
  this._push();
  var sec = cloneDeep(wp.section); sec.id=uid();
  (sec.columns||[]).forEach(function(c){ c.id=uid(); (c.modules||[]).forEach(function(m){m.id=uid();}); });
  this.layout.sections.push(sec); this._renderCanvas(); this._scheduleAutosave();
};

/* =========================================================================
   KEYBOARD SHORTCUTS
   ========================================================================= */
MetisBlockEditor.prototype._handleKeydown = function(e) {
  var key = (e.key||'').toLowerCase();
  var mod = e.ctrlKey || e.metaKey;
  var inInput = e.target && (e.target.tagName==='INPUT'||e.target.tagName==='TEXTAREA'||e.target.tagName==='SELECT'||e.target.isContentEditable);

  if (key === 'escape') { this._deselect(); this._hideFT(); return; }
  if (inInput) return;
  if (mod && key === 'z') { e.preventDefault(); e.shiftKey ? this.redo() : this.undo(); return; }
  if (mod && key === 'y') { e.preventDefault(); this.redo(); return; }
  if (mod && key === 'd' && this.sel && this.sel.kind === 'module') {
    e.preventDefault(); this._duplicateModule(this.sel.sIdx, this.sel.cIdx, this.sel.mIdx); return;
  }
  if ((key==='delete'||key==='backspace') && this.sel) {
    e.preventDefault();
    if (this.sel.kind==='module') this._deleteModule(this.sel.sIdx,this.sel.cIdx,this.sel.mIdx);
    else if (this.sel.kind==='section') this._deleteSection(this.sel.sIdx);
    return;
  }
  if (key === '?') {
    var help = document.getElementById('mube-shortcuts');
    if (help) help.hidden = !help.hidden;
  }
};

/* =========================================================================
   PUBLIC API  (matches page-builder.js integration surface)
   ========================================================================= */
MetisBlockEditor.prototype.getLayout  = function() { return cloneDeep(this.layout); };
MetisBlockEditor.prototype.getPageMeta = function() { return Object.assign({}, this._pageMeta); };
MetisBlockEditor.prototype.getBlocks  = function() {
  var blocks = [];
  (this.layout.sections||[]).forEach(function(sec){
    (sec.columns||[]).forEach(function(col){ (col.modules||[]).forEach(function(m){ blocks.push(cloneDeep(m)); }); });
  });
  return blocks;
};
MetisBlockEditor.prototype.setPreviewDevice = function(dev) {
  this._device = dev||'desktop';
  var c = document.getElementById('mube-canvas'); if (c) c.dataset.device = this._device;
};
MetisBlockEditor.prototype.refresh = function() { this._renderCanvas(); this._renderPropsPane(); this._renderSettingsPane(); };
MetisBlockEditor.prototype.setWebparts = function(items) { this._webparts = Array.isArray(items)?items:[]; this._renderWebparts(); };
MetisBlockEditor.prototype.setFormOptions = function(forms) { this._forms = Array.isArray(forms)?forms:[]; };
/* Compatibility shims for page-builder callers that reference these */
MetisBlockEditor.prototype.setCanvasWidthConfig   = function() {};
MetisBlockEditor.prototype.setCanvasPresentation  = function() {};
MetisBlockEditor.prototype.toggleBlocksPanel      = function() {};
MetisBlockEditor.prototype.setContextProfile      = function(p) { this._contextProfile = p; };
MetisBlockEditor.prototype.refreshReusableLibrary = function() {
  if (typeof this.config.onRequestReusableLibrary === 'function') this.config.onRequestReusableLibrary();
};
MetisBlockEditor.prototype.handleGlobalKeydown = function(e) { this._handleKeydown(e); };

/* =========================================================================
   EXPORT
   ========================================================================= */
global.MetisBlockEditor = MetisBlockEditor;


/* =========================================================================
   ADDITIONAL SHIMS FOR PAGE-BUILDER INTEGRATION
   ========================================================================= */

/**
 * Called by page-builder after editor mounts.
 * It tries to move '#mube-blocks-panel' into '#mwpb-blocks-sidebar-slot'.
 * In the new BB model the palette lives in '#mube-sidebar'.
 * We expose the sidebar element under the expected ID so the page-builder
 * can slot it in, OR we hide the integrated sidebar and let the shell
 * manage the palette via the slot.
 */
MetisBlockEditor.prototype._exposePaletteForShell = function() {
  var sidebar = document.getElementById('mube-sidebar');
  if (!sidebar) return;
  var slot = document.getElementById('mwpb-blocks-sidebar-slot');
  if (!slot) return;
  /* Hide the integrated sidebar since the shell provides its own */
  sidebar.style.display = 'none';
  slot.innerHTML = '';
  slot.appendChild(sidebar);
  sidebar.style.display = '';
  sidebar.style.width = '100%';
  sidebar.style.borderRight = 'none';
  sidebar.style.height = '100%';
};

/** validateForSave — page-builder calls this if available */
MetisBlockEditor.prototype.validateForSave = function() {
  return { valid: true, errors: [] };
};

/** setPropsVisible — page-builder calls this to show/hide properties panel */
MetisBlockEditor.prototype.setPropsVisible = function(visible) {
  if (!visible) this._deselect();
  else if (this.sel) this._switchSidebarTab('properties');
};

/** Expose the blocks panel element for the shell's slot system */
MetisBlockEditor.prototype.getBlocksPanelElement = function() {
  return document.getElementById('mube-sidebar');
};

/**
 * getSaveLayout — returns the full sections layout for saving.
 * page-builder should call this instead of getBlocks() + _serializeLayoutFromBlocks()
 * when the editor has been upgraded to the BB model.
 */
MetisBlockEditor.prototype.getSaveLayout = function() {
  return cloneDeep(this.layout);
};

}(window));
