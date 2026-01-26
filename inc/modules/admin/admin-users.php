<?php
if (!defined('ABSPATH')) exit;

/**
 * Kingdom Nexus - Admin Users (v2)
 *
 * Securely manages KNX users inside the WP Admin.
 * Includes role assignment, password hashing, and validation.
 */

global $wpdb;
$users_table = $wpdb->prefix . 'knx_users';

// Handle new user creation
if (isset($_POST['knx_add_user_btn'])) {
    check_admin_referer('knx_add_user_action', 'knx_add_user_nonce');

    $username = sanitize_user($_POST['knx_username']);
    $email    = sanitize_email($_POST['knx_email']);
    $password = sanitize_text_field($_POST['knx_password']);
    $role     = sanitize_text_field($_POST['knx_role']);
    $status   = 'active';

    if (empty($username) || empty($email) || empty($password)) {
        echo '<div class="notice notice-error"><p>Please fill in all required fields.</p></div>';
    } else {
        // Prevent duplicate email or username
        $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $users_table WHERE email = %s OR username = %s", $email, $username));

        if ($exists) {
            echo '<div class="notice notice-error"><p>User with that email or username already exists.</p></div>';
        } else {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $wpdb->insert($users_table, [
                'username' => $username,
                'email'    => $email,
                'password' => $hashed,
                'role'     => $role,
                'status'   => $status
            ]);
            echo '<div class="notice notice-success"><p>New KNX user created successfully.</p></div>';
        }
    }
}

// Fetch users
$users = $wpdb->get_results("SELECT * FROM $users_table ORDER BY id DESC");
?>

<link rel="stylesheet" href="<?php echo esc_url(KNX_URL . 'inc/modules/admin/admin-style.css'); ?>">

<div class="wrap">
  <h1>KNX User Management</h1>

  <div class="knx-admin-grid">
    <!-- Create user form -->
    <div class="knx-card">
      <h2>Add New User</h2>

      <form method="post">
        <?php wp_nonce_field('knx_add_user_action', 'knx_add_user_nonce'); ?>

        <p>
          <label>Username</label><br>
          <input type="text" name="knx_username" required>
        </p>

        <p>
          <label>Email</label><br>
          <input type="email" name="knx_email" required>
        </p>

        <p>
          <label>Password</label><br>
          <input type="password" name="knx_password" required>
        </p>

        <p>
          <label>Role</label><br>
          <select name="knx_role" required>
            <option value="super_admin">Super Admin</option>
            <option value="manager">Manager</option>
            <option value="menu_uploader">Menu Uploader</option>
            <option value="hub_management">Hub Management</option>
            <option value="driver">Driver</option>
            <option value="customer">Customer</option>
            <option value="user">User</option>
          </select>
        </p>

        <p>
          <button type="submit" name="knx_add_user_btn" class="button button-primary">
            Create User
          </button>
        </p>
      </form>
    </div>

    <!-- Users table -->
    <div class="knx-card">
      <h2>Existing Users</h2>

      <table class="wp-list-table widefat fixed striped">
        <thead>
          <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Email</th>
            <th>Role</th>
            <th>Status</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($users): ?>
            <?php foreach ($users as $u): ?>
              <tr>
                <td><?php echo esc_html($u->id); ?></td>
                <td><?php echo esc_html($u->username); ?></td>
                <td><?php echo esc_html($u->email); ?></td>
                <td><strong><?php echo esc_html(ucfirst(str_replace('_', ' ', $u->role))); ?></strong></td>
                <td><?php echo esc_html($u->status); ?></td>
                <td><?php echo esc_html($u->created_at); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="6" style="text-align:center;">No users found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
