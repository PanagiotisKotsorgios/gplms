<?php
session_start();

// Load configuration file containing constants and environment settings
require_once '../conf/config.php';

require_once '../conf/translation.php';

require_once '../functions/fetch-lib-name.php';
// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Get current user info
$current_user_id = $_SESSION['user_id'];

if ($current_user_id !== 1) {
    header("Location: login.php");
    exit;
}


$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$current_user = $stmt->fetch();

// Check if user is admin
if ($_SESSION['role'] !== 'Administrator') {
    $error_msg = $lang['access_denied_admin'];
}

// ------------------- CHECK USER REGISTRATION SETTING ------------------- //
$allow_user_registration = 0;
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'allow_user_registration' LIMIT 1");
    $stmt->execute();
    $result = $stmt->fetch();
    if ($result) {
        $allow_user_registration = intval($result['setting_value']);
    }
} catch (Exception $e) {
    // fail gracefully, keep default 0
}
// ----------------------------------------------------------------------- //

// Function to log activity
function logActivity($pdo, $user_id, $action, $target_object = null, $details = null) {
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $username = $_SESSION['username'] ?? 'system';
    
    $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, username, action, target_object, details, ip_address) 
                           VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $username, $action, $target_object, $details, $ip_address]);
}

// Handle form submissions
$success_msg = '';
$error_msg = isset($error_msg) ? $error_msg : '';

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action_type'])) {
        $action_type = $_POST['action_type'];
        
        // ------------- BLOCK REGISTRATION IF SETTING IS DISABLED ------------- //
        if ($action_type === 'add_user' && $allow_user_registration == 0) {
            // Instead of adding, set error and show popup
            $error_msg = $lang['user_reg_not_enabled'] ?? "Enable user registration first in settings before adding a new user.";
        } else {
        // --------------------------------------------------------------------- //
            try {
                $pdo->beginTransaction();
                
                if ($action_type === 'add_user') {
                    $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, phone, role_id, status) 
                                          VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $_POST['username'],
                        password_hash($_POST['password'], PASSWORD_DEFAULT),
                        $_POST['full_name'],
                        $_POST['email'],
                        $_POST['phone'],
                        $_POST['role_id'],
                        $_POST['status']
                    ]);
                    $success_msg = $lang['user_added_success'];
                    logActivity($pdo, $current_user_id, 'INSERT', 'users', $lang['log_added_user'] . ': '.$_POST['username']);
                }
                    elseif ($action_type === 'update_user') {
                        // Build base fields without user_id
                        $update_fields = [
                            'username' => $_POST['username'],
                            'full_name' => $_POST['full_name'],
                            'email' => $_POST['email'],
                            'phone' => $_POST['phone'],
                            'role_id' => $_POST['role_id'],
                            'status' => $_POST['status']
                        ];

                        // Add password if provided
                        if (!empty($_POST['password'])) {
                            $update_fields['password'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
                            $sql = "UPDATE users SET username = ?, full_name = ?, email = ?, phone = ?, 
                                    role_id = ?, status = ?, password = ? WHERE user_id = ?";
                        } else {
                            $sql = "UPDATE users SET username = ?, full_name = ?, email = ?, phone = ?, 
                                    role_id = ?, status = ? WHERE user_id = ?";
                        }

                        // Add user_id LAST for WHERE clause
                        $update_fields['user_id'] = $_POST['user_id'];

                        $stmt = $pdo->prepare($sql);
                        $stmt->execute(array_values($update_fields));
                        
                        $success_msg = $lang['user_updated_success'];
                        logActivity($pdo, $current_user_id, 'UPDATE', 'users', $lang['log_updated_user'] . ': '.$_POST['username']);
                    }
                                    
                $pdo->commit();
                
                // Redirect to clear parameters after successful form submission
                header("Location: users-manager.php");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error_msg = $lang['error_generic'] . $e->getMessage();
            }
        }
    }
}

