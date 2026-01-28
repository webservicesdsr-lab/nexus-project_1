
# Kingdom Nexus — System Manual (Phase-by-Phase)

What this document is

Kingdom Nexus is the application-level system built atop a WordPress container. This manual explains, phase by phase, how each domain is designed, which concerns it owns, and how it links with the rest of the system. The purpose is to describe architecture and operational contracts, not to replace the roadmap, implementation details, or role-level capability documents.

What this document is not

- It is not a roadmap or status tracker. Those belong in `ROADMAP.md`.
- It is not an implementation reference or evidence index. Concrete proofs and file-level anchors live in `documentation/` and the roadmap.
- It does not include code, SQL dumps, or exhaustive endpoint lists.

How to read this manual

Each phase is presented with: Purpose, Core Responsibilities, Key Concepts, How It Connects to Other Phases, and Notes/Constraints. Read phases in the roadmap order to follow the system dependency model.

## PHASE 0 — Core Infrastructure & Guard Rails

### Purpose
Provide the minimal runtime contracts for request dispatch, response formatting, and permission enforcement. This layer establishes how handlers are invoked and how errors are normalized.

### Core Responsibilities
- Define a single REST wrapper contract for handlers. 
- Provide a guard mechanism for permission checks and nonce validation. 
- Centralize response envelope and error shape.
- Ensure fail-closed behavior for missing authentication or required context.

### Key Concepts
- Wrapper/Guard: a small contract that every external entry point must satisfy.
- Fail-closed: absence of required context produces a hard rejection.
- Consistent response envelope for machine clients.

### How It Connects to Other Phases
- All higher phases expose entry points that traverse these guard rails. 
- Any write operation relies on this layer to enforce permission and nonce requirements.

### Notes / Constraints
- This phase should remain minimal and stable; expanding it risks breaking cross-cutting contracts.

## PHASE 1 — Auth & Session

### Purpose
Establish identity and session semantics that other phases depend on for authorization and ownership decisions.

### Core Responsibilities
- Issue and validate session tokens or equivalent server-side session markers.
- Bind session context to user identity and (when applicable) to a transient cart.
- Provide login/logout flows and controlled redirects after authentication.

### Key Concepts
- Session as authority: a validated session is the canonical assertion of actor identity.
- Session binding: cart, profile, and transient runtime state should be associated with the session rather than volatile client IDs.

### How It Connects to Other Phases
- Phases that perform writes (orders, addresses, ops) use session context to validate ownership and gating.
- Navigation and UI flows rely on post-login redirects defined here.

### Notes / Constraints
- Authentication mechanisms must never be bypassed by client-side assumptions; server-side validation is required.

## PHASE 2 — Roles & Capability Model

### Purpose
Define canonical roles and the mapping from those roles to permitted actions across the system.

### Core Responsibilities
- Declare the canonical role set used by the system.
- Provide a consistent permission mapping that routes and handlers consult.
- Ensure UI visibility does not imply authority.

### Key Concepts
- Canonical roles: a small, explicit vocabulary of authority.
- Permission callback vs handler-level enforcement: roles can be checked at registration time or during handler execution.

### How It Connects to Other Phases
- All admin and operational phases depend on this model to gate actions such as hub edits, city management, and ops controls.

### Notes / Constraints
- Role names are part of the contract; changing them is a breaking change.

## PHASE 3 — Cities

### Purpose
Cities are a top-level organisational unit that influences availability, delivery pricing inputs, and operational toggles.

### Core Responsibilities
- Model city-level operational state and delivery-rate inputs.
- Provide controlled CRUD for city entities, scoped to appropriate roles.

### Key Concepts
- Operational toggle: a binary operational state used by higher-level routing.
- Delivery rates as input: city-level rates feed the totals and delivery fee computations.

### How It Connects to Other Phases
- Cities supply parameters consumed by Coverage & Distance and Delivery Fee phases.
- City toggles affect whether hubs and associated menus are presented in the UI.

### Notes / Constraints
- City-level writes are high-impact and must be restricted to global administrative authority.

## PHASE 4 — Hubs

### Purpose
Hubs are the operational units that own menus, schedules, and delivery zones; they are the locality in which orders are fulfilled.

### Core Responsibilities
- Model hub identity, location, operational hours, and configuration.
- Expose hub editing flows and settings for authorized operators.

### Key Concepts
- Hub identity vs global configuration: hubs are local instances with their own data and constraints.
- Ownership and scoping: hub-level operations may be restricted to specific manager actors.

### How It Connects to Other Phases
- Hubs provide the menu and availability context that the Menu System and Cart use to compute quotes and eligibility.

### Notes / Constraints
- Hub edits influence availability and pricing; handlers must enforce owner checks where applicable.

