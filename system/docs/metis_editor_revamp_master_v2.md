# METIS EDITOR REVAMP MASTER (V2)

## Status
Authoritative Directive — Required for all editor UX work

---

## Purpose

This directive defines the full UX, interaction, and performance revamp of the Metis editor.

The goal is to achieve a **premium, Beaver Builder–level editing experience** while preserving all existing architecture.

---

## Core Principles

- True WYSIWYG editing
- Inline-first interaction model
- Minimal UI clutter
- Predictable structure and behavior
- Immediate visual feedback
- High performance under load
- Zero exposure of technical complexity

---

## Non-Negotiable Constraints

The following are strictly prohibited:

- Rewriting editor architecture  
- Modifying renderer or rendering contracts  
- Modifying dynamic content system  
- Modifying data model  
- Introducing new frameworks  
- Removing existing working functionality  

The following are required:

- Reuse existing systems  
- Extend only the UI/UX layer  
- Preserve all existing behavior  

---

## Execution Model

All work must be completed in phases.

### Phase Order

1. 15A — Premium Animations  
2. 15B — Keyboard Editing  
3. 15D — Undo System  
4. 15C — Reusable Blocks  
5. 15E — UX Refinement  
6. 15F — Drag System + Hierarchy  
7. 15G — Inline Media Editing  
8. 15H — Performance Scaling  

### Phase Rules

For each phase:

1. Output implementation plan  
2. Request confirmation  
3. Wait for approval  
4. Implement only that phase  
5. Provide summary and test steps  
6. Create backup outside Metis directory  
7. Stop before next phase  

---

## UX Refinement (Phase 15E)

- Inline editing: click → type immediately  
- Hover-based controls only  
- Floating toolbar near selection  
- Sidebar used only for inserting blocks  
- No persistent UI clutter  
- All changes render instantly  

---

## Premium Animations (Phase 15A)

- Use `transform` and `opacity` only  
- Duration: 120–180ms  
- Smooth easing (cubic-bezier)

Required animations:

- Hover scaling  
- Toolbar fade/slide  
- Drag lift  
- Placeholder expansion  
- Row movement  
- Block insert/delete  
- Button micro-feedback  

Animations must respect reduced motion settings.

---

## Drag System (Phase 15F)

- Single centralized drag system (DragManager)  
- FLIP animation strategy required  
- Midline detection for placement  
- Placeholder-based insertion  
- Smooth sibling shifting  

No layout thrashing allowed.

---

## Visual Hierarchy

Structure: