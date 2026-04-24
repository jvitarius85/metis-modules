# GRANDY’S STASH — TICKET SYSTEM (V3 COMPLETE)

## MODE: STRICT — SIMPLIFIED — ANTI-OVERWHELM — NO DRIFT

---

## OBJECTIVE

Build a simplified, high-efficiency ticket system for:

- Supply requests
- Supply donations

The system MUST:

- Reduce staff overwhelm
- Automatically group related submissions
- Track full lifecycle
- Provide clear, actionable workflows
- Support grant reporting

THIS IS NOT A GENERIC HELP DESK.

---

## CORE PRINCIPLES

1. Simplicity over flexibility  
2. Automation over manual sorting  
3. Action-first UI  
4. Minimal clicks  
5. Immediate clarity  

---

## NON-NEGOTIABLE RULES

❌ No enterprise complexity  
❌ No over-configuration  
❌ No UI clutter  
❌ No deviation from Metis UI  
❌ No duplicate systems  

✅ MUST match Metis UI (People / Roles / Newsletter)  
✅ MUST be usable without training  
✅ MUST be fast and predictable  

---

# CORE SYSTEM

## IDENTITY

- email REQUIRED  
- phone REQUIRED  

Matching priority:

1. email  
2. phone  
3. name  

---

## FACILITY

- Facilities MUST exist  
- Caseworkers can belong to facility  
- Tickets may be tied to facility  

---

## AUTO GROUPING (CRITICAL)

### RULES

System MUST:

- Match by:
  - email OR phone OR name
- Merge ALL TIME (no window)
- Create case group per person
- Allow manual unlink

---

### GROUPING LOGIC (EXPLICIT)

```txt
IF email matches → group
ELSE IF phone matches → group
ELSE IF name matches (case-insensitive) → group
ELSE → create new group