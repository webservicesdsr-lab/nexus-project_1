CONTEXT REPORT — KINGDOM NEXUS

## READ THIS BEFORE MAKING ANY CHANGE

This repository is **NOT** a generic WordPress plugin.
It is a **domain-sealed system** with strict authority contracts.

Most past breakages happened because assistants assumed:

* “helpers can be normalized”
* “nonces should be standardized”
* “refactors improve clarity”
* “duplicate logic should be abstracted”

⚠️ **Those assumptions are WRONG in Kingdom Nexus.**

---

## 1. Why changes often break things here

### 1.1 This system is contract-driven, not convenience-driven

Many files intentionally look:

* repetitive
* verbose
* explicit
* partially duplicated

This is **by design**.

In Nexus:

* Explicit logic > clever abstraction
* Local enforcement > global helpers
* Stability > elegance

If you “clean”, “normalize”, or “optimize” without permission, you are **breaking contracts**.

---

## 2. Nonces are a known danger zone 🚨

### 2.1 What usually goes wrong

AI assistants frequently:

* change nonce action strings
* replace inline checks with helpers
* move nonce logic to guards
* rename nonce parameters
* assume `_wpnonce` everywhere
* “standardize” to `wp_rest`

This **breaks session contracts** and causes silent production failures.

### 2.2 Canonical rule for nonces in Nexus

* If a nonce check already exists → **DO NOT TOUCH IT**
* If a mutation endpoint needs nonce enforcement → **ADD IT INLINE**
* Do NOT:

  * introduce new nonce helpers
  * refactor nonce logic into shared guards
  * normalize nonce usage across endpoints

Nonce logic is **part of the endpoint’s authority**, not a utility.

---

## 3. Helpers are NOT generic utilities here

### 3.1 Core helpers are contracts

Files like:

* `knx-rest-guard.php`
* `helpers.php`
* session / role helpers

are **authority contracts**, not convenience layers.

If a helper function:

* exists → respect it exactly
* does not exist → DO NOT invent it

The presence of references to a non-existing helper DOES NOT mean:

> “This should be implemented now”

It often means:

> “This is intentionally optional / legacy-tolerant”

---

## 4. Local changes are mandatory

### 4.1 What “local” means in Nexus

A correct change:

* touches **one function**
* in **one file**
* for **one explicit behavior**
* without altering unrelated flows

A wrong change:

* rewrites a file
* introduces new helpers
* “cleans up” logic
* moves authority elsewhere

Even if the code “looks better”, it is wrong.

---

## 5. Authority rules you MUST respect

* Backend is absolute authority
* REST handlers decide
* UI never decides
* Helpers do not override handlers
* Sessions + roles are sealed
* Orders are immutable snapshots
* Fail-closed is always preferred

If you are unsure:
👉 **Block the operation**, do not guess.

---

## 6. What NOT to do (common AI mistakes)

❌ Refactor for readability
❌ Normalize nonce logic
❌ Introduce shared abstractions
❌ Rewrite files to be “cleaner”
❌ Assume WordPress best practices apply
❌ Assume other plugins’ patterns apply

Kingdom Nexus is **not** a typical WP plugin.

---

## 7. How to behave correctly in this repo

When asked to change something:

1. Identify the **exact function**
2. Modify **only that function**
3. Preserve all existing behavior
4. Do not move logic
5. Do not rename things
6. Do not add helpers unless explicitly approved
7. If unsure → STOP and ASK

---

## 8. Summary (read this twice)

> **Do less.
> Be explicit.
> Respect contracts.
> Don’t be clever.**

Most bugs here were caused not by missing code,
but by **over-helpful AI behavior**.

---

## 9. A final warning ⚠️

If you:

* change nonce handling
* refactor guards
* normalize helpers
* abstract logic

without explicit instruction,

you are likely introducing **production-blocking bugs**.

---

### End of Copilot Context Report