## PHASE 5 — Menu System

### Purpose
Provide the canonical representation of orderable items, modifiers, and add-ons as consumed by the cart and checkout flows.

### Core Responsibilities
- Present read APIs for menus per hub.
- Allow controlled administrative mutation of items and modifiers.

### Key Concepts
- Menu snapshot: item metadata should be snapshotted into the order to avoid later recomputation inconsistencies.
- Modifiers and required options: a deterministic validation model applied at order creation.

### How It Connects to Other Phases
- Menu read services are inputs to Quote and Create-Order paths.
- Item snapshots are persisted by the Orders Foundation phase.

### Notes / Constraints
- The ordering flow must rely on snapshots; live menu changes do not alter existing orders.

## PHASE 6 — Cart & Navigation

### Purpose
Treat the cart as the Single Source of Truth (SSOT) for intent-to-order and provide navigation rules that prevent checkout deep-links.

### Core Responsibilities
- Maintain a canonical cart state tied to session and hub.
- Provide guarded navigation flows that route authenticated users to the cart as the canonical step before checkout.

### Key Concepts
- Cart SSOT: cart is authoritative for items and prevalidation state.
- Navigation gate: the cart enforces a single, auditable flow into checkout.

### How It Connects to Other Phases
- Cart feeds Quote and Create-Order operations and is validated by Auth & Session and Coverage engines.

### Notes / Constraints
- Deep-linking directly into the checkout should be discouraged by server-side checks; the cart must be the canonical entry.

## PHASE 7 — Orders Foundation

### Purpose
Turn a validated cart and payment snapshot into an immutable order record that serves as the canonical transaction history.

### Core Responsibilities
- Accept a canonical quote and persist an order snapshot.
- Enforce idempotency and transactional integrity around order creation.

### Key Concepts
- Snapshot immutability: once an order is created, totals and line items are not recomputed.
- Idempotency window: guard against duplicate submissions.

### How It Connects to Other Phases
- Receives inputs from Cart, Menu, Coverage, and Delivery Fees; produces snapshots consumed by Ops and Reporting.

### Notes / Constraints
- Order creation is an ACID-sensitive operation; errors during creation should result in clean rollbacks.

## PHASE 8 — Checkout Orchestrator UX

### Purpose
Provide a resilient, single-page checkout orchestration that surfaces backend flags without making the UI a source of truth.

### Core Responsibilities
- Coordinate prevalidation, quote display, and the final create-order action.
- Surface actionable error states from backend validations without leaking internal details.

### Key Concepts
- Orchestrator: the UI layer that sequences server calls and presents state to the user.
- Backend flags: server-provided signals that the UI reflects but does not assume authoritative control over.

### How It Connects to Other Phases
- Heavily dependent on Auth, Cart, Orders Foundation, Coverage, and Payment services for decision points and UX flags.

### Notes / Constraints
- The checkout frontend must remain resilient to missing inputs; server-side validation is the source of truth.

## PHASE 9 — Addresses

### Purpose
Manage delivery destinations and provide canonical coordinates used for coverage and ETA calculations.

### Core Responsibilities
- CRUD for address entities associated with sessions or users.
- Expose canonical selected address state used by the cart/checkout.

### Key Concepts
- Selected address as SSOT: selection persists to ensure deterministic delivery calculations.
- Address validation: coordinates and normalized location data are privileged to server-side engines.

### How It Connects to Other Phases
- Addresses feed Coverage, Distance, Delivery Fee computation, and Order creation.

### Notes / Constraints
- Address operations must be guarded to prevent cross-user leaks and must validate coordinates before acceptance.

## PHASE 10 — Coverage & Distance

### Purpose
Decide whether a destination is serviceable and compute deterministic distance metrics used by fee calculations.

### Core Responsibilities
- Evaluate polygon-based coverage and compute distance/ETA metrics.
- Return reason codes and canonical distance outputs for fee engines.

### Key Concepts
- Deterministic distance: identical inputs yield identical outputs.
- Reason codes: machine-readable reasons for non-deliverable results.

### How It Connects to Other Phases
- Coverage outputs are consumed by Delivery Fees, Checkout prevalidation, and Order creation.

### Notes / Constraints
- Coverage decisions must be made server-side and should never be trusted from client inputs alone.

## PHASE 11 — Delivery Fees

### Purpose
Compute the delivery fee deterministically from distance, city/hub rules, and configured rates, and capture that fee in the order snapshot.

### Core Responsibilities
- Provide a deterministic fee computation engine.
- Ensure the computed fee is snapshotted at order creation to prevent retroactive changes.

### Key Concepts
- Fee components: base, distance, tiering, and minimum/maximum caps.
- Snapshotting: final fee is persisted with the order to ensure billing integrity.

