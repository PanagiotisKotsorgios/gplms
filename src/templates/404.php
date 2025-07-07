

<!-- 404 error content container -->
<div class="error-container">
    <div class="error-header">
        <div class="error-number">404</div>
        <!-- Localized error heading -->
        <h1 class="error-title"><?= $lang['page_not_found_title'] ?></h1>
    </div>

    <p class="error-message">
        <?= $lang['page_not_found_message'] ?>
    </p>
    
    <div class="btn-container">
        <a href="login.php" class="btn-login">
            <i class="fas fa-sign-in-alt"></i> <?= $lang['go_to_login'] ?>
        </a>
    </div>
</div>