// Handle user status change
if (isset($_GET['status_change']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $new_status = $_GET['status_change'] === 'activate' ? 'active' : 'suspended';
    
    try {
        $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE user_id = ?");
        $stmt->execute([$new_status, $id]);
        
        $success_msg = $lang['user_status_updated_success'];
        logActivity($pdo, $current_user_id, 'UPDATE', 'users', $lang['log_changed_status_user'] . ': '.$id);
        
        // Redirect after status change
        header("Location: users-manager.php");
        exit;
    } catch (Exception $e) {
        $error_msg = $lang['error_updating_status'] . $e->getMessage();
    }
}

// Handle delete user
if (isset($_GET['delete']) && $_GET['delete'] === 'user' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    try {
        // Check if user is trying to delete themselves
        if ($id === $current_user_id) {
            $error_msg = $lang['cannot_delete_own_account'];
        } else {
            // Start transaction
            $pdo->beginTransaction();
            
            // Delete related activity logs first
            $stmt = $pdo->prepare("DELETE FROM activity_logs WHERE user_id = ?");
            $stmt->execute([$id]);
            
            // Now delete the user
            $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
            $stmt->execute([$id]);
            
            // Commit changes
            $pdo->commit();
            
            $success_msg = $lang['user_deleted_success'];
            logActivity($pdo, $current_user_id, 'DELETE', 'users', $lang['log_deleted_user'] . ': '.$id);
            
            // Redirect after delete
            header("Location: users-manager.php");
            exit;
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_msg = $lang['error_deleting_user'] . $e->getMessage();
    }
}

// Handle edit user request
if (isset($_GET['edit_user'])) {
    // Store user ID in session for modal handling
    $_SESSION['edit_user_id'] = (int)$_GET['edit_user'];
    
    // Redirect to clear URL parameters
    header("Location: users-manager.php");
    exit;
}

// Get users and roles
$users = $pdo->query("SELECT * FROM users")->fetchAll();
$roles = $pdo->query("SELECT * FROM roles")->fetchAll();

// Check if we have a stored edit user ID
$edit_user = null;
if (isset($_SESSION['edit_user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['edit_user_id']]);
    $edit_user = $stmt->fetch();
    
    // Clear the session variable after use
    unset($_SESSION['edit_user_id']);
}
?>
<!DOCTYPE html>
<html lang="<?= $default_language ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $lang['page_title_users_manager'] ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="../styles/users-manager-gen-styles.css" rel="stylesheet">
    <link href="../styles/components/sidebar1.css" rel="stylesheet">

    <link rel="icon" type="image/png" href="../../assets/logo-l.png">
</head>
<body>
    <?php include '../components/sidebar1.php'; ?>
    <?php include '../components/user-main-content.php'; ?>
    <?php include '../components/user-modal.php'; ?>

    <!-- Popup for registration disabled -->
    <?php if (!empty($error_msg) && isset($_POST['action_type']) && $_POST['action_type'] === 'add_user' && $allow_user_registration == 0): ?>
        <div class="modal fade" id="registrationDisabledModal" tabindex="-1" aria-labelledby="registrationDisabledLabel" aria-hidden="true">
          <div class="modal-dialog">
            <div class="modal-content">
              <div class="modal-header bg-warning">
                <h5 class="modal-title" id="registrationDisabledLabel"><?= $lang['user_reg_not_enabled'] ?? "User Registration Disabled" ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <?= htmlspecialchars($error_msg) ?>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= $lang['close'] ?? "Close" ?></button>
              </div>
            </div>
          </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var modal = new bootstrap.Modal(document.getElementById('registrationDisabledModal'));
            modal.show();
        });
        </script>
    <?php endif; ?>

    <!-- Bootstrap & jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Auto-show modal if editing user
        <?php if ($edit_user): ?>
            document.addEventListener('DOMContentLoaded', function() {
                var modal = new bootstrap.Modal(document.getElementById('userModal'));
                modal.show();
            });
        <?php endif; ?>
        
        // Toggle sidebar on mobile
        document.querySelector('.toggle-sidebar').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
            document.querySelector('.main-content').classList.toggle('active');
        });
        
        // Enhance table with row hover effects
        document.querySelectorAll('.admin-table tr').forEach(row => {
            row.addEventListener('mouseenter', () => {
                row.style.backgroundColor = '#f8fafd';
            });
            row.addEventListener('mouseleave', () => {
                row.style.backgroundColor = '';
            });
        });


    
document.addEventListener('DOMContentLoaded', function () {
    var userModal = document.getElementById('userModal');
    if (userModal) {
        userModal.addEventListener('hidden.bs.modal', function () {
            // Always reload the page after the modal is closed to reset state
            window.location.href = 'users-manager.php';
        });
    }
});

    </script>
</body>
</html>