### How It Connects to Other Phases
- Consumes Coverage outputs and city/hub rate configurations; writes into Order snapshots consumed by Payments and Reporting.

### Notes / Constraints
- Changes to rates must not mutate historical orders; billing integrity depends on snapshot immutability.

## PHASE 12 — Payments

### Purpose
Provide a secure, idempotent payment flow that maps order snapshots to provider intents and reconciles webhook events into final order settlement.

### Core Responsibilities
- Create provider payment intents using order snapshots.
- Reconcile asynchronous webhook events, ensuring idempotency and secure verification of events.

### Key Concepts
- Intent-first model: payments are tied to immutable order totals.
- Webhook reconciliation with idempotency guarantees.

### How It Connects to Other Phases
- Takes order snapshots as input and updates order settlement state; errors here influence retry and refund flows.

### Notes / Constraints
- Webhook handling must be robust against replay and partial failure; signatures or equivalent verification are required.

## PHASE 13 — Ops Dispatch

### Purpose
Provide the operational surface for assigning drivers, tracking dispatch state, and managing terminal ops actions.

### Core Responsibilities
- Manage assignment/unassignment and status transitions for driver-work items.
- Provide operational read/write surfaces for admins and managers to coordinate deliveries.

### Key Concepts
- Operational SSOT: driver assignment state is the live operational truth distinct from the order snapshot.
- Terminal states: delivery lifecycle states that prevent certain transitions once reached.

### How It Connects to Other Phases
- Consumes order snapshots and produces driver ops events; ops state is read by Dashboards and reporting.

### Notes / Constraints
- Terminal state transitions are privileged operations and must be guarded to prevent invalid reassignments.

## PHASE 14 — Drivers Runtime

### Purpose
Support the mobile/field-facing workflow for drivers: work lists, status updates, availability toggles, and reporting.

### Core Responsibilities
- Provide driver-facing order lists scoped to assignment.
- Accept status updates and availability changes from driver clients.

### Key Concepts
- Driver scoping: drivers only see work assigned to them or permitted by their hub scope.
- Non-money operations: drivers do not alter monetary snapshots; they report operational outcomes.

### How It Connects to Other Phases
- Driver actions mutate Ops Dispatch state and surface to Dashboards and Reporting.

### Notes / Constraints
- Driver clients should be rate-limited and validated; availability toggles must be auditable.

## PHASE 15 — Customer Order Experience

### Purpose
Present the customer with reliable order history, status, and timeline derived from immutable snapshots and ops events.

### Core Responsibilities
- Read-only presentation of order history and active order status.
- Timeline generation from snapshots and op-state events.

### Key Concepts
- Read-only canonical: the UI is a projection of persisted snapshots and ops events, not a source of truth.

### How It Connects to Other Phases
- Pulls data from Orders Foundation and Ops Dispatch to present coherent timelines.

### Notes / Constraints
- The customer view must not expose operational controls or allow direct mutation of snapshot data.

## PHASE 16 — Dashboards & Reporting

### Purpose
Consolidate historical and operational data for administrative visibility, audits, and performance analysis.

### Core Responsibilities
- Aggregate snapshots and ops events for reporting queries and dashboards.
- Provide read-only export views and pagination for large history sets.

### Key Concepts
- Read-only aggregation: reporting reads immutable snapshots rather than recalculating transactional data.

### How It Connects to Other Phases
- Consumes Orders snapshots and Ops events; supports business analysis and incident triage.

### Notes / Constraints
- Reporting tools must avoid recalculating orders; they should use the persisted snapshots to maintain fidelity.

## PHASE 17 — Notifications (Frozen)

### Purpose
Define the hooks and channels used for delivering operational notifications once core flows are stable.

### Core Responsibilities
- Provide a pluggable surface for notifications without embedding delivery logic into core transaction flows.

### Key Concepts
- Event subscription model: notifications subscribe to op or order events rather than driving flow logic.

### How It Connects to Other Phases
- Subscribes to Ops Dispatch and Orders events to deliver user-facing notifications.

### Notes / Constraints
- Notifications are intentionally decoupled and deferred until core contracts are stable.

## PHASE 18 — Experiments / Later

### Purpose
Hold a place for future non-essential features and experimentations that should not affect core contracts.

### Core Responsibilities
- Provide a sandboxed area for feature experiments and integration tests that can be toggled off.

### Key Concepts
- Isolation: experimental features must not mutate or rely on canonical snapshots.

### How It Connects to Other Phases
- Experiments may read from but must not write to canonical data without explicit migration paths.

### Notes / Constraints
- Experimental work must be guarded by feature flags and reversible changes.

---

End of manual.

