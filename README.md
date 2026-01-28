# Kingdom Nexus

tree -a -I "node_modules|vendor|.git" > project-tree.txt

**Kingdom Nexus** is a modular, PHP-driven system deployed as a WordPress plugin, designed to behave as a lightweight application framework for delivery operations.

Its core goal is simple:

> Build a stable, secure platform where collaborators can contribute without needing full-stack or DevOps expertise.

---

## Philosophy

- WordPress is used as **infrastructure**
- Nexus owns **business logic**
- All critical decisions are enforced by Nexus, not WordPress

WordPress acts as a container.  
Nexus acts as the system.

---

## Technical Environment

- Hosting: HostGator (Business Plan)
- CMS: WordPress (Softaculous-managed)
- Database: MySQL (fully custom tables)
- Deployment: FTP + Git-controlled workflow

This environment was chosen intentionally to avoid unnecessary DevOps complexity while retaining full control over logic and data.

---

## Architecture Overview

Inside the plugin, Nexus implements:

- A custom internal loader
- Modular engines and domains
- Custom APIs (not WP REST–dependent)
- A full delivery runtime (orders, drivers, OPS)
- A standalone identity and session system

The plugin may look small, but it encapsulates a full framework.

---

## Documentation

All architectural contracts and system rules live in `/documentation`.

Start here:

- `knx-documentation-english/`
- `knx-documentation-español/`

Key references include:

- Bootstrap & system initialization
- Auth, identity & permissions (SSOT)
- Theme shell & error handling
- OPS and runtime boundaries

---

## Important Note for Contributors

Nexus is **not a typical WordPress plugin**.

Before making changes:
- Read the documentation
- Understand sealed contracts
- Avoid mixing WordPress runtime logic into Nexus domains

When in doubt, ask.
