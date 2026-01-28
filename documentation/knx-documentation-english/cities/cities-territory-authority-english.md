1. File Role

The CITIES domain documents the territory model that Kingdom Nexus uses to represent locations where the system operates. Territory is a first-class concept: cities are explicit records in the database that represent geographic and administrative scope. Multi-region enablement is achieved by maintaining multiple city records and associating downstream entities with those records. Downstream domains (hubs, drivers, delivery rates, orders, managers) depend on the explicit existence of cities for scoping, authorization, and persistence.

2. Canonical Responsibilities

- City creation and existence: CITIES is authoritative for creating city records and for determining whether a city exists in the system.
- Operational enable/disable: CITIES stores and exposes an `is_operational` flag used by other components to indicate runtime availability.
- Soft deletion: CITIES implements soft delete semantics for city records and is the canonical source for deleted vs present state.
- Delivery rate persistence (writes): CITIES-area endpoints persist delivery-rate records tied to a city; write operations validate city identity against existing, non-deleted cities.
- Manager scoping by city: Role and manager scoping that depends on city boundaries is represented via associations to cities (through hubs and related records); CITIES is authoritative for the city-side of that association.

3. What CITIES Explicitly Does NOT Do

- Does not manage drivers: Driver lifecycle and driver-specific data are outside CITIES authority.
- Does not enforce order lifecycle: Order state transitions and workflow are outside CITIES authority.
- Does not calculate delivery pricing: Pricing logic and any dynamic delivery calculation are not performed inside CITIES.
- Does not assign hubs: Hub assignment and hub selection are handled by hub-related components, not by CITIES.
- Does not fully enforce identity binding: CITIES does not fully bind user identities to cities; identity checks are delegated to session and helper layers.

4. Territory & Multi-Region Model

- Multiple cities coexist as independent records; each city record is an explicit territory.
- There is no default city. The system does not implicitly infer a city from context.
- No inferred territory exists: existence requires explicit creation of a city record.
- Explicit creation is required for a city to be considered present in the territory model.

5. Existence vs Operational State (IMPORTANT)

- Two flags exist and are present in the current implementation: `status` (administrative) and `is_operational` (runtime availability).
- Both flags exist concurrently in the data model and in API responses.
- Both flags may affect availability today. Some consumers check `status`; others check `is_operational`.
- The implementation does not fully separate responsibilities for these flags: behavior overlaps and consumers make different assumptions.

Semantic distinction (documented intent, not enforced):
- `status`: system-level existence and administrative state (records present, soft-deleted, or administratively inactive).
- `is_operational`: runtime operational availability that indicates whether the city is currently usable for operational flows.

This co-existence and overlap is a documented architectural limit of the current implementation.

6. Operational Enforcement

- Create / Update: Create and update endpoints operate against the canonical city records and validate inputs against existing schema and constraints.
- Delete: Delete operations implement soft deletion and are blocked when dependent hubs exist. The system prevents deletion of a city record if hub records reference it.
- Toggle (operational): Toggling `is_operational` updates the flag on the canonical city record and that flag is consulted by some downstream consumers.
- Manager scoping: Manager scoping is enforced by associating managers to hubs which in turn are associated to cities; managers are effectively scoped to cities via those hub associations.

7. Delivery Rates (Current Behavior)

Writes:
- Delivery-rate write operations validate that the target city exists and is not soft-deleted before persisting rates.

Reads:
- Delivery-rate read operations may return default values when no delivery-rate row exists for a city.
- Read paths do not always strictly validate city existence or the city `is_operational` flag before returning defaults.

8. Identity & Session Interaction

- CITIES relies on session resolution provided by the helpers layer (`helpers.php`) for identity and role resolution in permission checks.
- Role hierarchy enforcement is delegated to helper functions; CITIES endpoints use those helper checks rather than re-implementing full identity enforcement.
- Manager scoping is enforced by checking associations (managers → hubs → cities) with identity resolved via session helpers.
- Drivers are not enforced at the CITIES layer. Identity binding is partial and delegated to helper/permission layers outside CITIES.

9. Fail-Closed Guarantees

Fails closed (observed):
- City writes: Write operations that create or update city records validate existence and fail when invalid.
- Deletes: Delete operations are blocked when dependent hubs exist and do not proceed silently.
- Operational toggles: Toggling `is_operational` persists to the canonical record and does not silently ignore invalid targets.
- Delivery-rate writes: Delivery-rate persistence validates city existence and does not write for missing or deleted cities.

Does NOT fail closed (observed):
- Delivery-rate reads: Read paths may return defaults instead of failing when a delivery-rate row is absent.
- Some schema-dependent paths: Behavior varies if schema columns are absent; those paths exhibit permissive behavior that depends on deployment schema.

10. Known Limits & Risks

- Delivery-rate read fallback behavior: When a delivery-rate row is absent, read endpoints may return default values rather than error, which can surface as permissive availability.
- Schema-dependent permissiveness: Several code paths detect or adapt to available schema and therefore behave permissively in environments with different schemas.
- Overlap between `status` and `is_operational`: The concurrent existence of both fields and their uneven use across consumers create ambiguity about availability and administrative intent.
- Dependency on `helpers.php` authority: Permission, session, and identity resolution are delegated to the helpers layer; CITIES behavior and enforcement depend on that external authority.

11. Canonical Status

Status: CANONICAL WITH LIMITS

- Why CITIES is canonical: CITIES is the canonical source for city records, city existence, soft deletion, `is_operational` state, and delivery-rate persistence on writes. Downstream domains reference cities for scoping and persistence.
- Why CITIES is not sealed: The domain contains implementation overlaps and permissive read behaviors (delivery-rate reads, schema-dependent logic) and depends on external helper authority for identity and role enforcement. Those limits prevent classifying CITIES as fully sealed.
- Limits are documented and observable in current behavior; they are not hidden.

12. Architectural Principle

Cities are authoritative territory primitives: explicit records represent scope and persist operational state. Territory existence requires explicit creation, and runtime availability is an orthogonal signal. Enforcement is explicit for writes and deletions; reads contain permissive paths that require careful consumer awareness.
