<?php
if (!defined('ABSPATH')) exit;

/**
 * ==========================================================
 * KNX Hub Managers Admin Shortcode [knx_hub_managers]
 * ----------------------------------------------------------
 * SuperAdmin/Manager UI to manage hub_management users:
 *  - List all hub managers
 *  - Create new hub_management user
 *  - Assign/unassign users to hubs
 *  - Delete hub_management users (super_admin only)
 *
 * Security: super_admin and manager only
 * ==========================================================
 */

add_shortcode('knx_hub_managers', function () {
    // Auth guard
    $session = knx_get_session();
    if (!$session || !in_array($session->role, ['super_admin', 'manager'])) {
        wp_safe_redirect(site_url('/login'));
        exit;
    }

    $is_super_admin = ($session->role === 'super_admin');
    $nonce = wp_create_nonce('knx_hub_managers_nonce');
    $wp_nonce = wp_create_nonce('wp_rest');

    // Get all hubs for dropdown
    global $wpdb;
    $hubs_table = $wpdb->prefix . 'knx_hubs';
    $hubs = $wpdb->get_results("SELECT id, name, status FROM {$hubs_table} ORDER BY name ASC");

    ob_start();
    ?>

<link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/core/knx-toast.css'); ?>">
<style>
.knx-managers-wrap {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.knx-managers-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
}

.knx-managers-header h1 {
    margin: 0;
    font-size: 1.5rem;
    font-weight: 700;
    color: #0b1220;
    display: flex;
    align-items: center;
    gap: 10px;
}

.knx-managers-header h1 i {
    color: #0b793a;
}

.knx-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    border: none;
    transition: all 0.15s ease;
    text-decoration: none;
}

.knx-btn-primary {
    background: #0b793a;
    color: #fff;
}

.knx-btn-primary:hover {
    background: #096830;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(11, 121, 58, 0.3);
}

.knx-btn-secondary {
    background: #f3f4f6;
    color: #374151;
    border: 1px solid #d1d5db;
}

.knx-btn-secondary:hover {
    background: #e5e7eb;
}

.knx-btn-danger {
    background: #dc2626;
    color: #fff;
}

.knx-btn-danger:hover {
    background: #b91c1c;
}

.knx-btn-sm {
    padding: 6px 12px;
    font-size: 13px;
}

/* Cards */
.knx-card {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    padding: 24px;
    margin-bottom: 20px;
}

.knx-card h2 {
    margin: 0 0 16px;
    font-size: 1.1rem;
    font-weight: 600;
    color: #1f2937;
}

/* Table */
.knx-table-wrap {
    overflow-x: auto;
}

.knx-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

.knx-table th,
.knx-table td {
    padding: 12px 16px;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
}

.knx-table th {
    background: #f9fafb;
    font-weight: 600;
    color: #6b7280;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.knx-table tbody tr:hover {
    background: #f9fafb;
}

.knx-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.knx-badge-green {
    background: #d1fae5;
    color: #065f46;
}

.knx-badge-gray {
    background: #f3f4f6;
    color: #6b7280;
}

.knx-badge-blue {
    background: #dbeafe;
    color: #1e40af;
}

.knx-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

/* Modal */
.knx-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 99999;
    align-items: center;
    justify-content: center;
}

.knx-modal.active {
    display: flex;
}

.knx-modal-content {
    background: #fff;
    border-radius: 16px;
    padding: 28px;
    max-width: 500px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
}

.knx-modal-content h3 {
    margin: 0 0 20px;
    font-size: 1.25rem;
    font-weight: 700;
    color: #0b1220;
}

.knx-form-group {
    margin-bottom: 16px;
}

.knx-form-group label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
    font-size: 13px;
    color: #374151;
}

.knx-form-group input,
.knx-form-group select {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 14px;
    transition: border-color 0.15s;
}

.knx-form-group input:focus,
.knx-form-group select:focus {
    outline: none;
    border-color: #0b793a;
    box-shadow: 0 0 0 3px rgba(11, 121, 58, 0.1);
}

.knx-modal-actions {
    display: flex;
    gap: 12px;
    margin-top: 24px;
    justify-content: flex-end;
}

/* Empty state */
.knx-empty {
    text-align: center;
    padding: 48px 24px;
    color: #6b7280;
}

.knx-empty i {
    font-size: 48px;
    color: #d1d5db;
    margin-bottom: 16px;
}

.knx-empty p {
    margin: 0;
    font-size: 15px;
}

/* Loading */
.knx-loading {
    text-align: center;
    padding: 32px;
    color: #6b7280;
}

.knx-loading i {
    font-size: 24px;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* User info in table */
.knx-user-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.knx-user-name {
    font-weight: 600;
    color: #1f2937;
}

.knx-user-email {
    font-size: 12px;
    color: #6b7280;
}

/* Hubs pills */
.knx-hubs-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.knx-hub-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    background: #eff6ff;
    color: #1e40af;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
}

.knx-hub-pill .remove-hub {
    cursor: pointer;
    opacity: 0.6;
    transition: opacity 0.15s;
}

.knx-hub-pill .remove-hub:hover {
    opacity: 1;
    color: #dc2626;
}

@media (max-width: 768px) {
    .knx-managers-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .knx-table th:nth-child(4),
    .knx-table td:nth-child(4) {
        display: none;
    }
}
</style>

