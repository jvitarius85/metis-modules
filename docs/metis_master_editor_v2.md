#######################################################
## METIS MASTER DIRECTIVE — WYSIWYG EDITOR SYSTEM (V2)
## MODE: STRICT + CONTROLLED + CONTEXT-AWARE
#######################################################

PRIORITY: CRITICAL  
THIS IS CORE INFRASTRUCTURE — NOT OPTIONAL  
NO DEVIATION. NO INTERPRETATION. NO SHORTCUTS.

#######################################################
## GLOBAL RULE — DOCUMENTATION FIRST
#######################################################

- ALWAYS check /docs/ before implementing anything
- /docs/ is the source of truth
- If missing or unclear:
  → ASK
  → DO NOT ASSUME

#######################################################
## OBJECTIVE
#######################################################

Build a single, unified, production-grade WYSIWYG editor that:

- Feels like editing a real page (true WYSIWYG)
- Is fully user-friendly (zero technical exposure)
- Is reusable across ALL Metis systems
- Adapts via context (NOT duplication)

SUPPORTED CONTEXTS:
- Pages
- Posts
- Templates
- Web Parts
- Newsletter Builder
- Email Builder (future-safe)

#######################################################
## NON-NEGOTIABLE RULES
#######################################################

❌ NO iframe editor  
❌ NO JSON exposure to user  
❌ NO duplicate editor implementations  
❌ NO database schema changes  
❌ NO new dependencies  
❌ NO refactoring unrelated systems  

✅ MUST use contenteditable  
✅ MUST use PHP-based rendering  
✅ MUST use SAME renderer (editor + frontend)  
✅ MUST render dynamic content live  
✅ MUST maintain performance (no lag)

#######################################################
## CORE ARCHITECTURE
#######################################################

EDITOR = SINGLE SYSTEM

- Must NOT be duplicated
- Must be reused everywhere
- Must adapt via internal configuration

DATA MODEL:
- Structured internal schema
- JSON allowed internally ONLY
- NEVER exposed

RENDERING:
- ONE shared renderer
- Editor preview = frontend output (1:1)

#######################################################
## CONTEXT-AWARE SYSTEM (CORE)
#######################################################

The editor MUST adapt based on context.

CONTEXT EXAMPLES:
- website
- template
- web_part
- newsletter
- email

Context controls:

- allowed blocks
- allowed styling
- layout capabilities
- dynamic content availability
- rendering mode

#######################################################
## BLOCK SYSTEM
#######################################################

- Insert above/below with indicator
- Remove duplicate blocks entirely

MULTI-ITEM BLOCKS:
- Must support:
  - add/remove/reorder
  - inline editing
  - simple UI only

BLOCK AVAILABILITY:
- MUST be context-driven
- MUST NOT be hardcoded

#######################################################
## LAYOUT SYSTEM
#######################################################

ROW = SECTION

- Row rail system (drag entire row)
- Row controls:
  - move
  - duplicate
  - delete
  - settings

WIDTH OPTIONS:
- Theme default
- Full width
- Full + constrained content
- Fixed
- Custom max

GRID:
- 12-column system
- Snap enforced
- % + px support
- Auto stack on mobile

VERTICAL ALIGN:
- top / center / bottom / space-between

#######################################################
## STYLING SYSTEM
#######################################################

RULE:
- Block-level styling only
- Inline formatting only for text

CONTROLS:
- Background
- Border
- Spacing
- Shadow
- Width

HIERARCHY:
Theme < Block Defaults < User Overrides

#######################################################
## INTERACTION MODEL
#######################################################

- Direct inline editing
- Single selection only

TOOLBAR:
- Contextual floating
- Standard icons only

CONTROLS:
- Hover: drag, duplicate, delete, settings
- Right-click menu
- Keyboard shortcuts

#######################################################
## SIDEBAR
#######################################################

- Accordion layout
- Search enabled
- No duplicate blocks

#######################################################
## DYNAMIC CONTENT
#######################################################

FORMAT:

[metis:<namespace>.<field>]

Example:
[metis:donation.description]

RULES:
- Stored as shortcode ONLY
- Never store resolved content

EDITOR:
- Shows resolved preview
- Uses backend renderer

#######################################################
## SHORTCODE RENDERING (GLOBAL)
#######################################################

ALL shortcode rendering MUST use a SINGLE shared renderer.

USED BY:
- Editor preview
- Public pages
- Templates
- Web parts
- Newsletters
- Emails

PROHIBITED:
❌ JS shortcode resolution  
❌ Multiple renderers  
❌ Template-level rendering  

#######################################################
## RENDER FLOW
#######################################################

1. Content stored with shortcode
2. Renderer processes content
3. Renderer resolves tokens
4. Renderer returns sanitized HTML

#######################################################
## CONTEXT RENDERING MODES
#######################################################

STANDARD:
- Full rendering allowed

EMAIL_SAFE:
- Inline styles only
- No scripts
- Restricted CSS

#######################################################
## VALIDATION
#######################################################

- Validate on input
- Validate on save
- Enforce on render

INVALID CONTENT:
- Must be blocked before save

#######################################################
## HTML BLOCK
#######################################################

- Allow safe HTML only
- Strip scripts
- JS handled via Web Parts

#######################################################
## SAVE SYSTEM
#######################################################

- Autosave on change
- Manual save allowed
- Save FULL payload only

STATUS:
- Draft
- Published
- Scheduled
- Revisions

#######################################################
## VERSION + LOCKING
#######################################################

- Full version history
- HARD LOCK editing (no concurrent edits)

#######################################################
## GLOBAL CONTROLS
#######################################################

Top bar MUST include:
- Title
- Save status
- Preview
- Device toggle
- Undo/Redo
- Publish
- Version history

#######################################################
## PERFORMANCE
#######################################################

- No lag allowed
- Render ALL blocks

#######################################################
## EXECUTION CONTROL
#######################################################

IMPLEMENT IN PHASES:

1. Core Architecture
2. Editor Container
3. Block System
4. Layout System
5. Styling System
6. Interaction Model
7. Sidebar
8. Dynamic Content
9. HTML Block
10. Save + Versioning
11. Responsive Controls
12. Snap System
13. Global Controls
14. Performance Pass

FOR EACH PHASE:

- Output plan
- Ask for confirmation
- WAIT

#######################################################
## BACKUP SYSTEM
#######################################################

AFTER EACH PHASE:

- Create backup OUTSIDE Metis

Example:
/volume1/backups/metis-editor/

FORMAT:
metis_backup_YYYYMMDD_HHMMSS/

EXCLUDE:
- cache/temp
- node_modules

VERIFY backup success

#######################################################
## ROLLBACK
#######################################################

- Must support restore from any backup
- Must safely replace project

#######################################################
## FAILURE PROTECTION
#######################################################

STOP if:

- Scope creep
- Unexpected changes
- Errors occur
- Backup fails

REPORT:
- Issue
- Location
- Fix

#######################################################
## FINAL UX RULE
#######################################################

Editor MUST:

- Match Beaver Builder power
- Be simpler than Gutenberg
- Require ZERO training
- Be fully visual
- Never expose technical complexity

#######################################################
## FAILURE CONDITIONS
#######################################################

Reject implementation if:

- Editor duplicated per module
- Context rules bypassed
- Rendering differs from frontend
- JSON exposed
- UI confusing
- Performance lag

#######################################################
## END DIRECTIVE
#######################################################