<div class="knx-managers-wrap"
     data-nonce="<?php echo esc_attr($nonce); ?>"
     data-wp-nonce="<?php echo esc_attr($wp_nonce); ?>"
     data-api-list="<?php echo esc_url(rest_url('knx/v1/hub-managers')); ?>"
     data-api-create="<?php echo esc_url(rest_url('knx/v1/hub-managers/create-user')); ?>"
     data-api-assign="<?php echo esc_url(rest_url('knx/v1/hub-managers/assign')); ?>"
     data-api-unassign="<?php echo esc_url(rest_url('knx/v1/hub-managers/unassign')); ?>"
     data-api-delete="<?php echo esc_url(rest_url('knx/v1/hub-managers')); ?>"
     data-is-super-admin="<?php echo $is_super_admin ? '1' : '0'; ?>">

    <!-- Header -->
    <div class="knx-managers-header">
        <h1><i class="fas fa-user-tie"></i> Hub Managers</h1>
        <div>
            <button class="knx-btn knx-btn-primary" id="btnCreateManager">
                <i class="fas fa-plus"></i> New Hub Manager
            </button>
        </div>
    </div>

    <!-- Managers List -->
    <div class="knx-card">
        <h2>All Hub Managers</h2>
        <div class="knx-table-wrap">
            <div id="managersTableContainer">
                <div class="knx-loading">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading managers...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Create New Manager -->
<div class="knx-modal" id="modalCreateManager">
    <div class="knx-modal-content">
        <h3><i class="fas fa-user-plus"></i> Create Hub Manager</h3>
        <form id="formCreateManager">
            <div class="knx-form-group">
                <label for="newManagerName">Full Name</label>
                <input type="text" id="newManagerName" name="name" placeholder="John Doe" required>
            </div>
            <div class="knx-form-group">
                <label for="newManagerUsername">Username</label>
                <input type="text" id="newManagerUsername" name="username" placeholder="johndoe" required minlength="3">
            </div>
            <div class="knx-form-group">
                <label for="newManagerEmail">Email</label>
                <input type="email" id="newManagerEmail" name="email" placeholder="john@example.com" required>
            </div>
            <div class="knx-form-group">
                <label for="newManagerPassword">Password</label>
                <input type="password" id="newManagerPassword" name="password" placeholder="Min 8 characters" required minlength="8">
            </div>
            <div class="knx-form-group">
                <label for="newManagerHub">Assign to Hub (optional)</label>
                <select id="newManagerHub" name="hub_id">
                    <option value="">-- Select a hub --</option>
                    <?php foreach ($hubs as $hub) : ?>
                    <option value="<?php echo esc_attr($hub->id); ?>">
                        <?php echo esc_html($hub->name); ?>
                        <?php if ($hub->status !== 'active') echo ' (inactive)'; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="knx-modal-actions">
                <button type="button" class="knx-btn knx-btn-secondary" id="btnCancelCreate">Cancel</button>
                <button type="submit" class="knx-btn knx-btn-primary" id="btnSubmitCreate">
                    <i class="fas fa-check"></i> Create Manager
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Assign Hub -->
<div class="knx-modal" id="modalAssignHub">
    <div class="knx-modal-content">
        <h3><i class="fas fa-link"></i> Assign Hub</h3>
        <form id="formAssignHub">
            <input type="hidden" id="assignUserId" name="user_id">
            <p id="assignUserInfo" style="margin-bottom: 16px; color: #6b7280;"></p>
            <div class="knx-form-group">
                <label for="assignHubSelect">Select Hub</label>
                <select id="assignHubSelect" name="hub_id" required>
                    <option value="">-- Select a hub --</option>
                    <?php foreach ($hubs as $hub) : ?>
                    <option value="<?php echo esc_attr($hub->id); ?>">
                        <?php echo esc_html($hub->name); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="knx-modal-actions">
                <button type="button" class="knx-btn knx-btn-secondary" id="btnCancelAssign">Cancel</button>
                <button type="submit" class="knx-btn knx-btn-primary">
                    <i class="fas fa-link"></i> Assign
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Confirm Delete -->
<div class="knx-modal" id="modalConfirmDelete">
    <div class="knx-modal-content">
        <h3><i class="fas fa-exclamation-triangle" style="color:#dc2626;"></i> Delete Manager</h3>
        <p id="deleteUserInfo" style="margin-bottom: 16px;"></p>
        <p style="color: #dc2626; font-weight: 600;">This action cannot be undone.</p>
        <input type="hidden" id="deleteUserId">
        <div class="knx-modal-actions">
            <button type="button" class="knx-btn knx-btn-secondary" id="btnCancelDelete">Cancel</button>
            <button type="button" class="knx-btn knx-btn-danger" id="btnConfirmDelete">
                <i class="fas fa-trash"></i> Delete
            </button>
        </div>
    </div>
</div>

<script src="<?php echo esc_url(KNX_URL . 'inc/modules/core/knx-toast.js'); ?>"></script>
<script src="<?php echo esc_url(KNX_URL . 'inc/modules/hubs/hub-managers.js?v=' . KNX_VERSION); ?>"></script>

<?php
    return ob_get_clean();
});
